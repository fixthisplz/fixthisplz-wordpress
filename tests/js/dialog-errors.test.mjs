import { test } from 'node:test';
import assert from 'node:assert/strict';
import { LocalError, serverMessage } from '../../assets/js/lib/dialog-errors.js';

// Echoes the key, so each case shows which label the visitor gets.
const t = (key) => `[${key}]`;

test('serverMessage maps each refusal status and WordPress code to its label', () => {
	assert.equal(serverMessage(403, { code: 'bugspot_not_allowed' }, t), '[errorNotAllowed]');
	assert.equal(serverMessage(403, { code: 'bugspot_captcha_failed' }, t), '[errorCaptcha]');
	assert.equal(serverMessage(403, { code: 'rest_cookie_invalid_nonce' }, t), '[errorGeneric]');
	assert.equal(serverMessage(404, { code: 'bugspot_disabled' }, t), '[errorDisabled]');
	assert.equal(serverMessage(404, { code: 'rest_no_route' }, t), '[errorGeneric]');
	assert.equal(serverMessage(413, null, t), '[errorTooLarge]');
	assert.equal(serverMessage(429, null, t), '[errorRateLimit]');
	assert.equal(serverMessage(503, null, t), '[errorCaptchaUnavailable]');
	assert.equal(serverMessage(500, { code: 'bugspot_not_allowed', message: 'Boom' }, t), '[errorGeneric]');
});

test('serverMessage shows the server validation text on 422, the generic invalid label without one', () => {
	assert.equal(serverMessage(422, { code: 'rest_invalid_param', message: 'Description is required.' }, t), 'Description is required.');
	assert.equal(serverMessage(422, { message: '' }, t), '[errorInvalid]');
	assert.equal(serverMessage(422, { message: 42 }, t), '[errorInvalid]');
	assert.equal(serverMessage(422, null, t), '[errorInvalid]');
});

test('serverMessage ignores a body that is not JSON or has a non-string code', () => {
	assert.equal(serverMessage(403, null, t), '[errorGeneric]');
	assert.equal(serverMessage(403, { code: 403 }, t), '[errorGeneric]');
	assert.equal(serverMessage(404, 'bugspot_disabled', t), '[errorGeneric]');
});

test('LocalError carries the message, the element to focus and whether to keep the captcha', () => {
	const field = { name: 'description' };
	const plain = new LocalError('', field);
	assert.ok(plain instanceof Error);
	assert.deepEqual([plain.message, plain.focusTarget, plain.keepCaptcha], ['', field, false]);
	assert.equal(new LocalError('Solve it', null, { keepCaptcha: true }).keepCaptcha, true);
});
