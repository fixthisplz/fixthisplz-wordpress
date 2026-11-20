/* Report dialog (spec 5, steps 3-5): accessible modal, exact technical context, optional screenshot,
 * anti-bot widget, guarded submit, confirmation and downloads. Every label comes from config.i18n. */
import { describeElement, summarizeElement } from './element.js';
import { buildDraft, readEnvironment, toMarkdown, toJson, toText, reportFileName, download, DESCRIPTION_MAX } from './report.js';
import { CaptureTooLargeError } from './jpeg.js';
// Static import (small): getDisplayMedia must be reached synchronously from the visitor's click.
import { takeScreenshot, CaptureDeniedError } from './tab-capture.js';
import { mountCaptcha, CaptchaUnavailableError } from './captcha.js';
import { make, translator, UI_ATTR } from './dom.js';

const EMAIL_MAX = 254;
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([tabindex="-1"]), textarea:not([disabled]), select:not([disabled]), summary, iframe, [tabindex]:not([tabindex="-1"])';
const EXPORTS = {
	md: { mime: 'text/markdown;charset=utf-8', build: (d, labels) => toMarkdown(d, labels) },
	json: { mime: 'application/json;charset=utf-8', build: (d) => toJson(d) },
	txt: { mime: 'text/plain;charset=utf-8', build: (d, labels) => toText(d, labels) },
};
const DIAGNOSTICS_KEY = Symbol.for('bugspot.diagnostics');

let uid = 0;

/** Frozen copy: the panel, the request and the downloads all use the same diagnostics. */
function snapshotDiagnostics() {
	const store = globalThis[DIAGNOSTICS_KEY] || {};
	const copy = (list) => (Array.isArray(list) ? list.map((e) => ({ ...e })) : []);
	return { console: copy(store.console), network: copy(store.network) };
}

class LocalError extends Error {
	constructor(message, focusTarget, { keepCaptcha = false } = {}) {
		super(message);
		this.focusTarget = focusTarget;
		this.keepCaptcha = keepCaptcha;
	}
}

// Anti-bot challenges open their own popups (appended to body, iframes): focus must be allowed to go there.
const PROVIDER_FRAMES = 'iframe[src*="recaptcha"], iframe[src*="hcaptcha.com"], iframe[src*="challenges.cloudflare.com"]';
const PROVIDER_DEPTH = 3;

function isProviderFocus(node) {
	if (!node || node.nodeType !== 1) {
		return false;
	}
	if (node.tagName === 'IFRAME') {
		return true;
	}
	let n = node;
	for (let i = 0; i < PROVIDER_DEPTH && n && n !== document.body; i += 1, n = n.parentElement) {
		if (n.querySelector(PROVIDER_FRAMES)) {
			return true;
		}
	}
	return false;
}

/**
 * @param {{config: object, element: Element|null, onRetakeElement: () => Promise<Element|'page'|null>, onClose?: () => void}} options
 */
export function openDialog({ config, element, onRetakeElement, onClose }) {
	const cfg = config || {};
	const dlg = cfg.dialog || {};
	const t = translator(cfg.i18n);
	const labels = { ...(cfg.i18n || {}), email: t('contactEmail') };
	const sensitiveSelectors = Array.isArray(cfg.sensitiveSelectors) ? cfg.sensitiveSelectors : [];
	const askEmail = Boolean(dlg.askEmail);
	const allowScreenshot = Boolean(dlg.allowScreenshot);
	const formats = (Array.isArray(dlg.formats) ? dlg.formats : []).filter((f) => Object.hasOwn(EXPORTS, f));
	const id = (name) => `bugspot-${name}-${uid}`;
	uid += 1;

	const env = readEnvironment();
	const diagnostics = snapshotDiagnostics();
	let described = element ? describeElement(element, { sensitiveSelectors }) : null;
	let shot = null;
	let shotInfo = null; // { method: 'tab'|'dom', masked } of the current shot, sent with the report
	let shotUrl = '';
	let captureRun = 0;
	let captureAbort = null; // stops the capture stream as soon as its run is stale
	let capturing = false;
	let inFlight = false;
	let closed = false;

	// ---- Structure ----------------------------------------------------------------------------------
	const title = make('h2', { id: id('title'), class: 'bugspot-title', text: dlg.title || t('reportTitle') });
	const closeButton = make('button', { type: 'button', class: 'bugspot-close', 'aria-label': t('close'), text: '×' });

	const elementSummary = make('p', { class: 'bugspot-element-summary' });
	const changeButton = make('button', { type: 'button', class: 'bugspot-btn bugspot-btn-link', text: t('changeElement') });

	const descriptionError = make('p', { id: id('description-error'), class: 'bugspot-field-error', hidden: true });
	const counter = make('p', { id: id('counter'), class: 'bugspot-counter' });
	const description = make('textarea', {
		id: id('description'),
		class: 'bugspot-textarea',
		rows: 5,
		maxlength: DESCRIPTION_MAX,
		required: true,
		'aria-required': 'true',
		placeholder: t('descriptionHint'),
		'aria-describedby': `${id('counter')} ${id('description-error')}`,
	});

	const emailError = make('p', { id: id('email-error'), class: 'bugspot-field-error', hidden: true });
	const email = askEmail
		? make('input', { id: id('email'), class: 'bugspot-input', type: 'email', autocomplete: 'email', maxlength: EMAIL_MAX, 'aria-describedby': id('email-error') })
		: null;

	const shotBox = make('input', { id: id('shot'), type: 'checkbox', class: 'bugspot-checkbox' });
	const shotStatus = make('p', { class: 'bugspot-shot-status', role: 'status', 'aria-live': 'polite' });
	const shotImage = make('img', { class: 'bugspot-shot-image', alt: t('screenshotPreview') });
	const retakeButton = make('button', { type: 'button', class: 'bugspot-btn', text: t('retakeScreenshot') });
	const removeButton = make('button', { type: 'button', class: 'bugspot-btn', text: t('removeScreenshot') });
	const shotPreview = make('div', { class: 'bugspot-shot-preview', hidden: true }, [shotImage, make('div', { class: 'bugspot-row' }, [retakeButton, removeButton])]);

	const contextCode = make('pre', { class: 'bugspot-context-json', tabindex: '0' });
	const honeypot = make('input', { type: 'text', name: 'website', id: id('website'), tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
	const captchaBox = make('div', { class: 'bugspot-captcha' });

	const formError = make('div', { class: 'bugspot-alert', role: 'alert', hidden: true });
	const errorDownloads = make('div', { class: 'bugspot-downloads', hidden: true });
	const cancelButton = make('button', { type: 'button', class: 'bugspot-btn', text: t('cancel') });
	const submitButton = make('button', { type: 'submit', class: 'bugspot-btn bugspot-btn-primary', text: t('submit') });

	const privacy = dlg.privacyText || dlg.privacyUrl
		? make('p', { class: 'bugspot-privacy' }, [
			dlg.privacyText ? document.createTextNode(`${dlg.privacyText} `) : null,
			dlg.privacyUrl ? make('a', { href: dlg.privacyUrl, target: '_blank', rel: 'noopener noreferrer', text: t('privacyLink') }) : null,
		])
		: null;

	const form = make('form', { class: 'bugspot-form', novalidate: true }, [
		dlg.instructions ? make('p', { class: 'bugspot-instructions', text: dlg.instructions }) : null,
		make('div', { class: 'bugspot-field' }, [
			make('p', { class: 'bugspot-label', text: t('selectedElement') }),
			make('div', { class: 'bugspot-element' }, [elementSummary, changeButton]),
		]),
		make('div', { class: 'bugspot-field' }, [
			make('label', { for: id('description'), class: 'bugspot-label' }, [document.createTextNode(t('description')), make('span', { class: 'bugspot-required', 'aria-hidden': 'true', text: ' *' })]),
			description,
			counter,
			descriptionError,
		]),
		email ? make('div', { class: 'bugspot-field' }, [make('label', { for: id('email'), class: 'bugspot-label', text: t('email') }), email, emailError]) : null,
		allowScreenshot
			? make('div', { class: 'bugspot-field' }, [
				make('div', { class: 'bugspot-check' }, [shotBox, make('label', { for: id('shot'), text: t('attachScreenshot') })]),
				shotStatus,
				shotPreview,
			])
			: null,
		privacy,
		make('details', { class: 'bugspot-details' }, [make('summary', { text: t('technicalContext') }), contextCode]),
		make('div', { class: 'bugspot-hp', 'aria-hidden': 'true' }, [make('label', { for: id('website'), text: 'Website' }), honeypot]),
		captchaBox,
		formError,
		errorDownloads,
		make('div', { class: 'bugspot-actions' }, [cancelButton, submitButton]),
	]);

	const successText = make('p', { class: 'bugspot-success-text', tabindex: '-1' });
	const successDownloads = make('div', { class: 'bugspot-downloads' });
	const doneButton = make('button', { type: 'button', class: 'bugspot-btn bugspot-btn-primary', text: t('done') });
	const success = make('div', { class: 'bugspot-success', hidden: true }, [successText, successDownloads, make('div', { class: 'bugspot-actions' }, [doneButton])]);

	const panel = make('div', { class: 'bugspot-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id('title') }, [
		make('div', { class: 'bugspot-header' }, [title, closeButton]),
		make('div', { class: 'bugspot-body' }, [form, success]),
	]);
	const root = make('div', { id: 'bugspot-dialog-root', class: 'bugspot-ui', [UI_ATTR]: true }, [make('div', { class: 'bugspot-backdrop' }), panel]);

	// ---- Draft --------------------------------------------------------------------------------------
	function currentDraft() {
		return buildDraft({
			description: description.value,
			reporterEmail: email ? email.value : '',
			element: described,
			diagnostics,
			env,
			config: cfg,
			screenshot: Boolean(shot),
			capture: shotInfo,
		});
	}

	function refreshElement() {
		elementSummary.textContent = described ? summarizeElement(described) : t('wholePage');
		contextCode.textContent = JSON.stringify(currentDraft().context, null, 2);
	}

	function refreshCounter() {
		counter.textContent = `${Array.from(description.value).length} / ${DESCRIPTION_MAX}`;
	}

	// ---- Downloads ----------------------------------------------------------------------------------
	function fillDownloads(container, draft) {
		container.replaceChildren();
		for (const format of formats) {
			const button = make('button', { type: 'button', class: 'bugspot-btn', text: `${t('download')} .${format}` });
			button.addEventListener('click', () => {
				const spec = EXPORTS[format];
				download(reportFileName(new Date(), format), spec.build(draft, labels), spec.mime);
			});
			container.append(button);
		}
		if (shot) {
			const blob = shot;
			const button = make('button', { type: 'button', class: 'bugspot-btn', text: t('downloadScreenshot') });
			button.addEventListener('click', () => download(reportFileName(new Date(), 'jpg'), blob, 'image/jpeg'));
			container.append(button);
		}
		container.hidden = container.childElementCount === 0;
	}

	// ---- Errors -------------------------------------------------------------------------------------
	function showFieldError(node, field, message) {
		node.textContent = message;
		node.hidden = message === '';
		field?.setAttribute('aria-invalid', message === '' ? 'false' : 'true');
	}

	function showFormError(message) {
		formError.textContent = message;
		formError.hidden = message === '';
		if (message === '') {
			errorDownloads.hidden = true;
		} else {
			fillDownloads(errorDownloads, currentDraft()); // never lose the report
		}
	}

	async function serverMessage(response) {
		let body = null;
		try {
			body = await response.json();
		} catch {
			body = null;
		}
		const code = body && typeof body.code === 'string' ? body.code : '';
		const serverText = body && typeof body.message === 'string' ? body.message : '';
		switch (response.status) {
			case 403:
				if (code === 'bugspot_not_allowed') {
					return t('errorNotAllowed');
				}
				return code === 'bugspot_captcha_failed' ? t('errorCaptcha') : t('errorGeneric');
			case 404:
				return code === 'bugspot_disabled' ? t('errorDisabled') : t('errorGeneric');
			case 413:
				return t('errorTooLarge');
			case 422:
				return serverText || t('errorInvalid');
			case 429:
				return t('errorRateLimit');
			case 503:
				return t('errorCaptchaUnavailable');
			default:
				return t('errorGeneric');
		}
	}

	// ---- Screenshot ---------------------------------------------------------------------------------
	function dropShot() {
		captureRun += 1; // any capture still running is now stale
		captureAbort?.abort();
		captureAbort = null;
		capturing = false;
		shot = null;
		shotInfo = null;
		if (shotUrl) {
			URL.revokeObjectURL(shotUrl);
			shotUrl = '';
		}
		shotImage.removeAttribute('src');
		shotPreview.hidden = true;
		refreshElement();
	}

	function captureMessage(error) {
		if (error instanceof CaptureDeniedError) {
			return t('errorCaptureDenied');
		}
		return error instanceof CaptureTooLargeError ? t('errorCaptureTooLarge') : t('errorCapture');
	}

	// No await before takeScreenshot(): the browser's capture prompt needs the visitor's gesture.
	async function takeShot() {
		dropShot();
		const run = captureRun;
		captureAbort = new AbortController();
		capturing = true;
		shotStatus.textContent = t('capturing');
		try {
			const { blob, method, masked } = await takeScreenshot({ vendorUrl: cfg.vendorUrl, method: dlg.captureMethod, mask: dlg.maskCapture === true, sensitiveSelectors, signal: captureAbort.signal });
			if (closed || run !== captureRun) {
				return;
			}
			shot = blob;
			shotInfo = { method, masked };
			shotUrl = URL.createObjectURL(blob);
			shotImage.src = shotUrl;
			shotPreview.hidden = false;
			shotStatus.textContent = '';
			refreshElement();
		} catch (error) {
			if (closed || run !== captureRun) {
				return;
			}
			shotBox.checked = false;
			shotStatus.textContent = captureMessage(error);
		} finally {
			if (run === captureRun) {
				capturing = false;
			}
			if (!closed && !panel.contains(document.activeElement)) {
				shotBox.focus(); // the UI was hidden during the grab, or Retake disappeared with the old preview
			}
		}
	}

	// ---- Anti-bot -----------------------------------------------------------------------------------
	const provider = cfg.captcha && cfg.captcha.provider ? cfg.captcha.provider : 'none';
	const captchaReady = provider === 'none' ? Promise.resolve(null) : mountCaptcha(captchaBox, cfg.captcha);
	if (provider === 'none') {
		captchaBox.hidden = true;
	}

	// ---- Submit -------------------------------------------------------------------------------------
	function validate(draft) {
		showFieldError(descriptionError, description, '');
		if (email) {
			showFieldError(emailError, email, '');
		}
		if (draft.description.trim() === '') {
			showFieldError(descriptionError, description, t('descriptionError'));
			throw new LocalError('', description);
		}
		if (email && email.value.trim() !== '' && !email.checkValidity()) {
			showFieldError(emailError, email, t('emailError'));
			throw new LocalError('', email);
		}
		if (draft.context.url === '') {
			throw new LocalError(t('errorPageUrl'), submitButton);
		}
		if (capturing) {
			throw new LocalError(t('capturing'), submitButton);
		}
	}

	async function tokenFor() {
		const captcha = await captchaReady;
		if (!captcha) {
			return '';
		}
		let token;
		try {
			token = await captcha.getToken();
		} catch (error) {
			throw new LocalError(error instanceof CaptchaUnavailableError ? t('errorCaptchaUnavailable') : t('errorGeneric'), submitButton);
		}
		if (token === '') {
			// The visitor may be solving the challenge right now: do not reset it.
			throw new LocalError(t('errorCaptchaRequired'), captchaBox.querySelector('iframe') || submitButton, { keepCaptcha: true });
		}
		return token;
	}

	function showSuccess(draft, number) {
		const sent = { ...draft, number };
		form.hidden = true;
		success.hidden = false;
		title.textContent = t('sentTitle');
		const template = t('sentNumber');
		successText.textContent = number && template.includes('%s') ? template.replace('%s', number) : t('sentTitle');
		fillDownloads(successDownloads, sent);
		successText.focus();
	}

	async function submit(event) {
		event.preventDefault();
		if (inFlight || closed) {
			return;
		}
		// Synchronous guard: a second click never reaches fetch.
		inFlight = true;
		submitButton.disabled = true;
		const draft = currentDraft();
		let tokenRequested = false;
		try {
			validate(draft);
			showFormError('');
			submitButton.textContent = t('sending');
			panel.setAttribute('aria-busy', 'true');
			tokenRequested = true;
			const token = await tokenFor();

			const body = new FormData();
			body.append('description', draft.description);
			body.append('context', JSON.stringify(draft.context));
			if (askEmail && draft.reporterEmail !== '') {
				body.append('reporter_email', draft.reporterEmail);
			}
			body.append('website', honeypot.value);
			body.append('captcha_token', token);
			if (allowScreenshot && shot) {
				body.append('screenshot', shot, 'capture.jpg');
			}
			const headers = cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {};

			let response;
			try {
				response = await fetch(`${cfg.restUrl}reports`, { method: 'POST', body, headers, credentials: 'same-origin' });
			} catch {
				throw new LocalError(t('errorGeneric'), submitButton);
			}
			if (closed) {
				return;
			}
			if (response.status === 201 || response.ok) {
				let data = {};
				try {
					data = await response.json();
				} catch {
					data = {};
				}
				inFlight = false;
				showSuccess(draft, typeof data.number === 'string' ? data.number : '');
				return;
			}
			throw new LocalError(await serverMessage(response), submitButton);
		} catch (error) {
			if (closed) {
				return;
			}
			inFlight = false;
			submitButton.disabled = false;
			const message = error instanceof LocalError ? error.message : t('errorGeneric');
			if (message !== '') {
				showFormError(message);
			}
			if (tokenRequested && !(error instanceof LocalError && error.keepCaptcha)) {
				(await captchaReady)?.reset(); // a token is single-use: ask for a fresh one
			}
			(error instanceof LocalError && error.focusTarget ? error.focusTarget : submitButton).focus();
		} finally {
			submitButton.textContent = t('submit');
			panel.removeAttribute('aria-busy');
		}
	}

	// ---- Focus, keyboard, lifecycle -----------------------------------------------------------------
	function focusables() {
		return Array.from(panel.querySelectorAll(FOCUSABLE)).filter((n) => n.getClientRects().length > 0 && !n.closest('[hidden]'));
	}

	function onKeydown(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			close();
			return;
		}
		if (event.key !== 'Tab') {
			return;
		}
		const list = focusables();
		if (list.length === 0) {
			event.preventDefault();
			return;
		}
		const first = list[0];
		const last = list[list.length - 1];
		if (event.shiftKey && (document.activeElement === first || !panel.contains(document.activeElement))) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && (document.activeElement === last || !panel.contains(document.activeElement))) {
			event.preventDefault();
			first.focus();
		}
	}

	function onFocusIn(event) {
		if (!root.hidden && !closed && !root.contains(event.target) && !isProviderFocus(event.target)) {
			(focusables()[0] || panel).focus();
		}
	}

	async function retake() {
		if (inFlight || typeof onRetakeElement !== 'function') {
			return;
		}
		root.hidden = true;
		let picked = null;
		try {
			picked = await onRetakeElement();
		} finally {
			if (!closed) {
				root.hidden = false;
				if (picked !== null && picked !== undefined) {
					described = picked === 'page' ? null : describeElement(picked, { sensitiveSelectors });
					refreshElement();
				}
				changeButton.focus();
			}
		}
	}

	function close() {
		if (closed) {
			return;
		}
		closed = true;
		dropShot();
		captchaReady.then((c) => c?.destroy()).catch(() => {});
		root.removeEventListener('keydown', onKeydown);
		document.removeEventListener('focusin', onFocusIn, true);
		root.remove();
		if (typeof onClose === 'function') {
			onClose();
		}
	}

	form.addEventListener('submit', submit);
	description.addEventListener('input', () => {
		refreshCounter();
		if (description.value.trim() !== '') {
			showFieldError(descriptionError, description, '');
		}
	});
	changeButton.addEventListener('click', retake);
	closeButton.addEventListener('click', close);
	cancelButton.addEventListener('click', close);
	doneButton.addEventListener('click', close);
	shotBox.addEventListener('change', () => (shotBox.checked ? takeShot() : (dropShot(), (shotStatus.textContent = ''))));
	retakeButton.addEventListener('click', takeShot);
	removeButton.addEventListener('click', () => {
		dropShot();
		shotBox.checked = false;
		shotStatus.textContent = '';
		shotBox.focus();
	});
	root.addEventListener('keydown', onKeydown);
	document.addEventListener('focusin', onFocusIn, true);

	refreshElement();
	refreshCounter();
	document.body.appendChild(root);
	description.focus();

	return {
		focus() {
			if (!root.hidden && !panel.contains(document.activeElement)) {
				(form.hidden ? successText : description).focus();
			}
		},
	};
}
