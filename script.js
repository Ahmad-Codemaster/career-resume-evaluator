jQuery(document).ready(function($) {
	const form = $('#cre-form');
	const submitBtn = $('#cre-submit-btn');
	const fileInput = $('#cre-resume');
	const progressBar = $('#cre-upload-progress');
	const progressBarInner = progressBar.find('.bar');
	const uploadSuccess = $('#cre-upload-success');
	
	let lastResponseData = null;

	// Check inputs/selects to enable button
	function checkFormFilled() {
		let allFilled = true;
		form.find('input[required]').each(function() {
			if ($(this).val() === '') allFilled = false;
		});
		form.find('select[required]').each(function() {
			if ($(this).val() === '' || $(this).val() === null) allFilled = false;
		});
		if (allFilled) {
			submitBtn.removeAttr('disabled');
		} else {
			submitBtn.attr('disabled', 'disabled');
		}
	}

	form.on('change keyup', 'input, select', checkFormFilled);

	// Remove error class on user input/change
	form.on('input change keyup', 'input, select', function() {
		$(this).removeClass('cre-field-error');
	});

	// File Upload Simulation & Feedback
	fileInput.on('change', function() {
		if (this.files.length > 0) {
			const fileName = this.files[0].name;
			const ext = fileName.split('.').pop().toLowerCase();
			
			if (ext !== 'docx') {
				alert('Only .docx files are allowed.');
				$(this).val('');
				return;
			}

			progressBar.show();
			uploadSuccess.hide();
			let width = 0;
			const interval = setInterval(function() {
				if (width >= 100) {
					clearInterval(interval);
					progressBar.hide();
					uploadSuccess.text('✅ Document uploaded successfully').fadeIn();
					$('#cre-upload-label-text').text(fileName);
					$('.cre-upload-area').addClass('uploaded');
					form.trigger('change');
				} else {
					width += 10;
					progressBarInner.css('width', width + '%');
				}
			}, 50);
		}
	});

	// Handle Form Submission
	form.on('submit', function(e) {
		e.preventDefault();

		// Validate required fields — highlight errors
		let hasError = false;
		form.find('input[required], select[required]').each(function() {
			const val = $(this).val();
			if (val === '' || val === null) {
				$(this).addClass('cre-field-error');
				hasError = true;
			}
		});

		if (hasError) return;

		form.slideUp();
		$('#cre-loading').fadeIn();

		const formData = new FormData(this);
		formData.append('action', 'cre_process_form');
		formData.append('nonce', cre_vars.nonce);

		$.ajax({
			url: cre_vars.ajax_url,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function(response) {
				$('#cre-loading').hide();
				if (response.success) {
					$('#cre-results').html(response.data.html);
					// Store data for email function
					lastResponseData = response.data;
				} else {
					$('#cre-results').html('<div class="cre-clean-card error-card"><h3>Error</h3><p>' + response.data + '</p><button onclick="location.reload()" class="cre-btn-small" style="background:#000; color:#fff;">Try Again</button></div>');
				}
			},
			error: function() {
				$('#cre-loading').hide();
				$('#cre-results').html('<div class="cre-clean-card error-card"><h3>Server Error</h3><p>Please try again later.</p></div>');
			}
		});
	});

	// Handle Send Email Button
	$(document).on('click', '#cre-btn-email', function(e) {
		e.preventDefault();
		const btn = $(this);
		const status = $('#cre-email-status');
		
		if (!lastResponseData) return;

		btn.attr('disabled', 'disabled').text('Sending...');

		$.ajax({
			url: cre_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'cre_send_email',
				nonce: cre_vars.nonce,
				email: lastResponseData.user_email,
				raw_data: lastResponseData.raw_data,
				adzuna_jobs: lastResponseData.adzuna_jobs
			},
			success: function(response) {
				if (response.success) {
					btn.text('Email Sent!').css({'background': '#16a34a', 'border-color': '#16a34a'});
					status.text('Check your inbox (and spam folder).').css('color', '#0f172a');
				} else {
					btn.removeAttr('disabled').text('Try Again');
					status.text(response.data).css('color', '#ef4444');
				}
			},
			error: function() {
				btn.removeAttr('disabled').text('Try Again');
				status.text('Connection error.').css('color', '#ef4444');
			}
		});
	});
});