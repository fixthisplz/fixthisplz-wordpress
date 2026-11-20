/* The optional screenshot of the report dialog (spec 5.2): checkbox, preview, Retake and Remove. Only the latest
 * capture run counts; an abandoned one (box unchecked, Retake, dialog closed) is stopped at once. */
import { CaptureTooLargeError } from './jpeg.js';
// Static import (small): getDisplayMedia must be reached synchronously from the visitor's click.
import { takeScreenshot, CaptureDeniedError } from './tab-capture.js';

function captureMessage(error, t) {
	if (error instanceof CaptureDeniedError) {
		return t('errorCaptureDenied');
	}
	return error instanceof CaptureTooLargeError ? t('errorCaptureTooLarge') : t('errorCapture');
}

/**
 * @param {{view: object, t: (key: string) => string, capture: object, isClosed: () => boolean, onChange: () => void}} options
 *   view: the dialog elements; capture: the takeScreenshot options (without signal); onChange: the shot changed.
 * @returns {{blob: Blob|null, info: {method: string, masked: boolean}|null, capturing: boolean, drop: () => void}}
 */
export function createShotController({ view, t, capture, isClosed, onChange }) {
	const { panel, shotBox, shotStatus, shotImage, shotPreview, retakeButton, removeButton } = view;
	let shot = null;
	let shotInfo = null; // { method: 'tab'|'dom', masked } of the current shot, sent with the report
	let shotUrl = '';
	let captureRun = 0;
	let captureAbort = null; // stops the capture stream as soon as its run is stale
	let capturing = false;

	function dropShot() {
		captureRun += 1; // any capture still running is now stale
		captureAbort?.abort();
		captureAbort = null;
		capturing = false;
		shot = null;
		shotInfo = null;
		if (shotUrl) {
			URL.revokeObjectURL(shotUrl);
			shotUrl = '';
		}
		shotImage.removeAttribute('src');
		shotPreview.hidden = true;
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
			const { blob, method, masked } = await takeScreenshot({ ...capture, signal: captureAbort.signal });
			if (isClosed() || run !== captureRun) {
				return;
			}
			shot = blob;
			shotInfo = { method, masked };
			shotUrl = URL.createObjectURL(blob);
			shotImage.src = shotUrl;
			shotPreview.hidden = false;
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
