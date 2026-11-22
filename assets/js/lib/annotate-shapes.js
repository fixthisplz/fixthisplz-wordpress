/* Editing of the screenshot annotations (spec 5.2, annotation): hit testing, moving, resizing, deleting, and the undo
 * history. Pure: shapes are the plain objects of annotate-draw.js, in the image's native pixels; nothing is mutated. */
import { handles, normalizeRect } from './annotate-draw.js';

const within = (value, min, max) => Math.min(Math.max(value, min), max);

/** Distance from (x, y) to the segment of an arrow. */
function distanceToSegment(x, y, { x1, y1, x2, y2 }) {
	const dx = x2 - x1;
	const dy = y2 - y1;
	const lengthSq = dx * dx + dy * dy;
	const t = lengthSq === 0 ? 0 : within(((x - x1) * dx + (y - y1) * dy) / lengthSq, 0, 1);
	return Math.hypot(x - (x1 + t * dx), y - (y1 + t * dy));
}

/** What can be grabbed is what is seen: an arrow's line, a rectangle's outline, a hidden area's whole surface. */
function hits(shape, x, y, slop) {
	if (shape.type === 'arrow') {
		return distanceToSegment(x, y, shape) <= slop;
	}
	const r = normalizeRect(shape);
	const inside = (margin) => x >= r.x - margin && x <= r.x + r.width + margin && y >= r.y - margin && y <= r.y + r.height + margin;
	return inside(slop) && (shape.type === 'mask' || !inside(-slop)); // a rectangle thinner than 2 slops is grabbed anywhere
}

/** Index of the topmost shape under (x, y) within slop (native px), or -1. */
export function hitTest(shapes, x, y, slop) {
	return shapes.findLastIndex((shape) => hits(shape, x, y, slop));
}

/** Handle of shape within radius (native px) of (x, y), or null. */
export function handleAt(shape, x, y, radius) {
	return handles(shape).find((handle) => Math.hypot(handle.x - x, handle.y - y) <= radius) ?? null;
}

/** shape moved by (dx, dy), stopped at the edges of the width x height image. */
export function moveShape(shape, dx, dy, width, height) {
	const box = normalizeRect(shape);
	const mx = within(dx, -box.x, width - box.x - box.width);
	const my = within(dy, -box.y, height - box.y - box.height);
	return { ...shape, x1: shape.x1 + mx, y1: shape.y1 + my, x2: shape.x2 + mx, y2: shape.y2 + my };
}

/** shape with handle dragged to (x, y), a point already inside the image: the opposite corner or end stays put, and
 * a rectangle dragged over it simply turns inside out (drawing and hit testing normalise it). */
export function moveHandle(shape, handle, x, y) {
	return { ...shape, [handle.xKey]: x, [handle.yKey]: y };
}

/** shapes with the one at index replaced by shape. */
export function replaceShape(shapes, index, shape) {
	return shapes.map((current, i) => (i === index ? shape : current));
}

/** shapes without the one at index. */
export function deleteShape(shapes, index) {
	return shapes.filter((_, i) => i !== index);
}

/** Undo history of an editing session. Shapes kept from an earlier session can be undone one by one, as if just drawn. */
export function startHistory(shapes) {
	return { shapes, past: shapes.map((_, i) => shapes.slice(0, i)) };
}

/** history after one action (add, move, resize, delete, clear) that left shapes. */
export function record(history, shapes) {
	return { shapes, past: [...history.past, history.shapes] };
}

/** history with its last action undone. history.past is not empty. */
export function undo(history) {
	return { shapes: history.past.at(-1), past: history.past.slice(0, -1) };
}
