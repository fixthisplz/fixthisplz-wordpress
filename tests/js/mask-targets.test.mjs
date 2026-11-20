import { test } from 'node:test';
import assert from 'node:assert/strict';
import { measureTarget, scaleRects, listMaskTargets } from '../../assets/js/lib/mask-targets.js';

const rect = (left, top, width, height) => ({ left, top, width, height, right: left + width, bottom: top + height });
const el = (rects, extra = {}) => ({ getClientRects: () => rects, getBoundingClientRect: () => rects[0] || rect(0, 0, 0, 0), ...extra });
const style = () => ({ paddingLeft: '0px', paddingTop: '0px' });

test('measureTarget returns the viewport box of a field, nothing for a field without a box', () => {
	assert.deepEqual(measureTarget({ el: el([rect(5, 6, 40, 10)]), frames: [] }, style), [{ left: 5, top: 6, width: 40, height: 10 }]);
	assert.deepEqual(measureTarget({ el: el([]), frames: [] }, style), []);
});

test('measureTarget shifts a field in a same-origin frame and clips it to the frame', () => {
	const frame = el([rect(100, 200, 300, 150)], { clientLeft: 2, clientTop: 2 });
	assert.deepEqual(measureTarget({ el: el([rect(10, 10, 400, 20)]), frames: [frame] }, style), [{ left: 112, top: 212, width: 288, height: 20 }]);
	assert.deepEqual(measureTarget({ el: el([rect(10, 500, 40, 20)]), frames: [frame] }, style), []); // scrolled out of the frame
});

/* ---- Fake DOM: just what listMaskTargets reads (no jsdom). ---- */
function node(tag, attrs = {}, children = [], extra = {}) {
	const node = {
		nodeType: 1,
		tagName: tag.toUpperCase(),
		attrs: { ...attrs },
		childNodes: children,
		shadowRoot: null,
		getAttribute(name) {
			return Object.hasOwn(this.attrs, name) ? String(this.attrs[name]) : null;
		},
		matches(selector) {
			const attr = /^\[([\w-]+)\]$/.exec(selector);
			if (attr) {
				return Object.hasOwn(this.attrs, attr[1]);
			}
			const cls = /^\.([\w-]+)$/.exec(selector);
			if (cls) {
				return String(this.attrs.class || '').split(/\s+/).includes(cls[1]);
			}
			if (/^[a-z]+$/.test(selector)) {
				return this.tagName === selector.toUpperCase();
			}
			throw new SyntaxError(`bad selector ${selector}`);
		},
		...extra,
	};
	return node;
}
const txt = (data) => ({ nodeType: 3, data });
const names = (targets) => targets.map((t) => t.el.attrs.id);

test('scaleRects maps viewport CSS pixels to image pixels with a 2 px margin', () => {
	const out = scaleRects([{ left: 10, top: 20, width: 100, height: 30 }], { width: 2000, height: 1600 }, { width: 1000, height: 800 });
	assert.deepEqual(out, [{ x: 16, y: 36, width: 208, height: 68 }]);
});

test('scaleRects uses separate horizontal and vertical scales and clamps to the image', () => {
	const out = scaleRects(
		[
			{ left: -50, top: 790, width: 100, height: 40 },
			{ left: 0, top: 0, width: 10, height: 10 },
		],
		{ width: 1500, height: 400 },
		{ width: 1000, height: 800 }
	);
	// x: (-52..52)*1.5 → clamp 0..78 ; y: (788..832)*0.5 → 394..400
	assert.deepEqual(out[0], { x: 0, y: 394, width: 78, height: 6 });
	assert.deepEqual(out[1], { x: 0, y: 0, width: 18, height: 6 });
});

test('scaleRects drops rectangles entirely outside the image', () => {
	const out = scaleRects([{ left: 1200, top: 10, width: 50, height: 50 }], { width: 1000, height: 800 }, { width: 1000, height: 800 });
	assert.deepEqual(out, []);
});

test('listMaskTargets lists value-bearing fields but leaves buttons, check boxes and radios visible', () => {
	const root = node('body', {}, [
		node('input', { id: 'text' }),
		node('input', { id: 'pwd', type: 'password' }),
		node('input', { id: 'range', type: 'range' }),
		node('input', { id: 'color', type: 'color' }),
		node('input', { id: 'file', type: 'file' }),
		node('input', { id: 'hidden', type: 'hidden' }),
		node('input', { id: 'submit', type: 'submit' }),
		node('input', { id: 'button', type: 'BUTTON' }),
		node('input', { id: 'check', type: 'checkbox' }),
		node('input', { id: 'radio', type: 'radio' }),
		node('textarea', { id: 'area' }),
		node('select', { id: 'sel' }, [node('option', { id: 'opt' })]),
		node('div', { id: 'edit', contenteditable: '' }, [txt('typed')]),
		node('div', { id: 'not-edit', contenteditable: 'false' }),
		node('p', { id: 'plain' }, [txt('hello')]),
	]);
	assert.deepEqual(names(listMaskTargets(root)), ['text', 'pwd', 'range', 'color', 'file', 'area', 'sel', 'edit']);
});

test('listMaskTargets includes sensitive zones and their descendants, built-in and configured', () => {
	const root = node('body', {}, [
		node('section', { id: 'marked', 'data-bugspot-sensitive': '' }, [node('span', { id: 'inner' }, [node('b', { id: 'deep' })])]),
		node('div', { id: 'configured', class: 'iban' }),
		node('div', { id: 'other' }),
	]);
	const out = names(listMaskTargets(root, ['.iban', 'not a selector ((', '', 42]));
	assert.deepEqual(out.sort(), ['configured', 'deep', 'inner', 'marked'].sort());
});

test('listMaskTargets walks open shadow roots and same-origin frames, and covers opaque frames whole', () => {
	const host = node('div', { id: 'host' });
	host.shadowRoot = { nodeType: 11, childNodes: [node('input', { id: 'in-shadow' })] };
	const frameDoc = { documentElement: node('html', {}, [node('body', {}, [node('textarea', { id: 'in-frame' })])]) };
	const sameOrigin = node('iframe', { id: 'same' }, [], { contentDocument: frameDoc });
	const crossOrigin = node('iframe', { id: 'cross' }, [], { contentDocument: null });
	const root = node('body', {}, [host, sameOrigin, crossOrigin]);
	const targets = listMaskTargets(root);
	assert.deepEqual(names(targets).sort(), ['cross', 'in-frame', 'in-shadow'].sort());
	const inFrame = targets.find((t) => t.el.attrs.id === 'in-frame');
	assert.deepEqual(inFrame.frames, [sameOrigin]);
	assert.deepEqual(targets.find((t) => t.el.attrs.id === 'cross').frames, []);
});

test('listMaskTargets ignores the plugin UI', () => {
	const root = node('body', {}, [node('div', { id: 'ui', 'data-bugspot-ui': '' }, [node('textarea', { id: 'desc' })]), node('input', { id: 'page' })]);
	assert.deepEqual(names(listMaskTargets(root)), ['page']);
});
