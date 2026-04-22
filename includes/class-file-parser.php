<?php
namespace CRE;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class File_Parser {

	public function extract_text( $file_path ) {
		$mime = mime_content_type( $file_path );

		if ( 'application/pdf' === $mime ) {
			return $this->extract_pdf( $file_path );
		}
		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime ) {
			return $this->extract_docx( $file_path );
		}

		return '';
	}

	private function extract_docx( $file_path ) {
		$content = '';
		$zip = new \ZipArchive();

		if ( $zip->open( $file_path ) === true ) {
			if ( ( $index = $zip->locateName( 'word/document.xml' ) ) !== false ) {
				$xml_data = $zip->getFromIndex( $index );
				$dom = new \DOMDocument();
				$dom->loadXML( $xml_data, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING );
				$content = strip_tags( $dom->saveXML() );
			}
			$zip->close();
		}
		return $content;
	}

	private function extract_pdf( $file_path ) {
		// Basic PDF Text Extractor suitable for standard resume PDFs without OCR requirements
		// If needed, can be swapped with smalot/pdfparser if Composer is allowed in future.
		$content = file_get_contents( $file_path );
		$text = '';

		// Regex to find text streams
		if ( preg_match_all( '/stream[\n\r]+(.*?)endstream/s', $content, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				// Try to uncompress
				$decoded = @gzuncompress( $stream );
				if ( false !== $decoded ) {
					// Extract text inside parentheses
					if ( preg_match_all( '/\((.*?)\)/', $decoded, $text_matches ) ) {
						foreach ( $text_matches[1] as $t ) {
							$text .= $t . ' ';
						}
					}
				}
			}
		}

		// Fallback/Clean up
		$text = preg_replace( '/\\[0-7]{3}/', '', $text ); // Remove octal
		$text = str_replace( [ '\\(', '\\)', '\\' ], [ '(', ')', '' ], $text );
		
		if ( empty( trim( $text ) ) ) {
			// If advanced encoding is used, fallback message
			return "[PDF Content could not be parsed via simple extraction. File is attached.]";
		}

		return substr( $text, 0, 10000 ); // Limit characters
	}
}