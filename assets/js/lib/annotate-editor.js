/* Screenshot annotation editor (spec 5.2, annotation), imported on the first click of Annotate. Full-screen plugin
 * UI over the page: the original screenshot at its native size on a canvas scaled to fit, shapes drawn with any
 * pointer (mouse, touch, pen). Drawing is pointer-only; every button works from the keyboard and Escape cancels. */
import { make, UI_ATTR } from './dom.js';
import { trapFocus } from './focus-trap.js';
import { encodeJpeg } from './jpeg.js';
import { drawShapes, fitSize, isTiny, toNative } from './annotate-draw.js';

const TOOLS = [
	['rect', 'toolRectangle'],
	['arrow', 'toolArrow'],
	['mask', 'toolHide'],
];
const MIN_SHAPE = 8; // CSS pixels: anything smaller is an accidental tap

/**
 * Opens the editor on the original screenshot with the shapes already applied (they can be undone, never burnt twice).
 * @param {{t: (key: string) => string, original: Blob, shapes: object[]}} options
 * @returns {Promise<{shapes: object[], blob: Blob}|null>} The new shapes and the image to send (the original itself
 *   when no shape is left), or null when cancelled. Rejects with CaptureTooLargeError when the result exceeds 5 MB.
 */
export async function editScreenshot({ t, original, shapes }) {
	const bitmap = await createImageBitmap(original);
	const { width, height } = bitmap;
	let list = [...shapes];
	let draft = null;
	let draftPointer = null; // only the pointer that started the shape may move or finish it
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
	const undoButton = button('undoShape');
	const clearButton = button('clearShapes');
	const cancelButton = button('cancel');
	const applyButton = button('applyAnnotations', 'bugspot-btn-primary');
	const fitBox = make('div', { class: 'bugspot-annot-fit' }, [canvas]);
	const panel = make('div', { class: 'bugspot-annot', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('annotate') }, [
		make('div', { class: 'bugspot-annot-bar' }, [
			make('div', { class: 'bugspot-row' }, toolButtons.map(([, node]) => node)),
			make('div', { class: 'bugspot-row' }, [undoButton, clearButton]),
		]),
		make('div', { class: 'bugspot-annot-stage' }, [fitBox]),
		make('div', { class: 'bugspot-annot-bar bugspot-annot-actions' }, [cancelButton, applyButton]),
	]);
	const root = make('div', { id: 'bugspot-annotator', class: 'bugspot-ui', [UI_ATTR]: true }, [panel]);

	function selectTool(name) {
		tool = name;
		toolButtons.forEach(([n, node]) => node.setAttribute('aria-pressed', String(n === name)));
	}

	function redraw() {
		ctx.drawImage(bitmap, 0, 0);
		drawShapes(ctx, draft ? [...list, draft] : list, width, height);
		undoButton.disabled = list.length === 0;
		clearButton.disabled = list.length === 0;
	}

	function fit() {
		const size = fitSize(width, height, fitBox.clientWidth, fitBox.clientHeight);
		canvas.style.width = `${size.width}px`;
		canvas.style.height = `${size.height}px`;
	}

	const point = (event) => toNative(event.clientX, event.clientY, canvas.getBoundingClientRect(), width, height);

	canvas.addEventListener('pointerdown', (event) => {
		if (!event.isPrimary || event.button !== 0) {
			return;
		}
		event.preventDefault();
		if (applying || draft) {
			return; // no drawing during the encode, and one shape at a time
		}
		canvas.setPointerCapture(event.pointerId);
		draftPointer = event.pointerId;
		const { x, y } = point(event);
		draft = { type: tool, x1: x, y1: y, x2: x, y2: y };
	});
	const ownsDraft = (event) => draft !== null && event.pointerId === draftPointer;

	canvas.addEventListener('pointermove', (event) => {
		if (ownsDraft(event)) {
			const { x, y } = point(event);
			draft = { ...draft, x2: x, y2: y };
			redraw();
		}
	});
	canvas.addEventListener('pointerup', (event) => {
		if (!ownsDraft(event)) {
			return;
		}
		const scale = width / canvas.getBoundingClientRect().width; // native pixels per CSS pixel
		if (!isTiny(draft, MIN_SHAPE * scale)) {
			list = [...list, draft];
		}
		draft = null;
		redraw();
	});
	canvas.addEventListener('pointercancel', (event) => {
		if (ownsDraft(event)) {
			draft = null;
			redraw();
		}
	});
	undoButton.addEventListener('click', () => {
		list = list.slice(0, -1);
		redraw();
	});
	clearButton.addEventListener('click', () => {
		list = [];
		redraw();
	});

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
			draft = null;
			redraw(); // the canvas now holds exactly the original and the kept shapes
			panel.querySelectorAll('button').forEach((node) => (node.disabled = true));
			panel.setAttribute('aria-busy', 'true');
			try {
				const blob = list.length > 0 ? await encodeJpeg(canvas) : original;
				close(resolve, { shapes: list, blob });
			} catch (error) {
				close(reject, error);
			}
		});

		selectTool('rect');
		redraw();
		document.body.appendChild(root);
		resizes.observe(fitBox);
		toolButtons[0][1].focus();
	});
}
