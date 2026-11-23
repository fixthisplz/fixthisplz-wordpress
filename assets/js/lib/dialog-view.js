/* DOM of the report dialog (spec 5, steps 3-5): built once, never with innerHTML; every label comes from t(). */
import { DESCRIPTION_MAX } from './report.js';
import { make, UI_ATTR } from './dom.js';

const EMAIL_MAX = 254;

let uid = 0;

/**
 * @param {{t: (key: string) => string, dlg: object, askEmail: boolean, allowScreenshot: boolean}} options
 * @returns {object} The root (not attached yet) and every element the dialog reads or updates.
 */
export function buildDialog({ t, dlg, askEmail, allowScreenshot }) {
	const id = (name) => `fixthisplz-${name}-${uid}`;
	uid += 1;

	const title = make('h2', { id: id('title'), class: 'fixthisplz-title', text: dlg.title || t('reportTitle') });
	const closeButton = make('button', { type: 'button', class: 'fixthisplz-close', 'aria-label': t('close'), text: '×' });

	const elementSummary = make('p', { class: 'fixthisplz-element-summary' });
	const changeButton = make('button', { type: 'button', class: 'fixthisplz-btn fixthisplz-btn-link', text: t('changeElement') });

	const descriptionError = make('p', { id: id('description-error'), class: 'fixthisplz-field-error', hidden: true });
	const counter = make('p', { id: id('counter'), class: 'fixthisplz-counter' });
	const description = make('textarea', {
		id: id('description'),
		class: 'fixthisplz-textarea',
		rows: 5,
		maxlength: DESCRIPTION_MAX,
		required: true,
		'aria-required': 'true',
		placeholder: t('descriptionHint'),
		'aria-describedby': `${id('counter')} ${id('description-error')}`,
	});

	const emailError = make('p', { id: id('email-error'), class: 'fixthisplz-field-error', hidden: true });
	const email = askEmail
		? make('input', { id: id('email'), class: 'fixthisplz-input', type: 'email', autocomplete: 'email', maxlength: EMAIL_MAX, 'aria-describedby': id('email-error') })
		: null;

	const shotBox = make('input', { id: id('shot'), type: 'checkbox', class: 'fixthisplz-checkbox' });
	const shotStatus = make('p', { class: 'fixthisplz-shot-status', role: 'status', 'aria-live': 'polite' });
	const shotImage = make('img', { class: 'fixthisplz-shot-image', alt: t('screenshotPreview') });
	const annotateButton = make('button', { type: 'button', class: 'fixthisplz-btn', text: t('annotate') });
	const retakeButton = make('button', { type: 'button', class: 'fixthisplz-btn', text: t('retakeScreenshot') });
	const removeButton = make('button', { type: 'button', class: 'fixthisplz-btn', text: t('removeScreenshot') });
	const shotPreview = make('div', { class: 'fixthisplz-shot-preview', hidden: true }, [shotImage, make('div', { class: 'fixthisplz-row' }, [annotateButton, retakeButton, removeButton])]);

	const contextCode = make('pre', { class: 'fixthisplz-context-json', tabindex: '0' });
	const honeypot = make('input', { type: 'text', name: 'website', id: id('website'), tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
	const captchaBox = make('div', { class: 'fixthisplz-captcha' });

	const formError = make('div', { class: 'fixthisplz-alert', role: 'alert', hidden: true });
	const errorDownloads = make('div', { class: 'fixthisplz-downloads', hidden: true });
	const cancelButton = make('button', { type: 'button', class: 'fixthisplz-btn', text: t('cancel') });
	const submitButton = make('button', { type: 'submit', class: 'fixthisplz-btn fixthisplz-btn-primary', text: t('submit') });

	const privacy = dlg.privacyText || dlg.privacyUrl
		? make('p', { class: 'fixthisplz-privacy' }, [
			dlg.privacyText ? document.createTextNode(`${dlg.privacyText} `) : null,
			dlg.privacyUrl ? make('a', { href: dlg.privacyUrl, target: '_blank', rel: 'noopener noreferrer', text: t('privacyLink') }) : null,
		])
		: null;

	const form = make('form', { class: 'fixthisplz-form', novalidate: true }, [
		dlg.instructions ? make('p', { class: 'fixthisplz-instructions', text: dlg.instructions }) : null,
		make('div', { class: 'fixthisplz-field' }, [
			make('p', { class: 'fixthisplz-label', text: t('selectedElement') }),
			make('div', { class: 'fixthisplz-element' }, [elementSummary, changeButton]),
		]),
		make('div', { class: 'fixthisplz-field' }, [
			make('label', { for: id('description'), class: 'fixthisplz-label' }, [document.createTextNode(t('description')), make('span', { class: 'fixthisplz-required', 'aria-hidden': 'true', text: ' *' })]),
			description,
			counter,
			descriptionError,
		]),
		email ? make('div', { class: 'fixthisplz-field' }, [make('label', { for: id('email'), class: 'fixthisplz-label', text: t('email') }), email, emailError]) : null,
		allowScreenshot
			? make('div', { class: 'fixthisplz-field' }, [
				make('div', { class: 'fixthisplz-check' }, [shotBox, make('label', { for: id('shot'), text: t('attachScreenshot') })]),
				shotStatus,
				shotPreview,
			])
			: null,
		privacy,
		make('details', { class: 'fixthisplz-details' }, [make('summary', { text: t('technicalContext') }), contextCode]),
		make('div', { class: 'fixthisplz-hp', 'aria-hidden': 'true' }, [make('label', { for: id('website'), text: 'Website' }), honeypot]),
		captchaBox,
		formError,
		errorDownloads,
		make('div', { class: 'fixthisplz-actions' }, [cancelButton, submitButton]),
	]);

	const successText = make('p', { class: 'fixthisplz-success-text', tabindex: '-1' });
	const successDownloads = make('div', { class: 'fixthisplz-downloads' });
	const doneButton = make('button', { type: 'button', class: 'fixthisplz-btn fixthisplz-btn-primary', text: t('done') });
	const success = make('div', { class: 'fixthisplz-success', hidden: true }, [successText, successDownloads, make('div', { class: 'fixthisplz-actions' }, [doneButton])]);

	const panel = make('div', { class: 'fixthisplz-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id('title') }, [
		make('div', { class: 'fixthisplz-header' }, [title, closeButton]),
		make('div', { class: 'fixthisplz-body' }, [form, success]),
	]);
	const root = make('div', { id: 'fixthisplz-dialog-root', class: 'fixthisplz-ui', [UI_ATTR]: true }, [make('div', { class: 'fixthisplz-backdrop' }), panel]);

	return {
		root, panel, title, closeButton, elementSummary, changeButton, description, counter, descriptionError, email, emailError,
		shotBox, shotStatus, shotImage, shotPreview, annotateButton, retakeButton, removeButton, contextCode, honeypot, captchaBox,
		formError, errorDownloads, cancelButton, submitButton, form, success, successText, successDownloads, doneButton,
	};
}
