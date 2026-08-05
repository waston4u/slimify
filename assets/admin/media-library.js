/**
 * Slimify media library (list, attachment edit, upload screens).
 * Requires: slimify-admin-common, jQuery (wp.Uploader hooks).
 * Config: #slimify-media-config (JSON)
 */
(() => {
	'use strict';

	if (window.slimifyMediaLibraryBound) {
		return;
	}
	window.slimifyMediaLibraryBound = true;

	const { readConfig, ajaxPost, ajaxPostQuiet } = window.Slimify;
	const config = readConfig('slimify-media-config');
	const ajaxUrl = config.ajaxUrl || window.ajaxurl;
	const i18n = config.i18n || {};
	const nonce = config.nonce || '';

	let pollTimer = null;
	const trackedIds = Object.create(null);
	let lastKickAt = 0;

	const trackId = (id) => {
		const key = String(id || '');
		if (!key || key === '0') {
			return;
		}
		trackedIds[key] = true;
	};

	const collectProcessingIds = () => {
		const ids = { ...trackedIds };

		document.querySelectorAll('.slimify-status[data-processing="1"]').forEach((node) => {
			const id = node.getAttribute('data-attachment-id');
			if (id) {
				ids[id] = true;
			}
		});

		document
			.querySelectorAll('.slimify-processing-label[data-attachment-id]')
			.forEach((node) => {
				const id = node.getAttribute('data-attachment-id');
				if (id) {
					ids[id] = true;
				}
			});

		document.querySelectorAll('.media-item[data-slimify-id]').forEach((node) => {
			if (node.getAttribute('data-slimify-done') === '1') {
				return;
			}
			const id = node.getAttribute('data-slimify-id');
			if (id) {
				ids[id] = true;
			}
		});

		return Object.keys(ids);
	};

	const applyStatusHtml = (attachmentId, html) => {
		document
			.querySelectorAll(`.slimify-status[data-attachment-id="${attachmentId}"]`)
			.forEach((node) => {
				node.outerHTML = html;
			});
	};

	const setMediaItemBadge = (item, text, state) => {
		if (!item) {
			return;
		}

		let badge = item.querySelector('.slimify-progress-label');
		if (!badge) {
			badge = document.createElement('div');
			badge.className = 'slimify-progress-label';
			const progress = item.querySelector('.progress');
			if (progress?.parentNode) {
				progress.parentNode.insertBefore(badge, progress.nextSibling);
			} else {
				item.appendChild(badge);
			}
		}

		badge.textContent = text;
		badge.classList.remove('is-done', 'is-waiting');
		if (state === 'done') {
			badge.classList.add('is-done');
		}
		if (state === 'waiting') {
			badge.classList.add('is-waiting');
		}
	};

	const pluploadStatusLabel = (file) => {
		if (!file) {
			return null;
		}

		const st = file.status;
		if (st === 1) {
			return {
				text: i18n.waitingQueue || 'Slimify: Waiting in queue…',
				state: 'waiting',
			};
		}
		if (st === 2) {
			if (file.percent >= 100) {
				return {
					text: i18n.processing || 'Slimify: Processing…',
					state: '',
				};
			}
			return {
				text: (i18n.uploading || 'Slimify: Uploading %s%…').replace(
					'%s',
					String(file.percent)
				),
				state: 'waiting',
			};
		}
		if (st === 5) {
			return {
				text: i18n.processing || 'Slimify: Processing…',
				state: '',
			};
		}

		return null;
	};

	const refreshUploaderLabels = (uploader) => {
		if (!uploader?.files) {
			return;
		}

		uploader.files.forEach((file) => {
			const item = document.getElementById(`media-item-${file.id}`);
			if (!item || item.getAttribute('data-slimify-done') === '1') {
				return;
			}
			const label = pluploadStatusLabel(file);
			if (label) {
				setMediaItemBadge(item, label.text, label.state);
			}
		});
	};

	const kickDrain = () => {
		const now = Date.now();
		if (now - lastKickAt < 2000) {
			return;
		}
		lastKickAt = now;
		ajaxPostQuiet({
			ajaxUrl,
			action: 'slimify_kick_drain',
			nonce,
		});
	};

	const pollProcessing = async () => {
		const ids = collectProcessingIds();
		if (!ids.length) {
			return;
		}

		try {
			const data = await ajaxPost({
				ajaxUrl,
				action: 'slimify_attachment_status',
				nonce,
				fields: { attachment_ids: ids.join(',') },
			});
			if (!data?.items) {
				return;
			}

			let stillBusy = false;
			Object.keys(data.items).forEach((id) => {
				const item = data.items[id];
				if (item?.html) {
					applyStatusHtml(id, item.html);
				}

				const mediaItems = document.querySelectorAll(
					`.media-item[data-slimify-id="${id}"]`
				);

				if (item?.processing) {
					stillBusy = true;
					mediaItems.forEach((node) => {
						setMediaItemBadge(
							node,
							i18n.processing || 'Slimify: Processing…',
							''
						);
					});
				} else if (item?.processed) {
					delete trackedIds[id];
					mediaItems.forEach((node) => {
						node.setAttribute('data-slimify-done', '1');
						setMediaItemBadge(node, i18n.done || 'Slimify: Done', 'done');
					});
				} else if (item && !item.processing) {
					delete trackedIds[id];
					mediaItems.forEach((node) => {
						node.setAttribute('data-slimify-done', '1');
						setMediaItemBadge(node, i18n.ready || 'Slimify: Ready', 'done');
					});
				}
			});

			if (stillBusy || collectProcessingIds().length) {
				kickDrain();
			}
		} catch {
			/* ignore poll errors */
		}
	};

	const ensurePoller = () => {
		if (pollTimer !== null) {
			return;
		}
		pollTimer = window.setInterval(() => {
			if (collectProcessingIds().length === 0) {
				return;
			}
			pollProcessing();
		}, 1500);
	};

	const bindAttachmentFromUpload = (file, serverData) => {
		let attachmentId = 0;

		if (typeof serverData === 'number') {
			attachmentId = serverData;
		} else if (typeof serverData === 'string') {
			const cleaned = serverData
				.replace(/^[\s\S]*?<pre>(\d+)<\/pre>[\s\S]*$/i, '$1')
				.trim();
			if (/^\d+$/.test(cleaned)) {
				attachmentId = Number.parseInt(cleaned, 10);
			} else if (/^\d+$/.test(serverData.trim())) {
				attachmentId = Number.parseInt(serverData.trim(), 10);
			}
		}

		const item = document.getElementById(
			`media-item-${file?.id ? file.id : ''}`
		);

		if (attachmentId > 0) {
			trackId(attachmentId);
			if (item) {
				item.setAttribute('data-slimify-id', String(attachmentId));
				setMediaItemBadge(
					item,
					i18n.processing || 'Slimify: Processing…',
					''
				);
			}
			ensurePoller();
			kickDrain();
			window.setTimeout(pollProcessing, 400);
			window.setTimeout(pollProcessing, 1600);
			window.setTimeout(pollProcessing, 4000);
		} else if (item) {
			setMediaItemBadge(
				item,
				i18n.processing || 'Slimify: Processing…',
				''
			);
		}
	};

	const attachUploader = (uploader) => {
		if (!uploader?.bind || uploader._slimifyLabels) {
			return;
		}
		uploader._slimifyLabels = true;

		uploader.bind('FilesAdded', (up) => {
			window.setTimeout(() => refreshUploaderLabels(up), 30);
			ensurePoller();
		});
		uploader.bind('UploadFile', (up) => {
			refreshUploaderLabels(up);
		});
		uploader.bind('UploadProgress', (up, file) => {
			refreshUploaderLabels(up);
			if (file && file.percent >= 100) {
				const item = document.getElementById(`media-item-${file.id}`);
				if (item && item.getAttribute('data-slimify-done') !== '1') {
					setMediaItemBadge(
						item,
						i18n.processing || 'Slimify: Processing…',
						''
					);
					const pct = item.querySelector('.percent');
					if (pct && /%/.test(pct.textContent || '')) {
						pct.textContent = i18n.processingShort || 'Processing…';
					}
				}
			}
		});
		uploader.bind('FileUploaded', (up, file, response) => {
			const body =
				response && response.response !== undefined
					? response.response
					: response;
			bindAttachmentFromUpload(file, body);
			refreshUploaderLabels(up);
			ensurePoller();
			kickDrain();
		});
		uploader.bind('UploadComplete', (up) => {
			refreshUploaderLabels(up);
			ensurePoller();
			kickDrain();
			window.setTimeout(pollProcessing, 500);
			window.setTimeout(pollProcessing, 2000);
			window.setTimeout(pollProcessing, 5000);
		});
		uploader.bind('QueueChanged', (up) => {
			refreshUploaderLabels(up);
		});
	};

	const bindMediaUploaderHooks = () => {
		if (typeof window.wp !== 'undefined' && wp.Uploader) {
			const proto = wp.Uploader.prototype;
			if (proto && !proto._slimifyBound) {
				proto._slimifyBound = true;
				const originalInit = proto.init;
				proto.init = function (...args) {
					if (typeof originalInit === 'function') {
						originalInit.apply(this, args);
					}
					attachUploader(this.uploader);
				};
			}
		}

		if (typeof window.uploader !== 'undefined' && window.uploader) {
			attachUploader(window.uploader);
		}

		window.setTimeout(() => {
			if (typeof window.uploader !== 'undefined') {
				attachUploader(window.uploader);
			}
		}, 200);
		window.setTimeout(() => {
			if (typeof window.uploader !== 'undefined') {
				attachUploader(window.uploader);
			}
		}, 1000);

		ensurePoller();
	};

	if (typeof window.jQuery !== 'undefined') {
		jQuery(bindMediaUploaderHooks);

		jQuery(document).on('ajaxComplete', (_event, _xhr, settings) => {
			if (!settings?.url) {
				return;
			}
			const url = String(settings.url);
			const data = String(settings.data || '');
			if (
				url.includes('query-attachments') ||
				data.includes('query-attachments') ||
				data.includes('get-attachment') ||
				url.includes('async-upload.php')
			) {
				ensurePoller();
				window.setTimeout(pollProcessing, 400);
				kickDrain();
			}
		});
	} else {
		ensurePoller();
	}

	document.addEventListener('click', async (event) => {
		const link = event.target.closest(
			'.slimify-compress-one, .slimify-restore-one'
		);
		if (!link) {
			return;
		}

		event.preventDefault();
		if (link.dataset.running === '1' || link.getAttribute('aria-disabled') === 'true') {
			return;
		}

		if (link.classList.contains('slimify-compress-one')) {
			const status = document.querySelector(
				`.slimify-status[data-attachment-id="${link.dataset.attachmentId}"]`
			);
			if (status?.getAttribute('data-processing') === '1') {
				return;
			}
		}

		const attachmentId = link.dataset.attachmentId;
		const restoring = link.classList.contains('slimify-restore-one');
		if (
			restoring &&
			!window.confirm(
				i18n.confirmRestore ||
					'Restore the original upload? Image sizes will be regenerated and WebP siblings removed.'
			)
		) {
			return;
		}

		const originalText = link.textContent;
		link.dataset.running = '1';
		link.setAttribute('aria-disabled', 'true');
		link.textContent = restoring
			? i18n.restoring || 'Restoring…'
			: i18n.compressing || 'Compressing…';

		try {
			const data = await ajaxPost({
				ajaxUrl,
				action: restoring
					? 'slimify_restore_attachment'
					: 'slimify_compress_attachment',
				nonce,
				fields: { attachment_id: attachmentId },
				failMessage: i18n.fail || 'Compression failed.',
			});

			if (data.html) {
				applyStatusHtml(attachmentId, data.html);
			}

			document
				.querySelectorAll(
					`.slimify-compress-one[data-attachment-id="${attachmentId}"],` +
						`.slimify-restore-one[data-attachment-id="${attachmentId}"]`
				)
				.forEach((action) => {
					if (!action.closest('.slimify-status') && data.next_action) {
						action.classList.remove(
							'slimify-compress-one',
							'slimify-restore-one'
						);
						action.classList.add(
							data.next_action === 'restore'
								? 'slimify-restore-one'
								: 'slimify-compress-one'
						);
						action.textContent = data.action_label || originalText;
					}
					action.dataset.running = '0';
					action.removeAttribute('aria-disabled');
				});

			if (data.processing || data.partial) {
				trackId(attachmentId);
				ensurePoller();
				kickDrain();
				window.setTimeout(pollProcessing, 1000);
			}
		} catch (error) {
			if (error.payload?.html) {
				applyStatusHtml(attachmentId, error.payload.html);
			}
			if (error.payload?.processing || error.payload?.busy) {
				trackId(attachmentId);
				ensurePoller();
				kickDrain();
				window.setTimeout(pollProcessing, 1000);
			} else {
				window.alert(
					`Slimify: ${error.message || i18n.fail || 'Compression failed.'}`
				);
			}
			link.textContent = originalText;
			link.dataset.running = '0';
			link.removeAttribute('aria-disabled');
		}
	});

	ensurePoller();
	window.setTimeout(pollProcessing, 400);
})();
