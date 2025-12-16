/**
 * Best Offer Sync - Admin Scripts
 * Author: EnviWeb (https://enviweb.gr)
 */

(function($) {
	'use strict';

	/**
	 * Initialize when DOM is ready
	 */
	$(document).ready(function() {
		initDeleteLog();
		initAutoRefresh();
	});

	/**
	 * Initialize delete log functionality
	 */
	function initDeleteLog() {
		$('.bestoffer-delete-log').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var logId = $button.data('log-id');

			if (!confirm('Are you sure you want to delete this log entry?')) {
				return;
			}

			// Disable button and show loading
			$button.prop('disabled', true).text('Deleting...');

			// Send AJAX request
			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'enviweb_bestoffer_delete_log',
					nonce: bestofferAdmin.nonce,
					log_id: logId
				},
				success: function(response) {
					if (response.success) {
						// Remove the row with animation
						$button.closest('tr').fadeOut(300, function() {
							$(this).remove();
							
							// Check if there's an error row below
							var $nextRow = $(this).next('tr.bestoffer-error-row');
							if ($nextRow.length) {
								$nextRow.fadeOut(300, function() {
									$(this).remove();
								});
							}
						});
					} else {
						alert('Error: ' + (response.data.message || 'Failed to delete log'));
						$button.prop('disabled', false).text('Delete');
					}
				},
				error: function() {
					alert('Error: Failed to communicate with server');
					$button.prop('disabled', false).text('Delete');
				}
			});
		});
	}

	/**
	 * Auto-refresh page if a sync is running
	 */
	function initAutoRefresh() {
		// Check if there's a running sync
		var $runningBadge = $('.status-badge.status-running');
		
		if ($runningBadge.length > 0) {
			// Refresh page every 30 seconds
			setTimeout(function() {
				location.reload();
			}, 30000);

			// Add visual indicator
			$runningBadge.append(' <span class="bestoffer-loading"></span>');
		}
	}

	/**
	 * Format number with commas
	 */
	function formatNumber(num) {
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
	}

	/**
	 * Format date
	 */
	function formatDate(dateString) {
		var date = new Date(dateString);
		return date.toLocaleString();
	}

})(jQuery);

