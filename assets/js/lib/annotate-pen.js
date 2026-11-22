/* The pen annotation type (spec 5.2, annotation): a freehand stroke { type: 'pen', color, size?, points: [{x, y}, ...] } in
 * the image's native pixels, drawn smoothly through the recorded points. Pure; follows the contract of the SHAPES
 * table (annotate-shapes.js). */
import { boxHandles, colorOf, distanceToSegment, withStrokeSize } from './annotate-common.js';

const midpoint = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });

/** Smallest box holding points. */
function pointsBounds(points) {
	const xs = points.map((p) => p.x);
	const ys = points.map((p) => p.y);
	const x = Math.min(...xs);
	const y = Math.min(...ys);
	return { x, y, width: Math.max(...xs) - x, height: Math.max(...ys) - y };
}

/**
 * Canvas path commands of a smooth stroke through points: quadratic curves from midpoint to midpoint, each bent by
 * the recorded point between them, so the polyline's corners are rounded off.
 * @returns {Array<[string, ...number[]]>} [method, ...arguments] calls of a 2D context.
 */
export function smoothPath(points) {
	const commands = [['moveTo', points[0].x, points[0].y]];
	for (let i = 1; i < points.length - 1; i += 1) {
		const end = midpoint(points[i], points[i + 1]);
		commands.push(['quadraticCurveTo', points[i].x, points[i].y, end.x, end.y]);
	}
	const last = points.at(-1);
	commands.push(['lineTo', last.x, last.y]);
	return commands;
}

/** Scale factor taking a coordinate from handle to target, the opposite one staying put; 1 along a flat side. */
const factor = (from, origin, target) => (from === origin ? 1 : (target - origin) / (from - origin));

export const PEN = {
	colored: true,
	withSize: withStrokeSize,
	create: (p, color, size) => ({ type: 'pen', color, size, points: [{ x: p.x, y: p.y }] }),
	// Points closer than step to the previous one add nothing but weight and jitter.
	extend(shape, q, step) {
		const last = shape.points.at(-1);
		return Math.hypot(q.x - last.x, q.y - last.y) < step ? shape : { ...shape, points: [...shape.points, { x: q.x, y: q.y }] };
	},
	bounds: (shape) => pointsBounds(shape.points),
	translate: (shape, dx, dy) => ({ ...shape, points: shape.points.map((p) => ({ x: p.x + dx, y: p.y + dy })) }),
	hits(shape, x, y, slop) {
		const { points } = shape;
		return points.length === 1
			? Math.hypot(x - points[0].x, y - points[0].y) <= slop
			: points.slice(1).some((p, i) => distanceToSegment(x, y, points[i], p) <= slop);
	},
	handles: (shape) => boxHandles(pointsBounds(shape.points)),
	// Scaled from the opposite corner; dragged over it, the stroke is mirrored.
	moveHandle(shape, handle, x, y) {
		const sx = factor(handle.x, handle.ox, x);
		const sy = factor(handle.y, handle.oy, y);
		return { ...shape, points: shape.points.map((p) => ({ x: handle.ox + (p.x - handle.ox) * sx, y: handle.oy + (p.y - handle.oy) * sy })) };
	},
	isTiny(shape, min) {
		const { width, height } = pointsBounds(shape.points);
		return Math.max(width, height) < min;
	},
	draw(ctx, shape) {
		ctx.save();
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.strokeStyle = colorOf(shape);
		ctx.beginPath();
		for (const [method, ...args] of smoothPath(shape.points)) {
			ctx[method](...args);
		}
		ctx.stroke();
		ctx.restore();
	},
};
