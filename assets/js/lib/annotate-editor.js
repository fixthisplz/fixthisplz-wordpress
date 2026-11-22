/* Screenshot annotation editor (spec 5.2, annotation), imported on the first click of Annotate. Full-screen plugin
 * UI over the page: the original screenshot at its native size on a canvas scaled to fit, shapes drawn, selected,
 * moved and resized with any pointer (mouse, touch, pen). Drawing is pointer-only; every button works from the
 * keyboard, Delete or Backspace removes the selected shape and Escape cancels. */
import { make, UI_ATTR } from './dom.js';
import { trapFocus } from './focus-trap.js';
import { encodeJpeg } from './jpeg.js';
import { drawSelection, drawShapes, fitSize, isTiny, toNative } from './annotate-draw.js';
import { deleteShape, handleAt, hitTest, moveHandle, moveShape, record, replaceShape, startHistory, undo } from './annotate-shapes.js';

const TOOLS = [
	['rect', 'toolRectangle'],
	['arrow', 'toolArrow'],
	['mask', 'toolHide'],
	['select', 'toolSelect'],
];
// CSS pixels, converted to native pixels at the current display size.
const MIN_SHAPE = 8; // anything smaller is an accidental tap
const TOUCH_SLOP = 12; // how far from a shape or a handle a finger may land and still grab it

/**
 * Opens the editor on the original screenshot with the shapes already applied (they can be undone, never burnt twice).
 * @param {{t: (key: string) => string, original: Blob, shapes: object[]}} options
 * @returns {Promise<{shapes: object[], blob: Blob}|null>} The new shapes and the image to send (the original itself
 *   when no shape is left), or null when cancelled. Rejects with CaptureTooLargeError when the result exceeds 5 MB.
 */
export async function editScreenshot({ t, original, shapes }) {
	const bitmap = await createImageBitmap(original);
	const { width, height } = bitmap;
	let history = startHistory([...shapes]);
	let selected = null; // index of the selected shape in history.shapes
	// The gesture in progress: only the pointer that started it may move or finish it. update(point) gives the list
	// shown while dragging; shapes stays null until the pointer moves, so a plain click changes nothing.
	let gesture = null;
	let tool = 'rect';
	let applying = false;

	const canvas = make('canvas', { class: 'bugspot-annot-canvas', width, height, role: 'img', 'aria-label': t('screenshotPreview') });
	const ctx = canvas.getContext('2d');
	const button = (key, extra = '') => make('button', { type: 'button', class: `bugspot-btn ${extra}`.trim(), text: t(key) });
	const toolButtons = TOOLS.map(([name, key]) => {
		const node = button(key);
		node.addEventListener('click', () => selectTool(name));
		return [name, node];
	});
	const selectToolButton = toolButtons.find(([name]) => name === 'select')[1];
	const deleteButton = button('deleteShape');
	const undoButton = button('undoShape');
	const clearButton = button('clearShapes');
	const cancelButton = button('cancel');
	const applyButton = button('applyAnnotations', 'bugspot-btn-primary');
	const fitBox = make('div', { class: 'bugspot-annot-fit' }, [canvas]);
	const panel = make('div', { class: 'bugspot-annot', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('annotate') }, [
		make('div', { class: 'bugspot-annot-bar' }, [
			make('div', { class: 'bugspot-row' }, toolButtons.map(([, node]) => node)),
			make('div', { class: 'bugspot-row' }, [deleteButton, undoButton, clearButton]),
		]),
		make('div', { class: 'bugspot-annot-stage' }, [fitBox]),
		make('div', { class: 'bugspot-annot-bar bugspot-annot-actions' }, [cancelButton, applyButton]),
	]);
	const root = make('div', { id: 'bugspot-annotator', class: 'bugspot-ui', [UI_ATTR]: true }, [panel]);

	const scale = () => width / canvas.getBoundingClientRect().width; // native pixels per CSS pixel
	const slop = () => TOUCH_SLOP * scale();

	function redraw() {
		const shown = gesture?.shapes ?? history.shapes;
		ctx.drawImage(bitmap, 0, 0);
		drawShapes(ctx, shown, width, height);
		if (selected !== null && !applying) {
			drawSelection(ctx, shown[selected], scale());
		}
		const focused = document.activeElement;
		deleteButton.disabled = selected === null;
		undoButton.disabled = history.past.length === 0;
		clearButton.disabled = history.shapes.length === 0;
		if (focused?.disabled && panel.contains(focused)) {
			selectToolButton.focus(); // a button that just got disabled would drop the focus out of the editor
		}
	}

	/** Ends any gesture and makes shapes the new state (one undo step), with the shape at index selected (or none). */
	function commit(shapes, index = null) {
		history = record(history, shapes);
		gesture = null;
		selected = index;
		redraw();
	}

	/** A drawing tool drops the selection: only the Select tool shows and edits it. */
	function selectTool(name) {
		tool = name;
		canvas.dataset.tool = name;
		canvas.style.cursor = '';
		toolButtons.forEach(([n, node]) => node.setAttribute('aria-pressed', String(n === name)));
		if (name !== 'select') {
			selected = null;
		}
		redraw();
	}

	function fit() {
		const size = fitSize(width, height, fitBox.clientWidth, fitBox.clientHeight);
		canvas.style.width = `${size.width}px`;
		canvas.style.height = `${size.height}px`;
		redraw(); // the selection overlay is sized in CSS pixels
	}

	const point = (event) => toNative(event.clientX, event.clientY, canvas.getBoundingClientRect(), width, height);

	/** What a press at p does with the Select tool: resize by a handle of the selected shape, else move the shape under it. */
	function grab(p) {
		const list = history.shapes;
		const handle = selected === null ? null : handleAt(list[selected], p.x, p.y, slop());
		const index = handle ? selected : hitTest(list, p.x, p.y, slop());
		selected = index === -1 ? null : index;
		if (selected === null) {
			return null;
		}
		const shape = list[index];
		return handle
			? (q) => replaceShape(list, index, moveHandle(shape, handle, q.x, q.y))
			: (q) => replaceShape(list, index, moveShape(shape, q.x - p.x, q.y - p.y, width, height));
	}

	canvas.addEventListener('pointerdown', (event) => {
		if (!event.isPrimary || event.button !== 0) {
			return;
		}
		event.preventDefault();
		if (applying || gesture) {
			return; // nothing during the encode, and one gesture at a time
		}
		const p = point(event);
		const list = history.shapes;
		const update = tool === 'select' ? grab(p) : (q) => [...list, { type: tool, x1: p.x, y1: p.y, x2: q.x, y2: q.y }];
		redraw(); // the selection may have changed
		if (update) {
			canvas.setPointerCapture(event.pointerId);
			gesture = { pointerId: event.pointerId, update, shapes: null };
		}
	});
	const ownsGesture = (event) => gesture !== null && event.pointerId === gesture.pointerId;

	canvas.addEventListener('pointermove', (event) => {
		if (ownsGesture(event)) {
			gesture.shapes = gesture.update(point(event));
			redraw();
		} else if (!gesture && tool === 'select') {
			const p = point(event); // hover feedback: what a press here would grab
			const onHandle = selected !== null && handleAt(history.shapes[selected], p.x, p.y, slop()) !== null;
			canvas.style.cursor = onHandle || hitTest(history.shapes, p.x, p.y, slop()) !== -1 ? 'move' : '';
		}
	});
	canvas.addEventListener('pointerup', (event) => {
		if (!ownsGesture(event)) {
			return;
		}
		const result = gesture.shapes;
		if (!result) {
			gesture = null;
			return;
		}
		if (tool === 'select') {
			commit(result, selected);
		} else if (isTiny(result.at(-1), MIN_SHAPE * scale())) {
			gesture = null;
			redraw();
		} else {
			// The new shape is selected at once so it can be adjusted.
			selectTool('select');
			commit(result, result.length - 1);
		}
	});
	canvas.addEventListener('pointercancel', (event) => {
		if (ownsGesture(event)) {
			gesture = null;
			redraw();
		}
	});
	const deleteSelected = () => commit(deleteShape(history.shapes, selected));
	deleteButton.addEventListener('click', deleteSelected);
	root.addEventListener('keydown', (event) => {
		if ((event.key === 'Delete' || event.key === 'Backspace') && selected !== null && !applying) {
			event.preventDefault();
			deleteSelected();
		}
	});
	undoButton.addEventListener('click', () => {
		history = undo(history);
		gesture = null;
		selected = null;
		redraw();
	});
	clearButton.addEventListener('click', () => commit([]));

	return new Promise((resolve, reject) => {
		const resizes = new ResizeObserver(fit);
		// Once Apply is encoding, nothing can cancel: the visitor must not believe the result was discarded.
		const cancel = () => applying || close(resolve, null);
		const release = trapFocus({ root, panel, isActive: () => root.isConnected, onEscape: cancel });

		function close(settle, value) {
			release();
			resizes.disconnect();
			bitmap.close();
			canvas.width = 0; // releases the native-size pixel buffer at once
			canvas.height = 0;
			root.remove();
			settle(value);
		}

		cancelButton.addEventListener('click', cancel);
		applyButton.addEventListener('click', async () => {
			applying = true;
			gesture = null;
			redraw(); // the canvas now holds exactly the original and the kept shapes
			panel.querySelectorAll('button').forEach((node) => (node.disabled = true));
			panel.setAttribute('aria-busy', 'true');
			try {
				const blob = history.shapes.length > 0 ? await encodeJpeg(canvas) : original;
				close(resolve, { shapes: history.shapes, blob });
			} catch (error) {
				close(reject, error);
			}
		});

		selectTool('rect');
		document.body.appendChild(root);
		resizes.observe(fitBox);
		toolButtons[0][1].focus();
	});
}
