<?php
/**
 * Page Importer.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use Demo_Importer_Plus;

class PageImporter extends Importer {

	/**
	 * Demo ID.
	 *
	 * @var int
	 */
	protected int $demo_id;

	/**
	 * Page ID.
	 *
	 * @var int
	 */
	protected int $page_id;

	/**
	 * New Page ID.
	 *
	 * @var int
	 */
	protected int $new_page_id;

	/**
	 * Constructor.
	 */
	public function __construct( int $page_id, int $demo_id ) {
		parent::__construct();
		$this->page_id = $page_id;
		$this->demo_id = $demo_id;
	}

	/**
	 * Import Page.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public function import() {
		$default_page_builder = Demo_Importer_Plus::get_instance()->get_setting( 'page_builder' );

		$server    = new DemoServer();
		$page_data = $server->fetch_page( $this->page_id, $this->demo_id );

		$content = $page_data['original_content'] ?? $page_data['content']['rendered'] ?? '';

		if ( 'elementor' === $default_page_builder ) {
			if ( isset( $page_data['options-data']['elementor_load_fa4_shim'] ) ) {
				update_option( 'elementor_load_fa4_shim', wp_kses_post( $page_data['options-data']['elementor_load_fa4_shim'] ) );
			}
		}

		$slug             = sanitize_title( $page_data['slug'] ?? '' );
		$existing_page_id = $this->get_page_id_by_slug( $slug );

		$post_args = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( $page_data['title']['rendered'] ?? '' ),
			'post_content' => wp_kses_post( $content ),
			'post_excerpt' => wp_kses_post( $page_data['excerpt']['rendered'] ),
		);

		if ( $existing_page_id ) {
			$post_args['ID'] = $existing_page_id;
		}

		$this->new_page_id = wp_insert_post( $post_args );

		// TODO: Look for use of this meta.
		update_post_meta( $this->new_page_id, '_demo_importer_enable_for_batch', true );

		$post_metas = $page_data['post-meta'] ?? array();
		$this->import_post_meta( is_array( $post_metas ) ? $post_metas : array() );

		$options = $page_data['options-data'] ?? array();
		$this->import_options( is_array( $options ) ? $options : array() );

		do_action( 'demo_importer_plus_process_single', $this->new_page_id, $this );

		return array(
			'remove-page-id' => $this->page_id,
			'id'             => $this->new_page_id,
			'link'           => get_permalink( $this->new_page_id ),
		);
	}

	/**
	 * Find an existing page by slug (catches pages created outside this importer, e.g. by another plugin on activation).
	 *
	 * @param string $slug Page slug.
	 * @return int Existing post ID, or 0 if not found.
	 */
	protected function get_page_id_by_slug( string $slug ) {
		if ( ! $slug ) {
			return 0;
		}

		$post_id = get_page_by_path( $slug, OBJECT, 'page' );

		return $post_id ? (int) $post_id->ID : 0;
	}

	/**
	 * Import Post Meta.
	 *
	 * @param array $meta Meta Data.
	 */
	protected function import_post_meta( array $meta ) {
		foreach ( $meta as $meta_key => $meta_value ) {
			if ( '_elementor_data' === $meta_key ) {

				$meta_value = json_decode( $meta_value, true );

				if ( is_array( $meta_value ) ) {
					$meta_value = wp_slash( wp_json_encode( $meta_value ) );
				}
			} elseif ( is_serialized( $meta_value, true ) ) {
				$meta_value = maybe_unserialize( stripslashes( $meta_value ) );
			}

			update_post_meta( $this->new_page_id, $meta_key, $meta_value );
		}
	}

	/**
	 * Import Options Data.
	 *
	 * @param array $options_data Options Data.
	 * TODO: Requires validation.
	 */
	protected function import_options( array $options_data ) {
		foreach ( $options_data as $option => $value ) {
			update_option( $option, sanitize_text_field( $value ) );
		}
	}
}
