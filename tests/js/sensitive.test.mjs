import { test } from 'node:test';
import assert from 'node:assert/strict';
import { closestAny, isEditable, matchesAny, sensitiveList } from '../../assets/js/lib/sensitive.js';

/* Minimal fake element: matches/closest understand "[attr]" and ".class"; anything else is an invalid selector. */
function el(attrs = {}, parent = null) {
	const test = (node, selector) => {
		const attr = /^\[([\w-]+)\]$/.exec(selector);
		if (attr) return node.getAttribute(attr[1]) !== null;
		if (selector.startsWith('.')) return (node.getAttribute('class') || '').split(/\s+/).includes(selector.slice(1));
		throw new SyntaxError(`invalid selector ${selector}`);
	};
	const node = {
		parent,
		getAttribute: (name) => (Object.hasOwn(attrs, name) ? String(attrs[name]) : null),
		matches: (selector) => test(node, selector),
		closest(selector) {
			for (let n = node; n; n = n.parent) {
				if (test(n, selector)) return n;
			}
			return null;
		},
	};
	return node;
}

test('sensitiveList always holds the built-in marker and drops blanks and non-strings', () => {
	assert.deepEqual(sensitiveList(), ['[data-bugspot-sensitive]']);
	assert.deepEqual(sensitiveList('.x'), ['[data-bugspot-sensitive]']);
	assert.deepEqual(sensitiveList(['.a', '', '  ', 3, null, '.b']), ['[data-bugspot-sensitive]', '.a', '.b']);
});

test('isEditable is true for any contenteditable value except "false"', () => {
	assert.equal(isEditable(el({ contenteditable: '' })), true);
	assert.equal(isEditable(el({ contenteditable: 'plaintext-only' })), true);
	assert.equal(isEditable(el({ contenteditable: 'FALSE' })), false);
	assert.equal(isEditable(el()), false);
});

test('matchesAny tests the node itself, closestAny its ancestors too; invalid selectors are ignored', () => {
	const parent = el({ 'data-bugspot-sensitive': '' });
	const child = el({ class: 'card' }, parent);
	const selectors = sensitiveList(['div >>> bad', '.card']);
	assert.equal(matchesAny(child, selectors), true);
	assert.equal(matchesAny(child, sensitiveList(['div >>> bad'])), false);
	assert.equal(closestAny(child, sensitiveList(['div >>> bad'])), true);
	assert.equal(closestAny(el({}, el()), selectors), false);
});
