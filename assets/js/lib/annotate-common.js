/* Helpers shared by the annotation shape types (spec 5.2, annotation). Pure, in the image's native pixels. */

// Default colour of the annotations, and of shapes kept from a session that had no colour choice.
export const ANNOTATION_COLOR = '#e0161b';

/** Colour a shape is drawn in. */
export const colorOf = (shape) => shape.color ?? ANNOTATION_COLOR;

export const within = (value, min, max) => Math.min(Math.max(value, min), max);

/** Rectangle of a two-point shape whatever the drag direction. */
export function normalizeRect({ x1, y1, x2, y2 }) {
	return { x: Math.min(x1, x2), y: Math.min(y1, y2), width: Math.abs(x2 - x1), height: Math.abs(y2 - y1) };
}

/** True when (x, y) lies in box enlarged by margin (shrunk when margin is negative). */
export function inBox(box, x, y, margin) {
	return x >= box.x - margin && x <= box.x + box.width + margin && y >= box.y - margin && y <= box.y + box.height + margin;
}

/** Distance from (x, y) to the segment a-b ({x, y} points). */
export function distanceToSegment(x, y, a, b) {
	const dx = b.x - a.x;
	const dy = b.y - a.y;
	const lengthSq = dx * dx + dy * dy;
	const t = lengthSq === 0 ? 0 : within(((x - a.x) * dx + (y - a.y) * dy) / lengthSq, 0, 1);
	return Math.hypot(x - (a.x + t * dx), y - (a.y + t * dy));
}

/**
 * The 4 corners of box, each with the opposite corner (ox, oy) that stays put when it is dragged: the handles of
 * the shapes resized by scaling (pen, text).
 */
export function boxHandles({ x, y, width, height }) {
	const corners = [[x, y], [x + width, y], [x + width, y + height], [x, y + height]];
	return corners.map(([cx, cy], i) => {
		const [ox, oy] = corners[(i + 2) % 4];
		return { x: cx, y: cy, ox, oy };
	});
}
