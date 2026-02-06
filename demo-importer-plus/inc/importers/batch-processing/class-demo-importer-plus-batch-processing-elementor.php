<?php
/**
 * Elementor Importer
 *
 * @package Demo Importer Plus
 */

namespace Elementor\TemplateLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	return;
}

use Elementor\Core\Base\Document;
use Elementor\Core\Editor\Editor;
use Elementor\DB;
use Elementor\Core\Settings\Manager as SettingsManager;
use Elementor\Core\Settings\Page\Model;
use Elementor\Modules\Library\Documents\Library_Document;
use Elementor\Plugin;
use Elementor\Utils;

/**
 * Elementor template library local source.
 *
 * Elementor template library local source handler class is responsible for
 * handling local Elementor templates saved by the user locally on his site.
 *
 * @since 1.0.0
 */
class Demo_Importer_Plus_Batch_Processing_Elementor extends Source_Local {

	/**
	 * Import
	 *
	 * @return void
	 */
	public function import() {
		$post_types = \Demo_Importer_Plus_Batch_Processing::get_post_types_supporting( 'elementor' );

		if ( empty( $post_types ) && ! is_array( $post_types ) ) {
			return;
		}

		$post_ids = \Demo_Importer_Plus_Batch_Processing::get_pages( $post_types );
		if ( empty( $post_ids ) && ! is_array( $post_ids ) ) {
			return;
		}

		foreach ( $post_ids as $post_id ) {
			$this->import_single_post( $post_id );
		}
	}
	/**
	 * Update post meta.
	 *
	 * @param  integer $post_id Post ID.
	 */
	public function import_single_post( $post_id = 0 ) {

		$is_elementor_post = get_post_meta( $post_id, '_elementor_version', true );
		if ( ! $is_elementor_post ) {
			return;
		}

		$imported_from_demo_site = get_post_meta( $post_id, '_demo_importer_enable_for_batch', true );
		if ( ! $imported_from_demo_site ) {
			return;
		}

		if ( defined( 'WP_CLI' ) ) {
			\WP_CLI::line( 'Elementor - Processing page: ' . $post_id );
		}

		if ( ! empty( $post_id ) ) {

			$data = get_post_meta( $post_id, '_elementor_data', true );

			if ( ! empty( $data ) ) {

				$ids_mapping = get_option( 'demo_importer_plus_cf7_ids_mapping', array() );
				if ( $ids_mapping ) {
					foreach ( $ids_mapping as $old_id => $new_id ) {
						$data = str_replace( '[contact-form-7 id=\"' . $old_id, '[contact-form-7 id=\"' . $new_id, $data );
						$data = str_replace( '"select_form":"' . $old_id, '"select_form":"' . $new_id, $data );
					}
				}

				if ( ! is_array( $data ) ) {
					$data = json_decode( $data, true );
				}
				$document = Plugin::$instance->documents->get( $post_id );
				if ( $document ) {
					$data = $document->get_elements_raw_data( $data, true );
				}

				// Download SVG icons from Elementor data.
				$data = $this->download_elementor_svg_icons( $data );

				$data = $this->process_export_import_content( $data, 'on_import' );

				$demo_url = DEMO_IMPORTER_PLUS_MAIN_DEMO_URI;

				$demo_data = get_option( 'demo_importer_plus_import_data', array() );
				if ( isset( $demo_url ) ) {
					$data = wp_json_encode( $data, true );
					if ( ! empty( $data ) ) {
						$site_url      = get_site_url();
						$site_url      = str_replace( '/', '\/', $site_url );
						$demo_site_url = $demo_url;
						$demo_site_url = str_replace( '/', '\/', $demo_site_url );
						$data          = str_replace( $demo_site_url, $site_url, $data );
						$data          = json_decode( $data, true );
					}
				}

				update_metadata( 'post', $post_id, '_elementor_data', $data );
				update_metadata( 'post', $post_id, '_demo_importer_plus_hotlink_imported', true );

				Plugin::$instance->files_manager->clear_cache();
			}
		}
	}

	/**
	 * Download SVG icons from Elementor data.
	 *
	 * Recursively scans Elementor data for SVG icon URLs and downloads them.
	 *
	 * @param array $data Elementor data array.
	 * @return array Modified Elementor data with local SVG URLs.
	 */
	private function download_elementor_svg_icons( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				// Recursively process nested arrays
				$data[ $key ] = $this->download_elementor_svg_icons( $value );
			} elseif ( $key === 'selected_icon' && is_array( $value ) ) {
				// Handle icon control with SVG
				if ( isset( $value['library'] ) && $value['library'] === 'svg' && isset( $value['value']['url'] ) ) {
					$svg_url = $value['value']['url'];
					if ( $this->is_demo_site_url( $svg_url ) ) {
						$downloaded_svg = $this->download_svg_file( $svg_url );
						if ( ! is_wp_error( $downloaded_svg ) && isset( $downloaded_svg['url'] ) ) {
							$data[ $key ]['value']['url'] = $downloaded_svg['url'];
							if ( isset( $downloaded_svg['id'] ) ) {
								$data[ $key ]['value']['id'] = $downloaded_svg['id'];
							}
						}
					}
				}
			} elseif ( is_string( $value ) && $this->is_svg_url( $value ) && $this->is_demo_site_url( $value ) ) {
				// Handle direct SVG URL strings
				$downloaded_svg = $this->download_svg_file( $value );
				if ( ! is_wp_error( $downloaded_svg ) && isset( $downloaded_svg['url'] ) ) {
					$data[ $key ] = $downloaded_svg['url'];
				}
			}
		}

		return $data;
	}

	/**
	 * Check if URL is from demo site.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL is from demo site.
	 */
	private function is_demo_site_url( $url ) {
		$demo_url = DEMO_IMPORTER_PLUS_MAIN_DEMO_URI;
		return strpos( $url, $demo_url ) !== false;
	}

	/**
	 * Check if URL points to an SVG file.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL ends with .svg or .svgz.
	 */
	private function is_svg_url( $url ) {
		$url_lower = strtolower( $url );
		return ( substr( $url_lower, -4 ) === '.svg' || substr( $url_lower, -5 ) === '.svgz' );
	}

	/**
	 * Download SVG file and add to media library.
	 *
	 * @param string $svg_url SVG file URL.
	 * @return array|\WP_Error Array with 'url' and 'id' on success, WP_Error on failure.
	 */
	private function download_svg_file( $svg_url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Use the image importer class
		$attachment = array(
			'url' => $svg_url,
			'id'  => 0,
		);

		$downloaded = \Demo_Importer_Plus_Sites_Image_Importer::get_instance()->import( $attachment );

		if ( isset( $downloaded['id'] ) && $downloaded['id'] > 0 ) {
			return array(
				'id'  => $downloaded['id'],
				'url' => $downloaded['url'],
			);
		}

		return new \WP_Error( 'svg_download_failed', 'Failed to download SVG icon' );
	}

}
