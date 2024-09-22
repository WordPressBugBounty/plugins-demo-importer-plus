<?php
/**
 * Demo Site Importer.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use WP_Error;

class DemoSiteImporter extends Importer {

	/**
	 * Step.
	 *
	 * @var null|string
	 */
	public ?string $step = null;

	/**
	 * Demo ID.
	 *
	 * @var int
	 */
	protected int $demo_id;

	public function __construct( $demo_id ) {
		parent::__construct();
		$this->demo_id = $demo_id;
	}

	/**
	 * Backup Settings.
	 *
	 * @return void
	 */
	public function backup_settings() {
		$logger = new Logger(
			array(
				'path'     => '',
				'filename' => 'demo-importer-plus-backup-' . gmdate( 'd-M-Y-h-i-s' ) . '.json',
			) );

		$old_settings = get_option( 'demo-importer-plus-settings', array() );

		$logger->log( $old_settings );
	}

	/**
	 * Import Demo Site.
	 *
	 * @return WP_Error|array
	 */
	public function import() {
		$server = new DemoServer();

		$demo_site_data = $server->fetch_demo( $this->demo_id );

		switch ( $this->step ) {
			case 'import_customizer':
				$customizer_importer = new CustomizerImporter();
				$customizer_importer->import_customizer( $demo_site_data[ 'customizer-data' ] ?? array() );

				return array();
			case 'prepare_wxr_file':
				$wxr_importer = new WXRImporter();

				return $wxr_importer->download_wxr_file( $demo_site_data[ 'wxr-path' ] );
			case 'import_from_wxr':
				$wxr_importer = new WXRImporter();

				$file = $wxr_importer->download_wxr_file( $demo_site_data[ 'wxr-path' ] );

				if ( is_wp_error( $file ) ) {
					return $file;
				}

				return $wxr_importer->import( $file[ 'file' ] );
			case 'import_site_options':
				$site_options_importer = new SiteOptionsImporter();
				if ( isset( $demo_site_data[ 'site-option' ] ) ) {
					$site_options_importer->import( $demo_site_data[ 'site-option' ] );
				}

				return array(
					'link' => get_bloginfo( 'url' ),
				);
			case 'import_widgets':
				$widget_importer = new WidgetsImporter();

				$widgets_data = $demo_site_data[ 'widgets-data' ] ?? array();
				if ( is_string( $widgets_data ) ) {
					try {
						$widgets_data = json_decode( $widgets_data, true );
					} catch ( \Exception $e ) {
						$widgets_data = array();
					}
				}

				return $widget_importer->import( $widgets_data );
			default:
				return new WP_Error( 'missing_params', __( 'Missing step parameter', 'demo-importer-plus' ) );
		}

	}
}
