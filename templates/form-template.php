<?php
/**
 * Frontend form template.
 *
 * Rendered by the [career_resume_evaluator] shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cre-wrap" id="cre-wrap">

	<form id="cre-form" enctype="multipart/form-data" novalidate>

		<div class="cre-field">
			<label for="cre-resume"><?php esc_html_e( 'Upload Your Resume (DOCX)', 'career-resume-evaluator' ); ?></label>
			<input type="file" id="cre-resume" name="resume" accept=".docx" required />
		</div>

		<div class="cre-field">
			<label for="cre-goals"><?php esc_html_e( 'Your Career Goals', 'career-resume-evaluator' ); ?></label>
			<textarea id="cre-goals" name="career_goals" rows="5" placeholder="<?php esc_attr_e( 'Describe the roles, industries, or skills you want to pursue…', 'career-resume-evaluator' ); ?>" required></textarea>
		</div>

		<button type="submit" id="cre-submit"><?php esc_html_e( 'Evaluate My Resume', 'career-resume-evaluator' ); ?></button>

	</form>

	<div id="cre-loading" style="display:none;">
		<span class="cre-spinner"></span>
		<span id="cre-loading-text"></span>
	</div>

	<div id="cre-error" role="alert" style="display:none;"></div>

	<div id="cre-results" style="display:none;">
		<h2><?php esc_html_e( 'Career Path Analysis', 'career-resume-evaluator' ); ?></h2>
		<div class="cre-table-wrapper">
			<table id="cre-results-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Career Path', 'career-resume-evaluator' ); ?></th>
						<th><?php esc_html_e( 'Match Score', 'career-resume-evaluator' ); ?></th>
						<th><?php esc_html_e( 'Required Skills', 'career-resume-evaluator' ); ?></th>
						<th><?php esc_html_e( 'Skills to Develop', 'career-resume-evaluator' ); ?></th>
						<th><?php esc_html_e( 'Recommendation', 'career-resume-evaluator' ); ?></th>
					</tr>
				</thead>
				<tbody id="cre-results-body"></tbody>
			</table>
		</div>
	</div>

</div>
