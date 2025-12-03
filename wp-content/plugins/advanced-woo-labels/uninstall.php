<?php
/**
 * Uninstall plugin
 * Deletes all the plugin data
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'awl_settings' );
delete_option( 'awl_plugin_ver' );