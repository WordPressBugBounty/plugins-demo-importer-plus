<?php
/**
 * Demo Server.
 */

namespace KraftPlugins\DemoImporterPlus;

use WP_Error;

class DemoServer {

	/**
	 * @var string $url
	 */
	protected static string $url;

	/**
	 * @var string $namespace
	 */
	const namespace = "demoimporterplusapi/v1/";

	/**
	 * @var string $rest_base
	 */
	const rest_base = 'dipa-demos/';

	/**
	 * Constructor.
	 */
	public function __construct() {
		static::$url = trailingslashit( DEMO_IMPORTER_PLUS_MAIN_DEMO_URI ) . 'wp-json/';
	}

	/**
	 * @param string $input
	 *
	 * @return string
	 */
	protected function get_cache_key( string $input ): string {
		return demo_importer_plus_get_unique_key( $input, 'dipa_cache' );
	}

	/**
	 * Fetch from URL.
	 *
	 * @param string $url URL.
	 *
	 * @return WP_Error|array
	 */
	protected function fetch( string $url, $cache = true ) {
		$cache_key = $this->get_cache_key( $url );

		$data = $cache ? get_transient( $cache_key ) : false;

		if ( ! $data ) {
			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = wp_remote_retrieve_body( $response );

			if ( '' === $data ) {
				return new WP_Error( 'empty_response', 'Empty response from server', $response );
			}

			$cache && set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		}

		try {
			$data = json_decode( $data, true );
		} catch ( \Exception $e ) {
			$data = array();
		}

		return $data[ 'data' ] ?? $data;
	}

	/**
	 * Get Demos
	 *
	 * @return array|WP_Error
	 * @since 2.0.0
	 */
	public function fetch_demos( array $query_args = array() ) {
		$args = wp_parse_args(
			$query_args,
			array(
				'per_page' => 10,
				'page'     => 1,
				'include'  => array(),
				'search' => '',
				'category' => '',
				'type' => '',
			)
		);

		$mapping = array(
			'include'  => 'ids',
			'per_page' => 'per_page',
			'page'     => 'page',
			'search'   => 'search_term',
			'category' => 'demo_category',
			'type'     => 'demo_type',
		);

		if ( ! empty( $args[ 'include' ] ) ) {
			$args[ 'ids' ] = implode( ',', $args[ 'include' ] );
		}
		unset( $args[ 'include' ] );

		foreach ( $mapping as $key => $value ) {
			if ( isset( $args[ $key ] ) ) {
				$args[ $value ] = $args[ $key ];
				if ( $key !== $value ) {
					unset( $args[ $key ] );
				}
			}
		}

		$url = add_query_arg( $args, static::$url . static::namespace . static::rest_base );

		return $this->fetch( $url, ! empty( $args[ 'search_term' ] ) );
	}

	/**
	 * Get Demo
	 *
	 * @param int $id Demo ID.
	 *
	 * @return array|WP_Error
	 * @since 2.0.0
	 */
	public function fetch_demo( int $id ) {
		$url = static::$url . static::namespace . static::rest_base . $id;

		return $this->fetch( $url );
	}

	/**
	 * Fetch Page
	 *
	 * @param int $id Page ID.
	 * @param int $demo_id Demo ID.
	 *
	 * @return array|WP_Error
	 * @since 2.0.0
	 */
	public function fetch_page( int $id, int $demo_id ) {
		$demo = $this->fetch_demo( $demo_id );

		$site_url = $demo[ 'site_url' ] ?? '';

		$url = "$site_url/wp-json/wp/v2/pages/$id";

		return $this->fetch( $url );
	}

	/**
	 * Convert Size to Bytes
	 *
	 * @param $value
	 *
	 * @return int
	 */
	public function convert_size_to_bytes( $value ): int {
		$value = trim( $value );
		$last  = strtolower( $value[ strlen( $value ) - 1 ] );

		$value = (int) $value;
		switch ( $last ) {
			case 'k':
				$value *= 1e3;
				break;
			case 'm':
				$value *= 1e6;
				break;
			case 'g':
				$value *= 1e9;
				break;
		}

		return $value;
	}

	/**
	 * Compare Server Requirements
	 *
	 * @param array $config Config.
	 *
	 * @return array
	 */
	public function compare_server_requirements( array $config ): array {
		$requirements = array(
			'max_execution_time'  => 60,
			'memory_limit'        => '256M',
			'post_max_size'       => '32M',
			'upload_max_filesize' => '32M',
		);

		return array(
			'max_execution_time'  => array(
				'value'    => (int) $config[ 'max_execution_time' ],
				'required' => $requirements[ 'max_execution_time' ],
				'status'   => $config[ 'max_execution_time' ] > 0 && ( $config[ 'max_execution_time' ] < $requirements[ 'max_execution_time' ] ) ? 'fail' : 'pass',
			),
			'memory_limit'        => array(
				'value'    => $config[ 'memory_limit' ],
				'required' => $requirements[ 'memory_limit' ],
				'status'   => $this->convert_size_to_bytes( $config[ 'memory_limit' ] ) >= $this->convert_size_to_bytes( $requirements[ 'memory_limit' ] ) ? 'pass' : 'fail',
			),
			'post_max_size'       => array(
				'value'    => $config[ 'post_max_size' ],
				'required' => $requirements[ 'post_max_size' ],
				'status'   => $this->convert_size_to_bytes( $config[ 'post_max_size' ] ) >= $this->convert_size_to_bytes( $requirements[ 'post_max_size' ] ) ? 'pass' : 'fail',
			),
			'upload_max_filesize' => array(
				'value'    => $config[ 'upload_max_filesize' ],
				'required' => $requirements[ 'upload_max_filesize' ],
				'status'   => $this->convert_size_to_bytes( $config[ 'upload_max_filesize' ] ) >= $this->convert_size_to_bytes( $requirements[ 'upload_max_filesize' ] ) ? 'pass' : 'fail',
			),
		);
	}

	/**
	 * Test Connection if a server is reachable.
	 *
	 * @return WP_Error|array
	 */
	public function test_connection() {
		$url = static::$url . static::namespace;

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $response_code ) {
			return new WP_Error( 'invalid_response_code', __( 'Invalid response code from server', 'demo-importer-plus' ), $response );
		}

		return array(
			'success' => true,
			'data'    => array(
				'code'    => 'server_reachable',
				'message' => __( 'Server connection successful.', 'demo-importer-plus' ),
			),
		);
	}

	/**
	 * Fetch Demos Categories
	 *
	 * @return array|WP_Error
	 */
	public function fetch_demos_categories( $args = array() ) {

		$url = add_query_arg( $args, static::$url . "wp/v2/categories" );

		return $this->fetch( $url );
	}

}
