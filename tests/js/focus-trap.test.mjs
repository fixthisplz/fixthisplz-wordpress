import { test } from 'node:test';
import assert from 'node:assert/strict';
import { focusables, isProviderFocus, wrapTarget } from '../../assets/js/lib/focus-trap.js';

const [a, b, c] = ['a', 'b', 'c'].map((name) => ({ name }));
const outside = { name: 'outside' };

test('wrapTarget wraps Tab from the last element and Shift+Tab from the first', () => {
	assert.equal(wrapTarget([a, b, c], c, true, false), a);
	assert.equal(wrapTarget([a, b, c], a, true, true), c);
});

test('wrapTarget lets the browser move focus between inner elements', () => {
	assert.equal(wrapTarget([a, b, c], a, true, false), null);
	assert.equal(wrapTarget([a, b, c], b, true, true), null);
	assert.equal(wrapTarget([a, b, c], c, true, true), null);
});

test('wrapTarget brings focus back inside when it is elsewhere', () => {
	assert.equal(wrapTarget([a, b, c], outside, false, false), a);
	assert.equal(wrapTarget([a, b, c], outside, false, true), c);
	assert.equal(wrapTarget([a], a, true, false), a, 'a single element keeps the focus');
});

test('focusables keeps only rendered elements outside a [hidden] part, in document order', () => {
	const node = (name, { rects = 1, hidden = false } = {}) => ({ name, getClientRects: () => ({ length: rects }), closest: (s) => (s === '[hidden]' && hidden ? {} : null) });
	const shown = node('shown');
	const list = [shown, node('display-none', { rects: 0 }), node('in-hidden', { hidden: true }), node('last')];
	let asked = '';
	const panel = { querySelectorAll: (selector) => ((asked = selector), list) };
	assert.deepEqual(focusables(panel).map((n) => n.name), ['shown', 'last']);
	for (const part of ['a[href]', 'button:not([disabled])', 'textarea:not([disabled])', 'summary', 'iframe', '[tabindex]:not([tabindex="-1"])']) {
		assert.ok(asked.includes(part), part);
	}
});

test('isProviderFocus lets focus go to iframes and to an anti-bot popup near its frame', () => {
	const body = { nodeType: 1, tagName: 'BODY', querySelector: () => ({}) };
	const saved = globalThis.document;
	globalThis.document = { body };
	const el = (parentElement, hasFrame = false) => ({ nodeType: 1, tagName: 'DIV', parentElement, querySelector: (s) => (hasFrame && /recaptcha/.test(s) ? {} : null) });
	try {
		assert.equal(isProviderFocus({ nodeType: 1, tagName: 'IFRAME' }), true);
		const popup = el(body, true);
		assert.equal(isProviderFocus(el(el(popup))), true, 'two levels below the popup that holds the frame');
		assert.equal(isProviderFocus(el(el(el(popup)))), false, 'deeper than three levels');
		assert.equal(isProviderFocus(el(body)), false, 'the page itself, never the body (which holds every frame)');
		assert.equal(isProviderFocus(null), false);
		assert.equal(isProviderFocus({ nodeType: 3 }), false);
	} finally {
		globalThis.document = saved;
	}
});
