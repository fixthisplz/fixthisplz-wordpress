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
	const id = (name) => `bugspot-${name}-${uid}`;
	uid += 1;

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

	return {
		root, panel, title, closeButton, elementSummary, changeButton, description, counter, descriptionError, email, emailError,
		shotBox, shotStatus, shotImage, shotPreview, retakeButton, removeButton, contextCode, honeypot, captchaBox,
		formError, errorDownloads, cancelButton, submitButton, form, success, successText, successDownloads, doneButton,
	};
}
