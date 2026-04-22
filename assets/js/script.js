jQuery(document).ready(function($) {
	const form = $('#cre-form');
	const submitBtn = $('#cre-submit-btn');
	const fileInput = $('#cre_resume');
	const progressBar = $('#cre-upload-progress');
	const progressBarInner = progressBar.find('.bar');
	const uploadSuccess = $('#cre-upload-success');
	
	let lastResponseData = null;
	let currentSessionId = null;
	let stripe, elements;

	// === RESTORE REPORT ON PAGE LOAD IF URL HAS ID ===
	const urlParams = new URLSearchParams(window.location.search);
	const reportId = urlParams.get('cre_report');

	if (reportId) {
		form.hide();
		$('#cre-form-desc').hide(); 
		$('#cre-loading').fadeIn().find('p').text('Restoring your roadmap...');
		
		$.ajax({
			url: cre_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'cre_restore_report',
				nonce: cre_vars.nonce,
				session_id: reportId
			},
			success: function(response) {
				$('#cre-loading').hide();
				if (response.success) {
					$('#cre-results').html(response.data.html);
					currentSessionId = response.data.session_id;
					lastResponseData = response.data;
				} else {
					alert(response.data);
					form.show();
					$('#cre-form-desc').show(); 
					const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
					window.history.pushState({path:cleanUrl}, '', cleanUrl);
				}
			},
			error: function() {
				$('#cre-loading').hide();
				form.show();
				$('#cre-form-desc').show(); 
			}
		});
	}

	function checkFormFilled() {
		let allFilled = true;
		form.find('input[required]').each(function() {
			if ($(this).attr('type') === 'file') {
				if (this.files.length === 0) allFilled = false;
			} else {
				if ($(this).val() === '') allFilled = false;
			}
		});
		form.find('select[required]').each(function() {
			if ($(this).val() === '' || $(this).val() === null) allFilled = false;
		});
		if (allFilled) submitBtn.removeAttr('disabled');
		else submitBtn.attr('disabled', 'disabled');
	}

	form.on('change keyup', 'input, select', checkFormFilled);
	form.on('input change keyup', 'input, select', function() { $(this).removeClass('cre-field-error'); });

	fileInput.on('change', function() {
		if (this.files.length > 0) {
			const fileName = this.files[0].name;
			const ext = fileName.split('.').pop().toLowerCase();
			if (ext !== 'docx') { alert('Only .docx files are allowed.'); $(this).val(''); return; }

			progressBarInner.css('width', '0%');
			progressBar.show();
			uploadSuccess.hide();
			let width = 0;
			const interval = setInterval(function() {
				if (width >= 100) {
					clearInterval(interval);
					progressBar.hide();
					uploadSuccess.text('✅ Document uploaded successfully').show();
					$('#cre-upload-label-text').text(fileName);
					$('.cre-upload-area').addClass('uploaded');
					checkFormFilled();
				} else {
					width += 10;
					progressBarInner.css('width', width + '%');
				}
			}, 50);
		}
	});

	form.on('submit', function(e) {
		e.preventDefault();
		let hasError = false;
		form.find('input[required], select[required]').each(function() {
			const val = $(this).val();
			if ($(this).attr('type') === 'file') {
				if (this.files.length === 0) { $(this).closest('.cre-upload-area').css('border-color', '#ef4444'); hasError = true; }
			} else if (val === '' || val === null) { $(this).addClass('cre-field-error'); hasError = true; }
		});

		if (hasError) return;

		form.slideUp();
		$('#cre-form-desc').slideUp(); 
		
		$('#cre-loading').find('p').text('Your profile is being analyzed to create a personalized roadmap for you. Please wait a moment while we prepare everything...');
		$('#cre-loading').fadeIn();

		const formData = new FormData(this);
		formData.append('action', 'cre_process_form');
		formData.append('nonce', cre_vars.nonce);
		formData.append('current_url', window.location.protocol + "//" + window.location.host + window.location.pathname);

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
					currentSessionId = response.data.session_id;
					lastResponseData = response.data;
					
					const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?cre_report=' + currentSessionId;
					window.history.pushState({path:newUrl}, '', newUrl);

				} else {
					// ---> NEW: THE KILL-SWITCH UI HANDLING <---
					// Re-show the form and display a red error message at the top
					form.slideDown();
					$('#cre-form-desc').slideDown();
					
					if ($('#form-error-message').length === 0) {
						form.prepend('<div id="form-error-message" style="display:none; background:#fee2e2; color:#ef4444; border:1px solid #f87171; padding:15px; border-radius:8px; margin-bottom:20px; font-weight:bold; text-align:center;"></div>');
					}
					$('#form-error-message').text(response.data).slideDown();
					
					// Scroll to top so user sees the error
					$('html, body').animate({ scrollTop: form.offset().top - 100 }, 500);
				}
			},
			error: function() {
				$('#cre-loading').hide();
				$('#cre-results').html('<div class="cre-clean-card error-card"><h3>Server Error</h3><p>Please try again later.</p></div>');
			}
		});
	});

	$(document).on('click', '#cre-unlock-btn', function(e) {
		e.preventDefault();
		const btn = $(this);
		
		if (!cre_vars.stripe_pub_key) {
			alert('Payment gateway is not configured in WordPress Admin.');
			return;
		}

		btn.text('Loading Secure Checkout...').attr('disabled', 'disabled');

		$.ajax({
			url: cre_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'cre_create_payment_intent',
				nonce: cre_vars.nonce,
				session_id: currentSessionId
			},
			success: function(response) {
				if (response.success) {
					btn.hide(); 
					const container = $('#stripe-payment-element-container');
					container.slideDown();

					stripe = Stripe(cre_vars.stripe_pub_key);
					const clientSecret = response.data.client_secret;
					
					const appearance = { theme: 'stripe' };
					elements = stripe.elements({ appearance, clientSecret });

					const paymentElement = elements.create('payment');
					paymentElement.mount('#stripe-payment-element-container');

					container.append(`
						<button id="cre-submit-payment" class="cre-submit-btn" style="margin-top:20px; background:#16a34a;">Pay $1.00 & Reveal Full Report</button>
						<div id="payment-message" style="color:#ef4444; font-weight:bold; margin-top:15px; display:none;"></div>
					`);
				} else {
					alert(response.data);
					btn.text('Unlock Full Report – $1.00').removeAttr('disabled');
				}
			},
			error: function() {
				alert('Connection error.');
				btn.text('Unlock Full Report – $1.00').removeAttr('disabled');
			}
		});
	});

	$(document).on('click', '#cre-submit-payment', async function(e) {
		e.preventDefault();
		const payBtn = $(this);
		const messageBox = $('#payment-message');
		
		payBtn.text('Processing...').attr('disabled', 'disabled');
		messageBox.hide();

		const { error } = await stripe.confirmPayment({
			elements,
			redirect: 'if_required' 
		});

		if (error) {
			messageBox.text(error.message).show();
			payBtn.text('Pay $1.00 & Reveal Full Report').removeAttr('disabled');
		} else {
			payBtn.text('Payment Successful! Unlocking...').css('background', '#0f172a');
			
			$.ajax({
				url: cre_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'cre_unlock_report',
					nonce: cre_vars.nonce,
					session_id: currentSessionId
				},
				success: function(unlockResponse) {
					if (unlockResponse.success) {
						$('#cre-results').html(unlockResponse.data.html);
						lastResponseData = unlockResponse.data;
					} else {
						messageBox.text('Error unlocking report. Please contact support.').show();
					}
				}
			});
		}
	});

	$(document).on('click', '#cre-btn-email', function(e) {
		e.preventDefault();
		const btn = $(this);
		const status = $('#cre-email-status');
		
		if (!lastResponseData || !lastResponseData.raw_data || !currentSessionId) return;

		btn.attr('disabled', 'disabled').text('Sending Email...');
		status.text('');

		const reportUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?cre_report=' + currentSessionId;

		$.ajax({
			url: cre_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'cre_send_email',
				nonce: cre_vars.nonce,
				email: lastResponseData.user_email,
				raw_data: lastResponseData.raw_data,
				report_url: reportUrl
			},
			success: function(response) {
				if (response.success) {
					btn.text('Email Sent!').css({'background': '#16a34a', 'border-color': '#16a34a'});
					status.text('Check your inbox (and spam folder) for your link.').css('color', '#0f172a');
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