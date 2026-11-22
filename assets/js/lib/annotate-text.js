/* The text annotation type (spec 5.2, annotation): { type: 'text', x, y, text, fontSize, color, width, height } in
 * the image's native pixels, (x, y) being the top-left corner of its box. The box is measured once, when the text is
 * typed (makeText) or set to a size preset, so bounds and hit testing stay pure. Follows the contract of the SHAPES table (annotate-shapes.js),
 * except that a text is typed in a field, not dragged out: no create, extend or isTiny. */
import { DEFAULT_SIZE, SIZES, boxHandles, colorOf, inBox, within } from './annotate-common.js';

export const TEXT_MAX_LENGTH = 200;
// The generic family every system has: the editor's field and the image use the same one.
const TEXT_FONT = 'sans-serif';
// Semi-transparent white under the text, so it reads on any screenshot.
export const TEXT_BACKGROUND = 'rgba(255, 255, 255, 0.8)';
export const LINE_HEIGHT = 1.25; // box height without padding, in font sizes
const PADDING = 0.25; // in font sizes
const FONT_RATIO = 60; // medium font size: the longer side of the image divided by this
const MEDIUM_MIN_FONT = 12; // medium font size of a small image
const MIN_FONT = 8;

/** CSS / canvas font of a text of size px. */
export const fontFor = (size) => `${size}px ${TEXT_FONT}`;

/** Padding between the text and the edge of its box, for a font size. */
export const textPadding = (fontSize) => fontSize * PADDING;

/** Font size (native px) of a size preset for an image of width x height: proportional, like the line width. */
export function fontSizeFor(width, height, size = DEFAULT_SIZE) {
	return Math.max(MIN_FONT, Math.round(Math.max(MEDIUM_MIN_FONT, Math.max(width, height) / FONT_RATIO) * SIZES[size]));
}

/**
 * Text shape with its box measured. measure(text, font) returns the width of text in that font (canvas measureText).
 * @param {{x: number, y: number, text: string, fontSize: number, color: string}} fields
 */
export function makeText(fields, measure) {
	const pad = textPadding(fields.fontSize);
	return {
		type: 'text',
		...fields,
		width: measure(fields.text, fontFor(fields.fontSize)) + 2 * pad,
		height: fields.fontSize * LINE_HEIGHT + 2 * pad,
	};
}

const box = ({ x, y, width, height }) => ({ x, y, width, height });

export const TEXT = {
	colored: true,
	bounds: box,
	translate: (shape, dx, dy) => ({ ...shape, x: shape.x + dx, y: shape.y + dy }),
	hits: (shape, x, y, slop) => inBox(box(shape), x, y, slop), // the whole box, like a hidden area
	// The preset font size, the box measured again and kept inside the image (made smaller if it cannot fit); the
	// top-left corner stays put unless the box would cross an edge. A size set by a corner is any font size.
	withSize(shape, size, { width, height, measure }) {
		const measured = makeText({ ...shape, fontSize: fontSizeFor(width, height, size) }, measure);
		const fit = Math.min(1, width / measured.width, height / measured.height);
		if (measured.fontSize * fit === shape.fontSize) {
			return shape;
		}
		const boxWidth = measured.width * fit;
		const boxHeight = measured.height * fit;
		return {
			...measured,
			fontSize: measured.fontSize * fit,
			width: boxWidth,
			height: boxHeight,
			x: within(shape.x, 0, width - boxWidth),
			y: within(shape.y, 0, height - boxHeight),
		};
	},
	handles: (shape) => boxHandles(box(shape)),
	// The font size follows the corner (the farther of its two directions), the opposite corner staying put; the box
	// lands on the side of the pointer and never grows past the edges of the imageWidth x imageHeight image.
	moveHandle(shape, handle, x, y, imageWidth, imageHeight) {
		const wanted = Math.max(Math.abs(x - handle.ox) / shape.width, Math.abs(y - handle.oy) / shape.height);
		const roomX = x < handle.ox ? handle.ox : imageWidth - handle.ox;
		const roomY = y < handle.oy ? handle.oy : imageHeight - handle.oy;
		const room = Math.min(roomX / shape.width, roomY / shape.height);
		const scale = Math.min(Math.max(wanted, MIN_FONT / shape.fontSize), room);
		const width = shape.width * scale;
		const height = shape.height * scale;
		return {
			...shape,
			fontSize: shape.fontSize * scale,
			width,
			height,
			x: x < handle.ox ? handle.ox - width : handle.ox,
			y: y < handle.oy ? handle.oy - height : handle.oy,
		};
	},
	draw(ctx, shape) {
		ctx.fillStyle = TEXT_BACKGROUND;
		ctx.fillRect(shape.x, shape.y, shape.width, shape.height);
		ctx.fillStyle = colorOf(shape);
		ctx.font = fontFor(shape.fontSize);
		ctx.textAlign = 'left';
		ctx.textBaseline = 'middle';
		ctx.fillText(shape.text, shape.x + textPadding(shape.fontSize), shape.y + shape.height / 2);
	},
};
