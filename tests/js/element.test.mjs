import { test } from 'node:test';
import assert from 'node:assert/strict';
import { describeElement, cssPath, summarizeElement } from '../../assets/js/lib/element.js';

/* Hand-made fake DOM: just enough of Element/Text for element.js (no jsdom). */
function matches(node, selector) {
	return selector.split(',').some((part) => {
		const s = part.trim();
		const attr = /^\[([\w-]+)\]$/.exec(s);
		if (attr) return node.getAttribute(attr[1]) !== null;
		if (s.startsWith('.')) return (node.getAttribute('class') || '').split(/\s+/).includes(s.slice(1));
		if (s.startsWith('#')) return node.getAttribute('id') === s.slice(1);
		if (/^[a-z][a-z0-9]*$/i.test(s)) return node.tagName.toLowerCase() === s.toLowerCase();
		throw new SyntaxError(`fake DOM: unsupported selector ${s}`);
	});
}

function el(tag, attrs = {}, children = [], extra = {}) {
	const node = {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		parentElement: null,
		childNodes: [],
		get children() {
			return this.childNodes.filter((c) => c.nodeType === 1);
		},
		getAttribute: (name) => (Object.hasOwn(attrs, name) ? String(attrs[name]) : null),
		hasAttribute: (name) => Object.hasOwn(attrs, name),
		matches(selector) {
			return matches(this, selector);
		},
		closest(selector) {
			for (let n = this; n; n = n.parentElement) {
				if (matches(n, selector)) return n;
			}
			return null;
		},
		getBoundingClientRect: () => ({ x: 10.4, y: 20.6, width: 100.2, height: 30.5 }),
		get textContent() {
			return this.childNodes.map((c) => c.textContent).join('');
		},
		...extra,
	};
	for (const child of children) {
		const c = typeof child === 'string' ? { nodeType: 3, textContent: child, data: child } : child;
		c.parentElement = node;
		node.childNodes.push(c);
	}
	return node;
}

function chain(depth) {
	let leaf = el('span', {}, ['leaf']);
	let node = leaf;
	for (let i = 0; i < depth; i++) {
		node = el('div', {}, [el('div'), node]);
	}
	el('body', {}, [node]);
	return leaf;
}

test('cssPath stops at 5 levels', () => {
	const leaf = chain(10);
	const path = cssPath(leaf);
	assert.equal(path.split(' > ').length, 5);
	assert.ok(path.endsWith('span'));
	assert.match(path, /div:nth-of-type\(2\)/);
	assert.equal(cssPath(leaf, 2).split(' > ').length, 2);
});

test('cssPath anchors on a stable id', () => {
	const leaf = el('a', {}, ['x']);
	el('nav', { id: 'main-menu' }, [leaf]);
	assert.equal(cssPath(leaf), '#main-menu > a');
});

test('describeElement ignores generated ids', () => {
	for (const id of ['el-83721', 'a1b2c3d4e5f6', '3f2b8c1e-9a4d-4e1b-8f00-123456789abc', ':r1:']) {
		const target = el('button', { id }, ['Send']);
		el('form', {}, [el('button'), target]);
		const d = describeElement(target, { sensitiveSelectors: [] });
		assert.ok(!d.selector.includes(id), `${id} must be ignored, got ${d.selector}`);
		assert.match(d.selector, /button:nth-of-type\(2\)$/);
	}
	const stable = el('button', { id: 'submit-order' }, ['Send']);
	assert.equal(describeElement(stable, { sensitiveSelectors: [] }).selector, '#submit-order');
});

test('describeElement prefers stable data attributes over the path', () => {
	const target = el('div', { 'data-testid': 'cart-total' }, ['12 €']);
	el('main', {}, [target]);
	assert.equal(describeElement(target, {}).selector, 'div[data-testid="cart-total"]');
});

test('describeElement returns tag, role, name, visible text and integer rect', () => {
	const target = el('a', { href: '/x', 'aria-label': 'Go home' }, ['  Home \n page ']);
	const d = describeElement(target, {});
	assert.equal(d.tag, 'a');
	assert.equal(d.role, 'link');
	assert.equal(d.name, 'Go home');
	assert.equal(d.text, 'Home page');
	assert.deepEqual(d.rect, { x: 10, y: 21, width: 100, height: 31 });
});

test('describeElement masks sensitive subtree', () => {
	const inner = el('span', {}, ['IBAN FR76 3000']);
	el('div', { 'data-bugspot-sensitive': '' }, [inner]);
	assert.equal(describeElement(inner, {}).text, '[masked]');

	const viaSetting = el('strong', {}, ['1 234,56 €']);
	el('section', { class: 'account balance' }, [viaSetting]);
	assert.equal(describeElement(viaSetting, { sensitiveSelectors: ['.balance'] }).text, '[masked]');

	// A sensitive descendant inside a non-sensitive selection is masked too.
	const parent = el('div', {}, ['Total: ', el('b', { class: 'balance' }, ['999 €']), ' end']);
	const d = describeElement(parent, { sensitiveSelectors: ['.balance'] });
	assert.ok(!d.text.includes('999'), d.text);
	assert.match(d.text, /\[masked\]/);

	// An invalid selector from the settings does not break the description.
	assert.equal(describeElement(el('p', {}, ['ok']), { sensitiveSelectors: ['%%bad'] }).text, 'ok');
});

test('describeElement never returns input value', () => {
	const label = el('label', {}, ['Password']);
	const password = el('input', { type: 'password', name: 'pwd', value: 'motdepasse' }, [], { value: 'motdepasse', labels: [label] });
	let d = describeElement(password, {});
	assert.equal(d.text, '');
	assert.equal(d.name, 'Password');
	assert.ok(!JSON.stringify(d).includes('motdepasse'));

	const text = el('input', { type: 'text', placeholder: 'Your name', value: 'secret' }, [], { value: 'secret' });
	d = describeElement(text, {});
	assert.equal(d.text, '');
	assert.equal(d.name, 'Your name');
	assert.ok(!JSON.stringify(d).includes('secret'));

	const textarea = el('textarea', { 'aria-label': 'Message' }, ['prefilled secret'], { value: 'typed secret' });
	d = describeElement(textarea, {});
	assert.equal(d.text, '');
	assert.ok(!JSON.stringify(d).includes('secret'));

	const submit = el('input', { type: 'submit', value: 'secret' }, [], { value: 'secret' });
	assert.ok(!JSON.stringify(describeElement(submit, {})).includes('secret'));

	// Field values and editable regions never leak through a container's text either.
	const form = el('form', {}, [
		'Login ',
		el('textarea', {}, ['secret note']),
		el('select', {}, [el('option', {}, ['secret choice'])]),
		el('div', { contenteditable: 'true' }, ['secret draft']),
		' end',
	]);
	d = describeElement(form, {});
	assert.ok(!d.text.includes('secret'), d.text);
	assert.equal(d.text, 'Login end');

	const editable = el('div', { contenteditable: '' }, ['secret draft']);
	assert.equal(describeElement(editable, {}).text, '');
});

test('describeElement names links and buttons from their content', () => {
	const link = el('a', { id: 'bs-link', href: '/x' }, ['Lien ', el('b', {}, ['test'])]);
	let d = describeElement(link, {});
	assert.equal(d.name, 'Lien test');
	assert.equal(d.role, 'link');

	assert.equal(describeElement(el('button', {}, ['  Send \n now ']), {}).name, 'Send now');
	assert.equal(describeElement(el('h2', {}, ['Title']), {}).name, 'Title');
	assert.equal(describeElement(el('a', { href: '/x', title: 'Tooltip' }, []), {}).name, 'Tooltip');
	// No name-from-content for generic containers.
	assert.equal(describeElement(el('div', {}, ['Some text']), {}).name, '');

	// Content of a sensitive descendant never becomes the name; a sensitive link is masked.
	d = describeElement(el('a', { href: '/x' }, ['Pay ', el('span', { class: 'balance' }, ['999 €'])]), { sensitiveSelectors: ['.balance'] });
	assert.ok(!d.name.includes('999'), d.name);
	const hidden = el('a', { href: '/x' }, ['Account 1234']);
	el('div', { 'data-bugspot-sensitive': '' }, [hidden]);
	d = describeElement(hidden, {});
	assert.equal(d.name, '[masked]');
	assert.equal(d.text, '[masked]');
});

test('describeElement never names an element from text typed in an editable region', () => {
	// Inherited contenteditable: the heading itself has no attribute.
	const heading = el('h2', {}, ['my draft']);
	el('div', { contenteditable: '' }, [heading]);
	let d = describeElement(heading, {});
	assert.equal(d.name, '');
	assert.equal(d.text, '');

	// A link inside an editable region, flagged by the browser's isContentEditable only.
	const link = el('a', { href: '/x' }, ['typed link'], { isContentEditable: true });
	d = describeElement(link, {});
	assert.ok(!JSON.stringify(d).includes('typed'), JSON.stringify(d));

	// A button whose content contains an editable descendant (flag only): that part is skipped.
	const button = el('button', {}, ['Save ', el('span', {}, ['typed words'], { isContentEditable: true })]);
	d = describeElement(button, {});
	assert.equal(d.name, 'Save');
	assert.ok(!d.text.includes('typed'), d.text);

	// An explicit aria-label is developer-authored and still used.
	const labelled = el('h2', { 'aria-label': 'Editor title' }, ['my draft']);
	el('div', { contenteditable: 'true' }, [labelled]);
	assert.equal(describeElement(labelled, {}).name, 'Editor title');
});

test('summarizeElement shows role (or tag) and name, else the start of the text', () => {
	assert.equal(summarizeElement({ tag: 'button', role: 'button', name: 'Send', text: 'Send now' }), 'button – Send');
	assert.equal(summarizeElement({ tag: 'p', role: '', name: '', text: 'x'.repeat(100) }), `p – ${'x'.repeat(80)}`);
	assert.equal(summarizeElement({ tag: 'div', role: '', name: '', text: '' }), 'div');
});
