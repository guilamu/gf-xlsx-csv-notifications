# Gravity Forms - XLSX to CSV Notifications

[![Latest Release](https://img.shields.io/github/v/release/guilamu/gf-xlsx-csv-notifications?color=blue)](https://github.com/guilamu/gf-xlsx-csv-notifications/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE.txt) [![WordPress: 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org) [![PHP: 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

Converts XLSX files uploaded through Gravity Forms into CSV and attaches both the CSV and the original XLSX to your notification emails.

## XLSX to CSV Conversion

- Convert the first worksheet of every uploaded `.xlsx` file to CSV (choose another worksheet with a filter)
- Generate Excel-friendly output: semicolon (`;`) delimiter and UTF-8 BOM so accented characters open correctly
- Reuse cached conversions: a CSV is regenerated only when the source XLSX is newer
- Run everything locally with the bundled SimpleXLSX library (MIT) — no external service involved

## Notification Attachments

- Attach the generated CSV (and, by default, the original XLSX) to Gravity Forms notification emails
- Match single and multi-file upload fields, including GP File Upload Pro — with no dependency on it
- Target specific forms, notifications, or fields with 5 dedicated filters
- Trace every conversion and error through the Gravity Forms logging system

## Key Features

- **Zero Configuration:** Activate the plugin and every notification of forms with file upload fields is covered
- **Multilingual:** Works with content in any language
- **Translation-Ready:** All strings are internationalized (French translation included)
- **Secure:** Uploaded file URLs are resolved with path-traversal protection, confined to the Gravity Forms upload directory
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements

- Gravity Forms 2.5 or higher
- WordPress 5.8 or higher
- PHP 7.4 or higher

## Installation

1. Upload the `gf-xlsx-csv-notifications` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Nothing else to configure: XLSX files uploaded through any form with a file upload field are converted and attached to its notifications

## FAQ

### Does it require GP File Upload Pro?

No. The plugin hooks into the standard `gform_notification` filter and works with any Gravity Forms file upload field, single or multi-file. GP File Upload Pro is supported but not required.

### Can I enable the conversion for specific forms or notifications only?

Yes, use the `gf_xlsx_csv_notifications_enabled` filter:

```php
add_filter( 'gf_xlsx_csv_notifications_enabled', function( $enabled, $notification, $form ) {
    return (int) $form['id'] === 12; // only form 12
}, 10, 3 );
```

### Can I attach the CSV only, without the original XLSX?

Yes, use the `gf_xlsx_csv_notifications_attach_original` filter:

```php
add_filter( 'gf_xlsx_csv_notifications_attach_original', '__return_false' );
```

### Can I change the CSV delimiter or the converted worksheet?

Yes, use the `gf_xlsx_csv_notifications_delimiter` and `gf_xlsx_csv_notifications_sheet_index` filters:

```php
add_filter( 'gf_xlsx_csv_notifications_delimiter', function() { return ','; } );
add_filter( 'gf_xlsx_csv_notifications_sheet_index', function() { return 1; } ); // 0 = first sheet
```

### Can I exclude a specific upload field?

Yes, use the `gf_xlsx_csv_notifications_field_enabled` filter:

```php
add_filter( 'gf_xlsx_csv_notifications_field_enabled', function( $enabled, $field ) {
    return (int) $field->id !== 7;
}, 10, 2 );
```

### Where can I see what was converted or why a file was skipped?

Enable Gravity Forms logging (**Forms → Settings → Logging**): the plugin logs every attachment and every conversion error there.

## Limitations

- Only `.xlsx` files are converted; the legacy `.xls` format is not supported by SimpleXLSX
- One worksheet per CSV: the first sheet by default, or another one via the `gf_xlsx_csv_notifications_sheet_index` filter
- Conversion happens when a notification is sent, not at upload time

## Project Structure

```
.
├── gf-xlsx-csv-notifications.php          # Main plugin file
├── uninstall.php                          # Cleanup on uninstall
├── README.md
├── LICENSE.txt
├── includes
│   ├── class-github-updater.php           # GitHub auto-updates
│   ├── Parsedown.php                      # Markdown parser for the plugin details popup
│   └── SimpleXLSX.php                     # XLSX reader (shuchkin/simplexlsx, MIT)
└── languages
    ├── gf-xlsx-csv-notifications-fr_FR.mo # French translation (binary)
    ├── gf-xlsx-csv-notifications-fr_FR.po # French translation (source)
    └── gf-xlsx-csv-notifications.pot      # Translation template
```

## Changelog

### 1.0.0 - 2026-07-04
- Initial release
- XLSX to CSV conversion (first worksheet, semicolon delimiter, UTF-8 BOM) attached to Gravity Forms notifications
- Optional attachment of the original XLSX file
- Filters to target forms, notifications and fields, and to change delimiter and worksheet
- Automatic updates from GitHub releases

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/gf-xlsx-csv-notifications/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/gf-xlsx-csv-notifications).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE.txt) file for details.

---

Made with love for the WordPress community
