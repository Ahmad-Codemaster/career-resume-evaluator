<?php
/**
 * Core plugin class.
 *
 * Registers hooks for the shortcode, admin settings, asset enqueueing,
 * and the AJAX handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRE_Resume_Evaluator {

	/**
	 * Register all WordPress hooks.
	 */
	public function init() {
		add_shortcode( 'career_resume_evaluator', array( $this, 'render_form' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		add_action( 'wp_ajax_cre_evaluate', array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_cre_evaluate', array( $this, 'handle_ajax' ) );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Render the frontend evaluation form.
	 *
	 * @return string HTML output.
	 */
	public function render_form() {
		ob_start();
		include CRE_PLUGIN_DIR . 'templates/form-template.php';
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue front-end CSS and JS.
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'cre-style',
			CRE_PLUGIN_URL . 'assets/css/style.css',
			array(),
			CRE_VERSION
		);

		wp_enqueue_script(
			'cre-script',
			CRE_PLUGIN_URL . 'assets/js/script.js',
			array( 'jquery' ),
			CRE_VERSION,
			true
		);

		wp_localize_script(
			'cre-script',
			'CRE',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cre_evaluate_nonce' ),
				'strings'  => array(
					'analyzing'    => __( 'Analyzing your resume…', 'career-resume-evaluator' ),
					'error_upload' => __( 'Please upload a DOCX file and enter your career goals.', 'career-resume-evaluator' ),
					'error_type'   => __( 'Only DOCX files are allowed.', 'career-resume-evaluator' ),
					'server_error' => __( 'An error occurred. Please try again.', 'career-resume-evaluator' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Admin Settings
	// -------------------------------------------------------------------------

	/**
	 * Register the plugin settings page under Settings menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Career Resume Evaluator', 'career-resume-evaluator' ),
			__( 'Resume Evaluator', 'career-resume-evaluator' ),
			'manage_options',
			'career-resume-evaluator',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin options.
	 */
	public function register_settings() {
		register_setting(
			'cre_settings_group',
			'cre_grok_api_key',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'cre_settings_group',
			'cre_grok_model',
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => CRE_Grok_API::DEFAULT_MODEL,
			)
		);

		add_settings_section(
			'cre_main_section',
			__( 'API Configuration', 'career-resume-evaluator' ),
			null,
			'career-resume-evaluator'
		);

		add_settings_field(
			'cre_grok_api_key',
			__( 'xAI Grok API Key', 'career-resume-evaluator' ),
			array( $this, 'render_api_key_field' ),
			'career-resume-evaluator',
			'cre_main_section'
		);

		add_settings_field(
			'cre_grok_model',
			__( 'Model', 'career-resume-evaluator' ),
			array( $this, 'render_model_field' ),
			'career-resume-evaluator',
			'cre_main_section'
		);
	}

	/**
	 * Render the API key input field.
	 */
	public function render_api_key_field() {
		$value = get_option( 'cre_grok_api_key', '' );
		printf(
			'<input type="password" id="cre_grok_api_key" name="cre_grok_api_key" value="%s" class="regular-text" autocomplete="off" />
			<p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'Enter your xAI Grok API key. Obtain one at https://console.x.ai/', 'career-resume-evaluator' )
		);
	}

	/**
	 * Render the model input field.
	 */
	public function render_model_field() {
		$value = get_option( 'cre_grok_model', CRE_Grok_API::DEFAULT_MODEL );
		printf(
			'<input type="text" id="cre_grok_model" name="cre_grok_model" value="%s" class="regular-text" />
			<p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'xAI model to use, e.g. grok-3-latest.', 'career-resume-evaluator' )
		);
	}

	/**
	 * Render the settings page HTML.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Career Resume Evaluator Settings', 'career-resume-evaluator' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'cre_settings_group' );
				do_settings_sections( 'career-resume-evaluator' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Handler
	// -------------------------------------------------------------------------

	/**
	 * Handle the AJAX evaluation request.
	 */
	public function handle_ajax() {
		check_ajax_referer( 'cre_evaluate_nonce', 'nonce' );

		if ( empty( $_FILES['resume'] ) || empty( $_POST['career_goals'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a resume and career goals.', 'career-resume-evaluator' ) ) );
		}

		$file        = $_FILES['resume'];
		$career_goals = sanitize_textarea_field( wp_unslash( $_POST['career_goals'] ) );

		// Validate file type by MIME type and extension.
		$allowed_mime  = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$file_ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( 'docx' !== $file_ext ) {
			wp_send_json_error( array( 'message' => __( 'Only DOCX files are accepted.', 'career-resume-evaluator' ) ) );
		}

		// Use wp_check_filetype_and_ext for additional validation.
		$wp_filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( ! empty( $wp_filetype['ext'] ) && 'docx' !== $wp_filetype['ext'] ) {
			wp_send_json_error( array( 'message' => __( 'Only DOCX files are accepted.', 'career-resume-evaluator' ) ) );
		}

		// Move uploaded file to a temporary location managed by WordPress.
		$upload_dir  = wp_upload_dir();
		$tmp_dir     = trailingslashit( $upload_dir['basedir'] ) . 'cre-tmp/';

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not create temporary directory.', 'career-resume-evaluator' ) ) );
		}

		$tmp_file = $tmp_dir . wp_unique_filename( $tmp_dir, sanitize_file_name( $file['name'] ) );

		if ( ! move_uploaded_file( $file['tmp_name'], $tmp_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save uploaded file.', 'career-resume-evaluator' ) ) );
		}

		// Parse DOCX.
		$parser      = new CRE_Docx_Parser();
		$resume_text = $parser->parse( $tmp_file );

		// Remove temp file immediately after parsing.
		@unlink( $tmp_file );

		if ( is_wp_error( $resume_text ) ) {
			wp_send_json_error( array( 'message' => $resume_text->get_error_message() ) );
		}

		// Call Grok API.
		$api_key = get_option( 'cre_grok_api_key', '' );
		$api     = new CRE_Grok_API();
		$careers = $api->analyze( $resume_text, $career_goals, $api_key );

		if ( is_wp_error( $careers ) ) {
			wp_send_json_error( array( 'message' => $careers->get_error_message() ) );
		}

		wp_send_json_success( array( 'careers' => $careers ) );
	}
}
