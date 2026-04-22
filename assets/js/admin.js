jQuery(document).ready(function($) {
	$('#cre-test-grok').on('click', function(e) {
		e.preventDefault();
		const resultSpan = $('#cre-test-result');
		resultSpan.text('Testing...').css('color', 'black');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'cre_test_connection',
				nonce: cre_admin_vars.nonce
			},
			success: function(response) {
				if (response.success) {
					resultSpan.text(response.data).css('color', 'green');
				} else {
					resultSpan.text(response.data).css('color', 'red');
				}
			},
			error: function() {
				resultSpan.text('Connection failed (Network error)').css('color', 'red');
			}
		});
	});
});