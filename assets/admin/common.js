/**
 * Shared admin helpers for Slimify screens.
 */
(() => {
	'use strict';

	const root = (window.Slimify = window.Slimify || {});

	/** Parse an application/json config element by id. */
	root.readConfig = (elementId) => {
		const el = document.getElementById(elementId);
		if (!el?.textContent?.trim()) {
			return {};
		}
		try {
			return JSON.parse(el.textContent);
		} catch {
			return {};
		}
	};

	root.escapeHtml = (value) =>
		String(value)
			.replaceAll('&', '&amp;')
			.replaceAll('<', '&lt;')
			.replaceAll('>', '&gt;')
			.replaceAll('"', '&quot;');

	/** Turn https:// URLs in plain text into safe links. */
	root.linkifyUrls = (text) => {
		const escaped = root.escapeHtml(text);
		return escaped.replace(/https:\/\/[^\s<>"')\]]{1,500}/g, (url) => {
			const clean = url.replace(/[.,;:!?]+$/, '');
			const trailing = url.slice(clean.length);
			if (clean.length > 500 || /[\x00-\x1f]/.test(clean)) {
				return escaped;
			}
			return (
				`<a href="${clean}" target="_blank" rel="noopener noreferrer">${clean}</a>` +
				trailing
			);
		});
	};

	/**
	 * POST to admin-ajax.php and parse WP JSON ({ success, data }).
	 *
	 * @param {object} opts
	 * @param {string} opts.ajaxUrl
	 * @param {string} opts.action
	 * @param {string} opts.nonce
	 * @param {Record<string, string|number|Blob>|undefined} opts.fields
	 * @param {AbortSignal|undefined} opts.signal
	 * @param {string|undefined} opts.failMessage
	 */
	root.ajaxPost = async ({
		ajaxUrl,
		action,
		nonce,
		fields = {},
		signal,
		failMessage = 'Request failed.',
	}) => {
		const body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		Object.entries(fields).forEach(([key, value]) => {
			if (value !== undefined && value !== null) {
				body.append(key, String(value));
			}
		});

		const response = await fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
			signal,
		});
		const payload = await response.json();

		if (!response.ok || !payload?.success) {
			const error = new Error(payload?.data?.message || failMessage);
			error.payload = payload?.data || {};
			error.status = response.status;
			throw error;
		}

		return payload.data;
	};

	/** Fire-and-forget admin-ajax (ignores failures). */
	root.ajaxPostQuiet = (opts) => {
		root.ajaxPost(opts).catch(() => {
			/* intentional no-op */
		});
	};

	root.sprintf = (template, ...args) => {
		let i = 0;
		return String(template).replace(/%[sd%]/g, (match) => {
			if (match === '%%') {
				return '%';
			}
			const value = args[i++];
			return value === undefined ? match : String(value);
		});
	};
})();
