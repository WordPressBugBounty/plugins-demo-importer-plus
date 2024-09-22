<?php
/**
 * Logger.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use Mpdf\Tag\P;

class Logger {
	/**
	 * Log Directory.
	 *
	 * @var string
	 */
	protected string $log_directory;

	/**
	 * Log Filename.
	 *
	 * @var string
	 */
	protected string $log_filename;

	/**
	 * Constructor.
	 */
	public function __construct( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'log_dir'  => wp_upload_dir()[ 'basedir' ],
				'path'     => 'demo-importer-plus',
				'filename' => 'demo-importer-plus.log',
			)
		);

		$this->log_directory = $args[ 'log_dir' ] . '/' . $args[ 'path' ];
		$this->log_filename  = $this->log_directory . '/' . $args[ 'filename' ];
	}

	/**
	 * Get an instance of WP_Filesystem_Direct.
	 *
	 * @return object|null
	 */
	public function get_filesystem(): ?object {
		global $wp_filesystem;

		require_once ABSPATH . '/wp-admin/includes/file.php';

		if ( WP_Filesystem() ) {
			return $wp_filesystem;
		}

		return null;
	}

	/**
	 * Log file directory
	 *
	 * @return string
	 */
	public function log_dir(): string {
		return $this->log_directory;
	}

	/**
	 * Log
	 *
	 * @param string|array $message Message.
	 *
	 * @return bool
	 */
	public function log( $message ): bool {
		$file_system = $this->get_filesystem();

		$message = is_array( $message ) ? wp_json_encode( $message ) : $message;
		if ( $file_system ) {
			if ( false === $file_system->put_contents( $this->log_filename, $message, FS_CHMOD_FILE ) ) {
				update_option( 'demo_importer_plus_sites_' . $this->log_filename, $message );
			}
		} else {
			update_option( 'demo_importer_plus_sites_' . $this->log_filename, $message );
		}

		return true;
	}
}
