<?php
/**
 * Uninstall cleanup for Gravity Forms - XLSX to CSV Notifications.
 *
 * The cached GitHub release data and the add-on version option are removed.
 * Per-form settings live inside each form's meta and are removed with the form.
 * Generated CSV files live next to the uploaded XLSX files in the Gravity Forms
 * upload directory and are intentionally left in place, as they may be
 * referenced by already-sent notifications.
 *
 * @package GF_Xlsx_Csv_Notifications
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'gf_xlsx_csv_notifications_github_release' );

// Version option registered by the GFAddOn framework.
delete_option( 'gravityformsaddon_gf-xlsx-csv-notifications_version' );
