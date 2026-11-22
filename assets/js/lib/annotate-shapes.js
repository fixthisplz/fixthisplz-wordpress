/* Editing of the screenshot annotations (spec 5.2, annotation): the SHAPES table, the single place where each type
 * says how it is created, drawn, bounded, hit, moved and resized; the dispatchers built on it; the undo history.
 * Pure: shapes are plain objects in the image's native pixels; nothing is mutated.
 *
 * Contract of a SHAPES[type] entry:
 *   colored                    whether the shape takes the palette colour (shape.color)
 *   create(p, color), extend(shape, q, step)   drawing by dragging from p, step being the pen's smallest step
 *   isTiny(shape, min)         whether a shape just drawn is too small to be meant
 *   (text is typed in a field instead: it has none of these three)
 *   draw(ctx, shape)           paints it; ctx.lineWidth is already the image's line width
 *   bounds(shape)              { x, y, width, height }
 *   translate(shape, dx, dy)   moved, unbounded
 *   hits(shape, x, y, slop)    whether a press at (x, y) grabs it
 *   handles(shape)             the points that resize it, each { x, y, ... } as moveHandle() needs
 *   moveHandle(shape, handle, x, y, width, height)   resized with handle dragged to (x, y), inside the width x height image */
import { colorOf, within } from './annotate-common.js';
import { ARROW, MASK, RECT } from './annotate-boxes.js';
import { PEN } from './annotate-pen.js';
import { TEXT } from './annotate-text.js';

export const SHAPES = { rect: RECT, arrow: ARROW, pen: PEN, text: TEXT, mask: MASK };

const typeOf = (shape) => SHAPES[shape.type];

/** Handles of a shape (its corners, or the 2 ends of an arrow). */
export const handles = (shape) => typeOf(shape).handles(shape);

/** Box around a shape. */
export const shapeBounds = (shape) => typeOf(shape).bounds(shape);

/** True for an accidental shape just drawn, smaller than min (native px). */
export const isTiny = (shape, min) => typeOf(shape).isTiny(shape, min);

/** Index of the topmost shape under (x, y) within slop (native px), or -1. */
export function hitTest(shapes, x, y, slop) {
	return shapes.findLastIndex((shape) => typeOf(shape).hits(shape, x, y, slop));
}

/** Handle of shape within radius (native px) of (x, y), or null. */
export function handleAt(shape, x, y, radius) {
	return handles(shape).find((handle) => Math.hypot(handle.x - x, handle.y - y) <= radius) ?? null;
}

/** shape moved by (dx, dy), stopped at the edges of the width x height image. */
export function moveShape(shape, dx, dy, width, height) {
	const box = shapeBounds(shape);
	const mx = within(dx, -box.x, width - box.x - box.width);
	const my = within(dy, -box.y, height - box.y - box.height);
	return typeOf(shape).translate(shape, mx, my);
}

/** shape with handle dragged to (x, y), a point inside the width x height image: the opposite corner or end stays put. */
export const moveHandle = (shape, handle, x, y, width, height) => typeOf(shape).moveHandle(shape, handle, x, y, width, height);

/** shapes with the one at index in color; the same list when that changes nothing (same colour, hidden area). */
export function recolor(shapes, index, color) {
	const shape = shapes[index];
	return !typeOf(shape).colored || colorOf(shape) === color ? shapes : replaceShape(shapes, index, { ...shape, color });
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

/** history after one action (add, move, resize, recolour, edit, delete, clear) that left shapes. */
export function record(history, shapes) {
	return { shapes, past: [...history.past, history.shapes] };
}

/** history with its last action undone. history.past is not empty. */
export function undo(history) {
	return { shapes: history.past.at(-1), past: history.past.slice(0, -1) };
}
