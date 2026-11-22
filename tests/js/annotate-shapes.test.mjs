import { test } from 'node:test';
import assert from 'node:assert/strict';
import { handles } from '../../assets/js/lib/annotate-draw.js';
import { deleteShape, handleAt, hitTest, moveHandle, moveShape, record, replaceShape, startHistory, undo } from '../../assets/js/lib/annotate-shapes.js';

const rect = { type: 'rect', x1: 100, y1: 100, x2: 300, y2: 200 };
const mask = { type: 'mask', x1: 100, y1: 100, x2: 300, y2: 200 };
const arrow = { type: 'arrow', x1: 0, y1: 0, x2: 400, y2: 0 };

test('hitTest grabs the topmost shape first', () => {
	const shapes = [mask, { ...rect }];
	assert.equal(hitTest(shapes, 100, 150, 10), 1, 'on the outline of the rectangle drawn last');
	assert.equal(hitTest(shapes, 200, 150, 10), 0, 'inside the rectangle, only the hidden area below is hit');
	assert.equal(hitTest(shapes, 600, 600, 10), -1, 'empty space hits nothing');
});

test('hitTest on a rectangle: its outline within the slop, not its empty middle; a thin one anywhere', () => {
	assert.equal(hitTest([rect], 91, 150, 10), 0, 'just outside the left edge');
	assert.equal(hitTest([rect], 109, 150, 10), 0, 'just inside the left edge');
	assert.equal(hitTest([rect], 89, 150, 10), -1);
	assert.equal(hitTest([rect], 200, 150, 10), -1, 'the middle shows the page, not the rectangle');
	assert.equal(hitTest([{ type: 'rect', x1: 0, y1: 0, x2: 200, y2: 15 }], 100, 7, 10), 0, 'thinner than two slops');
});

test('hitTest on a hidden area: its whole surface, plus the slop', () => {
	assert.equal(hitTest([mask], 200, 150, 10), 0);
	assert.equal(hitTest([mask], 305, 205, 10), 0);
	assert.equal(hitTest([mask], 311, 150, 10), -1);
});

test('hitTest on an arrow: distance to its line, ends included', () => {
	assert.equal(hitTest([arrow], 200, 9, 10), 0);
	assert.equal(hitTest([arrow], 200, 11, 10), -1);
	assert.equal(hitTest([arrow], 405, 5, 10), 0, 'near the tip');
	assert.equal(hitTest([arrow], 412, 0, 10), -1, 'beyond the tip');
	assert.equal(hitTest([{ type: 'arrow', x1: 5, y1: 5, x2: 5, y2: 5 }], 10, 5, 10), 0, 'a zero-length arrow is a point');
});

test('handleAt finds a corner or an end within the radius, or null', () => {
	assert.deepEqual(handleAt(rect, 295, 205, 12), { xKey: 'x2', yKey: 'y2', x: 300, y: 200 });
	assert.deepEqual(handleAt(rect, 108, 192, 12), { xKey: 'x1', yKey: 'y2', x: 100, y: 200 });
	assert.equal(handleAt(rect, 200, 150, 12), null);
	assert.deepEqual(handleAt(arrow, 4, 3, 12), { xKey: 'x1', yKey: 'y1', x: 0, y: 0 });
});

test('moveShape moves every point and stops at the edges of the image', () => {
	assert.deepEqual(moveShape(rect, 50, -20, 1000, 500), { type: 'rect', x1: 150, y1: 80, x2: 350, y2: 180 });
	assert.deepEqual(moveShape(rect, -500, 900, 1000, 500), { type: 'rect', x1: 0, y1: 400, x2: 200, y2: 500 });
	const reversed = { type: 'arrow', x1: 400, y1: 0, x2: 0, y2: 50 };
	assert.deepEqual(moveShape(reversed, 900, 10, 600, 500), { type: 'arrow', x1: 600, y1: 10, x2: 200, y2: 60 }, 'whatever the direction');
	assert.deepEqual(rect, { type: 'rect', x1: 100, y1: 100, x2: 300, y2: 200 }, 'the original is not modified');
});

test('moveHandle resizes from each corner, the opposite one staying put, crossing over included', () => {
	const [topLeft, topRight, bottomRight, bottomLeft] = handles(rect);
	assert.deepEqual(moveHandle(rect, topLeft, 50, 60), { type: 'rect', x1: 50, y1: 60, x2: 300, y2: 200 });
	assert.deepEqual(moveHandle(rect, topRight, 350, 90), { type: 'rect', x1: 100, y1: 90, x2: 350, y2: 200 });
	assert.deepEqual(moveHandle(rect, bottomRight, 250, 260), { type: 'rect', x1: 100, y1: 100, x2: 250, y2: 260 });
	assert.deepEqual(moveHandle(rect, bottomLeft, 80, 150), { type: 'rect', x1: 80, y1: 100, x2: 300, y2: 150 });
	const crossed = moveHandle(mask, bottomRight, 20, 30); // dragged past the top-left corner
	assert.deepEqual(handles(crossed).map(({ x, y }) => [x, y]).sort(), [[100, 100], [100, 30], [20, 100], [20, 30]].sort());
	assert.deepEqual(hitTest([crossed], 60, 60, 1), 0, 'still a normal area once crossed over');
});

test('moveHandle on an arrow moves that end only, so the arrow can be turned', () => {
	const [start, end] = handles(arrow);
	assert.deepEqual(moveHandle(arrow, end, 0, 300), { type: 'arrow', x1: 0, y1: 0, x2: 0, y2: 300 });
	assert.deepEqual(moveHandle(arrow, start, 500, 10), { type: 'arrow', x1: 500, y1: 10, x2: 400, y2: 0 });
});

test('replaceShape and deleteShape return new lists', () => {
	const shapes = [rect, mask, arrow];
	assert.deepEqual(replaceShape(shapes, 1, arrow), [rect, arrow, arrow]);
	assert.deepEqual(deleteShape(shapes, 1), [rect, arrow]);
	assert.deepEqual(shapes, [rect, mask, arrow]);
});

test('startHistory lets shapes kept from an earlier session be undone one by one', () => {
	let history = startHistory([rect, arrow]);
	assert.equal(history.past.length, 2);
	history = undo(history);
	assert.deepEqual(history.shapes, [rect]);
	history = undo(history);
	assert.deepEqual(history.shapes, []);
	assert.equal(history.past.length, 0, 'nothing left to undo');
	assert.deepEqual(startHistory([]), { shapes: [], past: [] });
});

test('undo reverts each kind of action: add, move, resize, delete, clear', () => {
	const added = record(startHistory([]), [rect]);
	const moved = record(added, [moveShape(rect, 10, 10, 1000, 1000)]);
	const resized = record(moved, [moveHandle(moved.shapes[0], handles(rect)[2], 500, 400)]);
	const withArrow = record(resized, [...resized.shapes, arrow]);
	const deleted = record(withArrow, deleteShape(withArrow.shapes, 0));
	const cleared = record(deleted, []);
	assert.deepEqual(undo(cleared).shapes, [arrow], 'clear all is undone');
	assert.deepEqual(undo(deleted).shapes, withArrow.shapes, 'delete is undone');
	assert.deepEqual(undo(resized).shapes, moved.shapes, 'resize is undone');
	assert.deepEqual(undo(moved).shapes, [rect], 'move is undone');
	assert.deepEqual(undo(added).shapes, [], 'add is undone');
	assert.equal(cleared.past.length, 6);
});
