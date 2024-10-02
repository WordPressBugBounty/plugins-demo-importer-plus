<?php
/**
 * AJAX Class
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 *
 * @since 2.0.0
 */
class Ajax {

	public static function handle_request() {
		// Get Method.
		$method = $_SERVER[ 'REQUEST_METHOD' ] ?? 'GET';

		$request = new WP_REST_Request( $method );

		if ( isset( $_SERVER[ 'HTTP_X_WP_NONCE' ] ) || isset( $_REQUEST[ '_nonce' ] ) ) {
			$request->set_header( 'X-WP-Nonce', $_SERVER[ 'HTTP_X_WP_NONCE' ] ?? $_REQUEST[ '_nonce' ] );

			if ( ! wp_verify_nonce( $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ) {
				wp_send_json_error( 'Invalid Request', 403 );
			}

			$request->set_body( file_get_contents( 'php://input' ) );
			$request->set_query_params( $_GET );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body_params( $_POST );

			$demo_action = $request->get_param( 'demo_action' );

			$data = array();
			switch ( $demo_action ) {
				case 'test-connection':
					$data = static::test_server_connection( $request );
					break;
				case 'check-server-config':
					$data = static::check_server_config( $request );
					break;
				case 'demos':
					if ( $request->get_param( 'id' ) ) {
						$data = static::get_demo_by_id( $request );
					} else {
						$data = static::get_demos( $request );
					}
					break;
				case 'demo-categories':
					$data = static::get_demo_categories( $request );
					break;
					break;
				case 'import-demo':
					if ( $request->get_param( 'id' ) ) {
						$data = static::import_demo_site( $request );
					} else {
						$data = new WP_Error( 'invalid_request', 'Requires Demo ID.' );
					}
					break;
				case 'update_attachment':
					$data = static::update_attachment( $request );
					break;
				case 'required-plugins':
					if ( $request->get_param( 'id' ) ) {
						$data = static::get_required_plugins( $request );
					} else {
						$data = new WP_Error( 'invalid_request', 'Requires Demo ID.' );
					}
					break;
				case 'import-page':
					if ( $request->get_param( 'id' ) && $request->get_param( 'page_id' ) ) {
						$data = static::import_page( $request );
					} else {
						$data = new WP_Error( 'invalid_request', 'Invalid Page ID and Demo ID.' );
					}
					break;
				case 'do-reinstall':
					$data = static::do_reinstall( $request );
					break;
				case 'activate-plugin':
					if ( ! current_user_can( 'activate_plugins' ) ) {
						$data = new WP_Error( 'permission_denied', 'You do not have permission to activate plugins.', 403 );
					} else if ( ! $request->get_param( 'plugin' ) ) {
						$data = new WP_Error( 'invalid_request', 'Invalid Plugin.', 400 );
					} else {
						$data = static::activate_plugin( $request );
					}
					break;
				case 'delete-site':
					if ( ! current_user_can( 'manage_options' ) ) {
						$data = new WP_Error( 'permission_denied', 'You do not have permission to delete site.', 403 );
					} else {
						$data = static::cleanup_previous_site( $request );
					}
					break;
				default:
					wp_send_json_error( 'Invalid Request' );
			}

			if ( is_wp_error( $data ) ) {
				wp_send_json_error( $data, 403 );
			}

			wp_send_json( $data );
		} else {
			wp_send_json_error( 'Invalid Request', 403 );
		}
	}

	/**
	 * Get Demos
	 *
	 * @retun WP_Error|array
	 * @since 2.0.0
	 */
	protected static function get_demos( WP_REST_Request $request ) {
		$server = new DemoServer();

		$include = $request->get_param( 'include' ) ?? DEMO_IMPORTER_PLUS_MAIN_DEMO_ID;
		if ( ! is_array( $include ) ) {
			$include = explode( ',', $include );
		}

		return $server->fetch_demos(
			array(
				'per_page' => $request->get_param( 'per_page' ) ?? 10,
				'page'     => $request->get_param( 'page' ) ?? 1,
				'include'  => $include,
				'search'   => $request->get_param( 'search' ) ?? '',
				'category' => $request->get_param( 'category' ) ?? '',
				'type'     => $request->get_param( 'type' ) ?? '',
			)
		);
	}

	/**
	 * Get Demo By ID
	 *
	 * @since 2.0.0
	 * @retun WP_Error|array
	 */
	protected static function get_demo_by_id( WP_REST_Request $request ) {
		$server = new DemoServer();

		$data = $server->fetch_demo( $request->get_param( 'id' ) );

		$demo_modal = new DemoDataModel( $data );

		$is_pro                      = $demo_modal->is_pro();
		$is_licensed                 = apply_filters( 'demo_importer_plus_pro_active', false ) === true;
		$data[ '__importer_config' ] = array(
			'is_pro'           => $is_pro,
			'is_licensed'      => $is_licensed,
			'is_pro_installed' => apply_filters( 'demo_importer_plus_is_pro', false ),
			'licensePageURL'   => apply_filters( 'demo_importer_plus_license_page', admin_url( '/' ) ),
			'is_importable'    => ! $is_pro || $is_licensed,
			'customize_url'    => admin_url( 'customize.php' ),
			'get_pro_url'      => apply_filters( 'demo_importer_plus_get_pro_url', '#' ),
			'labels'           => array(
				'get_pro'             => apply_filters( 'demo_importer_plus_get_pro_text', __( 'Get Pro', 'demo-importer-plus' ) ),
				'active_license_text' => apply_filters( 'demo_importer_plus_activate_license_text', __( 'Activate License', 'demo-importer-plus' ) ),
			),
		);

		return $data;
	}

	/**
	 * Import Demo Site.
	 *
	 * @return WP_Error|array
	 * @since 2.0.0
	 */
	protected static function import_demo_site( WP_REST_Request $request ) {
		$demo_id = $request->get_param( 'id' );
		$step    = $request->get_param( 'step' ) ?? null;

		$demo_importer       = new DemoSiteImporter( $demo_id );
		$demo_importer->step = $step;

		$result = $demo_importer->import();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Cleanup Previous Site.
	 *
	 * @return WP_Error|array
	 */
	protected static function cleanup_previous_site( $request ) {

		$method = $_SERVER[ 'REQUEST_METHOD' ] ?? 'GET';
		$step   = $request->get_param( 'step' ) ?? '';

		$steps_and_methods = [
			'prepare'                => WP_REST_Server::READABLE,
			'reset'                  => WP_REST_Server::CREATABLE,
			'delete_site_customizer' => WP_REST_Server::CREATABLE,
			'delete_site_options'    => WP_REST_Server::CREATABLE,
			'delete_site_widgets'    => WP_REST_Server::CREATABLE,
			'delete_site_content'    => WP_REST_Server::READABLE,
		];

		if ( ! isset( $steps_and_methods[ $step ] ) || $steps_and_methods[ $step ] !== $method ) {
			return new WP_Error( 'invalid_request', __( 'Invalid request.', 'demo-importer-plus' ) );
		}

		$cleanup = new PreviousSiteCleanup();

		$cleanup->step = $request->get_param( 'step' ) ?? '';
		if ( ! $cleanup->step ) {
			$cleanup->step = 'prepare';
		}

		$data = $cleanup->run();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Import Page.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|array
	 * @since 2.0.0
	 */
	protected static function import_page( WP_REST_Request $request ) {
		$demo_id = $request->get_param( 'id' );
		$page_id = $request->get_param( 'page_id' );

		$page_importer = new PageImporter( $page_id, $demo_id );

		$data = $page_importer->import();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'success' => true,
			'message' => __( 'Page has been imported successfully.', 'demo-importer-plus' ),
			'data'    => $data,
		);
	}

	/**
	 * Test Server Connection.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|array
	 * @since 2.0.0
	 */
	protected static function test_server_connection( WP_REST_Request $request ) {
		$server = new DemoServer();

		return $server->test_connection();
	}

	/**
	 * Check Server Config.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 * @since 2.0.0
	 */
	protected static function check_server_config( WP_REST_Request $request ): array {

		$config = array(
			'max_execution_time'  => @ini_get( 'max_execution_time' ),
			'memory_limit'        => @ini_get( 'memory_limit' ),
			'post_max_size'       => @ini_get( 'post_max_size' ),
			'upload_max_filesize' => @ini_get( 'upload_max_filesize' ),
		);

		$server = new DemoServer();

		return $server->compare_server_requirements( $config );
	}

	/**
	 * Get Required Plugins.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|array
	 * @since 2.0.0
	 */
	protected static function get_required_plugins( WP_REST_Request $request ) {
		$demo_id = $request->get_param( 'id' );

		$server = new DemoServer();

		$data = $server->fetch_demo( $demo_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$site_plugins = get_plugins();

		$demo_data_model = new DemoDataModel( $data );

		$required_plugins = $demo_data_model->get_required_plugins();

		foreach ( $required_plugins as &$required_plugin ) {
			$required_plugin[ 'installed' ] = isset( $site_plugins[ $required_plugin[ 'init' ] ] );
			$required_plugin[ 'active' ]    = is_plugin_active( $required_plugin[ 'init' ] );
		}

		return $required_plugins;
	}

	/**
	 * Reset Website.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_error|array
	 */
	protected static function do_reinstall( WP_REST_Request $request ) {
		$site_reset = new SiteReset();

		$result = $site_reset->reset();

		if ( $result ) {
			return array(
				'success' => true,
				'message' => 'Site has been reset successfully.',
			);
		} else {
			return new WP_Error( 'reset_failed', 'Failed to reset the site.' );
		}
	}

	/**
	 * Activate Plugin.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|array
	 */
	protected static function activate_plugin( WP_REST_Request $request ) {

		$plugin = $request->get_param( 'plugin' );

		wp_clean_plugins_cache();

		$activate = activate_plugin( $plugin, '', false, true );

		if ( is_wp_error( $activate ) ) {
			return $activate;
		}

		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

		return array(
			'success' => true,
			'message' => sprintf( __( 'Plugin "%s" is Activated', 'demo-importer-plus' ), $data[ 'Name' ] ?? 'Unknown' ),
			'data'    => $data,
		);
	}

	/**
	 * Get Demos Categories.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|array
	 */
	protected static function get_demo_categories( WP_REST_Request $request ) {
		$server = new DemoServer();

		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?? 100,
			'_fields'  => $request->get_param( 'fields' ) ?? 'id,name,slug',
		);

		return $server->fetch_demos_categories( $args );
	}

	/**
	 * Update Media.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|array
	 */
	protected static function update_attachment( WP_REST_Request $request ) {
		$attachment = $request->get_param( 'attachment' );

		$_upload_meta = get_post_meta( $attachment, '_upload_meta', true );

		$upload = $_upload_meta[ 'upload' ];
		$result = demo_importer_plus_replace_placeholder_with_media( $_upload_meta[ 'remote_url' ], $upload );

		if ( ! is_wp_error( $result ) ) {
			$attachment_metadata = wp_generate_attachment_metadata( $attachment, $upload[ 'file' ] );
			wp_update_attachment_metadata( $attachment, $attachment_metadata );
			delete_post_meta( $attachment, '_upload_meta' );

			return array(
				'success' => true,
				'message' => 'Attachment has been updated successfully.',
				'data'    => $result,
			);
		}

		return $result;

	}

}
