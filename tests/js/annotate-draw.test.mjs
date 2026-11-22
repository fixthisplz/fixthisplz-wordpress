import { test } from 'node:test';
import assert from 'node:assert/strict';
import { drawSelection, drawShapes, fitSize, lineWidthFor, toNative } from '../../assets/js/lib/annotate-draw.js';
import { ANNOTATION_COLOR, normalizeRect } from '../../assets/js/lib/annotate-common.js';
import { arrowHead } from '../../assets/js/lib/annotate-boxes.js';
import { handles, isTiny } from '../../assets/js/lib/annotate-shapes.js';
import { MASK_FILL } from '../../assets/js/lib/sensitive.js';

/** Records every call and property set, with the colour and width in force at that moment. */
function recordingContext() {
	const calls = [];
	const ctx = { calls, lineWidth: 1, strokeStyle: '', fillStyle: '', lineJoin: '' };
	for (const name of ['strokeRect', 'fillRect', 'beginPath', 'moveTo', 'lineTo', 'quadraticCurveTo', 'closePath', 'stroke', 'fill', 'setLineDash', 'save', 'restore']) {
		ctx[name] = (...args) => calls.push({ name, args, strokeStyle: ctx.strokeStyle, fillStyle: ctx.fillStyle, lineWidth: ctx.lineWidth });
	}
	return ctx;
}

const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-9, `${actual} ≈ ${expected}`);

test('normalizeRect gives the same rectangle whatever the drag direction', () => {
	const expected = { x: 10, y: 20, width: 30, height: 40 };
	assert.deepEqual(normalizeRect({ x1: 10, y1: 20, x2: 40, y2: 60 }), expected);
	assert.deepEqual(normalizeRect({ x1: 40, y1: 60, x2: 10, y2: 20 }), expected);
	assert.deepEqual(normalizeRect({ x1: 40, y1: 20, x2: 10, y2: 60 }), expected);
});

test('toNative maps a pointer on the displayed image to native pixels, clamped to the image', () => {
	const rect = { left: 100, top: 50, width: 400, height: 225 }; // a 1600 x 900 image shown at a quarter
	assert.deepEqual(toNative(300, 162.5, rect, 1600, 900), { x: 800, y: 450 });
	assert.deepEqual(toNative(100, 50, rect, 1600, 900), { x: 0, y: 0 });
	assert.deepEqual(toNative(90, 400, rect, 1600, 900), { x: 0, y: 900 }, 'a drag leaving the image stays on its edge');
	assert.deepEqual(toNative(600, 0, rect, 1600, 900), { x: 1600, y: 0 });
});

test('fitSize fits the image in the available box, keeps its ratio and never enlarges it', () => {
	assert.deepEqual(fitSize(1600, 900, 800, 800), { width: 800, height: 450 });
	assert.deepEqual(fitSize(1080, 2340, 360, 500), { width: 230, height: 500 }); // a phone screenshot
	assert.deepEqual(fitSize(400, 300, 1200, 900), { width: 400, height: 300 });
	assert.deepEqual(fitSize(400, 300, 0, 0), { width: 0, height: 0 });
});

test('lineWidthFor scales with the image so strokes look alike on every screen', () => {
	assert.equal(lineWidthFor(1920, 1080), 6);
	assert.equal(lineWidthFor(3840, 2160), 13);
	assert.equal(lineWidthFor(1080, 2340), 8, 'the longer side counts');
	assert.equal(lineWidthFor(200, 100), 2, 'never thinner than 2 px');
});

test('isTiny drops accidental taps and slivers but keeps real shapes', () => {
	assert.equal(isTiny({ type: 'rect', x1: 0, y1: 0, x2: 3, y2: 3 }, 8), true);
	assert.equal(isTiny({ type: 'mask', x1: 0, y1: 0, x2: 100, y2: 4 }, 8), true);
	assert.equal(isTiny({ type: 'rect', x1: 50, y1: 50, x2: 10, y2: 10 }, 8), false);
	assert.equal(isTiny({ type: 'arrow', x1: 0, y1: 0, x2: 5, y2: 5 }, 8), true);
	assert.equal(isTiny({ type: 'arrow', x1: 0, y1: 0, x2: 100, y2: 0 }, 8), false, 'a flat arrow is a real arrow');
});

test('arrowHead puts the tip on the end point and both wings behind it, symmetrically', () => {
	const { tip, left, right, base } = arrowHead({ x1: 0, y1: 0, x2: 100, y2: 0 }, 20);
	assert.deepEqual(tip, { x: 100, y: 0 });
	close(left.x, 100 - 20 * Math.cos(Math.PI / 6));
	close(left.y, 10);
	close(right.y, -10);
	close(left.x, right.x);
	close(base.x, left.x);
	close(base.y, 0);
});

test('drawShapes paints rectangles in red, hidden areas with the automatic mask fill, in order', () => {
	const ctx = recordingContext();
	drawShapes(ctx, [
		{ type: 'rect', x1: 50, y1: 60, x2: 10, y2: 20 },
		{ type: 'mask', x1: 0, y1: 0, x2: 30, y2: 40 },
	], 1920, 1080);
	assert.deepEqual(ctx.calls.map((c) => c.name), ['strokeRect', 'fillRect']);
	assert.deepEqual(ctx.calls[0].args, [10, 20, 40, 40]);
	assert.equal(ctx.calls[0].strokeStyle, ANNOTATION_COLOR);
	assert.equal(ctx.calls[0].lineWidth, 6);
	assert.deepEqual(ctx.calls[1].args, [0, 0, 30, 40]);
	assert.equal(ctx.calls[1].fillStyle, MASK_FILL);
});

test('drawShapes draws an arrow as a shaft stopping under a filled head sized from the line width', () => {
	const ctx = recordingContext();
	drawShapes(ctx, [{ type: 'arrow', x1: 0, y1: 0, x2: 300, y2: 0 }], 1920, 1080);
	assert.deepEqual(ctx.calls.map((c) => c.name), ['beginPath', 'moveTo', 'lineTo', 'stroke', 'beginPath', 'moveTo', 'lineTo', 'lineTo', 'closePath', 'fill']);
	const head = arrowHead({ x1: 0, y1: 0, x2: 300, y2: 0 }, 6 * 4);
	assert.deepEqual(ctx.calls[1].args, [0, 0]);
	assert.deepEqual(ctx.calls[2].args, [head.base.x, head.base.y]);
	assert.deepEqual(ctx.calls[5].args, [300, 0]);
	assert.equal(ctx.calls[3].strokeStyle, ANNOTATION_COLOR);
	assert.equal(ctx.calls[9].fillStyle, ANNOTATION_COLOR);
});

test('drawShapes with no shape draws nothing', () => {
	const ctx = recordingContext();
	drawShapes(ctx, [], 800, 600);
	assert.equal(ctx.calls.length, 0);
});

test('handles are the 4 corners of a rectangle or hidden area and the 2 ends of an arrow', () => {
	const corners = handles({ type: 'mask', x1: 10, y1: 20, x2: 50, y2: 60 }).map(({ x, y }) => [x, y]);
	assert.deepEqual(corners, [[10, 20], [50, 20], [50, 60], [10, 60]]);
	const ends = handles({ type: 'arrow', x1: 1, y1: 2, x2: 3, y2: 4 });
	assert.deepEqual(ends, [{ xKey: 'x1', yKey: 'y1', x: 1, y: 2 }, { xKey: 'x2', yKey: 'y2', x: 3, y: 4 }]);
});

test('the selection overlay (dashes, handles) is never part of drawShapes, which is what Apply encodes', () => {
	const shapes = [{ type: 'rect', x1: 10, y1: 10, x2: 90, y2: 90 }, { type: 'arrow', x1: 0, y1: 0, x2: 300, y2: 0 }];
	const ctx = recordingContext();
	drawShapes(ctx, shapes, 1920, 1080);
	assert.ok(ctx.calls.every((c) => c.name !== 'setLineDash'));
	assert.equal(ctx.calls.filter((c) => c.name === 'strokeRect' || c.name === 'fillRect').length, 1, 'only the red rectangle');
});

test('drawSelection draws a dashed box around the shape and one handle per corner, sized in CSS pixels', () => {
	const ctx = recordingContext();
	drawSelection(ctx, { type: 'rect', x1: 100, y1: 100, x2: 20, y2: 40 }, 2);
	const boxes = ctx.calls.filter((c) => c.name === 'strokeRect').slice(0, 2);
	assert.deepEqual(boxes.map((c) => c.args), [[12, 32, 96, 76], [12, 32, 96, 76]], 'padded by 4 CSS px (8 native px)');
	assert.deepEqual(ctx.calls.find((c) => c.name === 'setLineDash').args, [[12, 12]]);
	assert.deepEqual(ctx.calls.at(-1).name, 'strokeRect');
	assert.equal(ctx.calls.filter((c) => c.name === 'fillRect').length, 4);
	assert.deepEqual(ctx.calls.find((c) => c.name === 'fillRect').args, [90, 90, 20, 20], 'a 10 CSS px square centred on (100, 100)');
	const arrow = recordingContext();
	drawSelection(arrow, { type: 'arrow', x1: 0, y1: 0, x2: 100, y2: 0 }, 1);
	assert.equal(arrow.calls.filter((c) => c.name === 'fillRect').length, 2, 'two end handles for an arrow');
	assert.deepEqual(arrow.calls.filter((c) => c.name === 'setLineDash').at(-1).args, [[]], 'dashes reset for the next drawing');
});

test('drawShapes paints each shape in its own colour; shapes kept from an earlier session, without one, in red', () => {
	const ctx = recordingContext();
	drawShapes(ctx, [
		{ type: 'rect', color: '#1d6fe0', x1: 0, y1: 0, x2: 10, y2: 10 },
		{ type: 'rect', x1: 0, y1: 0, x2: 10, y2: 10 },
		{ type: 'arrow', color: '#f0b400', x1: 0, y1: 0, x2: 300, y2: 0 },
	], 1920, 1080);
	const strokes = ctx.calls.filter((c) => c.name === 'strokeRect' || c.name === 'stroke').map((c) => c.strokeStyle);
	assert.deepEqual(strokes, ['#1d6fe0', ANNOTATION_COLOR, '#f0b400']);
	assert.equal(ctx.calls.find((c) => c.name === 'fill').fillStyle, '#f0b400');
});

test('drawShapes draws a pen stroke along its smoothed path, with round ends, at the line width', () => {
	const ctx = recordingContext();
	drawShapes(ctx, [{ type: 'pen', color: '#1e9e3a', points: [{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 100, y: 100 }] }], 1920, 1080);
	assert.deepEqual(ctx.calls.map((c) => c.name), ['save', 'beginPath', 'moveTo', 'quadraticCurveTo', 'lineTo', 'stroke', 'restore']);
	assert.equal(ctx.calls[5].strokeStyle, '#1e9e3a');
	assert.equal(ctx.calls[5].lineWidth, 6);
	assert.equal(ctx.lineCap, 'round', 'set inside save/restore, so the rectangles keep their sharp corners');
});
