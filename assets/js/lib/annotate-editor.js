/* Screenshot annotation editor (spec 5.2, annotation), imported on the first click of Annotate. Full-screen plugin
 * UI over the page: the original screenshot at its native size on a canvas scaled to fit, shapes drawn, typed,
 * coloured, sized, selected, moved and resized with any pointer (mouse, touch, pen). Drawing is pointer-only; every
 * button works from the keyboard, Delete or Backspace removes the selected shape and Escape cancels. */
import { trapFocus } from './focus-trap.js';
import { encodeJpeg } from './jpeg.js';
import { FIELD_TAGS, isEditable } from './sensitive.js';
import { ANNOTATION_COLOR, DEFAULT_SIZE, colorOf } from './annotate-common.js';
import { drawSelection, drawShapes, fitSize, toNative } from './annotate-draw.js';
import { deleteShape, handleAt, hitTest, moveShape, recolor, record, setSize, startHistory, undo } from './annotate-shapes.js';
import { beginDraw, beginSelect, commitText, endGesture, gestureShapes, moveGesture } from './annotate-gesture.js';
import { fontSizeFor, makeText } from './annotate-text.js';
import { openTextField } from './annotate-text-field.js';
import { buildEditorView, pressOnly } from './annotate-view.js';

// CSS pixels, converted to native pixels at the current display size.
const MIN_SHAPE = 8; // anything smaller is an accidental tap
const TOUCH_SLOP = 12; // how far from a shape or a handle a finger may land and still grab it
const PEN_STEP = 3; // pen points closer than this to the previous one are dropped
const DOUBLE_PRESS_MS = 400; // two presses on a text within this delay edit it (double click or double tap)

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
	// The gesture in progress (annotate-gesture.js): only the pointer that started it may move or finish it.
	let gesture = null;
	let gesturePointer = null;
	let frame = 0; // pending animation frame of a redraw, 0 when none
	let tool = 'rect';
	let color = ANNOTATION_COLOR; // stamped on new shapes
	let size = DEFAULT_SIZE; // stamped on new shapes (a SIZES key)
	let textField = null; // the open text field, if any
	let editing = null; // index of the text being edited in the field, hidden from the canvas meanwhile
	let lastPress = null; // { index, time } of the last click on a shape with the Select tool
	let applying = false;

	const { root, panel, canvas, fitBox, toolButtons, colorButtons, sizeButtons, deleteButton, undoButton, clearButton, cancelButton, applyButton } =
		buildEditorView(t, width, height);
	const ctx = canvas.getContext('2d');
	toolButtons.forEach((node, name) =>
		node.addEventListener('click', () => {
			setTool(name);
			redraw();
		})
	);
	colorButtons.forEach((node, value) => node.addEventListener('click', () => pickColor(value)));
	sizeButtons.forEach((node, value) => node.addEventListener('click', () => pickSize(value)));

	const scale = () => width / canvas.getBoundingClientRect().width; // native pixels per CSS pixel
	const slop = () => TOUCH_SLOP * scale();

	function redraw() {
		cancelAnimationFrame(frame);
		frame = 0;
		const list = gesture ? gestureShapes(gesture) : history.shapes;
		const shown = editing === null ? list : deleteShape(list, editing);
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
			toolButtons.get('select').focus(); // a button that just got disabled would drop the focus out of the editor
		}
	}

	/** Ends any gesture and makes shapes the new state (one undo step), with the shape at index selected (or none). */
	function commit(shapes, index = null) {
		history = record(history, shapes);
		lastPress = null;
		gesture = null;
		selected = index;
		redraw();
	}

	/** Redraws once per frame at most while the pointer moves; any direct redraw() drops the pending one. */
	function scheduleRedraw() {
		frame ||= requestAnimationFrame(redraw);
	}

	/** A drawing tool drops the selection: only the Select tool shows and edits it. The caller redraws. */
	function setTool(name) {
		tool = name;
		canvas.dataset.tool = name;
		canvas.style.cursor = '';
		pressOnly(toolButtons, name);
		if (name !== 'select') {
			selected = null;
		}
	}

	/** The selected shape, if any, changed by change(shapes, index): one undo step, none when it changes nothing. */
	function changeSelected(change) {
		const changed = selected === null ? history.shapes : change(history.shapes, selected);
		if (changed !== history.shapes) {
			commit(changed, selected);
		}
	}

	const measure = (text, font) => {
		ctx.font = font;
		return ctx.measureText(text).width;
	};

	/** New shapes get the colour and size; so does the selected shape (a hidden area has neither). */
	function pickColor(value) {
		color = value;
		pressOnly(colorButtons, value);
		changeSelected((shapes, index) => recolor(shapes, index, value));
	}
	function pickSize(value) {
		size = value;
		pressOnly(sizeButtons, value);
		changeSelected((shapes, index) => setSize(shapes, index, value, { width, height, measure }));
	}

	/** Opens the text field on a new text at p (index null) or on the text at index. */
	function openText(index, p) {
		const shape = index === null ? null : history.shapes[index];
		const fields = shape
			? { x: shape.x, y: shape.y, fontSize: shape.fontSize, color: colorOf(shape) }
			: { x: p.x, y: p.y, fontSize: fontSizeFor(width, height, size), color };
		editing = index;
		selected = null;
		lastPress = null;
		redraw();
		const field = { host: fitBox, canvas, imageWidth: width, at: fields, ...fields, text: shape?.text ?? '', label: t('toolText'), measure };
		textField = openTextField(field, (text, reason) => {
			textField = null;
			editing = null;
			selected = index; // dropped with Escape: the text stays as it was
			if (text !== null) {
				// Measured once here, and kept inside the image like a moved shape.
				const typed = text === '' ? null : moveShape(makeText({ ...fields, text }, measure), 0, 0, width, height);
				const ended = commitText(history, index, typed);
				const added = index === null && ended.history !== history;
				({ history, selected } = ended);
				if (added) {
					setTool('select'); // the new text is selected at once, like a shape just drawn
				}
			}
			redraw();
			if (reason !== 'blur') {
				toolButtons.get(tool).focus(); // the focus was on the removed field; after a blur it is where the visitor put it
			}
		});
	}

	function fit() {
		const size = fitSize(width, height, fitBox.clientWidth, fitBox.clientHeight);
		canvas.style.width = `${size.width}px`;
		canvas.style.height = `${size.height}px`;
		textField?.reposition(); // e.g. the mobile keyboard opening, or the phone turned
		redraw(); // the selection overlay is sized in CSS pixels
	}

	const point = (event) => toNative(event.clientX, event.clientY, canvas.getBoundingClientRect(), width, height);

	canvas.addEventListener('pointerdown', (event) => {
		if (!event.isPrimary || event.button !== 0) {
			return;
		}
		event.preventDefault();
		if (applying || gesture) {
			return; // nothing during the encode, and one gesture at a time
		}
		if (textField) {
			textField.keep(); // a press elsewhere ends the typing (the field does not lose the focus to the canvas)
			return;
		}
		const p = point(event);
		if (tool === 'text') {
			openText(null, p);
			return;
		}
		if (tool === 'select') {
			const previous = lastPress;
			({ selected, gesture } = beginSelect(history.shapes, selected, p, slop()));
			const again = previous?.index === selected && event.timeStamp - previous.time < DOUBLE_PRESS_MS;
			if (again && history.shapes[selected].type === 'text') {
				gesture = null;
				openText(selected);
				return;
			}
		} else {
			gesture = beginDraw(history.shapes, tool, p, color, size);
		}
		redraw(); // the selection may have changed
		if (gesture) {
			canvas.setPointerCapture(event.pointerId);
			gesturePointer = event.pointerId;
		}
	});
	const ownsGesture = (event) => gesture !== null && event.pointerId === gesturePointer;
	// The image size and, for the pen, the smallest step kept between two points (native px).
	const area = () => ({ width, height, step: PEN_STEP * scale() });

	canvas.addEventListener('pointermove', (event) => {
		if (ownsGesture(event)) {
			gesture = moveGesture(gesture, point(event), area());
			scheduleRedraw();
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
		const ended = endGesture(history, gesture, MIN_SHAPE * scale());
		const drawn = gesture.drawing && ended.history !== history;
		lastPress = gesture.drawing || gesture.moved ? null : { index: gesture.index, time: event.timeStamp };
		gesture = null;
		({ history, selected } = ended);
		if (drawn) {
			setTool('select'); // the new shape is selected at once so it can be adjusted
		}
		redraw();
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
		const typing = FIELD_TAGS.has(event.target.tagName) || isEditable(event.target);
		if ((event.key === 'Delete' || event.key === 'Backspace') && selected !== null && !applying && !typing) {
			event.preventDefault();
			deleteSelected();
		}
	});
	undoButton.addEventListener('click', () => {
		history = undo(history);
		gesture = null;
		lastPress = null;
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
			cancelAnimationFrame(frame);
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

		setTool('rect');
		pickColor(ANNOTATION_COLOR);
		pickSize(DEFAULT_SIZE);
		redraw();
		document.body.appendChild(root);
		resizes.observe(fitBox);
		toolButtons.get('rect').focus();
	});
}
