import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PEN, smoothPath } from '../../assets/js/lib/annotate-pen.js';
import { handleAt, handles, hitTest, isTiny, moveHandle, moveShape, shapeBounds } from '../../assets/js/lib/annotate-shapes.js';

const pen = { type: 'pen', color: '#1e9e3a', points: [{ x: 100, y: 100 }, { x: 200, y: 100 }, { x: 200, y: 200 }] };

test('a stroke records the points of the drag, dropping those closer than the step to the previous one', () => {
	let shape = PEN.create({ x: 10, y: 10 }, '#1d6fe0', 'large');
	assert.deepEqual(shape, { type: 'pen', color: '#1d6fe0', size: 'large', points: [{ x: 10, y: 10 }] });
	shape = PEN.extend(shape, { x: 11, y: 11 }, 3);
	assert.equal(shape.points.length, 1, 'jitter is dropped');
	const longer = PEN.extend(shape, { x: 14, y: 10 }, 3);
	assert.deepEqual(longer.points.at(-1), { x: 14, y: 10 });
	assert.equal(shape.points.length, 1, 'the previous stroke is not modified');
});

test('smoothPath goes through midpoints with quadratic curves bent by the recorded points, ending on the last one', () => {
	assert.deepEqual(smoothPath(pen.points), [
		['moveTo', 100, 100],
		['quadraticCurveTo', 200, 100, 200, 150],
		['lineTo', 200, 200],
	]);
	assert.deepEqual(smoothPath([{ x: 0, y: 0 }, { x: 9, y: 9 }]), [['moveTo', 0, 0], ['lineTo', 9, 9]], 'two points: a line');
});

test('a stroke is grabbed near its line, not in the empty space it encloses', () => {
	assert.equal(hitTest([pen], 150, 108, 10), 0);
	assert.equal(hitTest([pen], 205, 180, 10), 0, 'second segment');
	assert.equal(hitTest([pen], 150, 150, 10), -1, 'inside the corner');
	assert.equal(hitTest([{ ...pen, points: [{ x: 5, y: 5 }] }], 12, 5, 10), 0, 'a single point');
});

test('a stroke moves as a whole, stopped at the edges of the image', () => {
	assert.deepEqual(moveShape(pen, 10, -500, 1000, 500).points, [{ x: 110, y: 0 }, { x: 210, y: 0 }, { x: 210, y: 100 }]);
	assert.deepEqual(shapeBounds(pen), { x: 100, y: 100, width: 100, height: 100 });
});

test('the 4 corner handles scale the stroke from the opposite corner, mirroring it when dragged over', () => {
	const corners = handles(pen);
	assert.deepEqual(corners.map(({ x, y }) => [x, y]), [[100, 100], [200, 100], [200, 200], [100, 200]]);
	const bottomRight = handleAt(pen, 198, 198, 12);
	assert.deepEqual(moveHandle(pen, bottomRight, 300, 150).points, [{ x: 100, y: 100 }, { x: 300, y: 100 }, { x: 300, y: 150 }]);
	const mirrored = moveHandle(pen, bottomRight, 0, 200);
	assert.deepEqual(mirrored.points.map((p) => p.x), [100, 0, 0], 'flipped left of the fixed corner');
	const flat = { type: 'pen', points: [{ x: 50, y: 0 }, { x: 50, y: 100 }] };
	assert.deepEqual(moveHandle(flat, handles(flat)[2], 90, 200).points, [{ x: 50, y: 0 }, { x: 50, y: 200 }], 'a straight line keeps its flat side');
	assert.equal(pen.points[1].x, 200, 'the original is not modified');
});

test('a stroke smaller than the minimum on both sides is an accidental tap', () => {
	assert.equal(isTiny({ type: 'pen', points: [{ x: 0, y: 0 }, { x: 3, y: 5 }] }, 8), true);
	assert.equal(isTiny({ type: 'pen', points: [{ x: 0, y: 0 }, { x: 0, y: 40 }] }, 8), false, 'a straight vertical line is meant');
});
