(function ($) {
	'use strict';

	var isProcessing = false;
	var isPaused = false;
	var retryCount = 0;

	function startImport() {
		if (isProcessing) {
			return;
		}

		isProcessing = true;
		isPaused = false;
		$('#wti-start-import').prop('disabled', true);
		$('#wti-pause-import').show();
		$('#wti-resume-import').hide();
		$('#wti-progress-wrap').show();
		$('#wti-log-output').text('');
		updateProgress({ total: 0, processed: 0 });
		updateStatus('Preparing Totobi feed...');
		addLog('Preparing Totobi feed...');

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_start_import',
			_wpnonce: wtiAdmin.nonce
		}).done(function (response) {
			if (!response.success) {
				addLog('ERROR: ' + (response.data || 'Cannot start sync'));
				resetUi();
				return;
			}

			updateProgress(response.data);
			updateStatus('Sync started. Processing first batch...');
			addLog('Sync started. Products: ' + response.data.total + ', catalog: ' + response.data.catalog_date);
			processBatch();
		}).fail(function () {
			addLog('ERROR: AJAX start request failed');
			resetUi();
		});
	}

	function processBatch() {
		if (isPaused || !isProcessing) {
			return;
		}

		updateStatus('Processing next batch...');

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_process_batch',
			_wpnonce: wtiAdmin.nonce,
			simple_batch_size: 50,
			variable_batch_size: 8
		}).done(function (response) {
			if (!response.success) {
				addLog('ERROR: ' + (response.data || 'Batch failed'));
				resetUi();
				return;
			}

			var data = response.data;
			retryCount = 0;
			updateProgress(data);
			updateStatus(statusText(data));
			(data.log_entries || []).forEach(addLog);

			if (data.completed) {
				addLog('Sync completed.');
				updateStatus('Sync completed.');
				resetUi();
				return;
			}

			window.setTimeout(processBatch, 500);
		}).fail(function () {
			retryCount++;
			addLog('ERROR: batch request failed. Retry ' + retryCount + ' in 5 seconds...');
			updateStatus('Batch request failed. Retrying...');
			window.setTimeout(processBatch, 5000);
		});
	}

	function pauseImport() {
		isPaused = true;
		$('#wti-pause-import').hide();
		$('#wti-resume-import').show();
		updateStatus('Sync paused.');
		addLog('Sync paused.');

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_pause_import',
			_wpnonce: wtiAdmin.nonce
		});
	}

	function resumeImport() {
		isPaused = false;
		$('#wti-pause-import').show();
		$('#wti-resume-import').hide();
		updateStatus('Sync resumed. Processing next batch...');
		addLog('Sync resumed.');

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_resume_import',
			_wpnonce: wtiAdmin.nonce
		}).always(processBatch);
	}

	function updateProgress(data) {
		var total = parseInt(data.total || 0, 10);
		var processed = parseInt(data.processed || 0, 10);
		var percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

		$('.wti-progress-bar').css('width', percent + '%');
		$('.wti-progress-percent').text(percent + '%');
		$('#wti-stat-processed').text(processed);
		$('#wti-stat-total').text(total);
		$('#wti-stat-created-simple').text(data.created_simple || 0);
		$('#wti-stat-updated-simple').text(data.updated_simple || 0);
		$('#wti-stat-created-variable').text(data.created_variable || 0);
		$('#wti-stat-updated-variable').text(data.updated_variable || 0);
		$('#wti-stat-created-variation').text(data.created_variation || 0);
		$('#wti-stat-updated-variation').text(data.updated_variation || 0);
		$('#wti-stat-images').text(data.imported_images || 0);
		$('#wti-stat-skipped').text(data.skipped_unchanged || 0);
		$('#wti-stat-errors').text(data.errors || 0);
	}

	function addLog(message) {
		var $log = $('#wti-log-output');
		var $item = $('<div/>').text(message);
		$log.append($item);
		while ($log.children().length > 40) {
			$log.children().first().remove();
		}
		$log.scrollTop($log[0].scrollHeight);
	}

	function updateStatus(message) {
		$('#wti-progress-status-text').text(message);
	}

	function statusText(data) {
		var stage = data.stage === 'variable' ? 'variable products' : 'simple products';
		return 'Processing ' + stage + ': ' + (data.processed || 0) + ' of ' + (data.total || 0);
	}

	function resetUi() {
		isProcessing = false;
		isPaused = false;
		$('#wti-start-import').prop('disabled', false);
		$('#wti-pause-import').hide();
		$('#wti-resume-import').hide();
	}

	$(function () {
		$('#wti-start-import').on('click', startImport);
		$('#wti-pause-import').on('click', pauseImport);
		$('#wti-resume-import').on('click', resumeImport);
	});
})(jQuery);
