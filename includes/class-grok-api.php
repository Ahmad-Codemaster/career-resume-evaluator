<?php
/**
 * Handles communication with the xAI Grok API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRE_Grok_API {

	/**
	 * xAI Grok API endpoint.
	 */
	const API_URL = 'https://api.x.ai/v1/chat/completions';

	/**
	 * Default model to use.
	 */
	const DEFAULT_MODEL = 'grok-3-latest';

	/**
	 * Analyze a resume and return career path suggestions.
	 *
	 * @param string $resume_text   Plain text extracted from the resume.
	 * @param string $career_goals  Career goals provided by the user.
	 * @param string $api_key       xAI Grok API key.
	 * @return array|WP_Error Parsed analysis rows on success, WP_Error on failure.
	 */
	public function analyze( $resume_text, $career_goals, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'xAI Grok API key is not configured.', 'career-resume-evaluator' ) );
		}

		$model = get_option( 'cre_grok_model', self::DEFAULT_MODEL );

		$prompt = $this->build_prompt( $resume_text, $career_goals );

		$body = wp_json_encode(
			array(
				'model'    => $model,
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => 'You are a professional career counselor. Analyze the provided resume and career goals, then respond ONLY with a JSON array. Each element must be an object with the keys: "career_path" (string), "match_score" (integer 0-100), "required_skills" (array of strings), "gap_skills" (array of strings), "recommendation" (string).',
					),
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => 0.3,
			)
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'xAI API returned an error (HTTP %d).', 'career-resume-evaluator' ),
					$status_code
				)
			);
		}

		$data = json_decode( $raw_body, true );

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'invalid_response', __( 'The API returned an unexpected response.', 'career-resume-evaluator' ) );
		}

		$content = $data['choices'][0]['message']['content'];

		// Strip markdown code fences if present.
		$content = preg_replace( '/^```(?:json)?\s*/i', '', trim( $content ) );
		$content = preg_replace( '/\s*```$/', '', $content );

		$careers = json_decode( trim( $content ), true );

		if ( ! is_array( $careers ) || empty( $careers ) ) {
			return new WP_Error( 'parse_error', __( 'Could not parse career suggestions from the API response.', 'career-resume-evaluator' ) );
		}

		return $careers;
	}

	/**
	 * Build the user prompt string.
	 *
	 * @param string $resume_text  Plain text of the resume.
	 * @param string $career_goals Career goals text.
	 * @return string
	 */
	private function build_prompt( $resume_text, $career_goals ) {
		return sprintf(
			"Resume:\n%s\n\nCareer Goals:\n%s",
			$resume_text,
			$career_goals
		);
	}
}
