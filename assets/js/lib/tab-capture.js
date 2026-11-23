/* Real screen capture on desktop (spec 5.2): the image is the pixels on screen.
 * The visitor chooses what to share in the browser's own prompt (this tab is suggested first where supported) and
 * sees the preview before sending: any surface is accepted (tab, window, screen) and nothing is masked by default.
 * When the site enables masking (config.dialog.maskCapture), fields, [contenteditable] and sensitive zones are
 * covered on the bitmap before any export — best effort, only when a tab was shared. The plugin UI is hidden while
 * the frame is grabbed; a CaptureController keeps this page in front when another tab or window is shared.
 * Routing: the site setting config.dialog.captureMethod 'dom' always means reconstruction; otherwise real capture
 * when getDisplayMedia exists (Chrome, Edge, Firefox, Safari desktop), the device has a
 * mouse or trackpad, and the Permissions-Policy allows display-capture; otherwise the DOM reconstruction of
 * capture.js (that module and the vendored library are loaded lazily). The stream is always stopped: on success, on
 * error, after a 10 s deadline, and as soon as the run is abandoned (box unchecked, Retake, dialog closed). */
import { encodeJpeg } from './jpeg.js';
import { UI_ATTR } from './dom.js';
import { listMaskTargets, measureTarget, scaleRects } from './mask-targets.js';
import { MASK_FILL } from './sensitive.js';
import { withTimeout } from './async.js';

const HIDE_CLASS = 'fixthisplz-capture-hide';
const STEP_TIMEOUT_MS = 5000;
const DEADLINE_MS = 10000; // whole shoot, once the permission is granted
// Hints only: browsers that do not know an option ignore it, and the visitor may still pick anything.
const DISPLAY_OPTIONS = {
	video: { displaySurface: 'browser' },
	audio: false,
	preferCurrentTab: true,
	selfBrowserSurface: 'include',
};

export class CaptureDeniedError extends Error {
	constructor() {
		super('Screen capture refused.');
		this.name = 'CaptureDeniedError';
	}
}

/** The run was abandoned (box unchecked, Retake, dialog closed), replaced by a newer one, or out of time. */
class StaleCaptureError extends Error {
	constructor() {
		super('Screenshot abandoned.');
		this.name = 'StaleCaptureError';
	}
}

function toCaptureError(error) {
	return error && error.name === 'NotAllowedError' ? new CaptureDeniedError() : error;
}

function allowsDisplayCapture(doc) {
	const policy = doc && (doc.permissionsPolicy || doc.featurePolicy);
	return !policy || typeof policy.allowsFeature !== 'function' || policy.allowsFeature('display-capture') !== false;
}

/** 'tab' (real capture) or 'dom' (reconstruction), see Routing above. The site setting 'dom' always wins. */
export function selectMethod(env = globalThis, preferred = 'tab') {
	if (preferred === 'dom') {
		return 'dom';
	}
	const devices = env.navigator && env.navigator.mediaDevices;
	if (!devices || typeof devices.getDisplayMedia !== 'function' || !allowsDisplayCapture(env.document)) {
		return 'dom';
	}
	const query = (q) => Boolean(typeof env.matchMedia === 'function' && env.matchMedia(q).matches);
	return query('(pointer: coarse)') && !query('(any-pointer: fine)') ? 'dom' : 'tab';
}

/** Masks only make sense on this page: masking on, and the shared surface is reported as a tab. */
export function shouldMask(mask, track) {
	const settings = track && typeof track.getSettings === 'function' ? track.getSettings() : {};
	return mask === true && settings.displaySurface === 'browser';
}

/**
 * Runs grab(videoTrack, assertLive, onStop) and always stops every track: on success, on failure, and as soon as
 * the signal aborts (the grab is then abandoned). Cleanups registered with onStop(fn) run at that same moment.
 */
export async function withStream(stream, grab, signal = null) {
	const cleanups = [];
	const onStop = (fn) => cleanups.push(fn);
	const stopAll = () => {
		stream.getTracks().forEach((track) => track.stop());
		cleanups.splice(0).forEach((fn) => fn());
	};
	const assertLive = () => {
		if (signal && signal.aborted) {
			throw new StaleCaptureError();
		}
	};
	let onAbort = null;
	const aborted = new Promise((_, reject) => {
		onAbort = () => {
			stopAll();
			reject(new StaleCaptureError());
		};
	});
	aborted.catch(() => {});
	try {
		assertLive();
		signal?.addEventListener('abort', onAbort);
		return await Promise.race([Promise.resolve().then(() => grab(stream.getVideoTracks()[0], assertLive, onStop)), aborted]);
	} finally {
		signal?.removeEventListener('abort', onAbort);
		stopAll();
	}
}

const timedOut = () => new Error('Screenshot timed out.');

const isHidden = () => document.visibilityState === 'hidden';

/** count animation frames; resolves at once if the page is or becomes hidden (no frames are painted then). */
const nextFrames = (count) =>
	new Promise((resolve) => {
		const done = () => {
			document.removeEventListener('visibilitychange', onVisibility);
			resolve();
		};
		const onVisibility = () => isHidden() && done();
		if (isHidden()) {
			done();
			return;
		}
		document.addEventListener('visibilitychange', onVisibility);
		const step = (n) => (n <= 0 ? done() : requestAnimationFrame(() => step(n - 1)));
		step(count);
	});

const FRESH_TRIES = 10;

/**
 * A frame CAPTURED after notBefore (performance.now() clock), as a drawable { source, width, height, release() }.
 * The capture pipeline can lag several frames behind the page under load: without this check the plugin UI,
 * hidden a moment ago, could still be in the image. Each ImageCapture.grabFrame() (Chromium) forces a fresh
 * frame, so static pages deliver one too. Without requestVideoFrameCallback/captureTime (older browsers) the
 * next delivered frame is used: best effort.
 */
async function grabFreshFrame(track, notBefore, assertLive, until) {
	if (isHidden() && typeof ImageCapture === 'function') {
		// Another tab or window is in front: our UI is not in its pixels, and video callbacks are paused here.
		const grabbed = new ImageCapture(track).grabFrame();
		let bitmap;
		try {
			bitmap = await withTimeout(until(grabbed), STEP_TIMEOUT_MS, timedOut);
		} catch (error) {
			// Abandoned or timed out: the frame may still arrive later, and nobody would close it.
			grabbed.then((late) => late.close(), () => {});
			throw error;
		}
		return { source: bitmap, width: bitmap.width, height: bitmap.height, release: () => bitmap.close() };
	}
	const video = document.createElement('video');
	video.muted = true;
	video.playsInline = true;
	video.srcObject = new MediaStream([track]);
	const release = () => {
		video.pause();
		video.srcObject = null;
	};
	const refresher = typeof ImageCapture === 'function' ? new ImageCapture(track) : null;
	const nextFrame = () =>
		withTimeout(
			until(new Promise((resolve) => {
				video.requestVideoFrameCallback((now, meta) => resolve(meta));
				refresher?.grabFrame().then((bitmap) => bitmap.close(), () => {});
			})),
			STEP_TIMEOUT_MS,
			timedOut
		);
	try {
		await withTimeout(until(video.play()), STEP_TIMEOUT_MS, timedOut);
		if (typeof video.requestVideoFrameCallback === 'function') {
			for (let tries = 1; ; tries += 1) {
				const meta = await nextFrame();
				assertLive();
				const capturedAt = meta.captureTime ?? meta.presentationTime;
				if (capturedAt === undefined || capturedAt >= notBefore) break;
				if (tries >= FRESH_TRIES) throw new Error('No fresh frame.');
			}
		}
		if (!video.videoWidth) {
			throw new Error('No video frame.');
		}
	} catch (error) {
		release();
		throw error;
	}
	return { source: video, width: video.videoWidth, height: video.videoHeight, release };
}

/** Grabs a frame with the plugin UI hidden; the mask rectangles (if any) are measured while it is hidden. */
async function grabFrameWithoutUi(track, { mask, sensitiveSelectors }, assertLive, onStop) {
	assertLive();
	const hidden = Array.from(document.querySelectorAll(`[${UI_ATTR}]`));
	const restore = () => hidden.forEach((node) => node.classList.remove(HIDE_CLASS));
	hidden.forEach((node) => node.classList.add(HIDE_CLASS));
	// Abandoned run or deadline: the UI comes back at once, and every pending wait below ends at once.
	let abandon = () => {};
	const abandoned = new Promise((_, reject) => {
		abandon = () => reject(new StaleCaptureError());
	});
	abandoned.catch(() => {});
	onStop(() => {
		restore();
		abandon();
	});
	const until = (promise) => Promise.race([promise, abandoned]);
	try {
		await until(nextFrames(3)); // the hidden state is rendered by now
		assertLive();
		const notBefore = performance.now();
		const viewport = { width: window.innerWidth, height: window.innerHeight };
		const rects = mask ? listMaskTargets(document.documentElement, sensitiveSelectors).flatMap((t) => measureTarget(t)) : [];
		const frame = await grabFreshFrame(track, notBefore, assertLive, until);
		return { frame, boxes: scaleRects(rects, frame, viewport) };
	} finally {
		restore();
	}
}

async function shoot(track, options, assertLive, onStop) {
	const masked = shouldMask(options.mask, track);
	const { frame, boxes } = await grabFrameWithoutUi(track, { ...options, mask: masked }, assertLive, onStop);
	const canvas = document.createElement('canvas');
	canvas.width = frame.width;
	canvas.height = frame.height;
	try {
		const ctx = canvas.getContext('2d');
		ctx.drawImage(frame.source, 0, 0, frame.width, frame.height);
		ctx.fillStyle = MASK_FILL;
		boxes.forEach((b) => ctx.fillRect(b.x, b.y, b.width, b.height)); // before any export
	} finally {
		frame.release();
	}
	return { blob: await encodeJpeg(canvas), method: 'tab', masked };
}

// One browser prompt at a time: a run started while it is open (uncheck/recheck) takes it over.
let prompt = null;

/**
 * Must be called synchronously from the visitor's gesture (no await before): getDisplayMedia needs it.
 * @param {{mask?: boolean, sensitiveSelectors?: string[], signal?: AbortSignal}} options
 * @returns {Promise<{blob: Blob, method: 'tab', masked: boolean}>} JPEG of the shared surface, <= 5 MB.
 */
export function captureTab({ mask = false, sensitiveSelectors = [], signal = null } = {}) {
	const run = {};
	if (prompt) {
		prompt.owner = run;
	} else {
		// Sharing another tab or window must not bring it to the front: our page would stop painting.
		const controller = typeof CaptureController === 'function' ? new CaptureController() : null;
		let request;
		try {
			request = Promise.resolve(navigator.mediaDevices.getDisplayMedia(controller ? { ...DISPLAY_OPTIONS, controller } : DISPLAY_OPTIONS));
		} catch (error) {
			return Promise.reject(toCaptureError(error));
		}
		const opened = { request, owner: run };
		prompt = opened;
		request
			.then(
				() => {
					try {
						controller?.setFocusBehavior('no-focus-change'); // must run as soon as the promise resolves
					} catch {
						// a whole screen: nothing to keep in front
					}
				},
				() => {}
			)
			.finally(() => {
				if (prompt === opened) prompt = null;
			});
	}
	const current = prompt;
	return current.request.then(
		(stream) => {
			if (current.owner !== run) {
				throw new StaleCaptureError(); // the newer run uses this stream and stops it
			}
			const deadline = Date.now() + DEADLINE_MS;
			return withStream(
				stream,
				(track, assertLive, onStop) => {
					// A late step of a timed-out shoot (e.g. a resumed animation frame) must not hide the UI again.
					const live = () => {
						assertLive();
						if (Date.now() > deadline) throw new StaleCaptureError();
					};
					return withTimeout(shoot(track, { mask, sensitiveSelectors }, live, onStop), DEADLINE_MS, timedOut);
				},
				signal
			);
		},
		(error) => {
			// A refusal that reaches an abandoned or replaced run is not its business any more.
			throw current.owner !== run || signal?.aborted ? new StaleCaptureError() : toCaptureError(error);
		}
	);
}

/**
 * Entry point used by the dialog: real capture on desktop, DOM reconstruction elsewhere. The result says how the
 * image was made (method 'tab' = real capture, 'dom' = page reconstruction) and whether masks were applied.
 * @param {{vendorUrl: string, method?: 'tab'|'dom', mask?: boolean, sensitiveSelectors?: string[], signal?: AbortSignal}} options
 * @returns {Promise<{blob: Blob, method: 'tab'|'dom', masked: boolean}>}
 */
export function takeScreenshot({ vendorUrl, method = 'tab', mask = false, sensitiveSelectors = [], signal = null }) {
	if (selectMethod(globalThis, method) === 'tab') {
		return captureTab({ mask, sensitiveSelectors, signal });
	}
	// Loaded on first use only: the reconstruction needs no gesture, unlike getDisplayMedia above.
	return import('./capture.js')
		.then(({ captureVisible }) => captureVisible({ vendorUrl, mask, sensitiveSelectors }))
		.then((blob) => ({ blob, method: 'dom', masked: mask === true }));
}
