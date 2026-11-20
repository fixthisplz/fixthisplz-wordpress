import { test } from 'node:test';
import assert from 'node:assert/strict';
import { maskClone } from '../../assets/js/lib/capture.js';

/* Hand-made fake cloned tree: just what maskClone touches (no jsdom). */
const doc = {
	createTextNode: (data) => text(data),
	createElement: (tag) => el(tag),
};

function text(data) {
	return { nodeType: 3, data, get textContent() { return this.data; } };
}

function el(tag, attrs = {}, children = []) {
	const styles = new Map();
	const node = {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		ownerDocument: doc,
		childNodes: [],
		attrs: { ...attrs },
		style: {
			setProperty: (name, value, priority) => styles.set(name, [value, priority]),
			get: (name) => styles.get(name)?.[0],
		},
		getAttribute(name) {
			return Object.hasOwn(this.attrs, name) ? String(this.attrs[name]) : null;
		},
		setAttribute(name, value) {
			this.attrs[name] = String(value);
		},
		removeAttribute(name) {
			delete this.attrs[name];
		},
		hasAttribute(name) {
			return Object.hasOwn(this.attrs, name);
		},
		matches(selector) {
			const attr = /^\[([\w-]+)\]$/.exec(selector);
			if (attr) return this.hasAttribute(attr[1]);
			if (selector.startsWith('.')) return (this.getAttribute('class') || '').split(/\s+/).includes(selector.slice(1));
			throw new SyntaxError(`unsupported selector ${selector}`);
		},
		get firstChild() {
			return this.childNodes[0] || null;
		},
		removeChild(child) {
			this.childNodes.splice(this.childNodes.indexOf(child), 1);
			return child;
		},
		appendChild(child) {
			this.childNodes.push(child);
			return child;
		},
		get textContent() {
			return this.childNodes.map((c) => c.textContent).join('');
		},
		set textContent(value) {
			this.childNodes = [text(value)];
		},
	};
	children.forEach((c) => node.appendChild(typeof c === 'string' ? text(c) : c));
	return node;
}

const serialize = (node) =>
	node.nodeType === 3 ? node.data : `<${node.tagName} ${JSON.stringify(node.attrs)}>${node.childNodes.map(serialize).join('')}</${node.tagName}>`;

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
	const marked = el('div', { 'data-bugspot-sensitive': '' }, [inner]);
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
	const box = el('div', { 'data-bugspot-sensitive': '' }, [input]);
	maskClone(el('main', {}, [box]));
	assert.equal(input.getAttribute('value'), BULLETS);
	assert.equal(input.style.get('visibility'), 'hidden');
});

/* ---- Off-screen cut (createCutFilter) ---- */
import { createCutFilter } from '../../assets/js/lib/capture.js';

const HTML_NS = 'http://www.w3.org/1999/xhtml';
const SVG_NS = 'http://www.w3.org/2000/svg';
const page = { name: 'page' };

function node(tag, children = [], { ns = HTML_NS, attrs = {} } = {}) {
	const n = {
		nodeType: 1,
		tagName: ns === SVG_NS ? tag : tag.toUpperCase(),
		namespaceURI: ns,
		ownerDocument: page,
		parentNode: null,
		childNodes: [],
		hasAttribute: (a) => Object.hasOwn(attrs, a),
	};
	for (const c of children) {
		const child = typeof c === 'string' ? { nodeType: 3, ownerDocument: page, data: c } : c;
		child.parentNode = n;
		n.childNodes.push(child);
	}
	return n;
}
const svg = (tag, children = []) => node(tag, children, { ns: SVG_NS });

/** Simulates the library: filter() each child, the kept element's onCloneEachNode comes after its subtree. */
function run(cut, root) {
	const kept = [];
	const lone = [];
	const visit = (n) => {
		for (const child of n.childNodes) {
			if (!cut.filter(child)) continue;
			if (child.nodeType === 1) {
				visit(child);
				kept.push(child);
				if (cut.takeShallow()) lone.push(child);
			} else {
				kept.push(child);
			}
		}
	};
	visit(root);
	return { kept, lone };
}

test('createCutFilter clones off-screen boxes alone and keeps on-screen subtrees', () => {
	const shown = node('p', ['on screen']);
	const offInner = node('span', ['far below']);
	const off = node('div', [offInner, 'text']);
	const body = node('body', [shown, off]);
	const visible = new WeakSet([body, shown]);
	const { kept, lone } = run(createCutFilter({ visible, needed: new WeakSet(), ownerDocument: page }), body);
	assert.ok(kept.includes(shown) && kept.includes(off));
	assert.ok(!kept.includes(offInner), 'children of an off-screen box are dropped');
	assert.deepEqual(lone, [off]);
	assert.ok(kept.some((k) => k.data === 'on screen'));
});

test('createCutFilter never cuts SVG content or svg elements', () => {
	// Visible <svg>: <defs>/<linearGradient> have no client rects, so they are not in the visible set.
	const stop = svg('stop');
	const gradient = svg('linearGradient', [stop]);
	const defs = svg('defs', [gradient]);
	const path = svg('path');
	const icon = svg('svg', [defs, path]);
	// Off-screen icon whose parent is visible: kept whole, not a lone clone.
	const offIcon = svg('svg', [svg('use')]);
	const body = node('body', [icon, offIcon]);
	const visible = new WeakSet([body, icon, path]);
	const { kept, lone } = run(createCutFilter({ visible, needed: new WeakSet(), ownerDocument: page }), body);
	for (const n of [icon, defs, gradient, stop, path, offIcon, offIcon.childNodes[0]]) {
		assert.ok(kept.includes(n), `${n.tagName} must be kept`);
	}
	assert.deepEqual(lone, []);
});

test('createCutFilter keeps the path to a hidden SVG sprite and drops its off-screen siblings', () => {
	const symbol = svg('symbol', [svg('path')]);
	const sprite = svg('svg', [symbol]);
	const sibling = node('div', [node('img')]);
	const wrapper = node('div', [sibling, sprite]);
	const body = node('body', [node('main'), wrapper]);
	const visible = new WeakSet([body]);
	const needed = new WeakSet([wrapper, sprite]);
	const { kept, lone } = run(createCutFilter({ visible, needed, ownerDocument: page }), body);
	assert.ok(kept.includes(wrapper) && kept.includes(sprite) && kept.includes(symbol));
	assert.ok(!lone.includes(wrapper), 'a needed path is cloned with its children');
	assert.ok(!kept.includes(sibling), 'other content of an off-screen needed box is dropped');
});

test('createCutFilter drops the plugin UI and lets iframe documents through', () => {
	const ui = node('div', [], { attrs: { 'data-bugspot-ui': '' } });
	const frameChild = { ...node('p'), ownerDocument: { name: 'frame' } };
	const body = node('body', [ui]);
	const cut = createCutFilter({ visible: new WeakSet([body]), needed: new WeakSet(), ownerDocument: page });
	assert.equal(cut.filter(ui), false);
	assert.equal(cut.filter(frameChild), true);
});

/* ---- Pseudo-element content (createPseudoRepair) ----
 * modern-screenshot copies ::before/::after as `content: '<computed value without any quote>'`, so a non-string
 * value is drawn as text: url("data:…") becomes the literal "url(data:…)" and alt text `"x" / ""` shows " / ". */
import { createPseudoRepair } from '../../assets/js/lib/capture.js';

const CHECKMARK = 'url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%3E%3C%2Fsvg%3E")';
const ALT_ICON = '"" / ""';

/** Simulates the library on a fake page: filter, then the subtree, then onCloneEachNode with the clone. */
function clonePage(repair, source, pseudoClass = (s) => s.getAttribute('class')) {
	const clone = el(source.tagName, { ...source.attrs });
	if (pseudoClass(source) !== source.getAttribute('class')) clone.setAttribute('class', pseudoClass(source));
	for (const child of source.childNodes) {
		if (child.nodeType !== 1) continue;
		repair.enter(child);
		clone.appendChild(clonePage(repair, child, pseudoClass));
	}
	repair.leave(clone);
	return clone;
}

/** Stands for the library's <style> (context.svgStyleElement), where the overrides are appended. */
const libraryStyle = () => el('style', {}, ['.uuid::before { content: \'url(data:…)\' }']);

test('createPseudoRepair re-applies url() and alt-text pseudo content exactly, leaves plain strings alone', () => {
	const box = el('input', { type: 'checkbox', class: 'box' });
	const icon = el('span', { class: 'ab-icon' });
	const label = el('span', { class: 'plain' });
	const body = el('body', {}, [el('label', {}, [box, label]), icon]);
	const contents = new Map([[box, { '::before': CHECKMARK }], [icon, { '::before': ALT_ICON }], [label, { '::after': '"Premium"' }]]);
	const repair = createPseudoRepair({
		ownerDocument: doc,
		getContent: (node, pseudo) => contents.get(node)?.[pseudo] ?? 'none',
	});
	// The library appends a generated class to every element whose pseudo-elements it copied.
	const root = clonePage(repair, body, (s) => (contents.has(s) ? `${s.getAttribute('class')} uuid` : s.getAttribute('class')));
	const style = libraryStyle();
	repair.apply(style);
	const css = style.textContent;
	const boxClone = root.childNodes[0].childNodes[0];
	const iconClone = root.childNodes[1];
	const id = (n) => n.getAttribute('data-bugspot-pseudo');
	assert.ok(id(boxClone) && id(iconClone) && id(boxClone) !== id(iconClone));
	assert.ok(css.includes(`[data-bugspot-pseudo="${id(boxClone)}"]::before{content:${CHECKMARK} !important}`), css);
	assert.ok(css.includes(`[data-bugspot-pseudo="${id(iconClone)}"]::before{content:${ALT_ICON} !important}`), css);
	assert.equal(root.childNodes[0].childNodes[1].getAttribute('data-bugspot-pseudo'), null, 'plain strings are copied fine');
	assert.ok(!css.includes('Premium'));
});

test('createPseudoRepair blanks pseudo content inside sensitive boxes and skips iframe documents', () => {
	const secret = el('div', { class: 'secret' });
	const body = el('body', {}, [secret]);
	const repair = createPseudoRepair({ ownerDocument: doc, getContent: () => CHECKMARK });
	const root = clonePage(repair, body, (s) => `${s.getAttribute('class')} uuid`);
	const secretClone = root.childNodes[0];
	const style = libraryStyle();
	repair.apply(style, [secretClone]);
	assert.ok(style.textContent.includes(`::before{content:"" !important}`));
	assert.ok(!style.textContent.includes('data:image'), 'nothing drawn in a masked box');

	// Same-origin iframe: the library clones the frame document root without filtering it (other document).
	const frameDoc = { createElement: doc.createElement, createTextNode: doc.createTextNode };
	const iframe = el('iframe', { class: 'f' });
	const after = el('i', { class: 'after' });
	const calls = [];
	const repair2 = createPseudoRepair({ ownerDocument: doc, getContent: (n, p) => (calls.push(n), p === '::before' ? CHECKMARK : 'none') });
	repair2.enter(iframe);
	const frameRoot = { ...el('html', { class: 'x' }), ownerDocument: frameDoc };
	repair2.leave(frameRoot);
	repair2.leave(frameRoot); // the library calls the hook again for the <iframe> it replaced
	repair2.enter(after);
	const afterClone = el('i', { class: 'after uuid' });
	repair2.leave(afterClone);
	repair2.leave(el('body', {}));
	assert.deepEqual([...new Set(calls)], [after], 'the <i> is paired with its own clone');
	assert.ok(afterClone.getAttribute('data-bugspot-pseudo'));
});

test('createPseudoRepair empties every pseudo-element of a sensitive box and of its descendants', () => {
	// A plain-string pseudo (copied as is by the library) gets no repair, but must still vanish when masked.
	const inner = el('span', { class: 'inner' });
	const secret = el('div', { class: 'secret' }, [inner]);
	const other = el('div', { class: 'other' });
	const body = el('body', {}, [secret, other]);
	const repair = createPseudoRepair({ ownerDocument: doc, getContent: () => '"visible"' });
	const root = clonePage(repair, body, (s) => `${s.getAttribute('class')} uuid`);
	const [secretClone, otherClone] = root.childNodes;
	const style = libraryStyle();
	repair.apply(style, [secretClone]);
	const css = style.textContent;
	assert.equal(secretClone.getAttribute('data-bugspot-masked'), '');
	assert.equal(otherClone.getAttribute('data-bugspot-masked'), null);
	for (const sel of ['[data-bugspot-masked]::before', '[data-bugspot-masked]::after', '[data-bugspot-masked] *::before', '[data-bugspot-masked] *::after']) {
		assert.ok(css.includes(sel), `${sel} in ${css}`);
	}
	assert.ok(/\{content:"" !important\}/.test(css));

	// A repaired pseudo nested inside a masked box is blanked too (the contains() branch).
	const deep = el('i', { class: 'deep' });
	const box = el('div', { class: 'box' }, [deep]);
	const repair2 = createPseudoRepair({ ownerDocument: doc, getContent: (n, p) => (n === deep && p === '::before' ? CHECKMARK : 'none') });
	const root2 = clonePage(repair2, el('body', {}, [box]), (s) => `${s.getAttribute('class')} uuid`);
	const boxClone = root2.childNodes[0];
	const deepClone = boxClone.childNodes[0];
	boxClone.contains = (n) => n === deepClone;
	const style2 = libraryStyle();
	repair2.apply(style2, [boxClone]);
	const id = deepClone.getAttribute('data-bugspot-pseudo');
	assert.ok(style2.textContent.includes(`[data-bugspot-pseudo="${id}"]::before{content:"" !important}`), style2.textContent);
	assert.ok(!style2.textContent.includes('data:image'));
});

test('createPseudoRepair.apply without a style element does nothing and does not throw', () => {
	const box = el('input', { type: 'checkbox', class: 'box' });
	const repair = createPseudoRepair({ ownerDocument: doc, getContent: () => CHECKMARK });
	clonePage(repair, el('body', {}, [box]), (s) => `${s.getAttribute('class')} uuid`);
	assert.doesNotThrow(() => repair.apply(undefined, []));
	assert.doesNotThrow(() => repair.apply(null, [el('div')]));
});

/* Guard on the vendored modern-screenshot 4.7.0: createPseudoRepair works around copyPseudoClass's quote stripping
 * and writes into context.svgStyleElement. An upgrade that changes either must fail here, not silently in captures. */
import { readFileSync } from 'node:fs';

test('vendored modern-screenshot 4.7.0 still has what createPseudoRepair relies on', () => {
	const vendor = readFileSync(new URL('../../assets/vendor/modern-screenshot.mjs', import.meta.url), 'utf8');
	assert.ok(vendor.includes('function copyPseudoClass('), 'copyPseudoClass renamed');
	assert.ok(vendor.includes('content = content.replace(/(\')|(")|(counter\\(.+\\))/g, "");'), 'quote stripping changed');
	assert.ok(vendor.includes('`content: \'${content}\';`'), 'pseudo content emission changed');
	assert.ok(vendor.includes('svgStyleElement.appendChild(ownerDocument.createTextNode(allCssText));'), 'svgStyleElement flush changed');
	assert.ok(/svgStyleElement\.appendChild[\s\S]*?await onCloneNode\?\.\(clone\)/.test(vendor), 'onCloneNode no longer runs after the style flush');
});
