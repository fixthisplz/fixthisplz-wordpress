/* Geometry and drawing of the screenshot annotations (spec 5.2, annotation). Pure: shapes are plain objects
 * { type: 'rect'|'arrow'|'mask', x1, y1, x2, y2 } in the image's native pixels, and the same drawShapes() paints
 * the editor's canvas and the final image, so what the visitor sees is what is sent. */
import { MASK_FILL } from './sensitive.js';

export const ANNOTATION_COLOR = '#e0161b';
// Line width relative to the longer side of the image: the strokes look the same whatever the screen.
const LINE_RATIO = 300;
const HEAD_RATIO = 4; // arrow head length, in line widths
const HEAD_ANGLE = Math.PI / 6;

const clamp = (value, max) => Math.min(Math.max(value, 0), max);

/** Line width (native px) for an image of width x height. */
export function lineWidthFor(width, height) {
	return Math.max(2, Math.round(Math.max(width, height) / LINE_RATIO));
}

/** Largest size of a width x height image that fits in boxWidth x boxHeight, never enlarged. */
export function fitSize(width, height, boxWidth, boxHeight) {
	const scale = Math.max(0, Math.min(boxWidth / width, boxHeight / height, 1));
	return { width: Math.floor(width * scale), height: Math.floor(height * scale) };
}

/** Pointer position (client coordinates) mapped onto the native image, clamped to it. rect: the displayed box. */
export function toNative(clientX, clientY, rect, width, height) {
	return {
		x: clamp(((clientX - rect.left) * width) / rect.width, width),
		y: clamp(((clientY - rect.top) * height) / rect.height, height),
	};
}

/** Rectangle of a shape whatever the drag direction. */
export function normalizeRect({ x1, y1, x2, y2 }) {
	return { x: Math.min(x1, x2), y: Math.min(y1, y2), width: Math.abs(x2 - x1), height: Math.abs(y2 - y1) };
}

/** True for an accidental shape: an arrow shorter than min, a rectangle thinner than min on either side. */
export function isTiny(shape, min) {
	if (shape.type === 'arrow') {
		return Math.hypot(shape.x2 - shape.x1, shape.y2 - shape.y1) < min;
	}
	const { width, height } = normalizeRect(shape);
	return width < min || height < min;
}

/** Arrow head of a line ending at (x2, y2): tip, both wing points, and the base where the shaft stops. */
export function arrowHead({ x1, y1, x2, y2 }, size) {
	const angle = Math.atan2(y2 - y1, x2 - x1);
	const wing = (side) => ({ x: x2 - size * Math.cos(angle + side * HEAD_ANGLE), y: y2 - size * Math.sin(angle + side * HEAD_ANGLE) });
	const left = wing(-1);
	const right = wing(1);
	return { tip: { x: x2, y: y2 }, left, right, base: { x: (left.x + right.x) / 2, y: (left.y + right.y) / 2 } };
}

const DRAW = {
	rect(ctx, shape) {
		const { x, y, width, height } = normalizeRect(shape);
		ctx.strokeStyle = ANNOTATION_COLOR;
		ctx.strokeRect(x, y, width, height);
	},
	mask(ctx, shape) {
		const { x, y, width, height } = normalizeRect(shape);
		ctx.fillStyle = MASK_FILL;
		ctx.fillRect(x, y, width, height);
	},
	arrow(ctx, shape) {
		const { tip, left, right, base } = arrowHead(shape, ctx.lineWidth * HEAD_RATIO);
		ctx.strokeStyle = ANNOTATION_COLOR;
		ctx.fillStyle = ANNOTATION_COLOR;
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

/** Paints shapes, in order, on a 2D context whose canvas has the image's native size (width x height). */
export function drawShapes(ctx, shapes, width, height) {
	ctx.lineWidth = lineWidthFor(width, height);
	ctx.lineJoin = 'miter';
	for (const shape of shapes) {
		DRAW[shape.type](ctx, shape);
	}
}
