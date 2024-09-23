<?php
/**
 * WXR Importer.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use Demo_Importer_Plus;
use Demo_Importer_Plus_Sites_Helper;
use Demo_Importer_Plus_Sites_Image_Importer;
use WP_Error;
use WP_Importer_Logger_ServerSentEvents;
use WXR_Importer;

/**
 * WXR Importer.
 *
 * @since 2.0.0
 */
class WXRImporter extends EventStream {

	/**
	 * Post Mapping.
	 *
	 * @var array
	 */
	protected static array $post_mapping = [];

	/**
	 * Taxonomy Term Mapping.
	 *
	 * @var array
	 */
	protected static array $taxonomy_term_mapping = [];

	/**
	 * WXR file path.
	 *
	 * @var array
	 */
	protected array $file_data;

	/**
	 * WXR Importer.
	 */
	protected WPWXRImporter $importer;

	/**
	 * Logger.
	 */
	protected WP_Importer_Logger_ServerSentEvents $logger;

	/**
	 * Download WXR file.
	 *
	 * @return WP_Error|array
	 */
	public function download_wxr_file( string $wxr_url ) {

		if ( ! function_exists( 'download_url' ) ) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$transient_key = demo_importer_plus_get_unique_key( $wxr_url, 'wxr_file' );

		$results = get_transient( $transient_key );
		if ( ! $results || ! file_exists( $results[ 'file' ] ) ) {
			$temp_file = download_url( $wxr_url, 300 );

			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}

			$file_args = array(
				'name'     => basename( $wxr_url ),
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => filesize( $temp_file ),
			);

			$results = wp_handle_sideload(
				$file_args,
				wp_parse_args(
					array( 'wp_handle_sideload' => 'upload' ),
					array(
						'test_form'   => false,
						'test_size'   => true,
						'test_upload' => true,
						'mimes'       => array(
							'xml'  => 'text/xml',
							'json' => 'text/plain',
						),
					) )
			);

			if ( isset( $results[ 'error' ] ) ) {
				return new WP_Error( 'php_upload_error', $results[ 'error' ] );
			}

			$this->set_importer();


			$information = $this->importer->get_preliminary_information( $results[ 'file' ] );

			$results[ '__meta' ] = array(
				'posts'    => $information->post_count ?? 0,
				'media'    => $information->media_count ?? 0,
				'terms'    => $information->term_count,
				'comments' => $information->comment_count,
				'users'    => count( $information->users ) ?? 0,
			);

			$results[ '__meta' ][ 'total_count' ] = array_sum( array_values( $results[ '__meta' ] ) );

			set_transient( $transient_key, $results, HOUR_IN_SECONDS );
		}

		return $results;
	}

	protected function add_importer_hooks() {
		add_filter( 'wp_image_editors', array( $this, 'enable_wp_image_editor_gd' ) );

		add_filter( 'wxr_importer.pre_process.post', array( $this, 'fix_image_duplicate_issue' ), 10, 4 );

		add_filter( 'wxr_importer.pre_process.user', '__return_null' );

		add_action( 'wxr_importer.processed.post', array( $this, 'imported_post' ), 10, 2 );
		add_action( 'wxr_importer.process_failed.post', array( $this, 'imported_post' ), 10, 2 );
		add_action( 'wxr_importer.process_already_imported.post', array( $this, 'already_imported_post' ), 10, 2 );
		add_action( 'wxr_importer.process_skipped.post', array( $this, 'already_imported_post' ), 10, 2 );
		add_action( 'wxr_importer.processed.comment', array( $this, 'imported_comment' ) );
		add_action( 'wxr_importer.process_already_imported.comment', array( $this, 'imported_comment' ) );
		add_action( 'wxr_importer.processed.term', array( $this, 'imported_term' ) );
		add_action( 'wxr_importer.process_failed.term', array( $this, 'imported_term' ) );
		add_action( 'wxr_importer.process_already_imported.term', array( $this, 'imported_term' ) );
		add_action( 'wxr_importer.processed.user', array( $this, 'imported_user' ) );
		add_action( 'wxr_importer.process_failed.user', array( $this, 'imported_user' ) );

		add_action( 'wxr_importer.processed.post', array( $this, 'track_post' ), 10, 2 );
		add_action( 'wxr_importer.processed.term', array( $this, 'track_term' ), 10, 2 );

		add_action( 'import_end', array( $this, 'import_end' ) );

		do_action( 'wxr_importer_add_importer_hooks', $this );
	}

	/**
	 * Set Importer.
	 */
	public function set_importer() {
		$options = apply_filters(
			'demo_importer_plus_xml_import_options',
			array(
				'update_attachment_guids' => true,
				'fetch_attachments'       => true,
				'default_author'          => get_current_user_id(),
			)
		);

		$this->importer = new WPWXRImporter( $options );
		$this->logger   = new WP_Importer_Logger_ServerSentEvents();

		$this->importer->set_logger( $this->logger );
	}

	/**
	 * Import WXR.
	 *
	 */
	public function import( $file ) {

		$this->setup();
		$this->set_importer();
		$this->add_importer_hooks();

		$response = $this->importer->import( $file );

		$this->emit_sse_message(
			array(
				'action' => 'complete',
				'error'  => is_wp_error( $response ) ? $response->get_error_message() : false,
			)
		);
		exit;
	}

	/**
	 * Enable the WP_Image_Editor_GD library.
	 *
	 * @param array $editors Image editors library list.
	 *
	 * @return array
	 */
	public function enable_wp_image_editor_gd( array $editors ): array {
		$gd_editor = 'WP_Image_Editor_GD';
		$editors   = array_diff( $editors, array( $gd_editor ) );
		array_unshift( $editors, $gd_editor );

		return $editors;
	}

	/**
	 * Set GUID as per the attachment URL which avoid duplicate images issue due to the different GUID.
	 *
	 * @param array $data Post data.
	 * @param array $meta Meta data.
	 * @param array $comments Comments on the post.
	 * @param array $terms Terms on the post.
	 */
	public function fix_image_duplicate_issue( $data, $meta, $comments, $terms ): array {

		$remote_url     = ! empty( $data[ 'attachment_url' ] ) ? $data[ 'attachment_url' ] : $data[ 'guid' ];
		$data[ 'guid' ] = $remote_url;

		return $data;
	}

	/**
	 * Send a message when a post has been imported.
	 *
	 * @param int $id Post ID.
	 * @param array $data Post data saved to the DB.
	 */
	public function imported_post( $id, $data ) {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => ( 'attachment' === $data[ 'post_type' ] ) ? 'media' : 'posts',
				'delta'  => 1,
			)
		);
	}

	/**
	 * Send a message when a post is marked as already imported.
	 *
	 * @param array $data Post data saved to the DB.
	 */
	public function already_imported_post( $data ) {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => ( 'attachment' === $data[ 'post_type' ] ) ? 'media' : 'posts',
				'delta'  => 1,
			)
		);
	}

	/**
	 * Send a message when a comment has been imported.
	 */
	public function imported_comment() {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => 'comments',
				'delta'  => 1,
			)
		);
	}

	/**
	 * Send a message when a term has been imported.
	 */
	public function imported_term() {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => 'terms',
				'delta'  => 1,
			)
		);
	}

	/**
	 * Send a message when a user has been imported.
	 */
	public function imported_user() {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => 'users',
				'delta'  => 1,
			)
		);
	}

	/**
	 * Track Imported Post
	 *
	 * @param int $post_id Post ID.
	 * @param array $data Raw data imported for the post.
	 */
	public function track_post( $post_id = 0, $data = array() ) {

		update_post_meta( $post_id, '_demo_importer_plus_sites_imported_post', true );
		update_post_meta( $post_id, '_demo_importer_enable_for_batch', true );

		if ( isset( $data[ 'post_type' ] ) && (int) $data[ 'post_id' ] !== (int) $post_id ) {
			self::$post_mapping[ $data[ 'post_type' ] ][ $data[ 'post_id' ] ] = $post_id;
		}

		// Set the full width template for the pages.
		if ( isset( $data[ 'post_type' ] ) && 'page' === $data[ 'post_type' ] ) {
			$is_elementor_page = get_post_meta( $post_id, '_elementor_version', true );
			$theme_status      = Demo_Importer_Plus::get_instance()->get_theme_status();
			if ( 'installed-and-active' !== $theme_status && $is_elementor_page ) {
				update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );
			}
		} else if ( isset( $data[ 'post_type' ] ) && 'attachment' === $data[ 'post_type' ] ) {
			$remote_url          = $data[ 'guid' ] ?? '';
			$attachment_hash_url = Demo_Importer_Plus_Sites_Image_Importer::get_instance()->get_hash_image( $remote_url );
			if ( ! empty( $attachment_hash_url ) ) {
				update_post_meta( $post_id, '_demo_importer_plus_sites_image_hash', $attachment_hash_url );
				update_post_meta( $post_id, '_elementor_source_image_hash', $attachment_hash_url );
			}
		}
	}

	/**
	 * Track Imported Term
	 *
	 * @param int $term_id Term ID.
	 */
	public function track_term( $term_id, $data ) {

		self::$taxonomy_term_mapping[ $data[ 'taxonomy' ] ][ $data[ 'id' ] ] = $term_id;

		update_term_meta( $term_id, '_demo_importer_plus_imported_term', true );
	}

	/**
	 * Import End.
	 */
	public function import_end() {
		update_option( '_demo_importer_posts_mapping', self::$post_mapping );
		update_option( '_demo_importer_terms_mapping', self::$taxonomy_term_mapping );
	}

}
