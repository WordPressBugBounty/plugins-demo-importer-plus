<?php
/**
 * Previous Site Cleanup.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use WP_Error;

/**
 * Previous Site Cleanup.
 *
 * @since 2.0.0
 */
class PreviousSiteCleanup extends EventStream {

	/**
	 * Step.
	 *
	 * @var string
	 */
	public string $step = 'prepare';

	/**
	 * Run.
	 *
	 * @return WP_Error|array
	 */
	public function run() {

		switch ( $this->step ) {
			case 'prepare':
				$data = $this->prepare();
				break;
			case 'delete_site_options':
				$data = $this->delete_site_options();
				break;
			case 'delete_site_widgets':
				$data = $this->delete_widgets_data();
				break;
			case 'delete_site_customizer':
				$data = $this->reset_customizer_data();
				break;
			case 'delete_site_content':
				$data = $this->delete_content();
				break;
			default:
				$data = new WP_Error( 'invalid_step', __( 'Invalid step', 'demo-importer-plus' ) );
		}

		return $data;

	}

	/**
	 * Prepare.
	 *
	 * @return array
	 */
	public function prepare(): array {
		global $wpdb;

		$post_ids = $wpdb->get_var( "SELECT COUNT( post_id ) FROM {$wpdb->postmeta} WHERE meta_key='_demo_importer_plus_sites_imported_post'" );
		$form_ids = $wpdb->get_var( "SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key='_demo_importer_plus_imported_contact_form7'" );
		$term_ids = $wpdb->get_var( "SELECT COUNT(term_id) FROM {$wpdb->termmeta} WHERE meta_key='_demo_importer_plus_imported_term'" );

		return array(
			'reset_posts'         => $post_ids,
			'reset_contact_form7' => $form_ids,
			'reset_terms'         => $term_ids,
			'reset_customizer'    => get_option( 'demo-importer-plus-settings', false ) !== false,
			'reset_options'       => get_option( '__demo_importer_plus_site_options', false ) !== false,
			'reset_widgets'       => get_option( 'sidebars_widgets', false ) !== false,
			'reset_content'       => $post_ids + $form_ids + $term_ids > 0,
			'__meta'              => array(
				'total_count' => $post_ids + $form_ids + $term_ids,
			),
		);
	}

	/**
	 * Reset.
	 *
	 * @return WP_Error|array
	 */
	public function reset_customizer_data() {
		if ( ! current_user_can( 'customize' ) ) {
			return new WP_Error( 'not_authorized', __( 'You are not allowed to perform this action', 'demo-importer-plus' ) );
		}

		delete_option( 'demo-importer-plus-settings' );

		return array();
	}

	/**
	 * Reset Site Options.
	 */
	public function delete_site_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'not_authorized', __( 'You are not allowed to perform this action', 'demo-importer-plus' ) );
		}

		$site_options_importer = new SiteOptionsImporter();

		return $site_options_importer->cleanup();
	}

	/**
	 * Delete Widgets Data.
	 */
	public function delete_widgets_data() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'not_authorized', __( 'You are not allowed to perform this action', 'demo-importer-plus' ) );
		}

		$widgets_importer = new WidgetsImporter();

		return $widgets_importer->cleanup();

	}

	/**
	 * Term Deleted.
	 */
	protected function term_deleted( $data ) {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => 'terms',
				'delta'  => 1,
			)
		);
		$this->emit_log_message( sprintf( __( "Deleted Term: %s", 'demo-importer-plus' ), $data[ 'title' ] ) );
	}

	/**
	 * Post Deleted.
	 */
	protected function post_deleted( $data ) {
		$this->emit_sse_message(
			array(
				'action' => 'updateDelta',
				'type'   => ( 'attachment' === $data[ 'post_type' ] ) ? 'media' : 'posts',
				'delta'  => 1,
			)
		);
		$this->emit_log_message( sprintf( __( "Deleted: %s [{$data['post_type']}]", 'demo-importer-plus' ), $data[ 'title' ] ) );
	}

	/**
	 * Delete Posts.
	 */
	protected function delete_posts() {
		global $wpdb;

		$total_posts = $wpdb->get_var( "SELECT COUNT(post_id)  FROM {$wpdb->postmeta} WHERE meta_key='_demo_importer_plus_sites_imported_post'" );

		$batch_size = 100;

		for ( $offset = 0; $offset < $total_posts; $offset += $batch_size ) {

			$results = $wpdb->get_results( $wpdb->prepare( "
				SELECT p.ID, p.post_type
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = '_demo_importer_plus_sites_imported_post'
				LIMIT %d OFFSET %d
    		", $batch_size, $offset )
			);

			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {
					do_action( 'demo_importer_plus_sites_before_delete_imported_posts', $result->ID, $result->post_type );
					if ( $post = wp_delete_post( $result->ID, true ) ) {
						$this->post_deleted( [ 'post_type' => $post->post_type, 'title' => $post->post_title ] );
					}
				}
			}
		}

	}

	public function delete_terms() {
		global $wpdb;

		$total_terms = $wpdb->get_var( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_demo_importer_plus_imported_term'" );

		$batch_size = 100;

		for ( $offset = 0; $offset < $total_terms; $offset += $batch_size ) {

			$results = $wpdb->get_col( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_demo_importer_plus_imported_term'" );

			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {

					$term = get_term( (int) $result );

					if ( $term instanceof \WP_Term ) {
						do_action( 'demo_importer_plus_before_delete_imported_terms', $term->term_id, $term );
						wp_delete_term( $term->term_id, $term->taxonomy );
						$this->term_deleted( [ 'title' => "$term->name [$term->term_id]" ] );
					}
				}
			}
		}

	}

	/**
	 * This will delete all the cached options data from previous site import.
	 */
	public function cleanup() {

	}

	/**
	 * Delete Content.
	 *
	 * return WP_Error|array
	 */
	public function delete_content() {

//		if ( ! current_user_can( 'delete_posts' ) ) {
//			return new WP_Error( 'not_authorized', __( 'You are not allowed to perform this action', 'demo-importer-plus' ) );
//		}

		global $wpdb;
		$this->setup();

		$post_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_demo_importer_plus_sites_imported_post'" );
		$form_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_demo_importer_plus_imported_contact_form7'" );
		$term_ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_demo_importer_plus_imported_term'" );

		$this->delete_posts();
		$this->delete_terms();

		$this->cleanup();

		$this->emit_sse_message(
			array(
				'action' => 'complete',
				'error'  => false,
			)
		);
		exit;
	}
}
