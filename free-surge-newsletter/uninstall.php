<?php
/**
 * Preserve subscriber data by default. A site owner may explicitly set
 * fsn_delete_data_on_uninstall to true to remove the subscriber table.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'fsn_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$table_name = $wpdb->prefix . 'fsn_subscribers';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
delete_option( 'fsn_db_version' );
delete_option( 'fsn_delete_data_on_uninstall' );

