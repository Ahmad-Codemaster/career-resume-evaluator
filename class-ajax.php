<?php
namespace CRE;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_cre_process_form', [ $this, 'process_form' ] );
		add_action( 'wp_ajax_nopriv_cre_process_form', [ $this, 'process_form' ] );
		
		add_action( 'wp_ajax_cre_send_email', [ $this, 'send_email' ] );
		add_action( 'wp_ajax_nopriv_cre_send_email', [ $this, 'send_email' ] );
	}

	public function process_form() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );

		// 1. Handle File Upload
		if ( empty( $_FILES['resume'] ) ) {
			wp_send_json_error( 'No resume uploaded.' );
		}

		// Strict Validation for DOCX
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

		// 3. Gather Inputs & Determine Currency
		$loc = sanitize_text_field( $_POST['cre_location'] ?? 'us' );
		$currencies = [
			'us' => 'USD', 'gb' => 'GBP', 'ca' => 'CAD', 'au' => 'AUD', 'nz' => 'NZD',
			'za' => 'ZAR', 'in' => 'INR', 'fr' => 'EUR', 'de' => 'EUR', 'nl' => 'EUR',
			'it' => 'EUR', 'es' => 'EUR', 'br' => 'BRL', 'mx' => 'MXN', 'pl' => 'PLN',
			'ru' => 'RUB', 'sg' => 'SGD'
		];
		$currency = $currencies[ $loc ] ?? 'USD';

		$data = [
			'work_setup'    => sanitize_text_field( $_POST['cre_work_setup'] ?? '' ),
			'cur_salary'    => sanitize_text_field( $_POST['cre_current_salary'] ?? '' ),
			'roles'         => sanitize_text_field( $_POST['cre_roles'] ?? '' ),
			'location'      => $loc,
			'currency'      => $currency,
			'exclude'       => sanitize_text_field( $_POST['cre_exclude'] ?? '' ),
			'tgt_salary'    => sanitize_text_field( $_POST['cre_salary'] ?? '' ),
			'email'         => sanitize_email( $_POST['cre_email'] ?? '' ),
		];

		// 4. Construct Prompt
		$prompt = $this->build_prompt( $data, $resume_text );

		// 5. Call Grok API
		$api_key = get_option( 'cre_grok_api_key' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( 'Grok API Key is missing in settings.' );
		}

		$model = get_option( 'cre_grok_model', 'grok-3' );

		$response = wp_remote_post( 'https://api.x.ai/v1/chat/completions', [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body'    => json_encode( [
				'model'    => $model,
				'messages' => [
					[ 
						'role' => 'system', 
						'content' => 'You are a Strict Senior Data Scientist & Career Strategist. You analyze resumes against real-world market data to provide high-precision career pivots. You have access to search capabilities to find real profiles. Output strictly valid JSON.' 
					],
					[ 'role' => 'user', 'content' => $prompt ],
				],
				'temperature' => 0.4 // Lower temperature for stricter results
			] ),
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'API Error: ' . $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			$error_body = wp_remote_retrieve_body( $response );
			$error_json = json_decode( $error_body, true );
			$error_msg = $error_json['error']['message'] ?? substr( $error_body, 0, 200 );
			wp_send_json_error( 'Grok API Error (HTTP ' . $http_code . '): ' . sanitize_text_field( $error_msg ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$json_response = json_decode( $body, true );
		
		$content = $json_response['choices'][0]['message']['content'] ?? '';

		if ( empty( $content ) ) {
			wp_send_json_error( 'No response from AI.' );
		}

		// Robust JSON Extraction
		$json_str = $content;
		// Remove markdown code blocks if present
		$json_str = str_replace( [ '```json', '```' ], '', $json_str );
		
		// Find the first opening brace and last closing brace
		$start = strpos( $json_str, '{' );
		$end   = strrpos( $json_str, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$json_str = substr( $json_str, $start, ( $end - $start ) + 1 );
		}

		$data_parsed = json_decode( $json_str, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( 'Failed to parse AI response. Raw: ' . substr( $content, 0, 100 ) . '...' );
		}

		// 6. Fetch Adzuna Jobs
		// Use the entry level role for job search as that is where they start
		$adzuna_jobs = [];
		if ( ! empty( $data_parsed['careers'][0]['entry_role_title'] ) && ! empty( $data['location'] ) ) {
			$search_role = $data_parsed['careers'][0]['entry_role_title'];
			$adzuna_jobs = $this->fetch_adzuna_jobs( $data['location'], $search_role );
		}

		// 7. Save to Database
		$candidate_name = $data_parsed['profile']['name'] ?? 'Candidate';
		
		DB::insert_submission(
			$candidate_name,
			$data['email'],
			$attachment_id,
			$data,
			$data_parsed,
			$adzuna_jobs
		);

		// 8. Return HTML and Data for Emailing
		$html = $this->render_results( $data_parsed, $adzuna_jobs, $data['email'], $data['currency'] );
		
		wp_send_json_success( [
			'html' => $html,
			'raw_data' => $data_parsed,
			'adzuna_jobs' => $adzuna_jobs,
			'user_email' => $data['email']
		] );
	}

	public function send_email() {
		check_ajax_referer( 'cre_form_nonce', 'nonce' );

		$email = sanitize_email( $_POST['email'] ?? '' );
		$raw_data = isset($_POST['raw_data']) ? map_deep( $_POST['raw_data'], 'sanitize_text_field' ) : [];

		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( 'Invalid email address.' );
		}

		$name = $raw_data['profile']['name'] ?? 'Candidate';
		$role = $raw_data['careers'][0]['entry_role_title'] ?? $raw_data['careers'][0]['role'] ?? 'N/A';
		
		ob_start();
		?>
		<p>Hello <?php echo esc_html( $name ); ?>,</p>
		<p>Here is your generated Career Roadmap based on the analysis of your resume.</p>
		<p>Please log in or visit the dashboard to view the full interactive report.</p>
		<p>Top Recommendation: <strong><?php echo esc_html( $role ); ?></strong></p>
		<p><em>Generated by Lumen Path</em></p>
		<?php
		$message = ob_get_clean();
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		
		$sent = wp_mail( $email, 'Your Career Roadmap', $message, $headers );

		if ( $sent ) {
			wp_send_json_success( 'Email sent successfully!' );
		} else {
			wp_send_json_error( 'Failed to send email. Please check server settings.' );
		}
	}

	private function fetch_adzuna_jobs( $country, $query ) {
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
			'results_per_page' => 12,
			'what'             => $query,
			'content-type'     => 'application/json',
		];

		$url = add_query_arg( $args, $url );
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['results'] ?? [];
	}

	private function build_prompt( $data, $resume_text ) {
		$currency = $data['currency'];

		return "Act as a Strict Senior Career Strategist. I will provide a Candidate's Resume and their Preferences. "
		. "Your task is to Analyze the resume to extract key profile data, and then Generate a high-precision, data-backed Career Path Generator table.\n\n"
		. "--- CANDIDATE PREFERENCES ---\n"
		. "Location/Setup: {$data['location']} ({$currency}), {$data['work_setup']}\n"
		. "Current Salary: {$data['cur_salary']} (Private Info)\n"
		. "Target Salary (Year 8): ". '$' . "{$data['tgt_salary']} {$currency}\n"
		. "Pivot Interests: {$data['roles']}\n"
		. "Constraints: {$data['exclude']}\n\n"
		. "--- RESUME TEXT ---\n{$resume_text}\n\n"
		. "--- INSTRUCTIONS ---\n"
		. "1. AUTO-EXTRACTION: Analyze the resume for Name, Current Role, Experience, and Skills.\n"
		. "2. CAREER GENERATION: Select Top 3 Career Pivots. For EACH role, you MUST provide a detailed year-by-year progression plan.\n"
		. "3. FINANCIALS: All salaries must be estimated in {$currency}.\n"
		. "4. JOB PROGRESSION: For the 'job_progression_stages' array, provide distinct stages for Year 0-2, Year 3-5, and Year 6-8. Each stage must have a specific Role Title, Salary, and Tasks.\n"
		. "5. ENTRY LEVEL FOCUS: Identify the specific 'entry_role_title' the candidate can start immediately (Year 0-2).\n"
		. "6. SKILLS & CERTS ANALYSIS: Perform deep research to identify the skills gap. Provide specific, real-world certifications with VALID enrollment URLs. Do not hallucinate links.\n"
		. "7. SIMILAR PROFILES: Use your knowledge to identify 5 REAL professionals who have successfully executed a similar career pivot. Prefer Google Search URLs if LinkedIn slug is uncertain.\n"
		. "--- OUTPUT FORMAT ---\n"
		. "Return ONLY valid JSON with this exact structure:\n"
		. "{\n"
		. "  \"profile\": { \n"
		. "     \"name\": \"Extracted Name\", \n"
		. "     \"extracted_role\": \"Extracted Current Role\", \n"
		. "     \"extracted_experience\": \"Extracted Total Years\", \n"
		. "     \"scores\": { \"resume_strength\": 85, \"career_match\": 75, \"conversion_chance\": 60 } \n"
		. "  },\n"
		. "  \"analysis\": { \"summary\": \"Executive summary...\", \"strengths\": [\"s1\"], \"weaknesses\": [\"w1\"] },\n"
		. "  \"careers\": [\n"
		. "    {\n"
		. "      \"rank\": 1,\n"
		. "      \"role\": \"Target Role (Year 8)\",\n"
		. "      \"entry_role_title\": \"Immediate Start Role (Year 0)\",\n"
		. "      \"description\": \"Description of the path\",\n"
		. "      \"salary_year_8\": \"$150,000 {$currency}\",\n"
		. "      \"skill_overlap_pct\": 75,\n"
		. "      \"skill_overlap_text\": \"e.g., Python, Management\",\n"
		. "      \"job_progression_stages\": [\n"
		. "         { \"year\": \"Year 0-2\", \"role\": \"Junior Role\", \"salary\": \"$60k {$currency}\", \"tasks\": \"Learning basics\" },\n"
		. "         { \"year\": \"Year 3-5\", \"role\": \"Mid-Level Role\", \"salary\": \"$90k {$currency}\", \"tasks\": \"Leading small teams\" },\n"
		. "         { \"year\": \"Year 6-8\", \"role\": \"Senior Role\", \"salary\": \"$120k {$currency}\", \"tasks\": \"Strategic planning\" }\n"
		. "      ],\n"
		. "      \"skills_analysis\": {\n"
		. "         \"current_fit\": \"Skills they already have\",\n"
		. "         \"missing\": \"Skills they lack\"\n"
		. "      },\n"
		. "      \"certification_details\": [\n"
		. "         { \"name\": \"Cert Name\", \"provider\": \"Coursera/Udemy/etc\", \"link\": \"https://...\" }\n"
		. "      ]\n"
		. "    }\n"
		. "  ],\n"
		. "  \"similar_profiles\": [\n"
		. "     { \"name\": \"John Doe\", \"trajectory\": \"Accountant > Data Analyst\", \"linkedin_url\": \"https://www.google.com/search?q=site:linkedin.com/in/+John+Doe+Data\", \"similarity_reason\": \"Successfully pivoted from finance to data...\" }\n"
		. "  ]\n"
		. "}";
	}

	private function render_results( $data, $adzuna_jobs = [], $user_email = '', $currency = 'USD' ) {
		if ( ! isset( $data['analysis'] ) || ! isset( $data['careers'] ) ) {
			return '<p class="error">Invalid data structure received from AI.</p>';
		}
		
		$profile = $data['profile'] ?? [];
		$analysis = $data['analysis'];
		$careers = $data['careers'];
		$similar = $data['similar_profiles'] ?? [];

		$ext_role = $profile['extracted_role'] ?? 'Unknown Role';
		$ext_exp = $profile['extracted_experience'] ?? 'Unknown Exp';

		ob_start();
		?>
		<div class="cre-results-container cre-content-panel fade-in">
			
			<!-- Dashboard Header with Graphs -->
			<div class="cre-dashboard-header">
				<div class="cre-candidate-info">
					<h3>Strategic Roadmap</h3>
					<h1><?php echo esc_html( $profile['name'] ?? 'Candidate' ); ?></h1>
					<div class="cre-extracted-info">
						<span class="cre-badge"><?php echo esc_html( $ext_role ); ?></span>
						<span class="cre-badge"><?php echo esc_html( $ext_exp ); ?></span>
					</div>
				</div>
				<div class="cre-graphs-container">
					<?php 
					$scores = $profile['scores'] ?? [ 'resume_strength' => 50, 'career_match' => 50, 'conversion_chance' => 50 ];
					foreach($scores as $key => $val) :
						$label = ucwords(str_replace('_', ' ', $key));
						$clean_val = intval($val);
					?>
					<div class="cre-circular-chart">
						<div class="cre-circle" style="--p:<?php echo $clean_val; ?>">
							<span><?php echo $clean_val; ?>%</span>
						</div>
						<p><?php echo esc_html($label); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Resume Analysis -->
			<div class="cre-section cre-analysis-section">
				<h2>Profile Analysis</h2>
				<div class="cre-analysis-grid">
					<div class="cre-card cre-clean-card">
						<h3>Executive Summary</h3>
						<p><?php echo esc_html( $analysis['summary'] ); ?></p>
					</div>
					<div class="cre-card cre-clean-card success">
						<h3>Strengths</h3>
						<ul>
							<?php foreach ( $analysis['strengths'] as $item ) echo '<li>' . esc_html( $item ) . '</li>'; ?>
						</ul>
					</div>
					<div class="cre-card cre-clean-card warning">
						<h3>Strategic Gaps</h3>
						<ul>
							<?php foreach ( $analysis['weaknesses'] as $item ) echo '<li>' . esc_html( $item ) . '</li>'; ?>
						</ul>
					</div>
				</div>
			</div>

			<!-- Career Path Generator Table -->
			<div class="cre-section cre-table-section">
				<h2>Career Path Generator</h2>
				<div class="cre-table-wrapper cre-clean-card">
					<table class="cre-data-table">
						<thead>
							<tr>
								<th>Rank</th>
								<th>Start Here (Yr 0-2)</th>
								<th>What This Role Does</th>
								<th>Pay (8Y <?php echo esc_html($currency); ?>)</th>
								<th>Skills Overlap</th>
								<th>Job Progression (0-8 Years)</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $careers as $career ) : 
								$entry_role = $career['entry_role_title'] ?? $career['role'];
							?>
							<tr>
								<td class="cre-align-top"><span class="rank-badge">#<?php echo esc_html( $career['rank'] ); ?></span></td>
								<td class="cre-align-top">
									<strong style="color:#2563EB; font-size:1.1em;"><?php echo esc_html( $entry_role ); ?></strong>
									<br><span style="font-size:0.8em;color:#64748b;">Target: <?php echo esc_html($career['role']); ?></span>
								</td>
								<td class="cre-desc-cell cre-align-top">
									<?php echo esc_html( $career['description'] ); ?>
								</td>
								<td class="cre-align-top"><?php echo esc_html( $career['salary_year_8'] ); ?></td>
								<td class="cre-align-top">
									<div class="cre-progress-bar"><div style="width:<?php echo intval($career['skill_overlap_pct']); ?>%"></div></div>
									<small><?php echo esc_html( $career['skill_overlap_pct'] ); ?>% Match</small>
									<div style="font-size:0.7em; opacity:0.8; margin-top:4px;"><?php echo esc_html( $career['skill_overlap_text'] ?? '' ); ?></div>
								</td>
								<td class="cre-progression-cell cre-align-top">
									<?php 
									$stages = $career['job_progression_stages'] ?? [];
									foreach($stages as $stage): 
									?>
									<div class="cre-progression-stage">
										<strong><?php echo esc_html($stage['year']); ?>:</strong> 
										<?php echo esc_html($stage['role']); ?> (<?php echo esc_html($stage['salary']); ?>)
										<br>
										<em style="opacity:0.8;"><?php echo esc_html($stage['tasks']); ?></em>
									</div>
									<?php endforeach; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Missing Skills & Required Certifications -->
			<div class="cre-section cre-skills-section">
				<h2>Missing Skills & Required Certifications</h2>
				<div class="cre-grid-cards">
					<?php foreach ( $careers as $career ) : 
						$entry_role = $career['entry_role_title'] ?? $career['role'];
						$skills = $career['skills_analysis'] ?? ['current_fit' => '', 'missing' => ''];
						$certs = $career['certification_details'] ?? [];
					?>
					<div class="cre-clean-card cre-skill-card">
						<div class="cre-skill-header">
							<h3><?php echo esc_html( $entry_role ); ?></h3>
							<span class="cre-badge">Skills Analysis</span>
						</div>
						
						<div class="cre-skill-block">
							<h4 class="text-success">✅ Current Fit</h4>
							<p><?php echo esc_html( $skills['current_fit'] ); ?></p>
						</div>
						<div class="cre-skill-block">
							<h4 class="text-danger">⚠️ Missing Skills</h4>
							<p><?php echo esc_html( $skills['missing'] ); ?></p>
						</div>
						
						<div class="cre-certs-block">
							<h4>🎓 Required Certifications (3-6 mo)</h4>
							<?php if(!empty($certs)): ?>
								<ul class="cre-cert-list">
								<?php foreach($certs as $c): ?>
									<li>
										<strong><?php echo esc_html($c['name']); ?></strong> <span style="font-size:0.85em; color:#64748b;">by <?php echo esc_html($c['provider']); ?></span>
										<a href="<?php echo esc_url($c['link']); ?>" target="_blank" class="cre-link-small">Enroll &rarr;</a>
									</li>
								<?php endforeach; ?>
								</ul>
							<?php else: ?>
								<p style="font-size:0.9em;">No specific certifications found.</p>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Similar Profiles Table -->
			<?php if ( ! empty( $similar ) ) : ?>
			<div class="cre-section cre-table-section">
				<h2>Similar Career Paths (Real Profiles)</h2>
				<div class="cre-table-wrapper cre-clean-card">
					<table class="cre-data-table">
						<thead>
							<tr>
								<th>Name</th>
								<th>Role Trajectory</th>
								<th>LinkedIn Profile</th>
								<th>Why their career is similar</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $similar as $prof ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $prof['name'] ); ?></strong></td>
								<td><?php echo esc_html( $prof['trajectory'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( $prof['linkedin_url'] ); ?>" target="_blank" class="cre-btn-small">View Profile &rarr;</a>
								</td>
								<td class="cre-desc-cell"><?php echo esc_html( $prof['similarity_reason'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<!-- Adzuna Jobs -->
			<?php if ( ! empty( $adzuna_jobs ) ) : ?>
			<div class="cre-section cre-jobs-section">
				<h2>Live Opportunities (<?php echo esc_html($careers[0]['entry_role_title']); ?>)</h2>
				<div class="cre-table-wrapper cre-clean-card">
					<table class="cre-data-table cre-jobs-table">
						<thead>
							<tr>
								<th>Job_Title</th>
								<th>Company</th>
								<th>Location</th>
								<th>Pay_(est)</th>
								<th>Apply</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $adzuna_jobs as $job ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $job['title'] ); ?></strong></td>
								<td><?php echo esc_html( $job['company']['display_name'] ?? 'Unknown' ); ?></td>
								<td><?php echo esc_html( $job['location']['display_name'] ?? 'Unknown' ); ?></td>
								<td><?php 
									echo isset($job['salary_min']) ? '$'.number_format($job['salary_min']) : 'Negotiable';
								?></td>
								<td>
									<a href="<?php echo esc_url( $job['redirect_url'] ); ?>" target="_blank" class="cre-btn-small">Apply</a>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<div class="cre-email-section">
				<button type="button" id="cre-btn-email" class="cre-submit-btn">Email Me This Report</button>
				<p id="cre-email-status"></p>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}