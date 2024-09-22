<?php
/**
 * Importer.
 */

namespace KraftPlugins\DemoImporterPlus;

class Importer {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	public Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = new Logger();
	}


}
