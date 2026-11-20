/* Errors of the report dialog (spec 5, step 5): what the visitor reads and where focus goes. No DOM access. */

/** A refusal shown in the dialog: message ('' when a field error is already shown) and the element to focus. */
export class LocalError extends Error {
	constructor(message, focusTarget, { keepCaptcha = false } = {}) {
		super(message);
		this.focusTarget = focusTarget;
		this.keepCaptcha = keepCaptcha; // the visitor may be solving the challenge: do not reset it
	}
}

/**
 * What the visitor reads when the server refuses a report.
 * @param {number} status HTTP status of the refused request.
 * @param {object|null} body Parsed JSON body ({ code, message } from WordPress), or null when unreadable.
 * @param {(key: string) => string} t Label lookup (config.i18n).
 * @returns {string} A 422 shows the server's own validation text; anything unknown gets the generic label.
 */
export function serverMessage(status, body, t) {
	const code = body && typeof body.code === 'string' ? body.code : '';
	const serverText = body && typeof body.message === 'string' ? body.message : '';
	switch (status) {
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
