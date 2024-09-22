<?php
/**
 * Menu Page.
 */
$sites_and_pages = Demo_Importer_Plus::get_instance()->get_all_sites();

$page_builder_sites = array_filter(
	$sites_and_pages,
	function ( $site ) {
		return $site[ 'site_page_builder' ] === $this->get_setting( 'page_builder' );
	}
);

$config = array(
	"xhr" => admin_url( 'admin-ajax.php' ),
);
?>
<div id="demo-importer-plus-app" class="demo-importer-plus-app" data-config="<?php echo esc_attr( wp_json_encode( $config ) ) ?>"></div>
