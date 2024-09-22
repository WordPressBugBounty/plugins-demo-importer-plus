<?php
/**
 * Site Reset.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use WP_Error;
use WP_Theme;
use WP_User;

class SiteReset {
	/**
	 * Blog name.
	 *
	 * @var string
	 */
	protected string $blogname;

	/**
	 * Blog Public.
	 *
	 * @var string
	 */
	protected string $blog_public;

	/**
	 * WP Language.
	 *
	 * @var string
	 */
	protected string $wplang;

	/**
	 * Site URL.
	 *
	 * @var string
	 */
	protected string $siteurl;

	/**
	 * Home.
	 *
	 * @var string
	 */
	protected string $home;
	/**
	 * @var WP_User|WP_Error|
	 */
	protected $current_user;

	/**
	 * @var WP_Theme
	 */
	protected WP_Theme $active_theme;

	/**
	 * @var array
	 */
	protected array $active_plugins = array();

	/**
	 * Drop prefixed tables.
	 *
	 * @return void
	 */
	public function drop_prefixed_tables(): void {
		global $wpdb;

		$prefix = str_replace( '_', '\_', $wpdb->prefix );
		$tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", array( $prefix . '%' ) ) );

		foreach ( $tables as $table ) {
			if ( $table === $wpdb->users || $table === $wpdb->usermeta ) {
				continue;
			}
			$wpdb->wpreset_table = $table;
			$wpdb->query( "DROP TABLE " . $table );
		}
	}

	/**
	 * @return mixed
	 */
	public function restore_current_user_data() {
		global $wpdb;

		$result = @wp_install( $this->blogname, $this->current_user->user_login, $this->current_user->user_email, $this->blog_public, '', md5( rand() ), $this->wplang );

		return $result[ 'user_id' ];
	}

	/**
	 * Check if WP-CLI is available and running.
	 *
	 * @return bool
	 */
	protected static function is_cli_running(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	protected function get_current_user() {
		global $current_user;

		if ( ! $current_user->ID ) {
			$tmp = get_users( array( 'role' => 'administrator', 'order' => 'ASC', 'order_by' => 'ID' ) );
			if ( empty( $tmp[ 0 ]->user_login ) ) {
				return new WP_Error( 1, 'Reset failed. Unable to find any admin users in database.' );
			}
			$current_user = $tmp[ 0 ];
		}

		return $current_user;
	}

	/**
	 * Restore site options.
	 *
	 * @return void
	 */
	protected function restore_site_options() {
		update_option( 'siteurl', $this->siteurl );
		update_option( 'home', $this->home );
	}

	/**
	 * Reset the site to the default state.
	 *
	 * @return bool
	 */
	public function reset(): bool {
		global $wpdb;

		// make sure the function is available to us
		if ( ! function_exists( 'wp_install' ) ) {
			require ABSPATH . '/wp-admin/includes/upgrade.php';
		}

		$this->blogname    = get_option( 'blogname' );
		$this->blog_public = get_option( 'blog_public' );
		$this->wplang      = get_option( 'wplang' );
		$this->siteurl     = get_option( 'siteurl' );
		$this->home        = get_option( 'home' );

		$this->active_theme = wp_get_theme();

		$this->current_user   = $this->get_current_user();
		$this->active_plugins = [ DEMO_IMPORTER_PLUS_FILE ];

		$this->drop_prefixed_tables();

		try {
			$user_id = $this->restore_current_user_data();
			$this->restore_site_options();
		} catch ( \Exception $e ) {

		}
		$this->reactivate_plugins();

		switch_theme( $this->active_theme->get_stylesheet() );

		return true;
	}

	/**
	 * Reactivate plugins.
	 */
	protected function reactivate_plugins() {
		foreach ( $this->active_plugins as $plugin_file ) {
			activate_plugin( $plugin_file );
		}
	}
}
