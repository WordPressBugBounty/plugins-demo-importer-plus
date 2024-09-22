<?php
/**
 * Demo Data Model.
 *
 * @since 2.0.0
 */

namespace KraftPlugins\DemoImporterPlus;

class DemoDataModel {

	/**
	 * @var array
	 */
	protected array $data;

	/**
	 * @var int
	 */
	public int $ID;

	/**
	 * Constructor
	 *
	 * @param array $data
	 */
	public function __construct( array $data ) {
		$this->ID = $data[ 'id' ];

		$this->data = $data;
	}

	/**
	 * Get Data
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	/**
	 * Get Required Plugins.
	 *
	 * @return array
	 */
	public function get_required_plugins(): array {
		return $this->get( 'required_plugins', array() );
	}

	/**
	 * Get Customizer Settings.
	 *
	 * @return array
	 */
	public function get_customizer_settings(): array {
		return $this->get( 'customizer-data', array() );
	}

	/**
	 * Check if the site is a pro site.
	 *
	 * @return boolean
	 */
	public function is_pro(): bool {
		return $this->get( 'site_type', false ) === 'pro';
	}
}
