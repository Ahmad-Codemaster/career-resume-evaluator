/* global CRE, jQuery */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {

		var $form    = $( '#cre-form' );
		var $submit  = $( '#cre-submit' );
		var $loading = $( '#cre-loading' );
		var $error   = $( '#cre-error' );
		var $results = $( '#cre-results' );
		var $tbody   = $( '#cre-results-body' );

		$form.on( 'submit', function ( e ) {
			e.preventDefault();

			var fileInput  = document.getElementById( 'cre-resume' );
			var goalsInput = document.getElementById( 'cre-goals' );

			// Client-side validation.
			if ( ! fileInput.files.length || ! goalsInput.value.trim() ) {
				showError( CRE.strings.error_upload );
				return;
			}

			var file = fileInput.files[ 0 ];
			if ( ! file.name.toLowerCase().endsWith( '.docx' ) ) {
				showError( CRE.strings.error_type );
				return;
			}

			var formData = new FormData();
			formData.append( 'action', 'cre_evaluate' );
			formData.append( 'nonce',  CRE.nonce );
			formData.append( 'resume', file );
			formData.append( 'career_goals', goalsInput.value.trim() );

			hideError();
			hideResults();
			showLoading( CRE.strings.analyzing );
			$submit.prop( 'disabled', true );

			$.ajax( {
				url:         CRE.ajax_url,
				type:        'POST',
				data:        formData,
				processData: false,
				contentType: false,
				success: function ( response ) {
					hideLoading();
					$submit.prop( 'disabled', false );

					if ( response.success && response.data && response.data.careers ) {
						renderResults( response.data.careers );
					} else {
						var msg = ( response.data && response.data.message )
							? response.data.message
							: CRE.strings.server_error;
						showError( msg );
					}
				},
				error: function () {
					hideLoading();
					$submit.prop( 'disabled', false );
					showError( CRE.strings.server_error );
				}
			} );
		} );

		/**
		 * Render the career analysis table rows.
		 *
		 * @param {Array} careers Array of career objects from the API.
		 */
		function renderResults( careers ) {
			$tbody.empty();

			careers.forEach( function ( career ) {
				var score      = parseInt( career.match_score, 10 ) || 0;
				var scoreClass = score >= 70 ? 'cre-score-high'
					: score >= 40 ? 'cre-score-medium'
					: 'cre-score-low';

				var required  = buildTagList( career.required_skills );
				var gap       = buildTagList( career.gap_skills );

				var $tr = $( '<tr>' ).append(
					$( '<td>' ).text( career.career_path || '' ),
					$( '<td>' ).html(
						'<span class="cre-score ' + scoreClass + '">' + score + '%</span>'
					),
					$( '<td>' ).html( required ),
					$( '<td>' ).html( gap ),
					$( '<td>' ).text( career.recommendation || '' )
				);

				$tbody.append( $tr );
			} );

			$results.slideDown( 300 );
		}

		/**
		 * Build an HTML list of skill tags from an array of strings.
		 *
		 * @param  {Array}  skills
		 * @return {string} HTML string.
		 */
		function buildTagList( skills ) {
			if ( ! Array.isArray( skills ) || ! skills.length ) {
				return '—';
			}
			return '<div class="cre-skills">' +
				skills.map( function ( s ) {
					return '<span class="cre-skill-tag">' + escHtml( s ) + '</span>';
				} ).join( '' ) +
				'</div>';
		}

		/**
		 * Escape a string for safe HTML insertion.
		 *
		 * @param  {string} str
		 * @return {string}
		 */
		function escHtml( str ) {
			return String( str )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' );
		}

		function showLoading( msg ) {
			$( '#cre-loading-text' ).text( msg );
			$loading.show();
		}

		function hideLoading() {
			$loading.hide();
		}

		function showError( msg ) {
			$error.text( msg ).show();
		}

		function hideError() {
			$error.hide().text( '' );
		}

		function hideResults() {
			$results.hide();
			$tbody.empty();
		}

	} );

} )( jQuery );
