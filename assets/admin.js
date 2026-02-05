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
});

