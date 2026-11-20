/* JPEG export shared by both screenshot paths (spec 5.2): decreasing quality until the file fits in 5 MB. */

const MAX_BYTES = 5242880;
const QUALITIES = [0.85, 0.7, 0.5, 0.35];

export class CaptureTooLargeError extends Error {
	constructor() {
		super('Screenshot over 5 MB at the lowest quality.');
		this.name = 'CaptureTooLargeError';
	}
}

function canvasToBlob(canvas, quality) {
	return new Promise((resolve, reject) => {
		canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Canvas export failed.'))), 'image/jpeg', quality);
	});
}

/** @returns {Promise<Blob>} JPEG of the canvas, <= 5 MB. */
export async function encodeJpeg(canvas) {
	for (const quality of QUALITIES) {
		const blob = await canvasToBlob(canvas, quality);
		if (blob.size <= MAX_BYTES) {
			return blob;
		}
	}
	throw new CaptureTooLargeError();
}
