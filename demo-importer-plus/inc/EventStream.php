<?php
/**
 * Event Streamer.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

use WP_Error;

/**
 * Event Stream.
 *
 * @since 2.0.0
 */
class EventStream {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	protected function setup() {

		header( 'Content-Type: text/event-stream, charset=UTF-8' );

		$previous = error_reporting( error_reporting() ^ E_WARNING );

		ini_set( 'output_buffering', 'off' );
		ini_set( 'zlib.output_compression', false );

		error_reporting( $previous );

		if ( $GLOBALS[ 'is_nginx' ] ) {
			header( 'X-Accel-Buffering: no' );
			header( 'Content-Encoding: none' );
		}

		echo esc_html( ':' . str_repeat( ' ', 2048 ) . "\n\n" );

		set_time_limit( 0 );

		wp_ob_end_flush_all();
		flush();
	}

	/**
	 * Emit SSE Message.
	 *
	 * @param array $data Data.
	 *
	 * @return void
	 */
	public function emit_sse_message( array $data, $event = 'message' ) {

		if ( ! defined( 'WP_CLI' ) ) {
			echo "event: {$event}\n";
			echo 'data: ' . wp_json_encode( $data ) . "\n\n";
			// Extra padding.
			echo esc_html( ':' . str_repeat( ' ', 2048 ) . "\n\n" );
		}

		flush();
	}

	/**
	 * Emit Log Message.
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	public function emit_log_message( string $message ) {
		$this->emit_sse_message( array( 'message' => $message ), 'log' );
	}

}
