/* The optional screenshot of the report dialog (spec 5.2): checkbox, preview, Annotate, Retake and Remove. Only the
 * latest capture run counts; an abandoned one (box unchecked, Retake, dialog closed) is stopped at once.
 * Owns the shot state: the original capture, the shapes drawn on it, and the image previewed, sent and downloaded
 * (the original, or the original with the shapes burnt in). */
import { CaptureTooLargeError } from './jpeg.js';
// Static import (small): getDisplayMedia must be reached synchronously from the visitor's click.
import { takeScreenshot, CaptureDeniedError } from './tab-capture.js';

function captureMessage(error, t) {
	if (error instanceof CaptureDeniedError) {
		return t('errorCaptureDenied');
	}
	return error instanceof CaptureTooLargeError ? t('errorCaptureTooLarge') : t('errorCapture');
}

// The editor is not needed until the first click on Annotate: kept out of the dialog's initial load.
const loadEditor = () => import('./annotate-editor.js');

/**
 * @param {{view: object, t: (key: string) => string, capture: object, isClosed: () => boolean, onChange: () => void,
 *   take?: Function, editor?: () => Promise<{editScreenshot: Function}>}} options
 *   view: the dialog elements; capture: the takeScreenshot options (without signal); onChange: the shot changed;
 *   take and editor: the capture and the editor module (replaced in the tests).
 * @returns {{blob: Blob|null, info: {method: string, masked: boolean}|null, capturing: boolean, drop: () => void}}
 */
export function createShotController({ view, t, capture, isClosed, onChange, take = takeScreenshot, editor = loadEditor }) {
	const { root, panel, shotBox, shotStatus, shotImage, shotPreview, annotateButton, retakeButton, removeButton } = view;
	let original = null; // the capture as taken, never modified
	let shapes = []; // annotations on original, in its native pixels
	let shot = null; // what is previewed, sent and downloaded
	let shotInfo = null; // { method: 'tab'|'dom', masked } of the current shot, sent with the report
	let shotUrl = '';
	let captureRun = 0;
	let captureAbort = null; // stops the capture stream as soon as its run is stale
	let capturing = false;

	function show(blob) {
		shot = blob;
		if (shotUrl) {
			URL.revokeObjectURL(shotUrl);
			shotUrl = '';
		}
		shotImage.removeAttribute('src');
		if (blob) {
			shotUrl = URL.createObjectURL(blob);
			shotImage.src = shotUrl;
		}
		shotPreview.hidden = !blob;
	}

	function dropShot() {
		captureRun += 1; // any capture still running is now stale
		captureAbort?.abort();
		captureAbort = null;
		capturing = false;
		original = null;
		shapes = [];
		shotInfo = null;
		show(null);
		onChange();
	}

	// No await before takeScreenshot(): the browser's capture prompt needs the visitor's gesture.
	async function takeShot() {
		dropShot();
		const run = captureRun;
		captureAbort = new AbortController();
		capturing = true;
		shotStatus.textContent = t('capturing');
		try {
			const { blob, method, masked } = await take({ ...capture, signal: captureAbort.signal });
			if (isClosed() || run !== captureRun) {
				return;
			}
			original = blob;
			shotInfo = { method, masked };
			show(blob);
			shotStatus.textContent = '';
			onChange();
		} catch (error) {
			if (isClosed() || run !== captureRun) {
				return;
			}
			shotBox.checked = false;
			shotStatus.textContent = captureMessage(error, t);
		} finally {
			if (run === captureRun) {
				capturing = false;
			}
			if (!isClosed() && !panel.contains(document.activeElement)) {
				shotBox.focus(); // the UI was hidden during the grab, or Retake disappeared with the old preview
			}
		}
	}

	// The dialog is hidden while the editor is open: nothing else can change the shot or close the dialog meanwhile.
	async function annotate() {
		shotStatus.textContent = '';
		root.hidden = true;
		try {
			const { editScreenshot } = await editor();
			const result = await editScreenshot({ t, original, shapes });
			if (result) {
				shapes = result.shapes;
				show(result.blob);
				onChange();
			}
		} catch (error) {
			// The previous image and shapes are kept.
			shotStatus.textContent = error instanceof CaptureTooLargeError ? t('errorCaptureTooLarge') : t('errorAnnotate');
		} finally {
			root.hidden = false;
			annotateButton.focus();
		}
	}

	annotateButton.addEventListener('click', annotate);
	shotBox.addEventListener('change', () => (shotBox.checked ? takeShot() : (dropShot(), (shotStatus.textContent = ''))));
	retakeButton.addEventListener('click', takeShot);
	removeButton.addEventListener('click', () => {
		dropShot();
		shotBox.checked = false;
		shotStatus.textContent = '';
		shotBox.focus();
	});

	return {
		get blob() {
			return shot;
		},
		get info() {
			return shotInfo;
		},
		get capturing() {
			return capturing;
		},
		drop: dropShot,
	};
}
