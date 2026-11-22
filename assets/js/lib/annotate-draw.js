/* Drawing of the screenshot annotations (spec 5.2, annotation). Pure: the same drawShapes() paints the editor's
 * canvas and the final image, so what the visitor sees is what is sent; the selection overlay is editor-only. */
import { SHAPES, handles, shapeBounds } from './annotate-shapes.js';
import { DEFAULT_SIZE, SIZES, sizeOf } from './annotate-common.js';

// Medium line width relative to the longer side of the image: the strokes look the same whatever the screen.
const LINE_RATIO = 300;
const MIN_LINE = 2; // medium line width of a small image
// Selection overlay (editor only), in CSS pixels: converted with the native-per-CSS scale so it looks the same at any zoom.
const SELECTION_COLOR = '#1d4ed8';
const SELECTION_PAD = 4;
const SELECTION_DASH = 6;
const HANDLE_SIZE = 10;

const clamp = (value, max) => Math.min(Math.max(value, 0), max);

/** Line width (native px) of a size preset for an image of width x height. */
export function lineWidthFor(width, height, size = DEFAULT_SIZE) {
	return Math.max(1, Math.round(Math.max(MIN_LINE, Math.max(width, height) / LINE_RATIO) * SIZES[size]));
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

/** Paints shapes, in order, on a 2D context whose canvas has the image's native size (width x height). */
export function drawShapes(ctx, shapes, width, height) {
	ctx.lineJoin = 'miter';
	for (const shape of shapes) {
		ctx.lineWidth = lineWidthFor(width, height, sizeOf(shape));
		SHAPES[shape.type].draw(ctx, shape);
	}
}

/**
 * Paints the editor's selection overlay (dashed box and handles) of one shape. Kept out of drawShapes() so it is never
 * part of the image sent. scale: native pixels per CSS pixel of the displayed canvas.
 */
export function drawSelection(ctx, shape, scale) {
	const { x, y, width, height } = shapeBounds(shape);
	const pad = SELECTION_PAD * scale;
	const box = [x - pad, y - pad, width + 2 * pad, height + 2 * pad];
	const size = HANDLE_SIZE * scale;
	ctx.lineWidth = 1.5 * scale;
	ctx.strokeStyle = '#ffffff'; // under the dashes, so the box shows on dark and light screenshots alike
	ctx.strokeRect(...box);
	ctx.setLineDash([SELECTION_DASH * scale, SELECTION_DASH * scale]);
	ctx.strokeStyle = SELECTION_COLOR;
	ctx.strokeRect(...box);
	ctx.setLineDash([]);
	ctx.fillStyle = '#ffffff';
	for (const handle of handles(shape)) {
		ctx.fillRect(handle.x - size / 2, handle.y - size / 2, size, size);
		ctx.strokeRect(handle.x - size / 2, handle.y - size / 2, size, size);
	}
}
