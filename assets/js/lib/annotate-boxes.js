/* The two-point annotation types (spec 5.2, annotation): rectangle, hidden area and arrow, plain objects
 * { type, x1, y1, x2, y2, color?, size? } in the image's native pixels. Pure. Each type follows the contract of the SHAPES
 * table (annotate-shapes.js). */
import { MASK_FILL } from './sensitive.js';
import { colorOf, distanceToSegment, inBox, normalizeRect, withStrokeSize } from './annotate-common.js';

const HEAD_RATIO = 4; // arrow head length, in line widths
const HEAD_ANGLE = Math.PI / 6;
const RECT_CORNERS = [['x1', 'y1'], ['x2', 'y1'], ['x2', 'y2'], ['x1', 'y2']];
const ARROW_ENDS = [['x1', 'y1'], ['x2', 'y2']];

/** Arrow head of a line ending at (x2, y2): tip, both wing points, and the base where the shaft stops. */
export function arrowHead({ x1, y1, x2, y2 }, size) {
	const angle = Math.atan2(y2 - y1, x2 - x1);
	const wing = (side) => ({ x: x2 - size * Math.cos(angle + side * HEAD_ANGLE), y: y2 - size * Math.sin(angle + side * HEAD_ANGLE) });
	const left = wing(-1);
	const right = wing(1);
	return { tip: { x: x2, y: y2 }, left, right, base: { x: (left.x + right.x) / 2, y: (left.y + right.y) / 2 } };
}

const pointHandles = (keys) => (shape) => keys.map(([xKey, yKey]) => ({ xKey, yKey, x: shape[xKey], y: shape[yKey] }));

/** Behaviour common to the three types: two points, dragged out from the press point. */
const twoPoints = {
	bounds: normalizeRect,
	translate: (shape, dx, dy) => ({ ...shape, x1: shape.x1 + dx, y1: shape.y1 + dy, x2: shape.x2 + dx, y2: shape.y2 + dy }),
	// A rectangle dragged over its opposite corner simply turns inside out: drawing and hit testing normalise it.
	moveHandle: (shape, handle, x, y) => ({ ...shape, [handle.xKey]: x, [handle.yKey]: y }),
	extend: (shape, q) => ({ ...shape, x2: q.x, y2: q.y }),
	handles: pointHandles(RECT_CORNERS),
	isTiny(shape, min) {
		const { width, height } = normalizeRect(shape);
		return width < min || height < min;
	},
};

export const RECT = {
	...twoPoints,
	colored: true,
	withSize: withStrokeSize,
	create: (p, color, size) => ({ type: 'rect', color, size, x1: p.x, y1: p.y, x2: p.x, y2: p.y }),
	// Its outline is what is seen and grabbed; the middle shows the page (a rectangle thinner than 2 slops is grabbed anywhere).
	hits(shape, x, y, slop) {
		const box = normalizeRect(shape);
		return inBox(box, x, y, slop) && !inBox(box, x, y, -slop);
	},
	draw(ctx, shape) {
		const { x, y, width, height } = normalizeRect(shape);
		ctx.strokeStyle = colorOf(shape);
		ctx.strokeRect(x, y, width, height);
	},
};

// A hidden area is always filled like the automatic masks: it has no colour nor size of its own.
export const MASK = {
	...twoPoints,
	colored: false,
	withSize: (shape) => shape,
	create: (p) => ({ type: 'mask', x1: p.x, y1: p.y, x2: p.x, y2: p.y }),
	hits: (shape, x, y, slop) => inBox(normalizeRect(shape), x, y, slop),
	draw(ctx, shape) {
		const { x, y, width, height } = normalizeRect(shape);
		ctx.fillStyle = MASK_FILL;
		ctx.fillRect(x, y, width, height);
	},
};

export const ARROW = {
	...twoPoints,
	colored: true,
	withSize: withStrokeSize,
	create: (p, color, size) => ({ type: 'arrow', color, size, x1: p.x, y1: p.y, x2: p.x, y2: p.y }),
	hits: (shape, x, y, slop) => distanceToSegment(x, y, { x: shape.x1, y: shape.y1 }, { x: shape.x2, y: shape.y2 }) <= slop,
	handles: pointHandles(ARROW_ENDS),
	isTiny: (shape, min) => Math.hypot(shape.x2 - shape.x1, shape.y2 - shape.y1) < min,
	draw(ctx, shape) {
		const { tip, left, right, base } = arrowHead(shape, ctx.lineWidth * HEAD_RATIO);
		ctx.strokeStyle = colorOf(shape);
		ctx.fillStyle = colorOf(shape);
		ctx.beginPath();
		ctx.moveTo(shape.x1, shape.y1);
		ctx.lineTo(base.x, base.y); // the shaft stops under the head so its end never pokes out of the tip
		ctx.stroke();
		ctx.beginPath();
		ctx.moveTo(tip.x, tip.y);
		ctx.lineTo(left.x, left.y);
		ctx.lineTo(right.x, right.y);
		ctx.closePath();
		ctx.fill();
	},
};
