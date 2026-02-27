/**
 * Best Offer Sync - Admin JavaScript
 *
 * Handles AJAX sync operations with real-time progress updates.
 *
 * @package Best_Offer_Sync
 * @since 1.2.0
 */

(function($) {
	'use strict';

	const BestOfferSync = {
		// State
		syncRunning: false,
		syncInterval: null,
		currentXmlFile: null,
		progressCheckInterval: null,

		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
			this.checkExistingSync();
		// History is already rendered by PHP, no need to load on init
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function() {
			// File upload
			$('#bestoffer-xml-file').on('change', this.handleFileUpload.bind(this));

			// File selection from previous uploads
			$('#bestoffer-select-xml').on('change', this.handleFileSelection.bind(this));

			// Start sync
			$('#bestoffer-start-sync').on('click', this.startSync.bind(this));

			// Cancel sync
			$('#bestoffer-cancel-sync').on('click', this.cancelSync.bind(this));

			// Delete log
			$(document).on('click', '.bestoffer-delete-log', this.deleteLog.bind(this));

			// Refresh history
			$('#bestoffer-refresh-history').on('click', this.loadSyncHistory.bind(this));
		},

		/**
		 * Check if sync is already running
		 */
		checkExistingSync: function() {
			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'bestoffer_get_progress',
					nonce: bestofferAdmin.nonce
				},
				success: (response) => {
					if (response.success && response.data.status === 'running') {
						this.resumeSync();
					}
				}
			});
		},

		/**
		 * Handle file upload
		 */
		handleFileUpload: function(e) {
			const file = e.target.files[0];

			if (!file) {
				return;
			}

			// Validate file type
			if (!file.name.toLowerCase().endsWith('.xml')) {
				this.showNotice('error', 'Please upload an XML file');
				return;
			}

			this.showNotice('info', 'Uploading file...');
			$('#bestoffer-upload-progress').show();

			const formData = new FormData();
			formData.append('action', 'bestoffer_upload_xml');
			formData.append('nonce', bestofferAdmin.nonce);
			formData.append('xml_file', file);

			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				xhr: function() {
					const xhr = new window.XMLHttpRequest();
					xhr.upload.addEventListener('progress', function(e) {
						if (e.lengthComputable) {
							const percent = Math.round((e.loaded / e.total) * 100);
							$('#bestoffer-upload-progress .progress-bar').css('width', percent + '%');
							$('#bestoffer-upload-progress .progress-text').text(percent + '%');
						}
					}, false);
					return xhr;
				},
				success: (response) => {
					$('#bestoffer-upload-progress').hide();

					if (response.success) {
						this.currentXmlFile = response.data.filepath;
						$('#bestoffer-current-file').text(response.data.filename);
						$('#bestoffer-product-count').text(response.data.product_count + ' products');
						$('#bestoffer-file-info').show();
						$('#bestoffer-start-sync').prop('disabled', false);
						this.showNotice('success', 'File uploaded successfully: ' + response.data.product_count + ' products found');
					} else {
						this.showNotice('error', response.data.message || 'Upload failed');
					}
				},
				error: () => {
					$('#bestoffer-upload-progress').hide();
					this.showNotice('error', 'Upload failed. Please try again.');
				}
			});
		},

		/**
		 * Handle file selection from dropdown
		 */
		handleFileSelection: function(e) {
			const filePath = $(e.target).val();

			if (filePath) {
				this.currentXmlFile = filePath;
				$('#bestoffer-current-file').text(filePath.split('/').pop());
				$('#bestoffer-file-info').show();
				$('#bestoffer-start-sync').prop('disabled', false);
			}
		},

		/**
		 * Start sync process
		 */
		startSync: function() {
			if (!this.currentXmlFile) {
				this.showNotice('error', 'Please select or upload an XML file first');
				return;
			}

			if (this.syncRunning) {
				this.showNotice('warning', 'Sync is already running');
				return;
			}

			// Get settings
			const settings = {
				dry_run: $('#bestoffer-dry-run').is(':checked')
			};

			this.showNotice('info', 'Starting sync...');

			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'bestoffer_start_sync',
					nonce: bestofferAdmin.nonce,
					xml_file: this.currentXmlFile,
					settings: settings
				},
				success: (response) => {
					if (response.success) {
						this.syncRunning = true;
						this.updateUIForRunningSync();
						this.showNotice('success', 'Sync started! Processing ' + response.data.total_products + ' products...');
						this.startProgressMonitoring();
					} else {
						this.showNotice('error', response.data.message || 'Failed to start sync');
					}
				},
				error: () => {
					this.showNotice('error', 'Failed to start sync. Please try again.');
				}
			});
		},

		/**
		 * Process next batch
		 */
		processBatch: function() {
			if (!this.syncRunning) {
				return;
			}

			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'bestoffer_process_batch',
					nonce: bestofferAdmin.nonce
				},
				success: (response) => {
					if (response.success) {
						this.updateProgress(response.data);

						if (response.data.complete) {
							this.syncComplete(response.data);
						} else {
							// Process next batch
							setTimeout(() => this.processBatch(), 500);
						}
					} else {
						this.syncFailed(response.data.message);
					}
				},
				error: (xhr, status, error) => {
					this.syncFailed('Network error: ' + error);
				}
			});
		},

		/**
		 * Start progress monitoring
		 */
		startProgressMonitoring: function() {
			// Start processing batches
			this.processBatch();

			// Also poll for progress updates
			this.progressCheckInterval = setInterval(() => {
				this.checkProgress();
			}, 2000);
		},

		/**
		 * Check progress
		 */
		checkProgress: function() {
			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'bestoffer_get_progress',
					nonce: bestofferAdmin.nonce
				},
				success: (response) => {
					if (response.success) {
						if (response.data.status === 'idle') {
							this.stopProgressMonitoring();
						} else if (response.data.status === 'completed' || response.data.status === 'failed') {
							this.stopProgressMonitoring();
							this.updateProgress(response.data);
						}
					}
				}
			});
		},

		/**
		 * Stop progress monitoring
		 */
		stopProgressMonitoring: function() {
			if (this.progressCheckInterval) {
				clearInterval(this.progressCheckInterval);
				this.progressCheckInterval = null;
			}
		},

		/**
		 * Resume existing sync
		 */
		resumeSync: function() {
			this.syncRunning = true;
			this.updateUIForRunningSync();
			this.showNotice('info', 'Resuming previous sync...');
			this.startProgressMonitoring();
		},

		/**
		 * Update progress display
		 */
		updateProgress: function(data) {
			// Update progress bar
			$('#bestoffer-progress-bar').css('width', data.percentage + '%');
			$('#bestoffer-progress-text').text(data.percentage + '%');

			// Update stats
			$('#bestoffer-processed').text(data.processed);
			$('#bestoffer-total').text(data.total);
			$('#bestoffer-current-batch').text(data.current_batch);

			// Update detailed stats
			if (data.stats) {
				$('#bestoffer-stat-updated').text(data.stats.updated || 0);
				$('#bestoffer-stat-created').text(data.stats.created || 0);
				$('#bestoffer-stat-created-draft').text(data.stats.created_as_draft || 0);
				$('#bestoffer-stat-unchanged').text(data.stats.unchanged || 0);
				$('#bestoffer-stat-locked').text(data.stats.locked || 0);
				$('#bestoffer-stat-errors').text(data.stats.errors || 0);
				$('#bestoffer-stat-not-found').text(data.stats.not_found || 0);
			}

			// Update elapsed time
			if (data.elapsed_time) {
				const minutes = Math.floor(data.elapsed_time / 60);
				const seconds = data.elapsed_time % 60;
				$('#bestoffer-elapsed-time').text(minutes + 'm ' + seconds + 's');
			}

			// Show progress section
			$('#bestoffer-sync-progress').show();
		},

		/**
		 * Sync completed successfully
		 */
		syncComplete: function(data) {
			this.syncRunning = false;
			this.stopProgressMonitoring();
			this.updateUIForIdleSync();

			let message = 'Sync completed successfully! ';
			message += 'Processed: ' + data.processed + ' products. ';

			if (data.stats) {
				if (data.stats.created > 0) {
					message += 'Created: ' + data.stats.created + '. ';
				}
				if (data.stats.updated > 0) {
					message += 'Updated: ' + data.stats.updated + '. ';
				}
			}

			this.showNotice('success', message);

			// Reload history
			setTimeout(() => {
				location.reload();
			}, 2000);
		},

		/**
		 * Sync failed
		 */
		syncFailed: function(message) {
			this.syncRunning = false;
			this.stopProgressMonitoring();
			this.updateUIForIdleSync();
			this.showNotice('error', 'Sync failed: ' + message);
		},

		/**
		 * Cancel sync
		 */
		cancelSync: function() {
			if (!this.syncRunning) {
				return;
			}

			if (!confirm('Are you sure you want to cancel the sync?')) {
				return;
			}

			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'bestoffer_cancel_sync',
					nonce: bestofferAdmin.nonce
				},
				success: (response) => {
					if (response.success) {
						this.syncRunning = false;
						this.stopProgressMonitoring();
						this.updateUIForIdleSync();
						this.showNotice('warning', 'Sync cancelled');
					}
				}
			});
		},

		/**
		 * Update UI for running sync
		 */
		updateUIForRunningSync: function() {
			$('#bestoffer-start-sync').prop('disabled', true).addClass('disabled');
			$('#bestoffer-cancel-sync').show();
			$('#bestoffer-xml-file').prop('disabled', true);
			$('#bestoffer-select-xml').prop('disabled', true);
			$('#bestoffer-sync-status').addClass('running').text('Running...');
		},

		/**
		 * Update UI for idle sync
		 */
		updateUIForIdleSync: function() {
			$('#bestoffer-start-sync').prop('disabled', false).removeClass('disabled');
			$('#bestoffer-cancel-sync').hide();
			$('#bestoffer-xml-file').prop('disabled', false);
			$('#bestoffer-select-xml').prop('disabled', false);
			$('#bestoffer-sync-status').removeClass('running').text('Ready');
		},

		/**
		 * Load sync history
		 */
		loadSyncHistory: function() {
			// Reload page to get fresh data
			location.reload();
		},

		/**
		 * Delete sync log
		 */
		deleteLog: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const logId = $button.data('log-id');

			if (!confirm('Are you sure you want to delete this sync log?')) {
				return;
			}

			$.ajax({
				url: bestofferAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'enviweb_bestoffer_delete_log',
					nonce: bestofferAdmin.nonce,
					log_id: logId
				},
				success: (response) => {
					if (response.success) {
						$button.closest('tr').fadeOut(400, function() {
							$(this).remove();
						});
						this.showNotice('success', 'Log deleted successfully');
					} else {
						this.showNotice('error', 'Failed to delete log');
					}
				}
			});
		},

		/**
		 * Show admin notice
		 */
		showNotice: function(type, message) {
			const $notice = $('<div>')
				.addClass('notice notice-' + type + ' is-dismissible bestoffer-notice')
				.html('<p>' + message + '</p>');

			$('.bestoffer-notices').empty().append($notice);

			// Auto dismiss after 5 seconds for success/info
			if (type === 'success' || type === 'info') {
				setTimeout(() => {
					$notice.fadeOut(400, function() {
						$(this).remove();
					});
				}, 5000);
			}
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		BestOfferSync.init();
	});

})(jQuery);
