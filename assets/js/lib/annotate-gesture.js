/* Gesture rules of the annotation editor (spec 5.2, annotation): what a press, a drag and a release do to the shapes
 * and the undo history. Pure, in native pixels; the editor only maps pointer events onto these functions.
 *
 * A gesture is { base, index, current, drawing, moved, step }: base is the list at the press, current the shape being
 * drawn (drawing) or edited at index, step(current, q, area) its next state for the pointer at q, area
 * { width, height, step } the image size and the pen's minimal step. */
import { SHAPES, deleteShape, handleAt, hitTest, isTiny, moveHandle, moveShape, record, replaceShape } from './annotate-shapes.js';
import { within } from './annotate-common.js';

/** Press with a drawing tool at p: a new shape of type, in color and at size (a SIZES key), drawn from p. */
export function beginDraw(shapes, type, p, color, size) {
	const { create, extend } = SHAPES[type];
	const step = (shape, q, area) => extend(shape, q, area.step);
	return { base: shapes, index: shapes.length, current: create(p, color, size), drawing: true, moved: false, step };
}

/**
 * Press with the Select tool at p: a handle of the selected shape resizes it, else the topmost shape under p is moved.
 * @returns {{selected: number|null, gesture: object|null}} The new selection, and the gesture (null on empty space).
 */
export function beginSelect(shapes, selected, p, slop) {
	const handle = selected === null ? null : handleAt(shapes[selected], p.x, p.y, slop);
	const index = handle ? selected : hitTest(shapes, p.x, p.y, slop);
	if (index === -1) {
		return { selected: null, gesture: null };
	}
	const shape = shapes[index];
	// The handle keeps its offset from the pointer, so it does not jump under it at the first move.
	const step = handle
		? (_, q, area) =>
				moveHandle(shape, handle, within(q.x + handle.x - p.x, 0, area.width), within(q.y + handle.y - p.y, 0, area.height), area.width, area.height)
		: (_, q, area) => moveShape(shape, q.x - p.x, q.y - p.y, area.width, area.height);
	return { selected: index, gesture: { base: shapes, index, current: shape, drawing: false, moved: false, step } };
}

/** gesture with the pointer moved to q. */
export function moveGesture(gesture, q, area) {
	return { ...gesture, current: gesture.step(gesture.current, q, area), moved: true };
}

/** Shapes shown during gesture: the list of the press until the pointer moves. Cancelling just shows base again. */
export function gestureShapes(gesture) {
	if (!gesture.moved) {
		return gesture.base;
	}
	return gesture.drawing ? [...gesture.base, gesture.current] : replaceShape(gesture.base, gesture.index, gesture.current);
}

const sameShapes = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/**
 * Release of gesture: one undo entry for a real change. A click, a shape smaller than min (native px) or a drag that
 * ends where it started records nothing. The shape drawn or edited ends selected.
 * @returns {{history: object, selected: number|null}}
 */
export function endGesture(history, gesture, min) {
	const unchanged = { history, selected: gesture.drawing ? null : gesture.index };
	if (!gesture.moved || (gesture.drawing && isTiny(gesture.current, min))) {
		return unchanged;
	}
	const shapes = gestureShapes(gesture);
	return sameShapes(shapes, gesture.base) ? unchanged : { history: record(history, shapes), selected: gesture.index };
}

/**
 * Closing of the text field on a new text (index null) or on the text at index, with shape the text typed (null when
 * left empty). One undo entry when something changed: a text added, edited or emptied (removed).
 * @returns {{history: object, selected: number|null}} The text added or edited ends selected.
 */
export function commitText(history, index, shape) {
	const { shapes } = history;
	if (index === null) {
		return shape ? { history: record(history, [...shapes, shape]), selected: shapes.length } : { history, selected: null };
	}
	if (!shape) {
		return { history: record(history, deleteShape(shapes, index)), selected: null };
	}
	const edited = shape.text === shapes[index].text ? history : record(history, replaceShape(shapes, index, shape));
	return { history: edited, selected: index };
}
