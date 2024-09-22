<?php
/**
 * Customizer Importer.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use Demo_Importer_Plus_Sites_Helper;
use WP_Error;

/**
 * Customizer Importer.
 */
class CustomizerImporter {

	/**
	 * @var string $stylesheet Theme stylesheet.
	 */
	public string $stylesheet;

	public function __construct() {
		$this->stylesheet = get_option( 'stylesheet' );
	}

	/**
	 * Import customizer options.
	 *
	 * @param $value
	 * @param $key
	 *
	 * @since  1.0.0
	 */
	protected function parse_value( &$value, $key ) {
		if ( is_scalar( $value ) ) {
			if ( Demo_Importer_Plus_Sites_Helper::is_image_url( $value ) ) {
				$data = Demo_Importer_Plus_Sites_Helper::sideload_image( $value );

				if ( ! is_wp_error( $data ) ) {
					$value = 'custom_logo' === $key ? $data->attachment_id : $data->url;
				}
			}
		}
	}

	/**
	 * Import Customizer Settings from provided data.
	 *
	 * @param array $customizer_data
	 *
	 * @return void
	 */
	public function import_customizer( array $customizer_data ) {

		array_walk_recursive(
			$customizer_data,
			array( $this, 'parse_value' )
		);

		if ( isset( $customizer_data[ 'custom-css' ] ) ) {
			wp_update_custom_css_post( $customizer_data[ 'custom-css' ] );
		}

		update_option( 'theme_mods_' . $this->stylesheet, $customizer_data );
	}

}
