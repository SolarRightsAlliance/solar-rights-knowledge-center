<?php
/**
 * Uninstall cleanup. Analytics data is deliberately retained by default so an
 * accidental uninstall does not destroy editorial history. To erase it first,
 * use Knowledge Center → Analytics → Clear all analytics.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'sra_search_options' );
delete_option( 'sra_search_db_version' );
