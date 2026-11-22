import { test } from 'node:test';
import assert from 'node:assert/strict';
import { TEXT, TEXT_BACKGROUND, fontSizeFor, makeText } from '../../assets/js/lib/annotate-text.js';
import { drawShapes } from '../../assets/js/lib/annotate-draw.js';
import { handles, hitTest, moveHandle, moveShape, shapeBounds } from '../../assets/js/lib/annotate-shapes.js';
import { beginSelect, moveGesture } from '../../assets/js/lib/annotate-gesture.js';

// 10 px per character at 20 px, proportional to the font size, like a real font.
const measure = (text, font) => text.length * (parseFloat(font) / 2);
const text = makeText({ x: 100, y: 50, text: 'Broken', fontSize: 20, color: '#1d6fe0' }, measure);

test('makeText measures the box once: text width plus padding, line height plus padding', () => {
	assert.deepEqual(text, { type: 'text', x: 100, y: 50, text: 'Broken', fontSize: 20, color: '#1d6fe0', width: 70, height: 35 });
	assert.deepEqual(shapeBounds(text), { x: 100, y: 50, width: 70, height: 35 });
});

test('fontSizeFor is proportional to the image, never below 12 px', () => {
	assert.equal(fontSizeFor(1920, 1080), 32);
	assert.equal(fontSizeFor(300, 200), 12);
});

test('a text is grabbed anywhere on its box, plus the slop', () => {
	assert.equal(hitTest([text], 135, 67, 5), 0);
	assert.equal(hitTest([text], 174, 88, 5), 0);
	assert.equal(hitTest([text], 180, 67, 5), -1);
});

test('a text moves inside the image', () => {
	const moved = moveShape(text, 2000, -10, 1000, 500);
	assert.deepEqual([moved.x, moved.y], [930, 40]);
});

test('a corner handle scales the font size and the box from the opposite corner', () => {
	const [topLeft, , bottomRight] = handles(text);
	const bigger = moveHandle(text, bottomRight, 240, 85, 1000, 500); // twice as wide
	assert.deepEqual(bigger, { ...text, fontSize: 40, width: 140, height: 70 });
	assert.equal(bigger.width, measure('Broken', '40px sans-serif') + 2 * 10, 'the box matches the new font size');
	const fromTopLeft = moveHandle(text, topLeft, 135, 67.5, 1000, 500); // half size, bottom-right corner fixed
	assert.deepEqual([fromTopLeft.x, fromTopLeft.y, fromTopLeft.fontSize], [135, 67.5, 10]);
	const crossed = moveHandle(text, bottomRight, 30, 85, 1000, 500); // dragged left of the fixed top-left corner
	assert.equal(crossed.x + crossed.width, 100, 'the box lands on the side of the pointer');
	assert.equal(moveHandle(text, bottomRight, 101, 51, 1000, 500).fontSize, 8, 'never smaller than 8 px');
});

test('a text is drawn in its colour on a semi-transparent white box, in a font every system has', () => {
	const calls = [];
	const ctx = { fillRect: (...args) => calls.push(['fillRect', ctx.fillStyle, ...args]), fillText: (...args) => calls.push(['fillText', ctx.fillStyle, ctx.font, ...args]) };
	drawShapes(ctx, [text], 800, 600);
	assert.deepEqual(calls, [
		['fillRect', TEXT_BACKGROUND, 100, 50, 70, 35],
		['fillText', '#1d6fe0', '20px sans-serif', 'Broken', 105, 67.5],
	]);
	assert.equal(ctx.textBaseline, 'middle');
	assert.equal(TEXT.colored, true);
});

test('a text resized by a corner never grows past the edges of the image', () => {
	const [topLeft, , bottomRight] = handles(text); // box 100..170 x 50..85
	const right = moveHandle(text, bottomRight, 400, 300, 240, 400); // room: 140 px wide, 350 px high
	assert.deepEqual([right.x, right.y, right.width, right.height], [100, 50, 140, 70], 'stopped at the right edge');
	const left = moveHandle(text, topLeft, 0, 0, 1000, 500); // room above the fixed corner (170, 85): 85 px
	assert.deepEqual([left.x, left.y, left.height], [0, 0, 85]);
	assert.equal(left.x + left.width, 170, 'the opposite corner stays put');
	assert.ok(left.x >= 0 && left.y >= 0);
});

test('the image size reaches the text through the gesture, as for moving', () => {
	const { gesture } = beginSelect([text], 0, { x: 170, y: 85 }, 12);
	const resized = moveGesture(gesture, { x: 240, y: 400 }, { width: 240, height: 400, step: 3 }).current;
	assert.equal(resized.x + resized.width, 240);
});
