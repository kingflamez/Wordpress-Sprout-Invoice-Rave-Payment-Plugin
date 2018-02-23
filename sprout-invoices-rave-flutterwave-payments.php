<?php
/*
Plugin Name: Sprout Invoices Add-on - Rave Payments
Plugin URI: https://rave.flutterwave.com/
Description: Accept Payments with Rave for Sprout Invoices.
Author: Oluwole Adebiyi (King Flamez)
Version: 1.0
Author URI: https://github.com/kingflamez
*/

/**
 * Plugin File
 */
define( 'SI_ADDON_RAVE_VERSION', '3.1' );
define( 'SI_ADDON_RAVE_DOWNLOAD_ID', 141 );
define( 'SI_ADDON_RAVE_FILE', __FILE__ );
define( 'SI_ADDON_RAVE_NAME', 'Sprout Invoices Rave Payments' );
define( 'SI_ADDON_RAVE_URL', plugins_url( '', __FILE__ ) );


// Load up the processor before updates
add_action( 'si_payment_processors_loaded', 'si_load_rave' );
function si_load_rave() {
	require_once( 'SI_Rave.php' );
}

// Load up the updater after si is completely loaded
add_action( 'sprout_invoices_loaded', 'si_load_rave_updates' );
function si_load_rave_updates() {
	if ( class_exists( 'SI_Updates' ) ) {
		require_once( 'SI_Updates.php' );
	}
}