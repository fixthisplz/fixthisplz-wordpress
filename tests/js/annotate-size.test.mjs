import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_SIZE, SIZES, sizeOf } from '../../assets/js/lib/annotate-common.js';
import { arrowHead } from '../../assets/js/lib/annotate-boxes.js';
import { drawShapes, lineWidthFor } from '../../assets/js/lib/annotate-draw.js';
import { beginDraw, endGesture, moveGesture } from '../../assets/js/lib/annotate-gesture.js';
import { record, setSize, startHistory, undo } from '../../assets/js/lib/annotate-shapes.js';
import { fontSizeFor, makeText } from '../../assets/js/lib/annotate-text.js';

// 10 px per character at 20 px, proportional to the font size, like a real font.
const measure = (text, font) => text.length * (parseFloat(font) / 2);
const image = { width: 1920, height: 1080, measure };
const area = { width: 1920, height: 1080, step: 3 };
const rect = { type: 'rect', x1: 10, y1: 10, x2: 200, y2: 100 }; // kept from a session without size choice
const arrow = { type: 'arrow', x1: 0, y1: 0, x2: 300, y2: 0 };
const pen = { type: 'pen', points: [{ x: 0, y: 0 }, { x: 50, y: 50 }] };
const mask = { type: 'mask', x1: 0, y1: 0, x2: 50, y2: 50 };
const text = makeText({ x: 100, y: 50, text: 'Broken', fontSize: fontSizeFor(1920, 1080), color: '#1d6fe0' }, measure);

/** Line widths used to stroke each of shapes, in order. */
function strokeWidths(shapes) {
	const widths = [];
	const ctx = {};
	for (const name of ['strokeRect', 'stroke']) {
		ctx[name] = () => widths.push(ctx.lineWidth);
	}
	for (const name of ['fillRect', 'beginPath', 'moveTo', 'lineTo', 'quadraticCurveTo', 'closePath', 'fill', 'save', 'restore', 'fillText']) {
		ctx[name] = () => {};
	}
	drawShapes(ctx, shapes, 1920, 1080);
	return widths;
}

test('the presets scale the medium line width and font size, medium being the sizes of before', () => {
	assert.deepEqual(SIZES, { small: 0.6, medium: 1, large: 1.8 });
	assert.equal(DEFAULT_SIZE, 'medium');
	assert.deepEqual(['small', 'medium', 'large'].map((size) => lineWidthFor(1920, 1080, size)), [4, 6, 12]);
	assert.deepEqual(['small', 'medium', 'large'].map((size) => fontSizeFor(1920, 1080, size)), [19, 32, 58]);
	assert.equal(lineWidthFor(1920, 1080), 6, 'medium by default');
	assert.deepEqual(['small', 'medium', 'large'].map((size) => lineWidthFor(200, 100, size)), [1, 2, 4], 'a small image');
	assert.deepEqual(['small', 'medium', 'large'].map((size) => fontSizeFor(300, 200, size)), [8, 12, 22], 'never below 8 px');
});

test('the current size is stamped on new rectangles, arrows and strokes, not on hidden areas', () => {
	const history = startHistory([]);
	for (const type of ['rect', 'arrow', 'pen']) {
		const drawn = endGesture(history, moveGesture(beginDraw([], type, { x: 10, y: 10 }, '#1d6fe0', 'large'), { x: 200, y: 90 }, area), 8);
		assert.equal(drawn.history.shapes[0].size, 'large', type);
	}
	const hidden = endGesture(history, moveGesture(beginDraw([], 'mask', { x: 10, y: 10 }, '#1d6fe0', 'large'), { x: 200, y: 90 }, area), 8);
	assert.equal('size' in hidden.history.shapes[0], false);
});

test('shapes kept from a session without size choice are medium and drawn exactly as before', () => {
	assert.equal(sizeOf(rect), 'medium');
	assert.deepEqual(strokeWidths([rect, arrow, pen]), [6, 6, 6]);
	assert.deepEqual(strokeWidths([{ ...rect, size: 'small' }, { ...arrow, size: 'large' }, { ...pen, size: 'large' }]), [4, 12, 12]);
});

test('the arrow head grows with the line width', () => {
	const head = (size) => {
		const lines = [];
		const ctx = { lineTo: (x, y) => lines.push({ x, y }) };
		for (const name of ['beginPath', 'moveTo', 'stroke', 'closePath', 'fill']) {
			ctx[name] = () => {};
		}
		drawShapes(ctx, [{ ...arrow, size }], 1920, 1080);
		return lines[0]; // where the shaft stops, under the head
	};
	assert.deepEqual(head('medium'), arrowHead(arrow, 6 * 4).base);
	assert.deepEqual(head('large'), arrowHead(arrow, 12 * 4).base);
	assert.ok(300 - head('large').x > 1.9 * (300 - head('medium').x), 'about twice as long');
});

test('a size picked with a shape selected changes its line width, as one undo entry', () => {
	const shapes = [rect, arrow, pen];
	for (const index of [0, 1, 2]) {
		const sized = setSize(shapes, index, 'small', image);
		assert.deepEqual(sized[index], { ...shapes[index], size: 'small' });
		assert.deepEqual(undo(record(startHistory(shapes), sized)).shapes, shapes, 'undone');
	}
});

test('a size that changes nothing (same size, hidden area) leaves the list as it was: no undo entry', () => {
	const shapes = [rect, mask, { ...arrow, size: 'large' }, text];
	assert.equal(setSize(shapes, 0, 'medium', image), shapes, 'a shape without size is medium already');
	assert.equal(setSize(shapes, 1, 'large', image), shapes, 'a hidden area has no size');
	assert.equal(setSize(shapes, 2, 'large', image), shapes);
	assert.equal(setSize(shapes, 3, 'medium', image), shapes, 'a text at the medium font size');
});

test('a size picked on a text sets the preset font size and measures its box again', () => {
	const [large] = setSize([text], 0, 'large', image);
	assert.deepEqual(large, makeText({ x: 100, y: 50, text: 'Broken', fontSize: 58, color: '#1d6fe0' }, measure));
	assert.equal(large.width, 6 * 29 + 2 * 58 * 0.25, 'the text width at 58 px, plus the padding');
	const resized = { ...text, fontSize: 41, width: 1, height: 1 }; // any font size, as left by a corner drag
	assert.equal(setSize([resized], 0, 'medium', image)[0].fontSize, 32, 'the preset value again');
});

test('a text set to a larger size stays inside the image, made smaller if it cannot fit', () => {
	const nearEdge = { ...text, x: 1880, y: 1060 };
	const [moved] = setSize([nearEdge], 0, 'large', image);
	assert.equal(moved.fontSize, 58);
	assert.deepEqual([moved.x + moved.width, moved.y + moved.height], [1920, 1080], 'pushed back from the right and bottom edges');
	const long = makeText({ x: 0, y: 0, text: 'x'.repeat(100), fontSize: 32, color: '#1d6fe0' }, measure); // 1616 px wide
	const [fitted] = setSize([long], 0, 'large', image); // 2929 px at 58 px: wider than the image
	assert.equal(fitted.width, 1920);
	assert.ok(fitted.fontSize < 58 && fitted.fontSize > 32);
	assert.equal(fitted.x, 0);
	assert.equal(setSize([fitted], 0, 'large', image)[0], fitted, 'picking Large again changes nothing');
});
