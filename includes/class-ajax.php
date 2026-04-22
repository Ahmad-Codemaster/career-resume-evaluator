<?php
namespace CRE;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_cre_process_form', [ $this, 'process_form' ] );
		add_action( 'wp_ajax_nopriv_cre_process_form', [ $this, 'process_form' ] );
		
		add_action( 'wp_ajax_cre_send_email', [ $this, 'send_email_ajax' ] );
		add_action( 'wp_ajax_nopriv_cre_send_email', [ $this, 'send_email_ajax' ] );

		add_action( 'wp_ajax_cre_create_payment_intent', [ $this, 'create_payment_intent' ] );
		add_action( 'wp_ajax_nopriv_cre_create_payment_intent', [ $this, 'create_payment_intent' ] );
		add_action( 'wp_ajax_cre_unlock_report', [ $this, 'unlock_report' ] );
		add_action( 'wp_ajax_nopriv_cre_unlock_report', [ $this, 'unlock_report' ] );
		add_action( 'wp_ajax_cre_restore_report', [ $this, 'restore_report' ] );
		add_action( 'wp_ajax_nopriv_cre_restore_report', [ $this, 'restore_report' ] );
	}

	public function process_form() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );

		// 0. Anti-Spam Check
		if ( ! empty( $_POST['cre_bot_catch'] ) ) {
			wp_send_json_error( 'Spam detected. Submission blocked.' );
		}

		// 1. Handle File Upload
		if ( empty( $_FILES['resume'] ) ) {
			wp_send_json_error( 'No resume uploaded.' );
		}

		$file_name = $_FILES['resume']['name'];
		$file_info = wp_check_filetype( $file_name );
		
		if ( 'docx' !== $file_info['ext'] ) {
			wp_send_json_error( 'Only .docx files are allowed.' );
		}

		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		require_once( ABSPATH . 'wp-admin/includes/media.php' );

		$attachment_id = media_handle_upload( 'resume', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( 'File upload failed: ' . $attachment_id->get_error_message() );
		}

		$file_path = get_attached_file( $attachment_id );

		// 2. Extract Text
		$parser = new File_Parser();
		$resume_text = $parser->extract_text( $file_path );
		
		// Map POST data
		$data = [
			'location'          => sanitize_text_field( $_POST['location'] ?? '' ),
			'work_setup'        => sanitize_text_field( $_POST['work_setup'] ?? '' ),
			'cur_salary'        => sanitize_text_field( $_POST['cur_salary'] ?? '' ),
			'tgt_salary'        => sanitize_text_field( $_POST['tgt_salary'] ?? '' ),
			'currency'          => sanitize_text_field( $_POST['currency'] ?? 'USD' ),
			'additional_skills' => sanitize_text_field( $_POST['additional_skills'] ?? '' ),
			'exclude'           => sanitize_text_field( $_POST['exclude'] ?? '' ),
			'email'             => sanitize_email( $_POST['email'] ?? '' ),
		];

		// 3. Two-step Grok pipeline
		$api_key = get_option( 'cre_grok_api_key' );
		$model   = get_option( 'cre_grok_model', 'grok-3' );

		// Step A: Resume grounding / classification
		$grounding = null;
		$grounding_prompt  = $this->build_grounding_prompt( $resume_text, $data['location'], $data['work_setup'] );
		$grounding_response = $this->call_grok_api( $grounding_prompt, $api_key, $model );
		if ( ! is_wp_error( $grounding_response ) ) {
			$grounding_text = $this->extract_ai_text( $grounding_response );
			$grounding      = $this->extract_json( $grounding_text );
		}
		// $grounding may be null if Step A failed — build_prompt handles the fallback gracefully.

		// Step B: Full career roadmap using grounding context
		$prompt   = $this->build_prompt( $data, $resume_text, $grounding );
		$response = $this->call_grok_api( $prompt, $api_key, $model );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'API Request Failed: ' . $response->get_error_message() );
		}

		$ai_text     = $this->extract_ai_text( $response );
		$data_parsed = $this->extract_json( $ai_text );

		if ( ! $data_parsed ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'CRE: Failed to parse AI response. Raw text: ' . substr( $ai_text, 0, 500 ) );
			}
			wp_send_json_error( 'Failed to parse AI response. Please try again.' );
		}

		// Schema validation
		if ( ! isset( $data_parsed['profile'] ) || ! isset( $data_parsed['analysis'] ) || ! isset( $data_parsed['careers'] ) || ! is_array( $data_parsed['careers'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'CRE: AI response missing required keys. Keys found: ' . implode( ', ', array_keys( $data_parsed ) ) );
			}
			wp_send_json_error( 'The AI returned an incomplete response. Please try again.' );
		}

		if ( empty( $data_parsed['careers'] ) ) {
			wp_send_json_error( 'The AI did not return any career paths. Please try again.' );
		}

		// 5. The Kill-Switch Validation
		if ( isset( $data_parsed['analysis']['summary'] ) && strpos( strtolower($data_parsed['analysis']['summary']), 'not a valid resume' ) !== false ) {
			wp_send_json_error( 'The uploaded document does not appear to be a valid professional resume. Please upload a standard resume with work history.' );
		}

		// 6. Fetch Adzuna Jobs
		$adzuna_jobs = [];
		if ( ! empty( $data_parsed['careers'] ) && ! empty( $data['location'] ) ) {
			foreach ( array_slice( $data_parsed['careers'], 0, 3 ) as $career_path ) {
				$search_role = $career_path['entry_role_title'] ?? $career_path['role'] ?? '';
				if ( ! empty( $search_role ) ) {
					$path_jobs = $this->fetch_adzuna_jobs( $data['location'], $search_role, 3 );
					if ( ! empty( $path_jobs ) ) {
						$adzuna_jobs[ $search_role ] = $path_jobs;
					}
				}
			}
		}

		$candidate_name = $data_parsed['profile']['name'] ?? 'Candidate';
		
		// Stores in WordPress Database Table
		require_once CRE_PLUGIN_DIR . 'includes/class-db.php';
		DB::insert_submission( $candidate_name, $data['email'], $attachment_id, $data, $data_parsed, $adzuna_jobs );

		// ---> 1. GENERATE THE SESSION AND REPORT URL FIRST <---
		$payments_enabled = get_option('cre_enable_payments', 'no'); 
		$is_teaser = ($payments_enabled === 'yes');

		$session_id = wp_generate_uuid4(); 
		
		$premium_data = [
			'raw_data'    => $data_parsed,
			'adzuna_jobs' => $adzuna_jobs,
			'user_email'  => $data['email'],
			'currency'    => $data['currency'],
			'is_unlocked' => !$is_teaser 
		];
		
		update_option( 'cre_report_' . $session_id, $premium_data, false );

		$base_url = esc_url_raw( $_POST['current_url'] ?? home_url() );
		$report_url = add_query_arg( 'cre_report', $session_id, $base_url );
		
		// ---> 2. SEND MAGIC LINK TO THE USER <---
		$this->send_magic_link_email( $data['email'], $data_parsed, $report_url );

		// ---> 3. SEND ADMIN EMAIL (NOW WITH DIRECT LINK INCLUDED) <---
		$admin_email = get_option( 'admin_email' );
		$admin_subject = 'New Career Roadmap Generated: ' . $candidate_name;
		$admin_message = "
			<h3>A new career roadmap was just generated on your website!</h3>
			<p><strong>Candidate Name:</strong> " . esc_html( $candidate_name ) . "</p>
			<p><strong>Candidate Email:</strong> " . esc_html( $data['email'] ) . "</p>
			<p><strong>Direct Report Link:</strong> <a href='" . esc_url( $report_url ) . "'>Click here to view their generated roadmap</a></p>
			<hr>
			<p><small>You can also view their full resume and data in your WordPress Dashboard under <strong>Career Evaluator &rarr; Submissions</strong>.</small></p>
		";
		$admin_headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		wp_mail( $admin_email, $admin_subject, $admin_message, $admin_headers );

		// ---> 4. RENDER RESULTS TO THE SCREEN <---
		$html = $this->render_results( $data_parsed, $adzuna_jobs, $data['email'], $data['currency'], $is_teaser );

		wp_send_json_success( [
			'html'       => $html,
			'session_id' => $session_id,
			'is_locked'  => $is_teaser
		] );
	}

	public function create_payment_intent() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );

		// Guard Clause for Payment Toggle
		if ( get_option('cre_enable_payments', 'no') !== 'yes' ) {
			wp_send_json_error( 'Payments are currently disabled.' );
		}

		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
		$stripe_secret = get_option( 'cre_stripe_secret_key' );

		if ( empty( $stripe_secret ) ) {
			wp_send_json_error( 'Payment Gateway is not configured.' );
		}

		$premium_data = get_option( 'cre_report_' . $session_id );
		if ( ! $premium_data ) {
			wp_send_json_error( 'Report not found. Please generate a new one.' );
		}

		$currency = strtolower( $premium_data['currency'] ?? 'usd' );
		$amount = 100; // 100 cents = $1.00

		$response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
			'headers' => [
				'Authorization' => 'Bearer ' . $stripe_secret,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'amount'   => $amount,
				'currency' => $currency,
				'automatic_payment_methods[enabled]' => 'true',
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Stripe API Connection Error.' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['client_secret'] ) ) {
			wp_send_json_success( [ 'client_secret' => $body['client_secret'] ] );
		} else {
			wp_send_json_error( $body['error']['message'] ?? 'Unable to initialize checkout.' );
		}
	}

	public function unlock_report() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );

		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

		if ( empty( $session_id ) ) {
			wp_send_json_error( 'Invalid session.' );
		}

		$premium_data = get_option( 'cre_report_' . $session_id );

		if ( ! $premium_data ) {
			wp_send_json_error( 'Report not found. Please generate a new one.' );
		}

		$premium_data['is_unlocked'] = true;
		update_option( 'cre_report_' . $session_id, $premium_data, false );

		$full_html = $this->render_results( 
			$premium_data['raw_data'], 
			$premium_data['adzuna_jobs'], 
			$premium_data['user_email'], 
			$premium_data['currency'], 
			false 
		);

		wp_send_json_success( [
			'html' => $full_html,
			'raw_data' => $premium_data['raw_data'],
			'adzuna_jobs' => $premium_data['adzuna_jobs'],
			'user_email' => $premium_data['user_email']
		] );
	}

	public function restore_report() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );

		$session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

		if ( empty( $session_id ) ) {
			wp_send_json_error( 'Invalid session.' );
		}

		$premium_data = get_option( 'cre_report_' . $session_id );

		if ( ! $premium_data ) {
			wp_send_json_error( 'Report not found. Please generate a new roadmap.' );
		}

		// Allow bypass if payments were disabled globally
		$payments_enabled = get_option('cre_enable_payments', 'no');
		$is_unlocked = isset( $premium_data['is_unlocked'] ) && $premium_data['is_unlocked'];
		if ( $payments_enabled !== 'yes' ) {
			$is_unlocked = true; 
		}

		$html = $this->render_results( 
			$premium_data['raw_data'], 
			$premium_data['adzuna_jobs'], 
			$premium_data['user_email'], 
			$premium_data['currency'], 
			!$is_unlocked 
		);

		wp_send_json_success( [
			'html'        => $html,
			'session_id'  => $session_id,
			'is_unlocked' => $is_unlocked,
			'raw_data'    => $premium_data['raw_data'],
			'adzuna_jobs' => $premium_data['adzuna_jobs'],
			'user_email'  => $premium_data['user_email']
		] );
	}

	public function send_email_ajax() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );
		$email = sanitize_email( $_POST['email'] ?? '' );
		$raw_data = isset($_POST['raw_data']) ? map_deep( $_POST['raw_data'], 'sanitize_text_field' ) : [];
		$report_url = esc_url_raw( $_POST['report_url'] ?? '' );

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( 'Invalid email address.' );
		}

		$sent = $this->send_magic_link_email( $email, $raw_data, $report_url );

		if ( $sent ) {
			wp_send_json_success( 'Email sent successfully!' );
		} else {
			wp_send_json_error( 'Failed to send email. Please check server settings.' );
		}
	}

	private function send_magic_link_email( $email, $raw_data, $report_url ) {
		$name = $raw_data['profile']['name'] ?? 'Candidate';
		$role = $raw_data['careers'][0]['entry_role_title'] ?? $raw_data['careers'][0]['role'] ?? 'N/A';

		ob_start();
		?>
		<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #334155; line-height: 1.6;">
			<h2 style="color: #0f172a;">Hello <?php echo esc_html( $name ); ?>,</h2>
			<p>Congratulations on taking the first step toward your new career!</p>
			<p>Your <strong>Personalized Career Roadmap</strong> has been securely saved. You can access your full report, including skill gaps and job matches, at any time.</p>
			
			<div style="background-color: #f1f5f9; padding: 15px; border-left: 4px solid #2563eb; margin: 20px 0;">
				<strong>Top Suggested Role:</strong> <?php echo esc_html( $role ); ?>
			</div>

			<p style="text-align: center; margin-top: 30px;">
				<a href="<?php echo esc_url( $report_url ); ?>" style="display: inline-block; padding: 14px 28px; background-color: #2563eb; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;">View My Personalized Roadmap</a>
			</p>
			
			<p style="margin-top: 40px; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 15px;">
				<em>Generated by Lumenpath Career Evaluator. Please save this email for future reference.</em>
			</p>
		</div>
		<?php
		$message = ob_get_clean();
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		return wp_mail( $email, 'Your Personalized Career Roadmap', $message, $headers );
	}

	private function fetch_adzuna_jobs( $country, $query, $limit = 3 ) {
		$app_id = get_option( 'cre_adzuna_app_id' );
		$app_key = get_option( 'cre_adzuna_app_key' );

		if ( empty( $app_id ) || empty( $app_key ) ) {
			return [];
		}

		$safe_query = urlencode( strtolower( trim( $query ) ) );
		
		$url = "https://api.adzuna.com/v1/api/jobs/{$country}/search/1";
		$args = [
			'app_id'           => $app_id,
			'app_key'          => $app_key,
			'results_per_page' => $limit,
			'what'             => $safe_query,
			'content-type'     => 'application/json',
		];

		$url = add_query_arg( $args, $url );
		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) return [];

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['results'] ?? [];
	}

	// ---> HELPER: Make a Grok API call <---
	private function call_grok_api( $prompt, $api_key, $model ) {
		return wp_remote_post( 'https://api.x.ai/v1/chat/completions', [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body'    => json_encode( [
				'model'       => $model,
				'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
				'temperature' => 0.1,
			] ),
			'timeout' => 120,
		] );
	}

	// ---> HELPER: Extract the text content from a Grok API response <---
	private function extract_ai_text( $response ) {
		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );
		return $result['choices'][0]['message']['content'] ?? '';
	}

	// ---> HELPER: Robustly extract a JSON object from AI text <---
	private function extract_json( $text ) {
		// Strip markdown code fences (e.g. ```json ... ```)
		$cleaned = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
		$cleaned = preg_replace( '/\s*```\s*$/i', '', $cleaned );
		$cleaned = trim( $cleaned );

		// Try direct decode first
		$decoded = json_decode( $cleaned, true );
		if ( $decoded !== null ) {
			return $decoded;
		}

		// Attempt to extract the first JSON object from the text
		if ( preg_match( '/\{.*\}/s', $cleaned, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( $decoded !== null ) {
				return $decoded;
			}
		}

		return null;
	}

	// ---> STEP A: Resume grounding / classification prompt <---
	private function build_grounding_prompt( $resume_text, $location, $work_setup ) {
		return "You are a professional resume analyst. Read the resume below and output ONLY a valid JSON object — no explanation, no markdown.

JSON structure (use exactly these keys):
{
  \"domain\": \"Primary career domain e.g. Performance Marketing, Software Engineering, Finance\",
  \"subdomains\": [\"subdomain1\", \"subdomain2\"],
  \"seniority\": \"junior or mid or senior or lead or executive\",
  \"likely_current_roles\": [\"Most likely job title 1\", \"Most likely job title 2\"],
  \"strongest_skills\": [\"Skill 1\", \"Skill 2\", \"Skill 3\", \"Skill 4\", \"Skill 5\"],
  \"confidence\": 85
}

Context:
Location: {$location}
Work Setup: {$work_setup}

RESUME:
{$resume_text}";
	}

	// ---> STEP B: Full career roadmap prompt (resume-first, grounding-aware) <---
	private function build_prompt( $data, $resume_text, $grounding = null ) {
		$currency = $data['currency'] ?? 'USD';

		// Build grounding block if Step A succeeded
		$grounding_block = '';
		if ( ! empty( $grounding ) && is_array( $grounding ) ) {
			$subdomains   = is_array( $grounding['subdomains'] ?? null )       ? implode( ', ', $grounding['subdomains'] )       : '';
			$current_roles = is_array( $grounding['likely_current_roles'] ?? null ) ? implode( ', ', $grounding['likely_current_roles'] ) : '';
			$skills       = is_array( $grounding['strongest_skills'] ?? null ) ? implode( ', ', $grounding['strongest_skills'] ) : '';

			$grounding_block = "
RESUME GROUNDING DATA (authoritative resume analysis — highest priority):
Domain: " . ( $grounding['domain'] ?? 'Unknown' ) . "
Subdomains: {$subdomains}
Seniority: " . ( $grounding['seniority'] ?? 'Unknown' ) . "
Likely Current Roles: {$current_roles}
Strongest Skills: {$skills}
---";
		}

		return "SYSTEM ROLE:
You are a strict, logic-driven Career Strategy Engine.
{$grounding_block}
*** CRITICAL DIRECTIVE: RESUME-FIRST PROTOCOL ***
1. The candidate's Resume (and the Grounding Data above, if present) are the ONLY absolute sources of truth.
2. Additional Skills and Restrictions below are soft context hints only — use them only if they are compatible with the resume.
3. Build all 3 career paths strictly from the resume-grounded domain and skills. Do NOT invent paths unrelated to the resume evidence.
4. If any soft hints were ignored or adjusted, state exactly why in analysis.summary.

---
INPUT DATA:
Location: {$data['location']}
Work Setup: {$data['work_setup']}
Current Salary: {$data['cur_salary']} {$currency}
Target Salary Year 8: {$data['tgt_salary']} {$currency}
Additional Skills (soft hint): {$data['additional_skills']}
Restrictions / Preferences (soft hint): {$data['exclude']}

RESUME:
{$resume_text}
---

EXECUTION STEPS:
STEP 1: Validate that this is a real professional resume. If not, set analysis.summary to include the phrase 'not a valid resume'.
STEP 2: Extract the candidate's current professional role and total years of experience from the resume.
STEP 3: Generate EXACTLY 3 data-driven career paths based strictly on the resume evidence. NEVER skip generation.
STEP 4: Score the profile 0-100 based on resume strength.

OUTPUT SCHEMA (MANDATORY — return EVERY key exactly as shown, no extra keys, no markdown):
{
  \"profile\": {
    \"name\": \"Extract Name\",
    \"extracted_role\": \"Max 4 words\",
    \"extracted_experience\": \"E.g. 3\",
    \"scores\": { \"resume_strength\": 0, \"career_match\": 0, \"transferability_score\": 0 }
  },
  \"analysis\": {
    \"summary\": \"Explain the strategy and any pivots here.\",
    \"strengths\": [\"Strength 1\"],
    \"weaknesses\": [\"Gap 1\"]
  },
  \"careers\": [
    {
      \"rank\": 1,
      \"role\": \"Target Job\",
      \"entry_role_title\": \"Immediate Job\",
      \"is_lumen_pick\": true,
      \"description\": \"What this does\",
      \"salary_year_8\": \"0 {$currency}\",
      \"salary_achievable\": true,
      \"salary_note\": \"\",
      \"skill_overlap_pct\": 80,
      \"skill_overlap_text\": \"\",
      \"youtube_search_url\": \"https://www.youtube.com/results?search_query=...\",
      \"job_progression_stages\": [
        { \"year\": \"Year 0-2\", \"role\": \"\", \"salary\": \"0 {$currency}\", \"tasks\": \"\" }
      ],
      \"skills_analysis\": { \"current_fit\": \"\", \"missing\": \"\" },
      \"certification_details\": [ { \"name\": \"\", \"provider\": \"\", \"link\": \"\" } ]
    }
  ]
}
Return ONLY the JSON object above. No preamble, no explanation, no markdown code fences.
";
	}

	private function render_results( $data, $adzuna_jobs = [], $user_email = '', $currency = 'USD', $is_teaser = false ) {
		if ( ! isset( $data['analysis'] ) || ! isset( $data['careers'] ) ) {
			return '<p class="error">Invalid data structure received from AI. Please try again.</p>';
		}
		
		$profile = $data['profile'] ?? [];
		$analysis = $data['analysis'];
		$careers = is_array($data['careers']) ? $data['careers'] : [];

		$ext_role = !empty($profile['extracted_role']) ? $profile['extracted_role'] : 'Role Not Extracted';
		$ext_exp  = !empty($profile['extracted_experience']) ? $profile['extracted_experience'] : '0';

		$exp_display = $ext_exp;
		if ( preg_match( '/^\d/', trim( $ext_exp ) ) ) {
			$exp_display = trim( $ext_exp ) . ' YEARS EXPERIENCE';
		}

		// Safety nets for missing arrays
		$strengths = (isset($analysis['strengths']) && is_array($analysis['strengths'])) ? $analysis['strengths'] : ['No specific strengths extracted.'];
		$weaknesses = (isset($analysis['weaknesses']) && is_array($analysis['weaknesses'])) ? $analysis['weaknesses'] : ['No specific gaps identified.'];
		$scores = isset($profile['scores']) && is_array($profile['scores']) ? $profile['scores'] : [ 'resume_strength' => 0, 'career_match' => 0, 'transferability_score' => 0 ];

		ob_start();
		?>
		<div class="cre-results-container cre-content-panel fade-in">
			
			<div class="cre-success-msg" style="background:#f0fdf4; color:#16a34a; padding:12px 20px; border-radius:8px; border:1px solid #bbf7d0; text-align:center; font-weight:bold; margin-bottom:25px;">
				🎉 Great news! Your personalized roadmap is ready.
			</div>

			<div class="cre-dashboard-header">
				<div class="cre-candidate-info">
					<h3>YOUR PERSONALIZED CAREER PATH</h3>
					<h1><?php echo esc_html( $profile['name'] ?? 'Candidate' ); ?></h1>
					<p class="cre-trajectory-desc" style="margin-top:10px;">Based on your current experience and preferences, Lumenpath has mapped your path to your 8-year target roles.</p>
					<div class="cre-extracted-info" style="margin-top:15px;">
						<span class="cre-badge"><?php echo esc_html( $ext_role ); ?></span>
						<span class="cre-badge"><?php echo esc_html( $exp_display ); ?></span>
					</div>
				</div>
				<div class="cre-graphs-container">
					<?php 
					$score_labels = [ 'resume_strength' => 'Resume Strength', 'career_match' => 'Career Match', 'transferability_score' => 'Transferability Score' ];
					foreach ( $scores as $key => $val ) :
						$label = $score_labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
						$clean_val = intval( $val );
					?>
					<div class="cre-circular-chart">
						<div class="cre-circle" style="--p:<?php echo $clean_val; ?>">
							<span><?php echo $clean_val; ?>%</span>
						</div>
						<p><?php echo esc_html( $label ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="cre-section cre-analysis-section">
				<h2>Profile Analysis</h2>
				<div class="cre-analysis-grid">
					<div class="cre-card cre-clean-card">
						<h3>Executive Summary</h3>
						<p><?php echo esc_html( $analysis['summary'] ?? 'Summary unavailable.' ); ?></p>
					</div>
					<div class="cre-card cre-clean-card success">
						<h3>Strengths</h3>
						<ul>
							<?php foreach ( $strengths as $item ) echo '<li>' . esc_html( $item ) . '</li>'; ?>
						</ul>
					</div>
					<div class="cre-card cre-clean-card warning">
						<h3>Strategic Gaps</h3>
						<ul>
							<?php foreach ( $weaknesses as $item ) echo '<li>' . esc_html( $item ) . '</li>'; ?>
						</ul>
					</div>
				</div>
			</div>

			<?php if ( $is_teaser ) : ?>
				<div class="cre-locked-overlay" style="position: relative; margin-top: 40px; text-align: center; padding: 50px 20px; background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, rgba(255,255,255,1) 40%); z-index: 10;">
					<div style="background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); display: inline-block; max-width: 500px; border: 1px solid #e2e8f0; margin-top: 50px;">
						<h3 style="margin-top:0; color:#0f172a; font-size:1.5rem;">Unlock Your 8-Year Roadmap</h3>
						<p style="color:#64748b; margin-bottom: 20px;">You're seeing a free preview! Unlock the rest of your report to reveal your <strong>Custom Career Paths, Missing Skills Analysis, Recommended Certifications, and Active Job Matches</strong>.</p>
						<button id="cre-unlock-btn" class="cre-submit-btn" style="margin-top:0; background:#2563eb;">Unlock Full Report – $1.00</button>
						<div id="stripe-payment-element-container" style="margin-top: 20px; display:none; min-height: 200px;"></div>
					</div>
				</div>
				<div style="filter: blur(6px); opacity: 0.4; pointer-events: none; user-select: none; margin-top: -150px; overflow: hidden; height: 500px;">
					<div class="cre-section cre-table-section">
						<h2>Career Path Generator</h2>
						<div class="cre-table-wrapper cre-clean-card">
							<table class="cre-data-table">
								<thead>
									<tr><th class="cre-col-rank">Rank</th><th class="cre-col-start">Start Here (Yr 0-2)</th><th>What This Role Does</th><th class="cre-col-pay">Pay (8Y)</th><th>Skills Overlap</th><th>Job Progression</th></tr>
								</thead>
								<tbody>
									<tr>
										<td class="cre-col-rank cre-align-top"><span class="rank-badge">#1</span><span class="cre-lumen-badge">⭐ Lumenpath Recommends</span></td>
										<td class="cre-col-start cre-align-top"><strong style="color:#2563EB; font-size:1.1em;">Senior Technical Lead</strong><br><span style="font-size:0.8em;color:#64748b;">Target: VP of Engineering</span></td>
										<td class="cre-desc-cell cre-align-top">Directs cross-functional engineering teams...</td>
										<td class="cre-col-pay cre-align-top">$185,000 USD</td>
										<td class="cre-align-top"><div class="cre-progress-bar"><div style="width:85%"></div></div><small>85% Match</small></td>
										<td class="cre-progression-cell cre-align-top">
											<div class="cre-progression-stage"><strong>Year 0-2:</strong> Engineering Manager</div>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div> 
			<?php return ob_get_clean(); endif; ?>

			<div class="cre-section cre-table-section">
				<h2>Career Path Generator</h2>
				<div class="cre-table-wrapper cre-clean-card">
					<table class="cre-data-table">
						<thead>
							<tr>
								<th class="cre-col-rank">Rank</th>
								<th class="cre-col-start">Start Here (Yr 0-2)</th>
								<th>What This Role Does</th>
								<th class="cre-col-pay">Pay (8Y <?php echo esc_html( $currency ); ?>)</th>
								<th>Skills Overlap</th>
								<th>Job Progression (0-8 Years)</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $careers as $career ) : 
								$entry_role    = $career['entry_role_title'] ?? $career['role'] ?? 'Role';
								$youtube_url   = $career['youtube_search_url'] ?? '';
								$is_lumen_pick = ! empty( $career['is_lumen_pick'] );
								$sal_achievable = $career['salary_achievable'] ?? true;
								$sal_note      = $career['salary_note'] ?? '';
								$prog_stages   = is_array($career['job_progression_stages'] ?? null) ? $career['job_progression_stages'] : [];
							?>
							<tr>
								<td class="cre-col-rank cre-align-top">
									<span class="rank-badge">#<?php echo esc_html( $career['rank'] ?? 1 ); ?></span>
									<?php if ( $is_lumen_pick ) : ?>
										<span class="cre-lumen-badge">⭐ Lumenpath Recommends</span>
									<?php endif; ?>
								</td>
								<td class="cre-col-start cre-align-top">
									<?php if ( ! empty( $youtube_url ) ) : ?>
										<strong style="color:#2563EB; font-size:1.1em;"><a href="<?php echo esc_url( $youtube_url ); ?>" target="_blank" style="color:inherit; text-decoration:none;"><?php echo esc_html( $entry_role ); ?></a></strong>
									<?php else : ?>
										<strong style="color:#2563EB; font-size:1.1em;"><?php echo esc_html( $entry_role ); ?></strong>
									<?php endif; ?>
									<br><span style="font-size:0.8em;color:#64748b;">Target: <?php echo esc_html( $career['role'] ?? '' ); ?></span>
								</td>
								<td class="cre-desc-cell cre-align-top"><?php echo esc_html( $career['description'] ?? '' ); ?></td>
								<td class="cre-col-pay cre-align-top">
									<?php echo esc_html( $career['salary_year_8'] ?? 'N/A' ); ?>
									<?php if ( false === $sal_achievable || 'false' === $sal_achievable ) : ?>
										<div class="cre-salary-warning"><?php echo esc_html( $sal_note ); ?></div>
									<?php endif; ?>
								</td>
								<td class="cre-align-top">
									<div class="cre-progress-bar"><div style="width:<?php echo intval( $career['skill_overlap_pct'] ?? 0 ); ?>%"></div></div>
									<small><?php echo esc_html( $career['skill_overlap_pct'] ?? 0 ); ?>% Match</small>
									<div style="font-size:0.7em; opacity:0.8; margin-top:4px;"><?php echo esc_html( $career['skill_overlap_text'] ?? '' ); ?></div>
								</td>
								<td class="cre-progression-cell cre-align-top">
									<?php foreach ( $prog_stages as $stage ) : ?>
									<div class="cre-progression-stage">
										<strong><?php echo esc_html( $stage['year'] ?? '' ); ?>:</strong> <?php echo esc_html( $stage['role'] ?? '' ); ?> (<?php echo esc_html( $stage['salary'] ?? '' ); ?>)<br>
										<em style="opacity:0.8;"><?php echo esc_html( $stage['tasks'] ?? '' ); ?></em>
									</div>
									<?php endforeach; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="cre-section cre-skills-section">
				<h2>Missing Skills &amp; Recommended Certifications</h2>
				<div class="cre-grid-cards">
					<?php foreach ( $careers as $career ) : 
						$entry_role = $career['entry_role_title'] ?? $career['role'] ?? 'Role';
						$skills = is_array($career['skills_analysis'] ?? null) ? $career['skills_analysis'] : ['current_fit' => 'N/A', 'missing' => 'N/A'];
						$certs = is_array($career['certification_details'] ?? null) ? $career['certification_details'] : [];
					?>
					<div class="cre-clean-card cre-skill-card">
						<div class="cre-skill-header">
							<h3><?php echo esc_html( $entry_role ); ?></h3>
						</div>
						<div class="cre-skill-block"><h4 class="text-success">✅ Current Fit</h4><p><?php echo esc_html( $skills['current_fit'] ?? '' ); ?></p></div>
						<div class="cre-skill-block"><h4 class="text-danger">⚠️ Missing Skills</h4><p><?php echo esc_html( $skills['missing'] ?? '' ); ?></p></div>
						<div class="cre-certs-block">
							<h4>🎓 Recommended Certifications</h4>
							<?php if ( ! empty( $certs ) ) : ?>
								<ul class="cre-cert-list">
								<?php foreach ( $certs as $c ) : ?>
									<li><strong><?php echo esc_html( $c['name'] ?? '' ); ?></strong> <span style="font-size:0.85em; color:#64748b;">by <?php echo esc_html( $c['provider'] ?? '' ); ?></span><a href="<?php echo esc_url( $c['link'] ?? '#' ); ?>" target="_blank" class="cre-link-small">Enroll &rarr;</a></li>
								<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p style="font-size:0.9em;">No specific certifications found.</p>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( ! empty( $adzuna_jobs ) ) : ?>
			<div class="cre-section cre-jobs-section">
				<h2>Current Active Jobs</h2>
				<?php foreach ( $adzuna_jobs as $role_name => $jobs ) : ?>
				<div class="cre-table-wrapper cre-clean-card" style="margin-bottom:20px;">
					<table class="cre-data-table cre-jobs-table">
						<thead><tr><th>Job Title</th><th>Company</th><th>Location</th><th>Pay (Est)</th><th>Apply</th></tr></thead>
						<tbody>
							<?php foreach ( $jobs as $job ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $job['title'] ); ?></strong></td>
								<td><?php echo esc_html( $job['company']['display_name'] ?? 'Unknown' ); ?></td>
								<td><?php echo esc_html( $job['location']['display_name'] ?? 'Unknown' ); ?></td>
								<td><?php echo ( ! empty( $job['salary_min'] ) && is_numeric( $job['salary_min'] ) ) ? '$' . number_format( $job['salary_min'] ) : 'N/A'; ?></td>
								<td><a href="<?php echo esc_url( $job['redirect_url'] ); ?>" target="_blank" class="cre-btn-small">Apply</a></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="cre-email-section">
				<button type="button" id="cre-btn-email" class="cre-submit-btn">Email Me This Report</button>
				<p id="cre-email-status" style="margin-top: 15px; font-weight: bold;"></p>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}