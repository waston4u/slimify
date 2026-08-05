/**
 * Slimify settings page (bulk compress + binary probe).
 * Requires: slimify-admin-common
 * Config: #slimify-settings-config (JSON)
 */
(() => {
	'use strict';

	const { readConfig, ajaxPost, escapeHtml, linkifyUrls, sprintf } = window.Slimify;
	const config = readConfig('slimify-settings-config');
	const ajaxUrl = config.ajaxUrl || window.ajaxurl;
	const i18n = config.i18n || {};
	const bulkNonce = config.bulkNonce || '';
	const binaryNonce = config.binaryNonce || '';

	const binaryButton = document.getElementById('slimify-test-binaries');
	const binaryOutput = document.getElementById('slimify-binary-results');
	const binaryEmpty = document.getElementById('slimify-binary-empty');
	const startButton = document.getElementById('slimify-compress-missing');
	const stopButton = document.getElementById('slimify-stop');
	const refreshButton = document.getElementById('slimify-refresh-count');
	const runPill = document.getElementById('slimify-run-pill');
	const total = document.getElementById('slimify-total');
	const compressed = document.getElementById('slimify-compressed');
	const pending = document.getElementById('slimify-pending');
	const pctEl = document.getElementById('slimify-pct');
	const progress = document.getElementById('slimify-progress');
	const progressBar = document.getElementById('slimify-progress-bar');
	const progressLabel = document.getElementById('slimify-progress-label');
	const message = document.getElementById('slimify-bulk-message');
	const quality = document.getElementById('slimify_jpeg_quality');
	const qualityVal = document.getElementById('slimify_jpeg_quality_val');

	let running = false;
	let consecutiveErrors = 0;
	let nextTimer = null;
	let activeController = null;

	const clearNextTimer = () => {
		if (nextTimer !== null) {
			window.clearTimeout(nextTimer);
			nextTimer = null;
		}
	};

	const setRunningUi = (isRunning) => {
		running = isRunning;
		if (startButton) {
			startButton.disabled = isRunning || Number(pending?.textContent) === 0;
		}
		if (stopButton) {
			stopButton.disabled = !isRunning;
			stopButton.setAttribute('aria-disabled', isRunning ? 'false' : 'true');
		}
		if (refreshButton) {
			refreshButton.disabled = isRunning;
		}
		if (runPill) {
			runPill.hidden = !isRunning;
		}
	};

	const setProgressUi = (done, all) => {
		const t = Math.max(1, all);
		const d = Math.max(0, Math.min(done, t));
		const pct = all === 0 ? 100 : Math.round((d / t) * 100);

		if (progress) {
			progress.max = t;
			progress.value = d;
		}
		if (progressBar) {
			progressBar.style.width = `${pct}%`;
		}
		if (progressLabel) {
			progressLabel.textContent = `${pct}%`;
		}
		if (pctEl) {
			pctEl.textContent = `${pct}%`;
		}
	};

	const updateStatus = (status) => {
		if (!status) {
			return;
		}
		if (total) {
			total.textContent = String(status.total);
		}
		if (compressed) {
			compressed.textContent = String(status.compressed);
		}
		if (pending) {
			pending.textContent = String(status.pending);
		}
		setProgressUi(Number(status.compressed), Number(status.total));
		if (startButton) {
			startButton.disabled = running || Number(status.pending) === 0;
		}
	};

	const stopRunner = (text) => {
		clearNextTimer();
		if (activeController) {
			try {
				activeController.abort();
			} catch {
				/* ignore */
			}
			activeController = null;
		}
		setRunningUi(false);
		if (text && message) {
			message.textContent = text;
		}
	};

	const scheduleNext = (ms) => {
		clearNextTimer();
		nextTimer = window.setTimeout(() => {
			nextTimer = null;
			runNext();
		}, ms);
	};

	const bulkPost = (action, controller = null) =>
		ajaxPost({
			ajaxUrl,
			action,
			nonce: bulkNonce,
			signal: controller?.signal,
			failMessage: i18n.requestFailed || 'Request failed.',
		});

	const runNext = async () => {
		if (!running) {
			return;
		}

		activeController =
			typeof AbortController !== 'undefined' ? new AbortController() : null;

		try {
			const data = await bulkPost('slimify_bulk_compress', activeController);
			activeController = null;
			if (!running) {
				return;
			}

			consecutiveErrors = 0;
			if (data.status) {
				updateStatus(data.status);
			} else {
				const nextStatus = {
					total: Number(total?.textContent),
					compressed: Number(compressed?.textContent),
					pending: Number(pending?.textContent),
				};
				if (data.done) {
					nextStatus.pending = 0;
					nextStatus.compressed = nextStatus.total;
				} else if (!data.partial) {
					nextStatus.compressed += 1;
					nextStatus.pending = Math.max(0, nextStatus.pending - 1);
				}
				updateStatus(nextStatus);
			}

			if (message) {
				message.textContent =
					data.message || i18n.imageProcessed || 'Image processed.';
			}

			const pendingLeft = data.status
				? Number(data.status.pending)
				: Number(pending?.textContent);

			if (data.done || pendingLeft === 0) {
				stopRunner(i18n.allDone || 'All eligible images are processed.');
				return;
			}

			scheduleNext(300);
		} catch (error) {
			activeController = null;
			if (!running) {
				return;
			}
			if (error?.name === 'AbortError') {
				stopRunner(i18n.stopped || 'Stopped. You can resume at any time.');
				return;
			}

			if (error.payload?.status) {
				updateStatus(error.payload.status);
			}

			if (error.payload?.busy) {
				if (message) {
					message.textContent = error.message;
				}
				scheduleNext(1200);
				return;
			}

			consecutiveErrors += 1;
			if (message) {
				message.textContent = `${i18n.errorPrefix || 'Error:'} ${error.message}`;
			}
			if (consecutiveErrors >= 3) {
				stopRunner(
					`${i18n.stoppedErrors || 'Stopped after repeated errors:'} ${error.message}`
				);
				return;
			}

			scheduleNext(1500);
		}
	};

	quality?.addEventListener('input', () => {
		if (qualityVal) {
			qualityVal.textContent = quality.value;
		}
	});

	startButton?.addEventListener('click', (event) => {
		event.preventDefault();
		if (running || Number(pending?.textContent) === 0) {
			return;
		}
		consecutiveErrors = 0;
		setRunningUi(true);
		if (message) {
			message.textContent = i18n.starting || 'Starting compression…';
		}
		runNext();
	});

	stopButton?.addEventListener('click', (event) => {
		event.preventDefault();
		if (!running) {
			return;
		}
		if (message) {
			message.textContent = i18n.stopping || 'Stopping…';
		}
		stopRunner(i18n.stopped || 'Stopped. You can resume at any time.');
	});

	refreshButton?.addEventListener('click', async (event) => {
		event.preventDefault();
		if (running) {
			return;
		}
		refreshButton.disabled = true;
		try {
			const status = await bulkPost('slimify_bulk_status');
			updateStatus(status);
			if (message) {
				message.textContent =
					status.pending === 0
						? i18n.allDone || 'All eligible images are processed.'
						: sprintf(
								i18n.pendingCount || '%d image(s) still need compression.',
								status.pending
							);
			}
		} catch (error) {
			if (message) {
				message.textContent = `${i18n.errorPrefix || 'Error:'} ${error.message}`;
			}
		} finally {
			refreshButton.disabled = false;
		}
	});

	if (binaryButton && binaryOutput) {
		binaryButton.addEventListener('click', async (event) => {
			event.preventDefault();
			binaryButton.disabled = true;
			binaryOutput.hidden = false;
			if (binaryEmpty) {
				binaryEmpty.hidden = true;
			}
			binaryOutput.innerHTML = `<p class="slimify-help">${escapeHtml(
				i18n.testingTools || 'Testing server tools…'
			)}</p>`;

			try {
				const data = await ajaxPost({
					ajaxUrl,
					action: 'slimify_test_binaries',
					nonce: binaryNonce,
					failMessage: i18n.requestFailed || 'Request failed.',
				});
				const tools = data.tools || [];
				if (!tools.length) {
					binaryOutput.innerHTML = `<p class="slimify-help">${escapeHtml(
						i18n.noResults || 'No results.'
					)}</p>`;
					return;
				}

				const available = tools.filter((t) => t.available).length;
				let html = `<p class="slimify-binary-summary"><strong>${available} / ${tools.length}</strong> ${escapeHtml(
					i18n.available || 'available'
				)}</p><ul class="slimify-binary-list">`;

				tools.forEach((tool) => {
					const ok = !!tool.available;
					html += `<li class="${ok ? 'is-ok' : 'is-miss'}">`;
					html += '<div class="slimify-binary-list__row">';
					html += `<span class="slimify-binary-list__name">${escapeHtml(
						tool.label || tool.id || ''
					)}</span>`;
					html += `<span class="slimify-tag ${
						ok ? 'slimify-tag--ok' : 'slimify-tag--bad'
					}">${escapeHtml(
						ok
							? i18n.toolAvailable || 'Available'
							: i18n.toolMissing || 'Missing'
					)}</span></div>`;
					if (!ok) {
						html += `<div class="slimify-binary-list__install">${linkifyUrls(
							tool.install ||
								i18n.askHost ||
								'Ask your host to install this tool.'
						)}</div>`;
					}
					html += '</li>';
				});

				html += '</ul>';
				binaryOutput.innerHTML = html;
			} catch (error) {
				binaryOutput.innerHTML = `<p class="slimify-callout slimify-callout--error">${escapeHtml(
					String(error.message)
				)}</p>`;
			} finally {
				binaryButton.disabled = false;
			}
		});
	}
})();
