/* Report dialog (spec 5, steps 3-5): accessible modal, exact technical context, optional screenshot,
 * anti-bot widget, guarded submit, confirmation and downloads. Every label comes from config.i18n. */
import { describeElement, summarizeElement } from './element.js';
import { buildDraft, readEnvironment, toMarkdown, toJson, toText, reportFileName, download, DESCRIPTION_MAX } from './report.js';
import { mountCaptcha, CaptchaUnavailableError } from './captcha.js';
import { make, translator } from './dom.js';
import { buildDialog } from './dialog-view.js';
import { LocalError, serverMessage } from './dialog-errors.js';
import { trapFocus } from './focus-trap.js';
import { createShotController } from './shot-controller.js';

const EXPORTS = {
	md: { mime: 'text/markdown;charset=utf-8', build: toMarkdown },
	json: { mime: 'application/json;charset=utf-8', build: toJson },
	txt: { mime: 'text/plain;charset=utf-8', build: toText },
};
const DIAGNOSTICS_KEY = Symbol.for('fixthisplz.diagnostics');

/** Frozen copy: the panel, the request and the downloads all use the same diagnostics. */
function snapshotDiagnostics() {
	const store = globalThis[DIAGNOSTICS_KEY] || {};
	const copy = (list) => (Array.isArray(list) ? list.map((e) => ({ ...e })) : []);
	return { console: copy(store.console), network: copy(store.network) };
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

	const env = readEnvironment();
	const diagnostics = snapshotDiagnostics();
	let described = element ? describeElement(element, { sensitiveSelectors }) : null;
	let inFlight = false;
	let closed = false;

	const view = buildDialog({ t, dlg, askEmail, allowScreenshot });
	const { root, panel, title, elementSummary, changeButton, description, counter, descriptionError, email, emailError } = view;
	const { contextCode, honeypot, captchaBox, formError, errorDownloads, submitButton, form, success, successText, successDownloads } = view;
	const capture = { vendorUrl: cfg.vendorUrl, method: dlg.captureMethod, mask: dlg.maskCapture === true, sensitiveSelectors };
	const shots = createShotController({ view, t, capture, isClosed: () => closed, onChange: refreshElement });

	// ---- Draft --------------------------------------------------------------------------------------
	function currentDraft() {
		return buildDraft({
			description: description.value,
			reporterEmail: email ? email.value : '',
			element: described,
			diagnostics,
			env,
			config: cfg,
			screenshot: Boolean(shots.blob),
			capture: shots.info,
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
			const button = make('button', { type: 'button', class: 'fixthisplz-btn', text: `${t('download')} .${format}` });
			button.addEventListener('click', () => {
				const spec = EXPORTS[format];
				download(reportFileName(new Date(), format), spec.build(draft, labels), spec.mime);
			});
			container.append(button);
		}
		if (shots.blob) {
			const blob = shots.blob;
			const button = make('button', { type: 'button', class: 'fixthisplz-btn', text: t('downloadScreenshot') });
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
		if (shots.capturing) {
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
			if (allowScreenshot && shots.blob) {
				body.append('screenshot', shots.blob, 'capture.jpg');
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
			const data = await response.json().catch(() => null); // no body, or not JSON
			if (response.status === 201 || response.ok) {
				inFlight = false;
				showSuccess(draft, data && typeof data.number === 'string' ? data.number : '');
				return;
			}
			throw new LocalError(serverMessage(response.status, data, t), submitButton);
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

	// ---- Lifecycle ----------------------------------------------------------------------------------
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
		shots.drop();
		captchaReady.then((c) => c?.destroy()).catch(() => {});
		releaseFocus();
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
	[view.closeButton, view.cancelButton, view.doneButton].forEach((button) => button.addEventListener('click', close));
	const releaseFocus = trapFocus({ root, panel, isActive: () => !root.hidden && !closed, onEscape: close });

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
