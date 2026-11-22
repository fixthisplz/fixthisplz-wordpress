import { test } from 'node:test';
import assert from 'node:assert/strict';
import { beginDraw, beginSelect, commitText, endGesture, gestureShapes, moveGesture } from '../../assets/js/lib/annotate-gesture.js';
import { record, startHistory, undo } from '../../assets/js/lib/annotate-shapes.js';

const area = { width: 1000, height: 500, step: 3 };
const rect = { type: 'rect', x1: 100, y1: 100, x2: 300, y2: 200 };

test('a click without moving, with the Select tool, selects but records nothing', () => {
	const history = startHistory([rect]);
	const { selected, gesture } = beginSelect(history.shapes, null, { x: 100, y: 150 }, 10);
	assert.equal(selected, 0);
	assert.equal(gestureShapes(gesture), history.shapes, 'nothing shown changes before the pointer moves');
	assert.deepEqual(endGesture(history, gesture, 8), { history, selected: 0 });
});

test('a press on empty space deselects and starts no gesture', () => {
	assert.deepEqual(beginSelect([rect], 0, { x: 600, y: 400 }, 10), { selected: null, gesture: null });
});

test('a drag records one entry, however many moves it had; one moved back to the start records nothing', () => {
	const history = startHistory([rect]);
	let { gesture } = beginSelect(history.shapes, null, { x: 100, y: 150 }, 10);
	gesture = moveGesture(gesture, { x: 120, y: 160 }, area);
	gesture = moveGesture(gesture, { x: 150, y: 170 }, area);
	const moved = endGesture(history, gesture, 8);
	assert.equal(moved.history.past.length, history.past.length + 1);
	assert.deepEqual(moved.history.shapes, [{ type: 'rect', x1: 150, y1: 120, x2: 350, y2: 220 }]);
	assert.equal(moved.selected, 0);
	const back = moveGesture(gesture, { x: 100, y: 150 }, area);
	assert.equal(endGesture(history, back, 8).history, history, 'no undo entry for a no-op drag');
});

test('cancelling a gesture leaves the shapes of the press', () => {
	const history = startHistory([rect]);
	const { gesture } = beginSelect(history.shapes, null, { x: 100, y: 150 }, 10);
	assert.deepEqual(gestureShapes(moveGesture(gesture, { x: 400, y: 400 }, area)).length, 1);
	assert.equal(gesture.base, history.shapes, 'the editor drops the gesture and shows history.shapes again');
});

test('a handle keeps its offset from the pointer: the corner does not jump under it', () => {
	const shapes = [rect];
	const { gesture } = beginSelect(shapes, 0, { x: 295, y: 205 }, 12); // 5 px off the bottom-right corner
	const moved = moveGesture(gesture, { x: 305, y: 215 }, area);
	assert.deepEqual(moved.current, { type: 'rect', x1: 100, y1: 100, x2: 310, y2: 210 });
	const outside = moveGesture(gesture, { x: 998, y: 300 }, area);
	assert.equal(outside.current.x2, 1000, 'the offset never takes the corner off the image');
});

test('drawing adds the shape in the chosen colour and selects it; a tiny one or a click records nothing', () => {
	const history = startHistory([]);
	const gesture = beginDraw(history.shapes, 'arrow', { x: 10, y: 10 }, '#1d6fe0');
	assert.deepEqual(endGesture(history, gesture, 8), { history, selected: null }, 'a click draws nothing');
	const drawn = endGesture(history, moveGesture(gesture, { x: 200, y: 10 }, area), 8);
	assert.deepEqual(drawn.history.shapes, [{ type: 'arrow', color: '#1d6fe0', x1: 10, y1: 10, x2: 200, y2: 10 }]);
	assert.equal(drawn.selected, 0);
	assert.equal(endGesture(history, moveGesture(gesture, { x: 13, y: 12 }, area), 8).history, history, 'tiny');
});

test('a hidden area drawn takes no colour', () => {
	const history = record(startHistory([]), [rect]);
	const gesture = moveGesture(beginDraw(history.shapes, 'mask', { x: 0, y: 0 }, '#1d6fe0'), { x: 50, y: 50 }, area);
	assert.deepEqual(endGesture(history, gesture, 8).history.shapes[1], { type: 'mask', x1: 0, y1: 0, x2: 50, y2: 50 });
});

test('a pen stroke is drawn point by point and kept as one undo entry', () => {
	const history = startHistory([]);
	let gesture = beginDraw(history.shapes, 'pen', { x: 10, y: 10 }, '#1d2327');
	for (const x of [20, 21, 40, 60]) {
		gesture = moveGesture(gesture, { x, y: 10 }, area);
	}
	const { history: drawn, selected } = endGesture(history, gesture, 8);
	assert.deepEqual(drawn.shapes[0].points.map((p) => p.x), [10, 20, 40, 60], 'the point 1 px after 20 is dropped');
	assert.equal(drawn.shapes[0].color, '#1d2327');
	assert.equal(selected, 0);
	assert.equal(drawn.past.length, 1);
});

const typed = { type: 'text', x: 0, y: 0, text: 'Hello', fontSize: 20, color: '#e0161b', width: 60, height: 35 };

test('a text typed is added and selected; a field left empty adds nothing', () => {
	const history = startHistory([rect]);
	assert.deepEqual(commitText(history, null, typed), { history: record(history, [rect, typed]), selected: 1 });
	assert.deepEqual(commitText(history, null, null), { history, selected: null });
});

test('editing a text is one undo entry; unchanged text records nothing; emptied text is removed', () => {
	const history = record(startHistory([]), [typed]);
	const edited = commitText(history, 0, { ...typed, text: 'Hello world', width: 120 });
	assert.equal(edited.history.past.length, 2);
	assert.equal(edited.selected, 0);
	assert.deepEqual(undo(edited.history).shapes, [typed], 'the edit is undone');
	assert.deepEqual(commitText(history, 0, { ...typed }), { history, selected: 0 });
	const emptied = commitText(history, 0, null);
	assert.deepEqual([emptied.history.shapes, emptied.selected], [[], null]);
});
