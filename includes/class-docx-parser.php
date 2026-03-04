<?php
/**
 * Extracts plain text from a DOCX file.
 *
 * A DOCX file is a ZIP archive containing XML. This class opens the archive,
 * reads word/document.xml, and strips XML tags to return plain text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRE_Docx_Parser {

	/**
	 * Parse a DOCX file and return its plain-text content.
	 *
	 * @param string $file_path Absolute path to the DOCX file.
	 * @return string|WP_Error Plain text on success, WP_Error on failure.
	 */
	public function parse( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Resume file not found.', 'career-resume-evaluator' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'ZipArchive PHP extension is required to parse DOCX files.', 'career-resume-evaluator' ) );
		}

		$zip = new ZipArchive();
		$result = $zip->open( $file_path );

		if ( true !== $result ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open the DOCX file.', 'career-resume-evaluator' ) );
		}

		$xml_content = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $xml_content ) {
			return new WP_Error( 'xml_missing', __( 'The DOCX file does not contain a valid document.', 'career-resume-evaluator' ) );
		}

		// Remove XML tags and decode entities to get plain text.
		$text = strip_tags( $xml_content );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		if ( '' === $text ) {
			return new WP_Error( 'empty_content', __( 'No readable text found in the resume.', 'career-resume-evaluator' ) );
		}

		return $text;
	}
}
