<?php
/*
Plugin Name: Rave By Flutterwave for Sprout Invoices
Plugin URI: https://rave.flutterwave.com/
Description: Accept Payments with Rave by Flutterwave for Sprout Invoices.
Author: KingFlamez
Version: 1.1.0
Author URI: https://twitter.com/mrflamez_
*/

/**
 * Plugin File
 */
define('SI_ADDON_RAVE_VERSION', '1.1.0');
define( 'SA_ADDON_RAVE_DOWNLOAD_ID', 1287 );
define( 'SA_ADDON_RAVE_FILE', __FILE__ );
define( 'SA_ADDON_RAVE_NAME', 'Sprout Invoices Paypal EC Payments' );
define( 'SA_ADDON_RAVE_URL', plugins_url( '', __FILE__ ) );


// Load up the processor before updates
add_action( 'si_payment_processors_loaded', 'sa_load_rave' );
function sa_load_rave() {
	if ( ! class_exists( 'SI_Rave' ) ) {
		require_once( 'SI_Rave.php' );
	} else {
		// deactivate plugin if the pro version is installed.
		require_once ABSPATH.'/wp-admin/includes/plugin.php';
		deactivate_plugins( __FILE__ );
	}
}

// Load up the updater after si is completely loaded
add_action( 'sprout_invoices_loaded', 'sa_load_rave_updates' );
function sa_load_rave_updates() {
	if ( class_exists( 'SI_Updates' ) ) {
		require_once( 'inc/sa-updates/SA_Updates.php' );
	}
}