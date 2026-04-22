<?php
namespace CRE;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode {

	public function __construct() {
		add_shortcode( 'career_evaluator', [ $this, 'render_form' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets() {
		$css_path = CRE_PLUGIN_DIR . 'assets/css/style.css';
		$js_path  = CRE_PLUGIN_DIR . 'assets/js/script.js';

		$css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : CRE_VERSION;
		$js_ver  = file_exists( $js_path )  ? filemtime( $js_path )  : CRE_VERSION;

		// Load Stripe Official JS
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, false );

		wp_register_style( 'cre-style', CRE_PLUGIN_URL . 'assets/css/style.css', [], $css_ver );
		wp_register_script( 'cre-script', CRE_PLUGIN_URL . 'assets/js/script.js', [ 'jquery', 'stripe-js' ], $js_ver, true );
		
		wp_localize_script( 'cre-script', 'cre_vars', [
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'cre_form_nonce' ),
			'stripe_pub_key' => get_option( 'cre_stripe_pub_key' ) 
		] );
	}

	public function render_form() {
		wp_enqueue_style( 'cre-style' );
		wp_enqueue_script( 'cre-script' );

		// Adzuna Supported Countries
		$countries = [
			'us' => 'United States',
			'gb' => 'United Kingdom',
			'ca' => 'Canada',
			'au' => 'Australia',
			'nz' => 'New Zealand',
			'za' => 'South Africa',
			'in' => 'India',
			'fr' => 'France',
			'de' => 'Germany',
			'nl' => 'Netherlands',
			'it' => 'Italy',
			'es' => 'Spain',
			'br' => 'Brazil',
			'mx' => 'Mexico',
			'pl' => 'Poland',
			'ru' => 'Russia',
			'sg' => 'Singapore'
		];

		ob_start();
		?>
		<div class="cre-main-container">
			<div class="cre-bg-circle cre-bg-circle-1"></div>
			<div class="cre-bg-circle cre-bg-circle-2"></div>
			
			<div id="cre-plugin-wrapper" class="cre-content-panel">
				<div class="cre-header">
					<h2>Lumenpath Career Roadmap</h2>
					<p class="cre-subheadline">Know Your Next Career Move — With Data, Not Guesswork.</p>
					<p id="cre-form-desc">Answer a few questions about your future goals and work preferences so Lumenpath can map your skills into higher-paying, future-ready roles.</p>
				</div>
				<form id="cre-form" enctype="multipart/form-data">
					
					<div style="display:none; position:absolute; left:-9999px;">
						<label for="cre_bot_catch">Leave this field empty</label>
						<input type="text" id="cre_bot_catch" name="cre_bot_catch" tabindex="-1" autocomplete="off">
					</div>

					<div class="cre-step-group">
						<div class="cre-step">
							<label for="cre_email">Where should we send your roadmap?</label>
							<input type="email" id="cre_email" name="cre_email" placeholder="you@example.com" required>
						</div>

						<div class="cre-step">
							<label for="cre_work_setup">Preferred Work Setup</label>
							<select id="cre_work_setup" name="cre_work_setup" required>
								<option value="Remote">Remote</option>
								<option value="Hybrid">Hybrid</option>
								<option value="In-Office">In-Office</option>
							</select>
						</div>
					</div>

					<div class="cre-step">
						<label for="cre_location">Where You Want to Work From</label>
						<select id="cre_location" name="cre_location" required>
							<option value="" disabled selected>Select Region</option>
							<?php foreach ( $countries as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="cre-row-2">
						<div class="cre-step">
							<label for="cre_current_salary">Current Pay <span class="cre-optional-hint">(optional)</span></label>
							<div class="cre-input-icon">
								<span>$</span>
								<input type="number" id="cre_current_salary" name="cre_current_salary" placeholder="0.00">
							</div>
						</div>
						<div class="cre-step">
							<label for="cre_salary">8-Year Target Pay</label>
							<div class="cre-input-icon">
								<span>$</span>
								<input type="number" id="cre_salary" name="cre_salary" placeholder="250000" required>
							</div>
						</div>
					</div>

					<div class="cre-step">
						<label for="cre_additional_skills">Additional Skills <span class="cre-optional-hint">(optional)</span></label>
						<input type="text" id="cre_additional_skills" name="cre_additional_skills" placeholder="Skills not on your resume">
					</div>

					<div class="cre-step">
						<label for="cre_exclude">Restrictions / Preferences <span class="cre-optional-hint">(optional)</span></label>
						<input type="text" id="cre_exclude" name="cre_exclude" placeholder="e.g., No relocation, 2 weeks notice, remote only">
					</div>

				<div class="cre-step cre-upload-area" id="cre-upload-area">
                        <label for="cre_resume" class="cre-upload-label">
                            <span class="cre-icon" id="cre-upload-icon">📄</span>
                            <span class="cre-text" id="cre-upload-label-text">Click to upload Resume (DOCX only)</span>
                            <input type="file" id="cre_resume" name="resume" accept=".docx" required>
                        </label>
                        
                        <p style="font-size: 0.85em; color: #64748b; margin-top: 10px; text-align: center;">
                            <em>Note: Please upload a standard professional resume. Comprehensive or academic CVs are not supported.</em>
                        </p>

                        <div id="cre-upload-progress">
                            <div class="bar"></div>
                        </div>
                        <div id="cre-upload-success"></div>
                    </div>

					<div class="cre-privacy-note">
						🔒 Your resume is safe with us — we never share any information, and you can upload anonymously (if no name is provided, it will appear as John Doe).
					</div>

					<button type="submit" id="cre-submit-btn" class="cre-submit-btn" disabled>GENERATE MY CUSTOMIZED ROADMAP</button>
				</form>

				<div id="cre-loading" style="display:none;">
					<div class="cre-spinner"></div>
					<p>Your profile is being analyzed to create a personalized roadmap for you. Please wait a moment while we prepare everything...</p>
				</div>
				
				<div id="cre-results"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}