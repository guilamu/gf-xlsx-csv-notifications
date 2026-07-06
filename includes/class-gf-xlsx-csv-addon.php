<?php
/**
 * Gravity Forms add-on: per-form settings page for XLSX → CSV conversion.
 *
 * Adds a "XLSX to CSV" tab under each form's settings
 * (admin.php?page=gf_edit_forms&view=settings&id=FORM_ID) exposing, for every
 * file upload field detected in the form, the options that used to live behind
 * global filters:
 *
 *   - attach the CSV only, without the original XLSX;
 *   - the CSV delimiter;
 *   - the converted worksheet.
 *
 * @package GF_Xlsx_Csv_Notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_addon_framework();

/**
 * Settings add-on.
 */
class GF_Xlsx_Csv_AddOn extends GFAddOn {

	protected $_version                    = GF_XLSX_CSV_VERSION;
	protected $_min_gravityforms_version   = '2.5';
	protected $_slug                       = 'gf-xlsx-csv-notifications';
	protected $_path                       = GF_XLSX_CSV_BASENAME;
	protected $_full_path                  = __FILE__;
	protected $_title                      = 'Gravity Forms - XLSX to CSV Notifications';
	protected $_short_title                = 'XLSX to CSV';

	protected $_capabilities               = array( 'gravityforms_edit_forms' );
	protected $_capabilities_form_settings = array( 'gravityforms_edit_forms' );
	protected $_capabilities_uninstall     = array( 'gravityforms_uninstall' );

	private static $_instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return GF_Xlsx_Csv_AddOn
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Admin-only setup: lay the per-field settings out as three columns.
	 *
	 * @return void
	 */
	public function init_admin() {
		parent::init_admin();
		add_action( 'admin_head', array( $this, 'print_form_settings_styles' ) );
	}

	/**
	 * Print the CSS that renders each field section as three equal columns.
	 *
	 * Scoped to this add-on's form settings tab so it never leaks elsewhere.
	 *
	 * @return void
	 */
	public function print_form_settings_styles() {

		if ( 'gf_edit_forms' !== rgget( 'page' ) || 'settings' !== rgget( 'view' ) || $this->_slug !== rgget( 'subview' ) ) {
			return;
		}
		?>
		<style>
			.gf-xlsx-csv-row .gform-settings-panel__content {
				display: flex;
				flex-wrap: wrap;
				gap: 24px;
				align-items: flex-start;
			}
			.gf-xlsx-csv-row .gform-settings-panel__content > .gform-settings-field {
				flex: 1 1 0;
				min-width: 0;
				margin: 0;
			}
			.gf-xlsx-csv-row .gform-settings-panel__content > .gform-settings-field select,
			.gf-xlsx-csv-row .gform-settings-panel__content input[type="number"] {
				width: 100%;
			}
		</style>
		<?php
	}

	/**
	 * Build the form settings tab: one section per file upload field.
	 *
	 * @param array $form The form being edited.
	 *
	 * @return array
	 */
	public function form_settings_fields( $form ) {

		$upload_fields = GF_Xlsx_Csv_Notifications::get_upload_fields( $form );

		if ( empty( $upload_fields ) ) {
			return array(
				array(
					'title'  => esc_html__( 'XLSX to CSV', 'gf-xlsx-csv-notifications' ),
					'fields' => array(
						array(
							'name' => 'no_upload_fields',
							'type' => 'html',
							'html' => '<p>' . esc_html__( 'This form has no file upload field. Add one to configure the XLSX → CSV conversion.', 'gf-xlsx-csv-notifications' ) . '</p>',
						),
					),
				),
			);
		}

		$sections = array();

		foreach ( $upload_fields as $field ) {

			$fid   = (int) $field->id;
			$label = $field->get_field_label( false, '' );
			if ( '' === trim( (string) $label ) ) {
				$label = esc_html__( 'Upload field', 'gf-xlsx-csv-notifications' );
			}

			$sections[] = array(
				'title'  => sprintf(
					/* translators: 1: field label, 2: field ID. */
					esc_html__( 'XLSX to CSV — %1$s (field #%2$d)', 'gf-xlsx-csv-notifications' ),
					esc_html( $label ),
					$fid
				),
				'class'  => 'gf-xlsx-csv-row',
				'fields' => array(
					array(
						'name'    => 'attach_csv_only_' . $fid,
						'type'    => 'checkbox',
						'label'   => esc_html__( 'Attachment', 'gf-xlsx-csv-notifications' ),
						'tooltip' => esc_html__( 'When enabled, only the converted CSV is attached to notifications. When disabled, both the CSV and the original XLSX are attached.', 'gf-xlsx-csv-notifications' ),
						'choices' => array(
							array(
								'name'  => 'attach_csv_only_' . $fid,
								'label' => esc_html__( 'CSV only (drop XLSX)', 'gf-xlsx-csv-notifications' ),
							),
						),
					),
					array(
						'name'          => 'delimiter_' . $fid,
						'type'          => 'select',
						'label'         => esc_html__( 'CSV delimiter', 'gf-xlsx-csv-notifications' ),
						'tooltip'       => esc_html__( 'Character used to separate the columns in the generated CSV.', 'gf-xlsx-csv-notifications' ),
						'default_value' => ';',
						'choices'       => array(
							array(
								'value' => ';',
								'label' => esc_html__( 'Semicolon ( ; )', 'gf-xlsx-csv-notifications' ),
							),
							array(
								'value' => ',',
								'label' => esc_html__( 'Comma ( , )', 'gf-xlsx-csv-notifications' ),
							),
							array(
								'value' => 'tab',
								'label' => esc_html__( 'Tab', 'gf-xlsx-csv-notifications' ),
							),
							array(
								'value' => '|',
								'label' => esc_html__( 'Pipe ( | )', 'gf-xlsx-csv-notifications' ),
							),
						),
					),
					array(
						'name'          => 'worksheet_' . $fid,
						'type'          => 'text',
						'label'         => esc_html__( 'Converted worksheet', 'gf-xlsx-csv-notifications' ),
						'tooltip'       => esc_html__( 'Number of the worksheet to convert (1 = first sheet). Falls back to the first sheet if the number does not exist.', 'gf-xlsx-csv-notifications' ),
						'default_value' => '1',
						'class'         => 'small',
						'input_type'    => 'number',
					),
				),
			);
		}

		return $sections;
	}
}
