(function ($) {
	'use strict';

	var isProcessing = false;
	var isPaused = false;
	var retryCount = 0;
	var strings = wtiAdmin.strings || {};

	function t(key, fallback, replacements) {
		var text = strings[key] || fallback;
		Object.keys(replacements || {}).forEach(function (name) {
			text = text.replace('%' + name + '%', replacements[name]);
		});
		return text;
	}

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
		updateStatus(t('preparing', 'Preparing Totobi feed...'));
		addLog(t('preparing', 'Preparing Totobi feed...'));

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_start_import',
			_wpnonce: wtiAdmin.nonce
		}).done(function (response) {
			if (!response.success) {
				addLog(t('errorPrefix', 'ERROR:') + ' ' + (response.data || t('cannotStart', 'Cannot start sync')));
				resetUi();
				return;
			}

			updateProgress(response.data);
			updateStatus(t('syncStarted', 'Sync started. Processing first batch...'));
			addLog(t('startedLog', 'Sync started. Products: %total%, catalog: %catalog%', {
				total: response.data.total,
				catalog: response.data.catalog_date
			}));
			processBatch();
		}).fail(function () {
			addLog(t('errorPrefix', 'ERROR:') + ' ' + t('ajaxStartFailed', 'AJAX start request failed'));
			resetUi();
		});
	}

	function processBatch() {
		if (isPaused || !isProcessing) {
			return;
		}

		updateStatus(t('processingBatch', 'Processing next batch...'));

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_process_batch',
			_wpnonce: wtiAdmin.nonce,
			simple_batch_size: 50,
			variable_batch_size: 8
		}).done(function (response) {
			if (!response.success) {
				addLog(t('errorPrefix', 'ERROR:') + ' ' + (response.data || t('batchFailed', 'Batch failed')));
				resetUi();
				return;
			}

			var data = response.data;
			retryCount = 0;
			updateProgress(data);
			updateStatus(statusText(data));
			(data.log_entries || []).forEach(addLog);

			if (data.completed) {
				addLog(t('syncCompleted', 'Sync completed.'));
				updateStatus(t('syncCompleted', 'Sync completed.'));
				resetUi();
				return;
			}

			window.setTimeout(processBatch, 500);
		}).fail(function () {
			retryCount++;
			addLog(t('errorPrefix', 'ERROR:') + ' ' + t('retryBatch', 'Batch request failed. Retry %count% in 5 seconds...', { count: retryCount }));
			updateStatus(t('retrying', 'Batch request failed. Retrying...'));
			window.setTimeout(processBatch, 5000);
		});
	}

	function pauseImport() {
		isPaused = true;
		$('#wti-pause-import').hide();
		$('#wti-resume-import').show();
		updateStatus(t('syncPaused', 'Sync paused.'));
		addLog(t('syncPaused', 'Sync paused.'));

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_pause_import',
			_wpnonce: wtiAdmin.nonce
		});
	}

	function resumeImport() {
		isPaused = false;
		$('#wti-pause-import').show();
		$('#wti-resume-import').hide();
		updateStatus(t('resumedStatus', 'Sync resumed. Processing next batch...'));
		addLog(t('syncResumed', 'Sync resumed.'));

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_resume_import',
			_wpnonce: wtiAdmin.nonce
		}).always(processBatch);
	}

	function resetImport() {
		if (!window.confirm(t('resetConfirm', 'Reset the current sync session?'))) {
			return;
		}

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_reset_import',
			_wpnonce: wtiAdmin.nonce
		}).always(function () {
			resetUi();
			$('#wti-log-output').text('');
			updateProgress({ total: 0, processed: 0 });
			updateStatus(t('resetDone', 'Sync session reset.'));
			addLog(t('resetDone', 'Sync session reset.'));
		});
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
		$('#wti-stat-deleted').text(data.deleted_outofstock || 0);
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
		var stage = data.stage === 'deleted' ? t('deletedProducts', 'missing products') : (data.stage === 'variable' ? t('variableProducts', 'variable products') : t('simpleProducts', 'simple products'));
		return t('processingStage', 'Processing %stage%: %processed% of %total%', {
			stage: stage,
			processed: data.processed || 0,
			total: data.total || 0
		});
	}

	function resetUi() {
		isProcessing = false;
		isPaused = false;
		$('#wti-start-import').prop('disabled', false);
		$('#wti-pause-import').hide();
		$('#wti-resume-import').hide();
	}

	function updateCategoryModeControls() {
		var isManual = $('input[name="category_mode"]:checked').val() === 'manual';
		$('.wti-manual-category-controls').toggleClass('is-disabled', !isManual);
		$('.wti-manual-category-controls').find('input, select').prop('disabled', !isManual);
		$('.wti-auto-category-note').toggle(!isManual);
	}

	$(function () {
		$('#wti-start-import').on('click', startImport);
		$('#wti-pause-import').on('click', pauseImport);
		$('#wti-resume-import').on('click', resumeImport);
		$('#wti-reset-import').on('click', resetImport);
		$('input[name="category_mode"]').on('change', updateCategoryModeControls);
		updateCategoryModeControls();
	});
})(jQuery);
