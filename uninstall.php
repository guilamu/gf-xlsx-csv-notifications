<?php
/**
 * Uninstall cleanup for Gravity Forms - XLSX to CSV Notifications.
 *
 * The plugin stores no options. Only the cached GitHub release data is
 * removed. Generated CSV files live next to the uploaded XLSX files in the
 * Gravity Forms upload directory and are intentionally left in place, as
 * they may be referenced by already-sent notifications.
 *
 * @package GF_Xlsx_Csv_Notifications
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'gf_xlsx_csv_notifications_github_release' );
