/* Elements of the screenshot annotation editor (spec 5.2, annotation), without behaviour: toolbar (tools, colour
 * palette, sizes, edit buttons), the canvas fitted in the stage, Cancel and Apply. annotate-editor.js wires them. */
import { make, UI_ATTR } from './dom.js';
import { ANNOTATION_COLOR, SIZES } from './annotate-common.js';

const TOOLS = [
	['rect', 'toolRectangle'],
	['arrow', 'toolArrow'],
	['pen', 'toolPen'],
	['text', 'toolText'],
	['mask', 'toolHide'],
	['select', 'toolSelect'],
];
// Saturated enough to read on screenshots, even as a thin outline (hence a dark yellow).
const PALETTE = [
	[ANNOTATION_COLOR, 'colorRed'],
	['#f26c0d', 'colorOrange'],
	['#f0b400', 'colorYellow'],
	['#1e9e3a', 'colorGreen'],
	['#1d6fe0', 'colorBlue'],
	['#1d2327', 'colorBlack'],
];
const SIZE_LABELS = { small: 'sizeSmall', medium: 'sizeMedium', large: 'sizeLarge' };
const DOT = 10; // CSS px, diameter of the medium size's dot; the others follow the preset factors

/** Marks the button of value as the pressed one of buttons (value => button). */
export function pressOnly(buttons, value) {
	buttons.forEach((node, v) => node.setAttribute('aria-pressed', String(v === value)));
}

/**
 * Builds the editor for a width x height screenshot (native canvas size).
 * @returns {object} root, panel, canvas, fitBox, the buttons, toolButtons (tool name => button), colorButtons
 *   (colour => button) and sizeButtons (SIZES key => button).
 */
export function buildEditorView(t, width, height) {
	const button = (key, extra = '') => make('button', { type: 'button', class: `bugspot-btn ${extra}`.trim(), text: t(key) });
	const toolButtons = new Map(TOOLS.map(([name, key]) => [name, button(key)]));
	const colorButtons = new Map(
		PALETTE.map(([value, key]) => {
			const swatch = make('span', { class: 'bugspot-annot-swatch' });
			swatch.style.background = value;
			return [value, make('button', { type: 'button', class: 'bugspot-btn bugspot-annot-choice', 'aria-label': t(key), title: t(key) }, [swatch])];
		})
	);
	const sizeButtons = new Map(
		Object.entries(SIZE_LABELS).map(([size, key]) => {
			const dot = make('span', { class: 'bugspot-annot-dot' });
			dot.style.width = dot.style.height = `${Math.round(DOT * SIZES[size])}px`;
			return [size, make('button', { type: 'button', class: 'bugspot-btn bugspot-annot-choice', 'aria-label': t(key), title: t(key) }, [dot])];
		})
	);
	const view = {
		canvas: make('canvas', { class: 'bugspot-annot-canvas', width, height, role: 'img', 'aria-label': t('screenshotPreview') }),
		toolButtons,
		colorButtons,
		sizeButtons,
		deleteButton: button('deleteShape'),
		undoButton: button('undoShape'),
		clearButton: button('clearShapes'),
		cancelButton: button('cancel'),
		applyButton: button('applyAnnotations', 'bugspot-btn-primary'),
	};
	view.fitBox = make('div', { class: 'bugspot-annot-fit' }, [view.canvas]);
	view.panel = make('div', { class: 'bugspot-annot', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('annotate') }, [
		make('div', { class: 'bugspot-annot-bar' }, [
			make('div', { class: 'bugspot-row' }, [...toolButtons.values()]),
			make('div', { class: 'bugspot-row' }, [
				make('div', { class: 'bugspot-row', role: 'group', 'aria-label': t('annotationColor') }, [...colorButtons.values()]),
				make('div', { class: 'bugspot-row', role: 'group', 'aria-label': t('annotationSize') }, [...sizeButtons.values()]),
			]),
			make('div', { class: 'bugspot-row' }, [view.deleteButton, view.undoButton, view.clearButton]),
		]),
		make('div', { class: 'bugspot-annot-stage' }, [view.fitBox]),
		make('div', { class: 'bugspot-annot-bar bugspot-annot-actions' }, [view.cancelButton, view.applyButton]),
	]);
	view.root = make('div', { id: 'bugspot-annotator', class: 'bugspot-ui', [UI_ATTR]: true }, [view.panel]);
	return view;
}
