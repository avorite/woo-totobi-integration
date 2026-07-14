(function ($) {
	'use strict';

	var isProcessing = false;
	var isPaused = false;
	var pollTimer = null;
	var retryCount = 0;
	var lastLogCount = 0;
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
		lastLogCount = 0;
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
			updateStatus(t('syncStarted', 'Sync started. Processing in background...'));
			addLog(t('startedLog', 'Sync started. Products: %total%, catalog: %catalog%', {
				total: response.data.total,
				catalog: response.data.catalog_date
			}));
			startPolling(1500);
		}).fail(function () {
			addLog(t('errorPrefix', 'ERROR:') + ' ' + t('ajaxStartFailed', 'AJAX start request failed'));
			resetUi();
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
		}).done(function (response) {
			if (response.success && response.data) {
				updateProgress(response.data);
			}
		});
	}

	function resumeImport() {
		isProcessing = true;
		isPaused = false;
		$('#wti-start-import').prop('disabled', true);
		$('#wti-pause-import').show();
		$('#wti-resume-import').hide();
		updateStatus(t('resumedStatus', 'Sync resumed. Processing in background...'));
		addLog(t('syncResumed', 'Sync resumed.'));

		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_resume_import',
			_wpnonce: wtiAdmin.nonce
		}).done(function (response) {
			if (response.success && response.data) {
				updateProgress(response.data);
				startPolling(1000);
			}
		}).fail(function () {
			startPolling(5000);
		});
	}

	function restoreImportSession() {
		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_get_progress',
			_wpnonce: wtiAdmin.nonce
		}).done(function (response) {
			if (!response.success || !response.data || !response.data.status) {
				resetUi();
				return;
			}

			var data = response.data;

			if (data.status === 'running' || data.status === 'preparing') {
				isProcessing = true;
				isPaused = false;
				$('#wti-progress-wrap').show();
				$('#wti-start-import').prop('disabled', true);
				$('#wti-pause-import').show();
				$('#wti-resume-import').hide();
				updateProgress(data);
				updateStatus(data.sync_type === 'automatic' ? t('automaticRunning', 'Automatic sync is running.') : statusText(data));
				addStoredLogs(data);
				startPolling(1500);
				return;
			}

			if (data.status === 'paused') {
				isProcessing = false;
				isPaused = true;
				$('#wti-progress-wrap').show();
				$('#wti-start-import').prop('disabled', true);
				$('#wti-pause-import').hide();
				$('#wti-resume-import').show();
				updateProgress(data);
				updateStatus(t('syncPaused', 'Sync paused.'));
				addStoredLogs(data);
				return;
			}

			updateProgress(data);
			resetUi();
		});
	}

	function startPolling(delay) {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
		}

		pollTimer = window.setTimeout(pollProgress, delay || 2500);
	}

	function pollProgress() {
		$.post(wtiAdmin.ajaxUrl, {
			action: 'wti_get_progress',
			_wpnonce: wtiAdmin.nonce
		}).done(function (response) {
			if (!response.success || !response.data) {
				retryCount++;
				updateStatus(t('retrying', 'Progress request failed. Retrying...'));
				startPolling(5000);
				return;
			}

			retryCount = 0;
			var data = response.data;
			updateProgress(data);
			addStoredLogs(data);

			if (data.status === 'completed') {
				updateStatus(t('syncCompleted', 'Sync completed.'));
				addLog(t('syncCompleted', 'Sync completed.'));
				resetUi();
				return;
			}

			if (data.status === 'paused') {
				updateStatus(t('syncPaused', 'Sync paused.'));
				$('#wti-pause-import').hide();
				$('#wti-resume-import').show();
				return;
			}

			if (data.status !== 'running' && data.status !== 'preparing') {
				resetUi();
				return;
			}

			updateStatus(data.sync_type === 'automatic' ? t('automaticRunning', 'Automatic sync is running.') : statusText(data));
			startPolling(2500);
		}).fail(function () {
			retryCount++;
			updateStatus(t('retrying', 'Progress request failed. Retrying...'));
			startPolling(retryCount > 3 ? 8000 : 5000);
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

	function addStoredLogs(data) {
		var entries = data.log_entries || [];
		entries.slice(lastLogCount).forEach(addLog);
		lastLogCount = entries.length;
	}

	function addLog(message) {
		if (!message) {
			return;
		}

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
		var stage = data.stage === 'media' ? t('mediaProducts', 'product images') : (data.stage === 'deleted' ? t('deletedProducts', 'missing products') : (data.stage === 'variable' ? t('variableProducts', 'variable products') : t('simpleProducts', 'simple products')));
		return t('processingStage', 'Processing %stage%: %processed% of %total%', {
			stage: stage,
			processed: data.processed || 0,
			total: data.total || 0
		});
	}

	function resetUi() {
		isProcessing = false;
		isPaused = false;
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
		}
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
		$('input[name="category_mode"]').on('change', updateCategoryModeControls);
		updateCategoryModeControls();
		restoreImportSession();
	});
})(jQuery);
