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
		wp_register_style( 'cre-style', CRE_PLUGIN_URL . 'assets/css/style.css', [], CRE_VERSION );
		wp_register_script( 'cre-script', CRE_PLUGIN_URL . 'assets/js/script.js', [ 'jquery' ], CRE_VERSION, true );
		
		wp_localize_script( 'cre-script', 'cre_vars', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cre_form_nonce' ),
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
					<h2>Lumen Path Career Roadmap</h2>
					<p class="cre-subheadline">Know Your Next Career Move — With Data, Not Guesswork.</p>
					<p>Answer a few questions about your future goals and work preferences so Lumen can map your skills into higher-paying, future-ready roles.</p>
				</div>
				<form id="cre-form" enctype="multipart/form-data">
					
					<!-- Personal & Contact -->
					<div class="cre-step-group">
						<div class="cre-step">
							<label for="cre_email">Email</label>
							<input type="email" id="cre_email" name="cre_email" placeholder="you@example.com" required>
						</div>

						<div class="cre-step">
							<label for="cre_work_setup">Setup</label>
							<select id="cre_work_setup" name="cre_work_setup" required>
								<option value="Remote">Remote</option>
								<option value="Hybrid">Hybrid</option>
								<option value="In-Office">In-Office</option>
							</select>
						</div>
					</div>

					<div class="cre-step">
						<label for="cre_location">Location</label>
						<select id="cre_location" name="cre_location" required>
							<option value="" disabled selected>Select Region</option>
							<?php foreach ( $countries as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<!-- Financial Goals -->
					<div class="cre-row-2">
						<div class="cre-step">
							<label for="cre_current_salary">Current Pay</label>
							<div class="cre-input-icon">
								<span>$</span>
								<input type="number" id="cre_current_salary" name="cre_current_salary" placeholder="0.00">
							</div>
						</div>
						<div class="cre-step">
							<label for="cre_salary">Target Pay (Yr 8)</label>
							<div class="cre-input-icon">
								<span>$</span>
								<input type="number" id="cre_salary" name="cre_salary" placeholder="250000" required>
							</div>
						</div>
					</div>

					<!-- Pivot Goals -->
					<div class="cre-step">
						<label for="cre_roles">Interests</label>
						<input type="text" id="cre_roles" name="cre_roles" placeholder="Tech, Product, Finance..." required>
					</div>

					<div class="cre-step">
						<label for="cre_exclude">Constraints</label>
						<input type="text" id="cre_exclude" name="cre_exclude" placeholder="No relocation, etc." required>
					</div>

					<!-- Resume Upload -->
					<div class="cre-step cre-upload-area">
						<label for="cre_resume" class="cre-upload-label">
							<span class="cre-icon">📄</span>
							<span class="cre-text">Click to upload Resume (DOCX only)</span>
							<input type="file" id="cre_resume" name="resume" accept=".docx" required>
						</label>
						<div id="cre-upload-progress">
							<div class="bar"></div>
						</div>
						<div id="cre-upload-success" style="display:none; margin-top:10px; color: #16a34a; font-weight:600; font-size: 0.9rem;">
							✅ Resume Uploaded Successfully
						</div>
					</div>

					<button type="submit" id="cre-submit-btn" class="cre-submit-btn" disabled>Generate Roadmap</button>
				</form>

				<div id="cre-loading" style="display:none;">
					<div class="cre-spinner"></div>
					<p>Analyzing...</p>
				</div>
				
				<div id="cre-results"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}