<?php
/**
 * Functions
 *
 * @since  1.0.0
 * @package Demo Importer Plus
 */

use KraftPlugins\DemoImporterPlus\DemoServer;

/**
 * Replace placeholder image with media.
 *
 * @return array|WP_Error
 */
function demo_importer_plus_replace_placeholder_with_media( string $url, array $upload ) {

	$response = wp_remote_get(
		$url,
		array(
			'stream'   => true,
			'filename' => $upload[ 'file' ],
		)
	);

	// request failed.
	if ( is_wp_error( $response ) ) {
		unlink( $upload[ 'file' ] );

		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	// make sure the fetch was successful.
	if ( 200 !== $code ) {
		unlink( $upload[ 'file' ] );

		return new WP_Error(
			'import_file_error',
			sprintf(
			/* translators: %1$s is error code, %2$s is error code header, %3$s is url. */
				__( 'Remote server returned %1$d %2$s for %3$s', 'demo-importer-plus' ),
				$code,
				get_status_header_desc( $code ),
				$url
			)
		);
	}

	$filesize = filesize( $upload[ 'file' ] );
	$headers  = wp_remote_retrieve_headers( $response );

	if ( isset( $headers[ 'content-length' ] ) && $filesize !== (int) $headers[ 'content-length' ] ) {
		unlink( $upload[ 'file' ] );

		return new WP_Error( 'import_file_error', __( 'Remote file is incorrect size', 'demo-importer-plus' ) );
	}

	if ( 0 === $filesize ) {
		unlink( $upload[ 'file' ] );

		return new WP_Error( 'import_file_error', __( 'Zero size file downloaded', 'demo-importer-plus' ) );
	}

	return $upload;
}

if ( ! function_exists( 'demo_importer_plus_error_log' ) ) :

	/**
	 * Demo Importer Error Log
	 *
	 * @param string $message Message.
	 */
	function demo_importer_plus_error_log( $message = '' ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			if ( is_array( $message ) ) {
				$message = wp_json_encode( $message );
			}

			error_log( '[' . date( 'd-m-Y H:i:s' ) . ']  ' . $message . "\n", 3, WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'demo-importer-plus.log' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

endif;

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
 * Non-scalar values are ignored.
 *
 * @param string|array $var Data to sanitize.
 *
 * @return string|array
 */
function demo_importer_plus_clean_vars( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'demo_importer_plus_clean_vars', $var );
	} else {
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}
}

/**
 * Check if a plugin is installed and active
 *
 * @param string $plugin Plugin name.
 *
 * @return array
 * @since 2.0.0
 *
 */
function demo_importer_plug_check_plugin_status( $plugin ) {
	$active_plugins = get_option( 'active_plugins' );
	$network_active = is_multisite() ? get_site_option( 'active_sitewide_plugins' ) : false;

	$plugin_status = array(
		'active'         => in_array( $plugin, $active_plugins, true ),
		'network_active' => $network_active && array_key_exists( $plugin, $network_active ),
	);

	return $plugin_status;
}

/**
 * Get unique key
 *
 * @param string $input Input.
 * @param string $prefix Prefix.
 *
 * @return string
 */
function demo_importer_plus_get_unique_key( string $input = '', string $prefix = '' ): string {
	$auth_salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
	$hash      = hash( 'sha256', $input . $auth_salt );

	$key = sprintf(
		'%s-%s-%s-%s',
		substr( $hash, 0, 4 ),
		substr( $hash, 4, 5 ),
		substr( $hash, 9, 5 ),
		substr( $hash, 14, 4 )
	);

	if ( ! empty( $prefix ) ) {
		$key = "{$prefix}_{$key}";
	}

	return $key;
}

/**
 * Downloads an image from the specified URL.
 *
 * @param string $file The image file path.
 */
function demo_importer_plus_sideload_image( string $file ) {
	$data = new stdClass();

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	if ( ! empty( $file ) ) {

		preg_match( '/[^\?]+\.(jpe?g|jpe|svg|gif|png)\b/i', $file, $matches );
		$file_array           = array();
		$file_array[ 'name' ] = basename( $matches[ 0 ] );

		$file_array[ 'tmp_name' ] = download_url( $file );

		if ( is_wp_error( $file_array[ 'tmp_name' ] ) ) {
			return $file_array[ 'tmp_name' ];
		}

		$id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $id ) ) {
			unlink( $file_array[ 'tmp_name' ] );

			return $id;
		}

		$meta                = wp_get_attachment_metadata( $id );
		$data->attachment_id = $id;
		$data->url           = wp_get_attachment_url( $id );
		$data->thumbnail_url = wp_get_attachment_thumb_url( $id );
		$data->height        = $meta[ 'height' ] ?? '';
		$data->width         = $meta[ 'width' ] ?? '';
	}

	return $data;
}

/**
 * Get Demo Server Instance.
 *
 * @return DemoServer
 */
function demo_importer_plus_demo_server(): DemoServer {
	return new DemoServer();
}
