<?php
/**
 * Plugin Name: Gravity Forms - XLSX to CSV Notifications
 * Plugin URI: https://github.com/guilamu/gf-xlsx-csv-notifications
 * Description: Converts XLSX files uploaded through Gravity Forms file upload fields (including GP File Upload Pro) to CSV and attaches the CSV (plus the original XLSX) to form notifications. Each upload field is configurable per form (attach CSV only, delimiter, worksheet) from the form settings.
 * Version: 1.1.1
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Text Domain: gf-xlsx-csv-notifications
 * Domain Path: /languages
 * Update URI: https://github.com/guilamu/gf-xlsx-csv-notifications/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: AGPL-3.0
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'GF_XLSX_CSV_VERSION', '1.1.1' );
define( 'GF_XLSX_CSV_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_XLSX_CSV_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_XLSX_CSV_BASENAME', plugin_basename( __FILE__ ) );

// Include the GitHub auto-updater.
require_once GF_XLSX_CSV_PATH . 'includes/class-github-updater.php';

// Register the per-form settings add-on. Must run on gform_loaded, never on
// plugins_loaded/init, or the Gravity Forms menu is already built and the tab
// silently fails to appear.
add_action( 'gform_loaded', 'gf_xlsx_csv_register_addon', 5 );

/**
 * Register the GFAddOn that renders the per-form "XLSX to CSV" settings tab.
 *
 * @return void
 */
function gf_xlsx_csv_register_addon() {
	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}
	require_once GF_XLSX_CSV_PATH . 'includes/class-gf-xlsx-csv-addon.php';
	GFAddOn::register( 'GF_Xlsx_Csv_AddOn' );
}

// Load translations.
add_action( 'init', function () {
	load_plugin_textdomain( 'gf-xlsx-csv-notifications', false, dirname( GF_XLSX_CSV_BASENAME ) . '/languages' );
} );

// Register with Guilamu Bug Reporter.
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		Guilamu_Bug_Reporter::register( array(
			'slug'        => 'gf-xlsx-csv-notifications',
			'name'        => 'Gravity Forms - XLSX to CSV Notifications',
			'version'     => GF_XLSX_CSV_VERSION,
			'github_repo' => 'guilamu/gf-xlsx-csv-notifications',
		) );
	}
}, 20 );

// Plugin row meta: "View details" + "Report a Bug" links.
add_filter( 'plugin_row_meta', 'gf_xlsx_csv_notifications_plugin_row_meta', 10, 2 );

/**
 * Add "View details" and "Report a Bug" links to the plugin row.
 *
 * @param string[] $links Existing row meta links.
 * @param string   $file  Plugin file currently rendered.
 *
 * @return string[]
 */
function gf_xlsx_csv_notifications_plugin_row_meta( $links, $file ) {
	if ( GF_XLSX_CSV_BASENAME !== $file ) {
		return $links;
	}

	// "View details" thickbox link - same pattern as WordPress.org-hosted plugins.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url( self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=gf-xlsx-csv-notifications'
			. '&TB_iframe=true&width=772&height=926'
		) ),
		esc_attr__( 'More information about Gravity Forms - XLSX to CSV Notifications', 'gf-xlsx-csv-notifications' ),
		esc_attr__( 'Gravity Forms - XLSX to CSV Notifications', 'gf-xlsx-csv-notifications' ),
		esc_html__( 'View details', 'gf-xlsx-csv-notifications' )
	);

	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$links[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="gf-xlsx-csv-notifications" data-plugin-name="%s">%s</a>',
			esc_attr__( 'Gravity Forms - XLSX to CSV Notifications', 'gf-xlsx-csv-notifications' ),
			esc_html__( '🐛 Report a Bug', 'gf-xlsx-csv-notifications' )
		);
	} else {
		// Fallback: prompt the user to install Bug Reporter.
		$links[] = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			'https://github.com/guilamu/guilamu-bug-reporter/releases',
			esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'gf-xlsx-csv-notifications' )
		);
	}

	return $links;
}

/**
 * Main plugin class.
 *
 * Converts XLSX attachments of Gravity Forms entries to CSV and attaches
 * both files to outgoing notifications.
 */
class GF_Xlsx_Csv_Notifications {

	/**
	 * Entry point: hook into Gravity Forms notifications.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gform_notification', array( __CLASS__, 'add_attachments' ), 10, 3 );
	}

	/**
	 * Add the converted CSV files (and the original XLSX files) to the notification attachments.
	 *
	 * @param array $notification The notification being sent.
	 * @param array $form         The form.
	 * @param array $entry        The entry (submission).
	 *
	 * @return array
	 */
	public static function add_attachments( $notification, $form, $entry ) {

		if ( ! class_exists( 'GFFormsModel' ) || ! is_array( $entry ) ) {
			return $notification;
		}

		/**
		 * Allows disabling the conversion for specific notifications/forms.
		 * add_filter( 'gf_xlsx_csv_notifications_enabled', function( $enabled, $notification, $form ) {
		 *     return $form['id'] === 12; // example: only form 12
		 * }, 10, 3 );
		 */
		if ( ! apply_filters( 'gf_xlsx_csv_notifications_enabled', true, $notification, $form, $entry ) ) {
			return $notification;
		}

		$xlsx_files = self::get_entry_xlsx_paths( $form, $entry );
		if ( empty( $xlsx_files ) ) {
			return $notification;
		}

		if ( empty( $notification['attachments'] ) || ! is_array( $notification['attachments'] ) ) {
			$notification['attachments'] = array();
		}

		$settings = self::get_form_settings( $form );

		foreach ( $xlsx_files as $file ) {

			$field_id  = $file['field_id'];
			$xlsx_path = $file['path'];

			$delimiter   = self::get_field_delimiter( $settings, $field_id );
			$sheet_index = self::get_field_sheet_index( $settings, $field_id );

			// When "attach the CSV only" is enabled for this field, skip the XLSX.
			$attach_original = ! self::field_setting_enabled( $settings, 'attach_csv_only_' . $field_id );

			$csv_path = self::convert_to_csv( $xlsx_path, $delimiter, $sheet_index );
			if ( $csv_path ) {
				$notification['attachments'][] = $csv_path;
				self::log( sprintf( 'CSV attached to notification "%s": %s', rgar( $notification, 'name' ), $csv_path ) );
			}
			if ( $attach_original ) {
				$notification['attachments'][] = $xlsx_path;
			}
		}

		$notification['attachments'] = array_values( array_unique( $notification['attachments'] ) );

		return $notification;
	}

	/**
	 * Return every file upload field contained in a form.
	 *
	 * Covers regular Gravity Forms file upload fields and GP File Upload Pro,
	 * both of which report the "fileupload" input type. Used to build the
	 * per-field settings tab and to iterate uploads at notification time.
	 *
	 * @param array $form The form.
	 *
	 * @return GF_Field[]
	 */
	public static function get_upload_fields( $form ) {

		$fields = array();

		if ( empty( $form['fields'] ) ) {
			return $fields;
		}

		foreach ( $form['fields'] as $field ) {
			if ( is_object( $field ) && $field->get_input_type() === 'fileupload' ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Return the .xlsx files uploaded in the entry, tagged with their field ID.
	 *
	 * Handles single file upload fields (value = URL) and multi-file fields
	 * (value = JSON array of URLs), which covers GP File Upload Pro.
	 *
	 * @param array $form  The form.
	 * @param array $entry The entry.
	 *
	 * @return array[] List of array( 'field_id' => int, 'path' => string ).
	 */
	public static function get_entry_xlsx_paths( $form, $entry ) {

		$files = array();

		foreach ( self::get_upload_fields( $form ) as $field ) {

			/** Allows excluding specific fields from the conversion. */
			if ( ! apply_filters( 'gf_xlsx_csv_notifications_field_enabled', true, $field, $form ) ) {
				continue;
			}

			$value = rgar( $entry, (string) $field->id );
			if ( empty( $value ) ) {
				continue;
			}

			// Multi-file: JSON array of URLs. Single file: plain URL.
			if ( is_array( $value ) ) {
				$urls = $value;
			} else {
				$urls = json_decode( (string) $value, true );
				if ( ! is_array( $urls ) ) {
					$urls = array( $value );
				}
			}

			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || $url === '' ) {
					continue;
				}
				$path = self::url_to_path( $url );
				if ( $path && strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === 'xlsx' && is_readable( $path ) ) {
					$files[] = array(
						'field_id' => (int) $field->id,
						'path'     => $path,
					);
				}
			}
		}

		return $files;
	}

	/**
	 * Read this form's saved add-on settings.
	 *
	 * @param array $form The form.
	 *
	 * @return array
	 */
	protected static function get_form_settings( $form ) {
		if ( class_exists( 'GF_Xlsx_Csv_AddOn' ) ) {
			$settings = GF_Xlsx_Csv_AddOn::get_instance()->get_form_settings( $form );
			return is_array( $settings ) ? $settings : array();
		}

		return array();
	}

	/**
	 * Whether a checkbox-style form setting is enabled.
	 *
	 * @param array  $settings Saved form settings.
	 * @param string $key      Setting key.
	 *
	 * @return bool
	 */
	protected static function field_setting_enabled( $settings, $key ) {
		return '1' === (string) rgar( $settings, $key );
	}

	/**
	 * Resolve the CSV delimiter configured for a field (";" by default).
	 *
	 * @param array $settings Saved form settings.
	 * @param int   $field_id Upload field ID.
	 *
	 * @return string Single-character delimiter.
	 */
	protected static function get_field_delimiter( $settings, $field_id ) {

		$raw = rgar( $settings, 'delimiter_' . $field_id );

		if ( 'tab' === $raw ) {
			return "\t";
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			return substr( $raw, 0, 1 );
		}

		return ';';
	}

	/**
	 * Resolve the worksheet index configured for a field.
	 *
	 * The UI is 1-based (1 = first sheet, matching Excel); SimpleXLSX is 0-based,
	 * so the stored value is shifted down by one. Empty/invalid falls back to 0.
	 *
	 * @param array $settings Saved form settings.
	 * @param int   $field_id Upload field ID.
	 *
	 * @return int Zero-based worksheet index.
	 */
	protected static function get_field_sheet_index( $settings, $field_id ) {
		return max( 0, (int) rgar( $settings, 'worksheet_' . $field_id ) - 1 );
	}

	/**
	 * Safely convert a Gravity Forms upload URL to a physical path.
	 *
	 * @param string $url Uploaded file URL.
	 *
	 * @return string|false
	 */
	public static function url_to_path( $url ) {

		$url = strtok( $url, '?' ); // Strip any query parameters.

		$url_root  = GFFormsModel::get_upload_url_root();
		$path_root = GFFormsModel::get_upload_root();

		if ( strpos( $url, $url_root ) !== 0 ) {
			return false;
		}

		$relative = rawurldecode( substr( $url, strlen( $url_root ) ) );
		$path     = wp_normalize_path( $path_root . $relative );

		// Path traversal protection: the resolved path must stay inside the GF upload folder.
		$real_path = realpath( $path );
		$real_root = realpath( $path_root );
		if ( ! $real_path || ! $real_root || strpos( wp_normalize_path( $real_path ), wp_normalize_path( $real_root ) ) !== 0 ) {
			return false;
		}

		return $real_path;
	}

	/**
	 * Convert an XLSX file to CSV (UTF-8 with BOM) using per-field settings.
	 *
	 * The CSV is written next to the XLSX and reused when already up to date.
	 *
	 * @param string $xlsx_path   Path to the XLSX file.
	 * @param string $delimiter   Single-character CSV delimiter (";" by default).
	 * @param int    $sheet_index Index of the worksheet to convert (0 = first).
	 *
	 * @return string|false Path to the generated CSV, or false on failure.
	 */
	public static function convert_to_csv( $xlsx_path, $delimiter = ';', $sheet_index = 0 ) {

		if ( ! class_exists( '\Shuchkin\SimpleXLSX' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/SimpleXLSX.php';
		}

		$delimiter   = ( is_string( $delimiter ) && '' !== $delimiter ) ? substr( $delimiter, 0, 1 ) : ';';
		$sheet_index = max( 0, (int) $sheet_index );

		$csv_path = self::csv_destination( $xlsx_path, $delimiter, $sheet_index );

		// Cache: CSV already generated and newer than the XLSX.
		if ( file_exists( $csv_path ) && filemtime( $csv_path ) >= filemtime( $xlsx_path ) ) {
			return $csv_path;
		}

		$xlsx = \Shuchkin\SimpleXLSX::parse( $xlsx_path );
		if ( ! $xlsx ) {
			self::log( 'Failed to read XLSX (' . \Shuchkin\SimpleXLSX::parseError() . '): ' . $xlsx_path, true );
			return false;
		}

		if ( $sheet_index > 0 && ! $xlsx->worksheet( $sheet_index ) ) {
			$sheet_index = 0; // Fall back to the first sheet.
		}

		$fh = @fopen( $csv_path, 'wb' );
		if ( ! $fh ) {
			self::log( 'Cannot write CSV: ' . $csv_path, true );
			return false;
		}

		fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM: Excel opens accented characters correctly.

		foreach ( $xlsx->readRows( $sheet_index ) as $row ) {
			foreach ( $row as $k => $cell ) {
				if ( is_bool( $cell ) ) {
					$row[ $k ] = $cell ? '1' : '0';
				}
			}
			fputcsv( $fh, $row, $delimiter, '"', '\\' );
		}

		fclose( $fh );

		return $csv_path;
	}

	/**
	 * Determine where to write the CSV: next to the XLSX when possible, otherwise in the temp folder.
	 *
	 * With default settings (semicolon, first sheet) the CSV keeps the clean
	 * "{name}.csv" filename. Non-default settings add a short hash suffix so a
	 * settings change produces a distinct file instead of serving a stale cache.
	 *
	 * @param string $xlsx_path   Path to the XLSX file.
	 * @param string $delimiter   CSV delimiter in effect.
	 * @param int    $sheet_index Worksheet index in effect.
	 *
	 * @return string
	 */
	protected static function csv_destination( $xlsx_path, $delimiter = ';', $sheet_index = 0 ) {

		$dir    = dirname( $xlsx_path );
		$base   = pathinfo( $xlsx_path, PATHINFO_FILENAME );
		$suffix = ( ';' === $delimiter && 0 === (int) $sheet_index )
			? ''
			: '-' . substr( md5( $delimiter . '|' . $sheet_index ), 0, 8 );
		$name   = $base . $suffix . '.csv';

		if ( is_writable( $dir ) ) {
			return trailingslashit( $dir ) . $name;
		}

		return get_temp_dir() . md5( $xlsx_path ) . '-' . $name;
	}

	/**
	 * Log through the Gravity Forms logging system (Forms → Settings → Logging).
	 *
	 * @param string $message  Message.
	 * @param bool   $is_error Error or plain debug entry.
	 *
	 * @return void
	 */
	protected static function log( $message, $is_error = false ) {
		if ( class_exists( 'GFCommon' ) ) {
			if ( $is_error ) {
				GFCommon::log_error( 'gf-xlsx-csv-notifications: ' . $message );
			} else {
				GFCommon::log_debug( 'gf-xlsx-csv-notifications: ' . $message );
			}
		}
	}
}

GF_Xlsx_Csv_Notifications::init();
