import { test } from 'node:test';
import assert from 'node:assert/strict';
import { doc, el } from './fake-dom.mjs';

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
/** The computed display the filter asks for, faked: set `display` on a node (block by default). */
const isInline = (n) => /^inline/.test(n.display || 'block');

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
	const { kept, lone } = run(createCutFilter({ visible, needed: new WeakSet(), ownerDocument: page, isInline }), body);
	assert.ok(kept.includes(shown) && kept.includes(off));
	assert.ok(!kept.includes(offInner), 'children of an off-screen box are dropped');
	assert.deepEqual(lone, [off]);
	assert.ok(kept.some((k) => k.data === 'on screen'));
});

test('createCutFilter keeps off-screen inline-level boxes whole: a lone empty clone would move the line they sit on', () => {
	// An emptied inline-block sits on its bottom edge instead of its text baseline (the line grows by the descent);
	// an emptied inline loses its text and the paragraph reflows.
	const nagText = node('a', ['WordPress 7.1.5']);
	const nag = Object.assign(node('div', [nagText, ' is available']), { display: 'inline-block' });
	const link = Object.assign(node('a', ['read more']), { display: 'inline' });
	const block = node('div', [node('p', ['far above'])]);
	const wrap = node('div', [nag, link, block, node('h1', ['on screen'])]);
	const body = node('body', [wrap]);
	const visible = new WeakSet([body, wrap, wrap.childNodes[3]]);
	const { kept, lone } = run(createCutFilter({ visible, needed: new WeakSet(), ownerDocument: page, isInline }), body);
	for (const n of [nag, nagText, nagText.childNodes[0], nag.childNodes[1], link, link.childNodes[0]]) {
		assert.ok(kept.includes(n), `${n.tagName || n.data} must be kept`);
	}
	assert.deepEqual(lone, [block], 'only the off-screen block box is cloned alone');
	assert.ok(!kept.includes(block.childNodes[0]));
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
	const { kept, lone } = run(createCutFilter({ visible, needed: new WeakSet(), ownerDocument: page, isInline }), body);
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
	const { kept, lone } = run(createCutFilter({ visible, needed, ownerDocument: page, isInline }), body);
	assert.ok(kept.includes(wrapper) && kept.includes(sprite) && kept.includes(symbol));
	assert.ok(!lone.includes(wrapper), 'a needed path is cloned with its children');
	assert.ok(!kept.includes(sibling), 'other content of an off-screen needed box is dropped');
});

test('createCutFilter drops the plugin UI and lets iframe documents through', () => {
	const ui = node('div', [], { attrs: { 'data-fixthisplz-ui': '' } });
	const frameChild = { ...node('p'), ownerDocument: { name: 'frame' } };
	const body = node('body', [ui]);
	const cut = createCutFilter({ visible: new WeakSet([body]), needed: new WeakSet(), ownerDocument: page, isInline });
	assert.equal(cut.filter(ui), false);
	assert.equal(cut.filter(frameChild), true);
});

/* ---- Pseudo-element content (createPseudoRepair) ----
 * modern-screenshot copies ::before/::after as `content: '<computed value without any quote>'`, so a non-string
 * value is drawn as text: url("data:…") becomes the literal "url(data:…)" and alt text `"x" / ""` shows " / ". */
import { createPseudoRepair } from '../../assets/js/lib/pseudo-repair.js';

const CHECKMARK = 'url("data:image/svg+xml;utf8,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%3E%3C%2Fsvg%3E")';
const ALT_ICON = '"" / ""';
/** The computed style of a pseudo-element, as getComputedStyle(node, pseudo) gives it (only what is read). */
const pseudoStyle = (content, fontFamily = '') => ({ content, fontFamily });

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
		getStyle: (node, pseudo) => pseudoStyle(contents.get(node)?.[pseudo] ?? 'none'),
	});
	// The library appends a generated class to every element whose pseudo-elements it copied.
	const root = clonePage(repair, body, (s) => (contents.has(s) ? `${s.getAttribute('class')} uuid` : s.getAttribute('class')));
	const style = libraryStyle();
	repair.apply(style);
	const css = style.textContent;
	const boxClone = root.childNodes[0].childNodes[0];
	const iconClone = root.childNodes[1];
	const id = (n) => n.getAttribute('data-fixthisplz-pseudo');
	assert.ok(id(boxClone) && id(iconClone) && id(boxClone) !== id(iconClone));
	assert.ok(css.includes(`[data-fixthisplz-pseudo="${id(boxClone)}"]::before{content:${CHECKMARK} !important}`), css);
	assert.ok(css.includes(`[data-fixthisplz-pseudo="${id(iconClone)}"]::before{content:${ALT_ICON} !important}`), css);
	assert.equal(root.childNodes[0].childNodes[1].getAttribute('data-fixthisplz-pseudo'), null, 'plain strings are copied fine');
	assert.ok(!css.includes('Premium'));
});

test('createPseudoRepair blanks pseudo content inside sensitive boxes and skips iframe documents', () => {
	const secret = el('div', { class: 'secret' });
	const body = el('body', {}, [secret]);
	const repair = createPseudoRepair({ ownerDocument: doc, getStyle: () => pseudoStyle(CHECKMARK) });
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
	const repair2 = createPseudoRepair({ ownerDocument: doc, getStyle: (n, p) => (calls.push(n), pseudoStyle(p === '::before' ? CHECKMARK : 'none')) });
	repair2.enter(iframe);
	const frameRoot = { ...el('html', { class: 'x' }), ownerDocument: frameDoc };
	repair2.leave(frameRoot);
	repair2.leave(frameRoot); // the library calls the hook again for the <iframe> it replaced
	repair2.enter(after);
	const afterClone = el('i', { class: 'after uuid' });
	repair2.leave(afterClone);
	repair2.leave(el('body', {}));
	assert.deepEqual([...new Set(calls)], [after], 'the <i> is paired with its own clone');
	assert.ok(afterClone.getAttribute('data-fixthisplz-pseudo'));
});

test('createPseudoRepair empties every pseudo-element of a sensitive box and of its descendants', () => {
	// A plain-string pseudo (copied as is by the library) gets no repair, but must still vanish when masked.
	const inner = el('span', { class: 'inner' });
	const secret = el('div', { class: 'secret' }, [inner]);
	const other = el('div', { class: 'other' });
	const body = el('body', {}, [secret, other]);
	const repair = createPseudoRepair({ ownerDocument: doc, getStyle: () => pseudoStyle('"visible"') });
	const root = clonePage(repair, body, (s) => `${s.getAttribute('class')} uuid`);
	const [secretClone, otherClone] = root.childNodes;
	const style = libraryStyle();
	repair.apply(style, [secretClone]);
	const css = style.textContent;
	assert.equal(secretClone.getAttribute('data-fixthisplz-masked'), '');
	assert.equal(otherClone.getAttribute('data-fixthisplz-masked'), null);
	for (const sel of ['[data-fixthisplz-masked]::before', '[data-fixthisplz-masked]::after', '[data-fixthisplz-masked] *::before', '[data-fixthisplz-masked] *::after']) {
		assert.ok(css.includes(sel), `${sel} in ${css}`);
	}
	assert.ok(/\{content:"" !important\}/.test(css));

	// A repaired pseudo nested inside a masked box is blanked too (the contains() branch).
	const deep = el('i', { class: 'deep' });
	const box = el('div', { class: 'box' }, [deep]);
	const repair2 = createPseudoRepair({ ownerDocument: doc, getStyle: (n, p) => pseudoStyle(n === deep && p === '::before' ? CHECKMARK : 'none') });
	const root2 = clonePage(repair2, el('body', {}, [box]), (s) => `${s.getAttribute('class')} uuid`);
	const boxClone = root2.childNodes[0];
	const deepClone = boxClone.childNodes[0];
	boxClone.contains = (n) => n === deepClone;
	const style2 = libraryStyle();
	repair2.apply(style2, [boxClone]);
	const id = deepClone.getAttribute('data-fixthisplz-pseudo');
	assert.ok(style2.textContent.includes(`[data-fixthisplz-pseudo="${id}"]::before{content:"" !important}`), style2.textContent);
	assert.ok(!style2.textContent.includes('data:image'));
});

test('createPseudoRepair.apply without a style element does nothing and does not throw', () => {
	const box = el('input', { type: 'checkbox', class: 'box' });
	const repair = createPseudoRepair({ ownerDocument: doc, getStyle: () => pseudoStyle(CHECKMARK) });
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

/* ---- Fonts (Task 18) ----
 * Without the page's fonts, text is drawn with a wider fallback inside boxes whose widths were copied from the page:
 * lines wrap, and what follows them (admin bar badge, avatar) drops into the content below. */

test('createPseudoRepair reports the font families of copied pseudo-elements (icon fonts) with their characters', () => {
	const icon = el('span', { class: 'ab-icon' });
	const plain = el('span', { class: 'plain' });
	const body = el('body', {}, [icon, plain]);
	const styles = new Map([[icon, { '::before': pseudoStyle('""', 'dashicons') }], [plain, { '::after': pseudoStyle('none', 'Unused') }]]);
	const repair = createPseudoRepair({ ownerDocument: doc, getStyle: (n, p) => styles.get(n)?.[p] ?? pseudoStyle('none', 'Body Font') });
	clonePage(repair, body, (s) => (styles.has(s) ? `${s.getAttribute('class')} uuid` : s.getAttribute('class')));
	assert.deepEqual([...repair.fonts.keys()], ['dashicons'], 'only pseudo-elements that draw something count');
	assert.deepEqual([...repair.fonts.get('dashicons')], ['']);
});

test('captureVisible embeds the page fonts: the library font option is not disabled', async () => {
	const html = { tagName: 'HTML', nodeType: 1 };
	const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
	globalThis.window = { innerWidth: 800, innerHeight: 600, scrollX: 0, scrollY: 0 };
	globalThis.document = { documentElement: html, body: null, styleSheets: [], querySelectorAll: () => [] };
	globalThis.getComputedStyle = () => ({ backgroundColor: 'rgb(255, 255, 255)' });
	globalThis.__fixThisPlzVendorOptions = null;
	const vendorUrl = `data:text/javascript,${encodeURIComponent(
		'export const createContext = async (node, options) => (globalThis.__fixThisPlzVendorOptions = options, {});' +
			'export const domToCanvas = async () => { throw new Error("stop"); };' +
			'export const destroyContext = () => {};'
	)}`;
	try {
		const { captureVisible } = await import('../../assets/js/lib/capture.js');
		await assert.rejects(captureVisible({ vendorUrl }), /stop/);
		const options = globalThis.__fixThisPlzVendorOptions;
		assert.ok(options, 'createContext was called');
		assert.notEqual(options.font, false, 'fonts must be embedded');
	} finally {
		Object.assign(globalThis, saved);
		delete globalThis.__fixThisPlzVendorOptions;
	}
});

/* ---- Document scroll (rootShift) ----
 * The clone is drawn from its top: the cloned <html> is pulled up by the page scroll with a margin (fixed boxes stay
 * put). WordPress gives <html> its own margin on the front end (admin bar: margin-top 32px !important). */
import { rootShift } from '../../assets/js/lib/capture.js';

test('rootShift moves the cloned document by the scroll while keeping the page own <html> margin', () => {
	const style = rootShift({ scrollX: 0, scrollY: 0, height: 800, marginTop: '32px', marginLeft: '0px' });
	assert.equal(style['margin-top'], '32px', 'not scrolled: the admin bar margin stays, content is not pulled under the bar');
	const scrolled = rootShift({ scrollX: 10, scrollY: 400, height: 800, marginTop: '32px', marginLeft: '5px' });
	assert.equal(scrolled['margin-top'], '-368px');
	assert.equal(scrolled['margin-left'], '-5px');
	assert.equal(scrolled['min-height'], '1200px');
	assert.equal(scrolled.height, 'auto');
	assert.equal(scrolled.overflow, 'visible');
	assert.equal(rootShift({ scrollX: 0, scrollY: 100, height: 600, marginTop: 'auto', marginLeft: '' })['margin-top'], '-100px');
});

test('createPseudoRepair ignores empty pseudo content (clearfix): no font family recorded for it', () => {
	const clearfix = el('div', { class: 'clearfix' });
	const repair = createPseudoRepair({ ownerDocument: doc, getStyle: () => pseudoStyle('""', 'Heavy Font') });
	clonePage(repair, el('body', {}, [clearfix]), (s) => `${s.getAttribute('class')} uuid`);
	assert.equal(repair.fonts.size, 0);
});

test('createPseudoRepair records drawn characters of plain strings only, not url()/counter() values', () => {
	const box = el('input', { type: 'checkbox', class: 'box' });
	const repair = createPseudoRepair({ ownerDocument: doc, getStyle: () => pseudoStyle(CHECKMARK, 'Box Font') });
	clonePage(repair, el('body', {}, [box]), (s) => `${s.getAttribute('class')} uuid`);
	assert.equal(repair.fonts.size, 0);
});
