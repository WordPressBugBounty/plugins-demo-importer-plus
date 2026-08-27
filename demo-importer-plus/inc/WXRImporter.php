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
	protected static array $post_mapping = array();

	/**
	 * Taxonomy Term Mapping.
	 *
	 * @var array
	 */
	protected static array $taxonomy_term_mapping = array();

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
		if ( ! $results || ! file_exists( $results['file'] ) ) {
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
					)
				)
			);

			if ( isset( $results['error'] ) ) {
				return new WP_Error( 'php_upload_error', $results['error'] );
			}

			$this->set_importer();

			$information = $this->importer->get_preliminary_information( $results['file'] );

			$results['__meta'] = array(
				'posts'    => $information->post_count ?? 0,
				'media'    => $information->media_count ?? 0,
				'terms'    => $information->term_count,
				'comments' => $information->comment_count,
				'users'    => count( $information->users ) ?? 0,
			);

			$results['__meta']['total_count'] = array_sum( array_values( $results['__meta'] ) );

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
		add_action( 'wxr_importer.process_already_imported.term', array( $this, 'track_already_imported_term' ), 10, 1 );

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

		$remote_url   = ! empty( $data['attachment_url'] ) ? $data['attachment_url'] : $data['guid'];
		$data['guid'] = $remote_url;

		return $data;
	}

	/**
	 * Send a message when a post has been imported.
	 *
	 * @param int   $id Post ID.
	 * @param array $data Post data saved to the DB.
	 */
	public function imported_post( $id, $data ) {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => ( 'attachment' === $data['post_type'] ) ? 'media' : 'posts',
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
				'type'   => ( 'attachment' === $data['post_type'] ) ? 'media' : 'posts',
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
	 * @param int   $post_id Post ID.
	 * @param array $data Raw data imported for the post.
	 */
	public function track_post( $post_id = 0, $data = array() ) {

		update_post_meta( $post_id, '_demo_importer_plus_sites_imported_post', true );
		update_post_meta( $post_id, '_demo_importer_enable_for_batch', true );

		if ( isset( $data['post_type'] ) && (int) $data['post_id'] !== (int) $post_id ) {
			self::$post_mapping[ $data['post_type'] ][ $data['post_id'] ] = $post_id;
		}

		// Set the full width template for the pages.
		if ( isset( $data['post_type'] ) && 'page' === $data['post_type'] ) {
			$is_elementor_page = get_post_meta( $post_id, '_elementor_version', true );
			$theme_status      = Demo_Importer_Plus::get_instance()->get_theme_status();
			if ( 'installed-and-active' !== $theme_status && $is_elementor_page ) {
				update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );
			}
		} elseif ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) {
			$remote_url          = $data['guid'] ?? '';
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

		self::$taxonomy_term_mapping[ $data['taxonomy'] ][ $data['id'] ] = $term_id;

		update_term_meta( $term_id, '_demo_importer_plus_imported_term', true );
	}

	/**
	 * Track Already Imported Term
	 *
	 * When a term already exists locally (same slug+taxonomy), the WXR importer fires
	 * process_already_imported.term instead of processed.term, so track_term is never
	 * called. This means the old-demo-ID → local-ID mapping is never recorded, and
	 * remap_wte_packages cannot remap package-categories term ID keys, leaving prices
	 * stored under the demo server's term IDs which never match local term IDs.
	 *
	 * @since 1.0.11
	 * @param array $data Raw term data from the WXR file (includes 'id', 'slug', 'taxonomy').
	 */
	public function track_already_imported_term( $data ) {
		if ( empty( $data['taxonomy'] ) || empty( $data['slug'] ) || empty( $data['id'] ) ) {
			return;
		}
		$existing = term_exists( $data['slug'], $data['taxonomy'] );
		if ( ! $existing || is_wp_error( $existing ) ) {
			return;
		}
		$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
		$this->track_term( $term_id, $data );
	}

	/**
	 * Import End.
	 */
	public function import_end() {
		update_option( '_demo_importer_posts_mapping', self::$post_mapping );
		update_option( '_demo_importer_terms_mapping', self::$taxonomy_term_mapping );

		$this->remap_wte_packages();
	}

	/**
	 * Remap WP Travel Engine package IDs and term IDs after import, then regenerate
	 * cached price metas. Also callable as a standalone repair for already-imported data.
	 *
	 * Pass empty arrays (or call with no arguments) to run in repair mode, which skips
	 * post-ID remapping and relies solely on the label-name fallback to fix term IDs.
	 *
	 * @since 1.0.11
	 * @param array $post_mapping  trip/trip-packages old-ID→new-ID map (from current import session).
	 * @param array $term_mapping  trip-packages-categories old-ID→new-ID map (from current import session).
	 */
	public static function repair_wte_prices( array $post_mapping = array(), array $term_mapping = array() ): void {
		// Only run when WP Travel Engine is active.
		if ( ! taxonomy_exists( 'trip-packages-categories' ) ) {
			return;
		}

		$trip_packages_map = $post_mapping['trip-packages'] ?? array();
		$trips_map         = $post_mapping['trip'] ?? array();
		$category_terms    = $term_mapping['trip-packages-categories'] ?? array();

		// 1. Remap packages_ids, trip_ID and primary_package on each trip. The WXR file
		// carries no primary_package value, so when the stored one is missing or does
		// not resolve to one of the trip's packages, the first package becomes primary.
		$trip_ids = ! empty( $trips_map )
			? $trips_map
			: get_posts(
				array(
					'post_type'      => 'trip',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

		foreach ( $trip_ids as $trip_id ) {
			$package_ids     = get_post_meta( $trip_id, 'packages_ids', true );
			$new_package_ids = array();

			if ( is_array( $package_ids ) ) {
				foreach ( $package_ids as $package_id ) {
					$new_package_id = (int) ( $trip_packages_map[ $package_id ] ?? $package_id );

					// Drop IDs that do not resolve to a real local package.
					if ( 'trip-packages' !== get_post_type( $new_package_id ) ) {
						continue;
					}

					update_post_meta( $new_package_id, 'trip_ID', $trip_id );
					$new_package_ids[] = $new_package_id;
				}
			}

			if ( empty( $new_package_ids ) ) {
				continue;
			}

			update_post_meta( $trip_id, 'packages_ids', $new_package_ids );

			$old_primary = (int) get_post_meta( $trip_id, 'primary_package', true );
			$new_primary = (int) ( $trip_packages_map[ $old_primary ] ?? $old_primary );
			if ( ! in_array( $new_primary, $new_package_ids, true ) ) {
				$new_primary = $new_package_ids[0];
			}
			update_post_meta( $trip_id, 'primary_package', $new_primary );
		}

		// 2. Remap package-categories term IDs for every trip-packages post.
		// Strategy: use the import-time $category_terms map first; for any term ID
		// not covered by it, fall back to matching the embedded 'labels' value
		// (e.g. "Adult", "Child") against local trip-packages-categories terms by name.
		// This handles the common case where WTE default terms already existed locally
		// and the import-time mapping was never recorded.
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'package-categories'" );
		foreach ( $rows as $row ) {
			$package_categories = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $package_categories ) ) {
				continue;
			}

			$embedded_labels = $package_categories['labels'] ?? array();
			$effective_map   = $category_terms;

			// Build per-package effective term ID map.
			foreach ( array_keys( $package_categories['c_ids'] ?? array() ) as $old_id ) {
				if ( isset( $effective_map[ $old_id ] ) ) {
					continue;
				}
				// ID already valid locally — no remapping needed.
				$local_term = get_term( $old_id, 'trip-packages-categories' );
				if ( $local_term && ! is_wp_error( $local_term ) ) {
					$effective_map[ $old_id ] = $old_id;
					continue;
				}
				// Fall back to matching the embedded label name against local terms.
				$label = $embedded_labels[ $old_id ] ?? '';
				if ( $label ) {
					$found = get_term_by( 'name', $label, 'trip-packages-categories' );
					if ( $found && ! is_wp_error( $found ) ) {
						$effective_map[ $old_id ] = $found->term_id;
					}
				}
			}

			// Point _primary_category_id at the remapped "Adult" category (first one as fallback).
			$mapped_c_ids = array();
			foreach ( array_keys( $package_categories['c_ids'] ?? array() ) as $old_id ) {
				$mapped_c_ids[] = (int) ( $effective_map[ $old_id ] ?? $old_id );
			}
			if ( ! empty( $mapped_c_ids ) && ! in_array( (int) get_post_meta( $row->post_id, '_primary_category_id', true ), $mapped_c_ids, true ) ) {
				$adult_old_id = array_search( 'Adult', $embedded_labels, true );
				$adult_id     = false !== $adult_old_id ? (int) ( $effective_map[ $adult_old_id ] ?? $adult_old_id ) : 0;
				update_post_meta(
					$row->post_id,
					'_primary_category_id',
					in_array( $adult_id, $mapped_c_ids, true ) ? $adult_id : $mapped_c_ids[0]
				);
			}

			// Skip if nothing would actually change.
			$needs_update = false;
			foreach ( array_keys( $package_categories['c_ids'] ?? array() ) as $old_id ) {
				if ( isset( $effective_map[ $old_id ] ) && (int) $effective_map[ $old_id ] !== (int) $old_id ) {
					$needs_update = true;
					break;
				}
			}
			if ( ! $needs_update ) {
				continue;
			}

			$new_package_categories = array();
			foreach ( $package_categories as $key => $value ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $k => $v ) {
						$new_key = $effective_map[ $k ] ?? $k;
						if ( 'c_ids' === $key ) {
							$new_package_categories[ $key ][ $new_key ] = $new_key;
						} else {
							$new_package_categories[ $key ][ $new_key ] = $v;
						}
					}
				} else {
					$new_package_categories[ $key ] = $value;
				}
			}
			update_post_meta( $row->post_id, 'package-categories', $new_package_categories );
		}

		// 3. Fix the primary_pricing_category option if it points to a stale demo term ID.
		$primary_cat_id = (int) get_option( 'primary_pricing_category', 0 );
		if ( $primary_cat_id ) {
			$local_primary = get_term( $primary_cat_id, 'trip-packages-categories' );
			if ( ! $local_primary || is_wp_error( $local_primary ) ) {
				// Try import-time map first.
				if ( isset( $category_terms[ $primary_cat_id ] ) ) {
					update_option( 'primary_pricing_category', (int) $category_terms[ $primary_cat_id ] );
				} else {
					// Fall back to the first available local term (usually "Adult").
					$first = get_terms(
						array(
							'taxonomy'   => 'trip-packages-categories',
							'hide_empty' => false,
							'number'     => 1,
						)
					);
					if ( ! empty( $first ) && ! is_wp_error( $first ) ) {
						update_option( 'primary_pricing_category', (int) $first[0]->term_id );
					}
				}
			}
		}

		// 4. Regenerate cached price metas (_s_price, wp_travel_engine_setting_trip_price, etc.)
		if ( class_exists( '\WPTravelEngine\Modules\TripSearch' ) ) {
			\WPTravelEngine\Modules\TripSearch::update_metas_for_trip_search();
		}
	}

	/**
	 * Called at end of WXR import — delegates to repair_wte_prices with the
	 * mappings collected during this import session.
	 *
	 * @since 1.0.11
	 */
	private function remap_wte_packages(): void {
		self::repair_wte_prices( self::$post_mapping, self::$taxonomy_term_mapping );
	}
}
