<?php
/**
 * Image Importer
 *
 * => How to use?
 *
 *  $image = array(
 *      'url' => '<image-url>',
 *      'id'  => '<image-id>',
 *  );
 *
 *  $downloaded_image = Demo_Importer_Plus_Sites_Image_Importer::get_instance()->import( $image );
 *
 * @package Demo Importer Plus
 * @since 1.0.0
 */

if ( ! class_exists( 'Demo_Importer_Plus_Sites_Image_Importer' ) ) :

	/**
	 * Demo Importer Plus Sites Image Importer
	 *
	 * @since 1.0.0
	 */
	class Demo_Importer_Plus_Sites_Image_Importer {

		/**
		 * Instance
		 *
		 * @var object Class object.
		 * @access private
		 */
		private static $instance;

		/**
		 * Images IDs
		 *
		 * @var array   The Array of already image IDs.
		 */
		private $already_imported_ids = array();

		/**
		 * Initiator
		 *
		 * @return object initialized object of class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 */
		public function __construct() {

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();

			// Load SVG sanitizer if not already loaded.
			if ( ! class_exists( 'enshrined\svgSanitize\Sanitizer' ) ) {
				require_once DEMO_IMPORTER_PLUS_DIR . 'vendor/autoload.php';
			}
		}

		/**
		 * Process Image Download
		 *
		 * @param  array $attachments Attachment array.
		 * @return array              Attachment array.
		 */
		public function process( $attachments ) {

			$downloaded_images = array();

			foreach ( $attachments as $key => $attachment ) {
				$downloaded_images[] = $this->import( $attachment );
			}

			return $downloaded_images;
		}

		/**
		 * Get Hash Image.
		 *
		 * @param  string $attachment_url Attachment URL.
		 * @return string                 Hash string.
		 */
		public function get_hash_image( $attachment_url ) {
			return sha1( $attachment_url );
		}

		/**
		 * Get Saved Image.
		 *
		 * @param  string $attachment   Attachment Data.
		 * @return string                 Hash string.
		 */
		private function get_saved_image( $attachment ) {

			if ( apply_filters( 'demo_importer_plus_image_importer_skip_image', false, $attachment ) ) {
				Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - SKIP Image - {from filter} - ' . $attachment['url'] . ' - Filter name `demo_importer_plus_image_importer_skip_image`.' );
				return array(
					'status'     => true,
					'attachment' => $attachment,
				);
			}

			global $wpdb;

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT `post_id` FROM `' . $wpdb->postmeta . '`
						WHERE `meta_key` = \'_demo_importer_plus_sites_image_hash\'
							AND `meta_value` = %s
					;',
					$this->get_hash_image( $attachment['url'] )
				)
			);

			// 2. Is image already imported though XML?
			if ( empty( $post_id ) ) {
				$filename = basename( $attachment['url'] );

				$post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta}
						WHERE meta_key = '_wp_attached_file'
						AND meta_value LIKE %s",
						'%/' . $filename . '%'
					)
				);

				Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - SKIP Image {already imported from xml} - ' . $attachment['url'] );
			}

			if ( $post_id ) {
				$new_attachment               = array(
					'id'  => $post_id,
					'url' => wp_get_attachment_url( $post_id ),
				);
				$this->already_imported_ids[] = $post_id;

				return array(
					'status'     => true,
					'attachment' => $new_attachment,
				);
			}

			return array(
				'status'     => false,
				'attachment' => $attachment,
			);
		}

		/**
		 * Import Image
		 *
		 * @param  array $attachment Attachment array.
		 * @return array              Attachment array.
		 */
		public function import( $attachment ) {

			Demo_Importer_Plus_Sites_Importer_Log::add( 'Source - ' . $attachment['url'] );
			$saved_image = $this->get_saved_image( $attachment );
			Demo_Importer_Plus_Sites_Importer_Log::add( 'Log - ' . wp_json_encode( $saved_image['attachment'] ) );

			if ( $saved_image['status'] ) {
				return $saved_image['attachment'];
			}

			$response = wp_safe_remote_get(
				$attachment['url'],
				array(
					'timeout'   => '60',
					'sslverify' => false,
				)
			);

			// Validate HTTP response.
			$validation = $this->validate_http_response( $response );
			if ( is_wp_error( $validation ) ) {
				Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - FAIL Image {Error: ' . $validation->get_error_message() . '} - ' . $attachment['url'] );
				return $attachment;
			}

			$file_content = wp_remote_retrieve_body( $response );

			if ( empty( $file_content ) ) {

				Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - FAIL Image {Error: Failed wp_remote_retrieve_body} - ' . $attachment['url'] );
				return $attachment;
			}

			$filename = basename( $attachment['url'] );

			// Sanitize SVG files before saving.
			if ( $this->is_svg_file( $filename ) ) {
				$sanitized_content = $this->sanitize_svg_content( $file_content );
				if ( is_wp_error( $sanitized_content ) ) {
					Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - FAIL SVG {Error: ' . $sanitized_content->get_error_message() . '} - ' . $attachment['url'] );
					return $attachment;
				}
				$file_content = $sanitized_content;
				Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - SVG Sanitized - ' . $attachment['url'] );
			}

			$upload = wp_upload_bits(
				$filename,
				null,
				$file_content
			);

			demo_importer_plus_error_log( $filename );
			demo_importer_plus_error_log( wp_json_encode( $upload ) );

			$post = array(
				'post_title' => $filename,
				'guid'       => $upload['url'],
			);
			demo_importer_plus_error_log( wp_json_encode( $post ) );

			$info = wp_check_filetype( $upload['file'] );
			if ( $info ) {
				$post['post_mime_type'] = $info['type'];
			} else {
				return $attachment;
			}

			$post_id = wp_insert_attachment( $post, $upload['file'] );
			wp_update_attachment_metadata(
				$post_id,
				wp_generate_attachment_metadata( $post_id, $upload['file'] )
			);
			update_post_meta( $post_id, '_demo_importer_plus_sites_image_hash', $this->get_hash_image( $attachment['url'] ) );

			Demo_Importer_Plus_WXR_Importer::instance()->track_post( $post_id );

			$new_attachment = array(
				'id'  => $post_id,
				'url' => $upload['url'],
			);

			Demo_Importer_Plus_Sites_Importer_Log::add( 'BATCH - SUCCESS Image {Imported} - ' . $new_attachment['url'] );

			$this->already_imported_ids[] = $post_id;

			return $new_attachment;
		}

		/**
		 * Is Image URL
		 *
		 * @param  string $url URL.
		 * @return boolean
		 */
		public function is_image_url( $url = '' ) {
			if ( empty( $url ) ) {
				return false;
			}

			if ( preg_match( '/^((https?:\/\/)|(www\.))([a-z0-9-].?)+(:[0-9]+)?\/[\w\-]+\.(jpg|png|svg|gif|jpeg)\/?$/i', $url ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Check if a file is an SVG
		 *
		 * @param string $filename File name or path.
		 * @return boolean
		 */
		private function is_svg_file( $filename ) {
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			return in_array( $extension, array( 'svg', 'svgz' ), true );
		}

		/**
		 * Sanitize SVG file content
		 *
		 * @param string $file_content SVG file content.
		 * @return string|WP_Error Sanitized content or WP_Error on failure.
		 */
		private function sanitize_svg_content( $file_content ) {
			if ( ! class_exists( 'enshrined\svgSanitize\Sanitizer' ) ) {
				return new WP_Error( 'svg_sanitizer_missing', 'SVG Sanitizer library is not loaded.' );
			}

			$sanitizer = new \enshrined\svgSanitize\Sanitizer();
			$sanitized_content = $sanitizer->sanitize( $file_content );

			if ( false === $sanitized_content || empty( $sanitized_content ) ) {
				return new WP_Error( 'svg_sanitization_failed', 'SVG sanitization failed. The file may contain malicious code.' );
			}

			return $sanitized_content;
		}

		/**
		 * Validate HTTP response for security
		 *
		 * @param array  $response HTTP response array.
		 * @param string $expected_type Expected MIME type (optional).
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_http_response( $response, $expected_type = '' ) {
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				return new WP_Error( 'invalid_response_code', sprintf( 'HTTP response code: %d', $response_code ) );
			}

			// Validate Content-Type header if expected type is provided.
			if ( ! empty( $expected_type ) ) {
				$content_type = wp_remote_retrieve_header( $response, 'content-type' );
				if ( $content_type && false === strpos( $content_type, $expected_type ) ) {
					return new WP_Error(
						'content_type_mismatch',
						sprintf( 'Expected %s but got %s', $expected_type, $content_type )
					);
				}
			}

			// Check file size limit (10MB default).
			$content_length = wp_remote_retrieve_header( $response, 'content-length' );
			$max_size = apply_filters( 'demo_importer_plus_max_file_size', 10 * 1024 * 1024 ); // 10MB.

			if ( $content_length && $content_length > $max_size ) {
				return new WP_Error(
					'file_too_large',
					sprintf( 'File size (%s) exceeds maximum allowed size (%s)', size_format( $content_length ), size_format( $max_size ) )
				);
			}

			return true;
		}

	}

	/**
	 * Starting this by calling 'get_instance()' method
	 */
	Demo_Importer_Plus_Sites_Image_Importer::get_instance();

endif;
