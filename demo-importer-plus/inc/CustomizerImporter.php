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

		// Fire customize_save_after so themes can run post-save processing
		// (e.g. clearing CSS caches, regenerating dynamic stylesheets).
		// Without this, imported settings sit in the DB but theme hooks never fire,
		// leaving the frontend unchanged until the user manually republishes.
		if ( ! class_exists( 'WP_Customize_Manager' ) ) {
			require_once \ABSPATH . 'wp-includes/class-wp-customize-manager.php';
		}
		global $wp_customize;
		if ( ! ( $wp_customize instanceof \WP_Customize_Manager ) ) {
			$wp_customize = new \WP_Customize_Manager();
		}
		\do_action( 'customize_save_after', $wp_customize );
	}

}
