jQuery(document).ready(function($) {
	'use strict';

	function getErrorMessage(response, fallback) {
		if (response && response.data && response.data.message) {
			return response.data.message;
		}
		return fallback;
	}

	function setConnectionStatus(type, message) {
		const $status = $('#abw-test-connection-status');
		if (!$status.length) {
			return;
		}

		$status
			.removeClass('is-success is-error is-loading')
			.addClass(type ? 'is-' + type : '')
			.text(message || '');
	}

	$('#abw-test-connection').on('click', function() {
		if (typeof abwAdmin === 'undefined') {
			return;
		}

		const $button = $(this);
		const defaultLabel = $button.text();
		$button.prop('disabled', true).text(abwAdmin.i18n && abwAdmin.i18n.testing ? abwAdmin.i18n.testing : 'Testing...');
		setConnectionStatus('loading', abwAdmin.i18n && abwAdmin.i18n.testing_message ? abwAdmin.i18n.testing_message : 'Checking saved provider settings...');

		$.ajax({
			url: abwAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abw_test_ai_connection',
				nonce: abwAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					setConnectionStatus('success', response.data.message);
				} else {
					setConnectionStatus('error', getErrorMessage(response, abwAdmin.i18n ? abwAdmin.i18n.error : 'Unable to test the connection.'));
				}
			},
			error: function(xhr) {
				const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
					? xhr.responseJSON.data.message
					: (abwAdmin.i18n ? abwAdmin.i18n.error : 'Unable to test the connection.');
				setConnectionStatus('error', message);
			},
			complete: function() {
				$button.prop('disabled', false).text(defaultLabel);
			}
		});
	});

	// =========================================================================
	// Background Jobs Page
	// =========================================================================

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
				job_id: jobId
			},
			success: function(response) {
				if (response.success) {
					const $row = $btn.closest('tr');
					$row.find('.abw-job-badge')
						.removeClass('abw-job-badge-failed')
						.addClass('abw-job-badge-pending')
						.text('Pending');
					$btn.remove();
				} else {
					alert(getErrorMessage(response, 'Error retrying job'));
					$btn.prop('disabled', false).text('Retry');
				}
			},
			error: function(xhr) {
				const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
					? xhr.responseJSON.data.message
					: (abwAdmin.i18n ? abwAdmin.i18n.error : 'An error occurred.');
				alert(message);
				$btn.prop('disabled', false).text('Retry');
			}
		});
	});

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
				job_id: jobId
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
					alert(getErrorMessage(response, 'Error cancelling job'));
					$btn.prop('disabled', false).text('Cancel');
				}
			},
			error: function(xhr) {
				const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
					? xhr.responseJSON.data.message
					: (abwAdmin.i18n ? abwAdmin.i18n.error : 'An error occurred.');
				alert(message);
				$btn.prop('disabled', false).text('Cancel');
			}
		});
	});

	let jobsRefreshInterval = null;

	function startJobsAutoRefresh() {
		if (jobsRefreshInterval || !$('#abw-jobs-table-container').length) {
			return;
		}

		jobsRefreshInterval = setInterval(function() {
			if (!$('#abw-jobs-auto-refresh').is(':checked') || typeof abwAdmin === 'undefined') {
				return;
			}

			$.ajax({
				url: abwAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'abw_admin_job_list',
					nonce: abwAdmin.nonce,
					page: 1
				},
				success: function(response) {
					if (!response.success) {
						return;
					}

					const jobs = response.data.jobs || [];
					const hasActive = jobs.some(function(job) {
						return job.status === 'pending' || job.status === 'processing';
					});

					if (hasActive) {
						location.reload();
					}
				}
			});
		}, 5000);
	}

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

