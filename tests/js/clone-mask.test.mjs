import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cloneMasker } from '../../assets/js/lib/clone-mask.js';
import { el, serialize } from './fake-dom.mjs';

// Masking is opt-in (design decision 2026-11-20): these tests turn it on.
const maskClone = (root, selectors) => cloneMasker(true, selectors)(root);

const BULLETS = '••••••';

test('maskClone puts fixed bullets in passwords, even empty ones', () => {
	const filled = el('input', { type: 'password', value: 'motdepasse' });
	const short = el('input', { type: 'password', value: 'ab' });
	const empty = el('input', { type: 'password' });
	maskClone(el('form', {}, [filled, short, empty]));
	for (const input of [filled, short, empty]) {
		assert.equal(input.getAttribute('value'), BULLETS);
	}
});

test('maskClone replaces text, range and color values, keeps button labels', () => {
	const textInput = el('input', { type: 'text', value: 'secret' });
	const untyped = el('input', { value: 'secret' });
	const range = el('input', { type: 'range', value: '42' });
	const color = el('input', { type: 'color', value: '#123456' });
	const hidden = el('input', { type: 'hidden', value: 'token' });
	const submit = el('input', { type: 'submit', value: 'Send' });
	const emptyText = el('input', { type: 'text', placeholder: 'Name' });
	maskClone(el('form', {}, [textInput, untyped, range, color, hidden, submit, emptyText]));
	assert.equal(textInput.getAttribute('value'), BULLETS);
	assert.equal(untyped.getAttribute('value'), BULLETS);
	assert.equal(range.getAttribute('value'), BULLETS);
	assert.equal(color.getAttribute('value'), BULLETS);
	assert.equal(hidden.getAttribute('value'), '');
	assert.equal(submit.getAttribute('value'), 'Send');
	assert.equal(emptyText.getAttribute('value'), null);
});

test('maskClone replaces textarea, select and contenteditable content', () => {
	const textarea = el('textarea', { value: 'typed secret' }, ['default secret']);
	const select = el('select', { value: 'b' }, [el('option', {}, ['secret a']), el('option', { selected: '' }, ['secret b'])]);
	const editable = el('div', { contenteditable: 'true' }, ['secret draft', el('b', {}, ['secret bold'])]);
	const notEditable = el('div', { contenteditable: 'false' }, ['public']);
	const root = el('main', {}, [textarea, select, editable, notEditable]);
	maskClone(root);
	const out = serialize(root);
	assert.ok(!out.includes('secret'), out);
	assert.equal(textarea.textContent, BULLETS);
	assert.equal(textarea.getAttribute('value'), null);
	assert.equal(select.childNodes.length, 1);
	assert.equal(select.textContent, BULLETS);
	assert.equal(editable.textContent, BULLETS);
	assert.equal(notEditable.textContent, 'public');
});

test('maskClone fills sensitive boxes and hides their whole subtree', () => {
	const deep = el('span', {}, ['1 234 €']);
	const inner = el('p', {}, ['IBAN ', deep]);
	const marked = el('div', { 'data-ftp-mask': '' }, [inner]);
	const viaSetting = el('section', { class: 'balance' }, [el('em', {}, ['42'])]);
	const other = el('div', {}, ['public']);
	maskClone(el('main', {}, [marked, viaSetting, other]), ['.balance', '%%invalid']);

	assert.equal(marked.style.get('background'), '#3c434a');
	assert.equal(marked.style.get('color'), 'transparent');
	assert.equal(inner.style.get('visibility'), 'hidden');
	assert.equal(deep.style.get('visibility'), 'hidden');
	assert.equal(viaSetting.style.get('background'), '#3c434a');
	assert.equal(viaSetting.childNodes[0].style.get('visibility'), 'hidden');
	assert.equal(other.style.get('background'), undefined);
});

test('maskClone masks fields inside a sensitive box too', () => {
	const input = el('input', { type: 'text', value: 'secret' });
	const box = el('div', { 'data-ftp-mask': '' }, [input]);
	maskClone(el('main', {}, [box]));
	assert.equal(input.getAttribute('value'), BULLETS);
	assert.equal(input.style.get('visibility'), 'hidden');
});

test('masking off (default): the clone keeps values and nothing is filled', () => {
	const pwd = el('input', { type: 'password', value: 'motdepasse' });
	const text = el('input', { type: 'text', value: 'secret' });
	const marked = el('div', { 'data-ftp-mask': '' }, [text]);
	for (const flag of [undefined, false, 'true']) {
		assert.deepEqual(cloneMasker(flag, ['.x'])(el('form', {}, [pwd, marked])), []);
	}
	assert.equal(pwd.getAttribute('value'), 'motdepasse');
	assert.equal(text.getAttribute('value'), 'secret');
	assert.equal(marked.style.get('background'), undefined);
});
