<?php

/**
 * Updates class
 *
 * @package Sprout_Invoice
 * @subpackage Updates
 */
class SI_Rave_Updates extends SI_Updates {	
	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'init_edd_udpater' ) );
		}
	}

	public static function init_edd_udpater() {
		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater_SA_Mod( self::PLUGIN_URL, SI_ADDON_RAVE_FILE, array(
				'item_id' => SI_ADDON_RAVE_DOWNLOAD_ID,// Set the download_id manually
				'version' 	=> SI_ADDON_RAVE_VERSION,		// current version number
				'license' 	=> self::license_key(),	 		// license key (used get_option above to retrieve from DB)
				'item_name' => SI_ADDON_RAVE_NAME, 		// name of this plugin
				'author' 	=> 'flamekeed' 				// author of this plugin
			)
		);

		// $edd_updater->api_request( 'plugin_latest_version', array( 'slug' => basename( self::PLUGIN_FILE, '.php') ) );

		// uncomment this line for testing
		// set_site_transient( 'update_plugins', null );
	}

	
}
SI_Rave_Updates::init();