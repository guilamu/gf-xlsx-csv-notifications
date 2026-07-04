<?php
/**
 * Plugin Name: Gravity Forms - XLSX to CSV Notifications
 * Plugin URI: https://github.com/guilamu/gf-xlsx-csv-notifications
 * Description: Converts XLSX files uploaded through Gravity Forms file upload fields (including GP File Upload Pro) to CSV and attaches the CSV (plus the original XLSX) to form notifications.
 * Version: 1.0.0
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
define( 'GF_XLSX_CSV_VERSION', '1.0.0' );
define( 'GF_XLSX_CSV_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_XLSX_CSV_URL', plugin_dir_url( __FILE__ ) );
define( 'GF_XLSX_CSV_BASENAME', plugin_basename( __FILE__ ) );

// Include the GitHub auto-updater.
require_once GF_XLSX_CSV_PATH . 'includes/class-github-updater.php';

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

		$xlsx_paths = self::get_entry_xlsx_paths( $form, $entry );
		if ( empty( $xlsx_paths ) ) {
			return $notification;
		}

		if ( empty( $notification['attachments'] ) || ! is_array( $notification['attachments'] ) ) {
			$notification['attachments'] = array();
		}

		/** Also attach the original XLSX file (true by default). */
		$attach_original = apply_filters( 'gf_xlsx_csv_notifications_attach_original', true, $notification, $form, $entry );

		foreach ( $xlsx_paths as $xlsx_path ) {
			$csv_path = self::convert_to_csv( $xlsx_path );
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
	 * Return the physical paths of the .xlsx files uploaded in the entry.
	 *
	 * Handles single file upload fields (value = URL) and multi-file fields
	 * (value = JSON array of URLs), which covers GP File Upload Pro.
	 *
	 * @param array $form  The form.
	 * @param array $entry The entry.
	 *
	 * @return string[]
	 */
	public static function get_entry_xlsx_paths( $form, $entry ) {

		$paths = array();

		if ( empty( $form['fields'] ) ) {
			return $paths;
		}

		foreach ( $form['fields'] as $field ) {

			if ( ! is_object( $field ) || $field->get_input_type() !== 'fileupload' ) {
				continue;
			}

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
					$paths[] = $path;
				}
			}
		}

		return $paths;
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
	 * Convert an XLSX file to CSV (semicolon delimiter, UTF-8 with BOM, first sheet).
	 *
	 * The CSV is written next to the XLSX and reused when already up to date.
	 *
	 * @param string $xlsx_path Path to the XLSX file.
	 *
	 * @return string|false Path to the generated CSV, or false on failure.
	 */
	public static function convert_to_csv( $xlsx_path ) {

		if ( ! class_exists( '\Shuchkin\SimpleXLSX' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/SimpleXLSX.php';
		}

		/** CSV delimiter (";" by default, Excel-friendly for European locales). */
		$delimiter = apply_filters( 'gf_xlsx_csv_notifications_delimiter', ';' );
		/** Index of the worksheet to convert (0 = first). */
		$sheet_index = (int) apply_filters( 'gf_xlsx_csv_notifications_sheet_index', 0 );

		$csv_path = self::csv_destination( $xlsx_path );

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
	 * @param string $xlsx_path Path to the XLSX file.
	 *
	 * @return string
	 */
	protected static function csv_destination( $xlsx_path ) {

		$dir  = dirname( $xlsx_path );
		$name = pathinfo( $xlsx_path, PATHINFO_FILENAME ) . '.csv';

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
