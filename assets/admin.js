jQuery(document).ready(function($) {
	// Select All functionality
	const $selectAll = $('#abw-select-all-scopes');
	const $scopeCheckboxes = $('.abw-scope-checkbox');

	// Handle Select All checkbox
	$selectAll.on('change', function() {
		const isChecked = $(this).is(':checked');
		$scopeCheckboxes.prop('checked', isChecked);
	});

	// Update Select All checkbox state when individual checkboxes change
	$scopeCheckboxes.on('change', function() {
		const totalCheckboxes = $scopeCheckboxes.length;
		const checkedCheckboxes = $scopeCheckboxes.filter(':checked').length;
		
		// Update Select All checkbox state
		if (checkedCheckboxes === 0) {
			$selectAll.prop('checked', false);
			$selectAll.prop('indeterminate', false);
		} else if (checkedCheckboxes === totalCheckboxes) {
			$selectAll.prop('checked', true);
			$selectAll.prop('indeterminate', false);
		} else {
			$selectAll.prop('checked', false);
			$selectAll.prop('indeterminate', true);
		}
	});

	// Initialize Select All state on page load
	const totalCheckboxes = $scopeCheckboxes.length;
	const checkedCheckboxes = $scopeCheckboxes.filter(':checked').length;
	if (checkedCheckboxes === totalCheckboxes && totalCheckboxes > 0) {
		$selectAll.prop('checked', true);
	} else if (checkedCheckboxes > 0) {
		$selectAll.prop('indeterminate', true);
	}

	// Create token form
	$('#abw-create-token-form').on('submit', function(e) {
		e.preventDefault();

		const scopes = [];
		$('input[name="scopes[]"]:checked').each(function() {
			scopes.push($(this).val());
		});

		const expires = parseInt($('#token-expires').val()) * 24 * 60 * 60 * 1000; // Convert to milliseconds
		const expiresTimestamp = Math.floor(Date.now() / 1000) + (parseInt($('#token-expires').val()) * 24 * 60 * 60);

		$.ajax({
			url: abwAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abw_create_token',
				nonce: abwAdmin.nonce,
				scopes: scopes,
				expires: expiresTimestamp,
			},
			success: function(response) {
				if (response.success) {
					$('#abw-token-display').val(response.data.token);
					$('#abw-token-modal').show();
				} else {
					alert('Error: ' + (response.data?.message || 'Unknown error'));
				}
			},
			error: function() {
				alert('An error occurred. Please try again.');
			}
		});
	});

	// Copy token
	$('#abw-copy-token').on('click', function() {
		const tokenInput = $('#abw-token-display');
		tokenInput.select();
		document.execCommand('copy');
		$(this).text('Copied!');
		setTimeout(() => {
			$(this).text('Copy Token');
		}, 2000);
	});

	// Close modal
	$('.abw-modal-close').on('click', function() {
		$('.abw-modal').hide();
		location.reload(); // Reload to show new token in list
	});

	// Revoke token
	$('.abw-revoke-token').on('click', function() {
		if (!confirm('Are you sure you want to revoke this token?')) {
			return;
		}

		const tokenId = $(this).data('token-id');
		const $row = $(this).closest('tr');

		$.ajax({
			url: abwAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abw_revoke_token',
				nonce: abwAdmin.nonce,
				token_id: tokenId,
			},
			success: function(response) {
				if (response.success) {
					$row.fadeOut(function() {
						$(this).remove();
					});
				} else {
					alert('Error: ' + (response.data?.message || 'Unknown error'));
				}
			},
			error: function() {
				alert('An error occurred. Please try again.');
			}
		});
	});

	// =========================================================================
	// Background Jobs Page
	// =========================================================================

	// Retry job
	$(document).on('click', '.abw-retry-job', function() {
		if (typeof abwAdmin === 'undefined') return;
		if (!confirm(abwAdmin.i18n ? abwAdmin.i18n.retry_confirm : 'Retry this job?')) return;

		const $btn = $(this);
		const jobId = $btn.data('job-id');
		$btn.prop('disabled', true).text('Retrying...');

		$.ajax({
			url: abwAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abw_retry_job',
				nonce: abwAdmin.nonce,
				job_id: jobId,
			},
			success: function(response) {
				if (response.success) {
					// Update the row visually.
					const $row = $btn.closest('tr');
					$row.find('.abw-job-badge')
						.removeClass('abw-job-badge-failed')
						.addClass('abw-job-badge-pending')
						.text('Pending');
					$btn.remove();
				} else {
					alert(response.data?.message || 'Error retrying job');
					$btn.prop('disabled', false).text('Retry');
				}
			},
			error: function() {
				alert(abwAdmin.i18n ? abwAdmin.i18n.error : 'An error occurred.');
				$btn.prop('disabled', false).text('Retry');
			}
		});
	});

	// Cancel job
	$(document).on('click', '.abw-cancel-job', function() {
		if (typeof abwAdmin === 'undefined') return;
		if (!confirm(abwAdmin.i18n ? abwAdmin.i18n.cancel_confirm : 'Cancel this job?')) return;

		const $btn = $(this);
		const jobId = $btn.data('job-id');
		$btn.prop('disabled', true).text('Cancelling...');

		$.ajax({
			url: abwAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abw_cancel_job',
				nonce: abwAdmin.nonce,
				job_id: jobId,
			},
			success: function(response) {
				if (response.success) {
					const $row = $btn.closest('tr');
					$row.find('.abw-job-badge')
						.removeClass('abw-job-badge-pending')
						.addClass('abw-job-badge-cancelled')
						.text('Cancelled');
					$btn.remove();
				} else {
					alert(response.data?.message || 'Error cancelling job');
					$btn.prop('disabled', false).text('Cancel');
				}
			},
			error: function() {
				alert(abwAdmin.i18n ? abwAdmin.i18n.error : 'An error occurred.');
				$btn.prop('disabled', false).text('Cancel');
			}
		});
	});

	// Auto-refresh for Background Jobs page
	let jobsRefreshInterval = null;

	function startJobsAutoRefresh() {
		if (jobsRefreshInterval) return;
		if (!$('#abw-jobs-table-container').length) return;

		jobsRefreshInterval = setInterval(function() {
			if (!$('#abw-jobs-auto-refresh').is(':checked')) return;
			if (typeof abwAdmin === 'undefined') return;

			$.ajax({
				url: abwAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'abw_admin_job_list',
					nonce: abwAdmin.nonce,
					page: 1,
				},
				success: function(response) {
					if (!response.success) return;

					// Reload the page if job statuses have changed.
					// For simplicity, we do a full page reload every 5 seconds
					// when auto-refresh is on and there are active (pending/processing) jobs.
					const jobs = response.data.jobs || [];
					const hasActive = jobs.some(function(j) {
						return j.status === 'pending' || j.status === 'processing';
					});

					if (hasActive) {
						location.reload();
					}
				}
			});
		}, 5000);
	}

	// Start auto-refresh if we're on the jobs page.
	if ($('#abw-jobs-table-container').length || $('#abw-jobs-auto-refresh').length) {
		startJobsAutoRefresh();
	}

	$('#abw-jobs-auto-refresh').on('change', function() {
		if ($(this).is(':checked')) {
			startJobsAutoRefresh();
		} else if (jobsRefreshInterval) {
			clearInterval(jobsRefreshInterval);
			jobsRefreshInterval = null;
		}
	});
});

