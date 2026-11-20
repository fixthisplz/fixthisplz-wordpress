import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	selectMethod,
	withStream,
	shouldMask,
	captureTab,
	takeScreenshot,
	CaptureDeniedError,
} from '../../assets/js/lib/tab-capture.js';

function env({ api = true, coarse = false, fine = true, document = undefined } = {}) {
	return {
		navigator: { mediaDevices: api ? { getDisplayMedia() {} } : {} },
		document,
		matchMedia: (q) => ({ matches: q === '(pointer: coarse)' ? coarse : q === '(any-pointer: fine)' ? fine : false }),
	};
}

test('selectMethod picks the real capture on any desktop browser with getDisplayMedia (no CropTarget needed)', () => {
	assert.equal(selectMethod(env()), 'tab');
	assert.equal(selectMethod(env({ coarse: true, fine: true })), 'tab'); // touch laptop with a mouse
});

test('selectMethod honours the site setting dom even when the API is present', () => {
	assert.equal(selectMethod(env(), 'dom'), 'dom');
	assert.equal(selectMethod(env(), 'tab'), 'tab');
	assert.equal(selectMethod(env({ api: false }), 'tab'), 'dom'); // tab never forces an unavailable API
});

test('selectMethod falls back without the API or on a touch-only device', () => {
	assert.equal(selectMethod(env({ api: false })), 'dom');
	assert.equal(selectMethod({ navigator: {} }), 'dom');
	assert.equal(selectMethod(env({ coarse: true, fine: false })), 'dom');
});

test('selectMethod falls back when the Permissions-Policy forbids display-capture', () => {
	const policy = (allowed) => ({ allowsFeature: (f) => (f === 'display-capture' ? allowed : true) });
	assert.equal(selectMethod(env({ document: { permissionsPolicy: policy(false) } })), 'dom');
	assert.equal(selectMethod(env({ document: { featurePolicy: policy(false) } })), 'dom');
	assert.equal(selectMethod(env({ document: { permissionsPolicy: policy(true) } })), 'tab');
	assert.equal(selectMethod(env({ document: {} })), 'tab'); // browser without the API: allowed
});

function fakeStream() {
	const tracks = [{ stopped: 0, stop() { this.stopped += 1; } }, { stopped: 0, stop() { this.stopped += 1; } }];
	return { tracks, getTracks: () => tracks, getVideoTracks: () => [tracks[0]] };
}

test('withStream stops every track after a successful grab', async () => {
	const stream = fakeStream();
	const value = await withStream(stream, async (track) => {
		assert.equal(track, stream.tracks[0]);
		assert.equal(track.stopped, 0);
		return 'image';
	});
	assert.equal(value, 'image');
	assert.deepEqual(stream.tracks.map((t) => t.stopped), [1, 1]);
});

test('withStream stops every track even when the grab fails', async () => {
	const stream = fakeStream();
	await assert.rejects(withStream(stream, async () => { throw new Error('grab failed'); }), /grab failed/);
	assert.deepEqual(stream.tracks.map((t) => t.stopped), [1, 1]);
});

test('withStream stops every track even when the grab throws synchronously', async () => {
	const stream = fakeStream();
	await assert.rejects(withStream(stream, () => { throw new Error('sync'); }), /sync/);
	assert.deepEqual(stream.tracks.map((t) => t.stopped), [1, 1]);
});

test('shouldMask: off by default; when on, only for a surface reported as a tab', () => {
	const track = (surface) => ({ getSettings: () => (surface ? { displaySurface: surface } : {}) });
	assert.equal(shouldMask(undefined, track('browser')), false);
	assert.equal(shouldMask(false, track('browser')), false);
	assert.equal(shouldMask('yes', track('browser')), false); // strictly true
	assert.equal(shouldMask(true, track('browser')), true);
	assert.equal(shouldMask(true, track(undefined)), false); // unknown surface: masked would be false information
	assert.equal(shouldMask(true, track('window')), false); // page rectangles mean nothing there: skipped
	assert.equal(shouldMask(true, track('monitor')), false);
});

test('withStream stops every track at once when the run is abandoned mid-grab', async () => {
	const stream = fakeStream();
	const controller = new AbortController();
	let finishGrab;
	const pending = withStream(stream, () => new Promise((resolve) => { finishGrab = resolve; }), controller.signal);
	await Promise.resolve();
	controller.abort();
	await assert.rejects(pending, { name: 'StaleCaptureError' });
	assert.deepEqual(stream.tracks.map((t) => t.stopped > 0), [true, true]);
	finishGrab('late image'); // ignored
});

test('withStream never grabs for a run already abandoned, and stops the tracks', async () => {
	const stream = fakeStream();
	const controller = new AbortController();
	controller.abort();
	let grabbed = false;
	await assert.rejects(withStream(stream, () => { grabbed = true; }, controller.signal), { name: 'StaleCaptureError' });
	assert.equal(grabbed, false);
	assert.deepEqual(stream.tracks.map((t) => t.stopped > 0), [true, true]);
});

/* ---- A fake browser: getDisplayMedia recorded, minimal DOM for one real-capture shoot. ---- */
function withFakeBrowser(fn, { hidden = false, video = {} } = {}) {
	const saved = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
	const calls = [];
	const focus = [];
	let settle;
	const mediaDevices = {
		getDisplayMedia(options) {
			calls.push(options);
			return new Promise((resolve, reject) => { settle = { resolve, reject }; });
		},
	};
	Object.defineProperty(globalThis, 'navigator', { value: { mediaDevices }, configurable: true, writable: true });
	const painted = [];
	const delivered = []; // true = stale frame (captured before the UI was hidden)
	const classes = new Set();
	const ui = { classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c) } };
	const page = { nodeType: 1, tagName: 'HTML', childNodes: [], shadowRoot: null, getAttribute: () => null, matches: () => false };
	page.childNodes.push({ nodeType: 1, tagName: 'INPUT', childNodes: [], shadowRoot: null, getAttribute: () => null, matches: () => false, getClientRects: () => [1], getBoundingClientRect: () => ({ left: 10, top: 10, right: 30, bottom: 20 }) });
	Object.assign(globalThis, {
		document: {
			documentElement: page,
			visibilityState: hidden ? 'hidden' : 'visible',
			addEventListener() {},
			removeEventListener() {},
			querySelectorAll: () => [ui],
			createElement: (tag) =>
				tag === 'video'
					? {
						videoWidth: 200,
						videoHeight: 100,
						play: () => Promise.resolve(),
						pause() {},
						requestVideoFrameCallback(cb) {
							this.calls = (this.calls || 0) + 1;
							const stale = this.calls === 1;
							delivered.push(stale);
							setTimeout(() => cb(0, { captureTime: stale ? 0 : performance.now() }), 0);
						},
						...video,
					}
					: {
						getContext: () => ({ drawImage() {}, fillRect: (...box) => painted.push(box) }),
						toBlob: (done) => done({ size: 10, type: 'image/jpeg' }),
					},
		},
		window: { innerWidth: 100, innerHeight: 50 },
		requestAnimationFrame: hidden ? () => { throw new Error('no animation frames in a hidden page'); } : (cb) => setTimeout(cb, 0),
		MediaStream: class {},
		ImageCapture: class {
			grabFrame() {
				return Promise.resolve({ width: 200, height: 100, close() {} });
			}
		},
		CaptureController: class {
			setFocusBehavior(value) {
				focus.push(value);
			}
		},
	});
	return Promise.resolve()
		.then(() => fn({ calls, focus, settle: () => settle, painted, delivered, uiHidden: () => classes.has('bugspot-capture-hide') }))
		.finally(() => {
			for (const name of ['document', 'window', 'requestAnimationFrame', 'ImageCapture', 'MediaStream', 'CaptureController']) delete globalThis[name];
			if (saved) Object.defineProperty(globalThis, 'navigator', saved);
			else delete globalThis.navigator;
		});
}

const denied = () => Object.assign(new Error('no'), { name: 'NotAllowedError' });

function streamOf(surface) {
	const stream = fakeStream();
	stream.tracks[0].getSettings = () => ({ displaySurface: surface });
	return stream;
}

test('captureTab and takeScreenshot call getDisplayMedia synchronously (inside the gesture)', () =>
	withFakeBrowser(async ({ calls, settle }) => {
		const first = captureTab();
		assert.equal(calls.length, 1, 'called before any microtask');
		assert.equal(calls[0].preferCurrentTab, true);
		settle().reject(denied());
		await assert.rejects(first, CaptureDeniedError);
		await Promise.resolve();
		const second = takeScreenshot({ vendorUrl: 'unused', sensitiveSelectors: [] });
		assert.equal(calls.length, 2, 'takeScreenshot routes to the real capture without awaiting');
		settle().reject(denied());
		await assert.rejects(second, CaptureDeniedError);
	}));

test('sharing another tab keeps this page in front (CaptureController, no focus change)', () =>
	withFakeBrowser(async ({ calls, focus, settle }) => {
		const result = captureTab();
		assert.ok(calls[0].controller, 'controller passed to getDisplayMedia');
		settle().resolve(streamOf('browser'));
		await result;
		assert.deepEqual(focus, ['no-focus-change']);
	}));

test('a second capture while the browser prompt is open reuses it instead of asking again', () =>
	withFakeBrowser(async ({ calls, settle }) => {
		const older = new AbortController();
		const first = captureTab({ signal: older.signal });
		older.abort(); // box unchecked
		const second = captureTab({ signal: new AbortController().signal }); // checked again
		assert.equal(calls.length, 1);
		const stream = fakeStream();
		settle().resolve(stream);
		await assert.rejects(first, { name: 'StaleCaptureError' });
		assert.equal((await second).method, 'tab'); // the newer run owns the stream…
		assert.deepEqual(stream.tracks.map((t) => t.stopped > 0), [true, true]); // …and stops it
	}));

test('a refusal reaching an abandoned run is ignored (stale), the current run gets the refusal', () =>
	withFakeBrowser(async ({ settle }) => {
		const older = new AbortController();
		const first = captureTab({ signal: older.signal });
		older.abort();
		const second = captureTab();
		settle().reject(denied());
		await assert.rejects(first, { name: 'StaleCaptureError' });
		await assert.rejects(second, CaptureDeniedError);
	}));

test('the real capture says how it was made: method tab, not masked by default, stream stopped, UI back', () =>
	withFakeBrowser(async ({ settle, painted, delivered, uiHidden }) => {
		const result = takeScreenshot({ vendorUrl: 'unused', signal: new AbortController().signal });
		const stream = streamOf('browser');
		settle().resolve(stream);
		const { blob, method, masked } = await result;
		assert.equal(blob.type, 'image/jpeg');
		assert.equal(method, 'tab');
		assert.equal(masked, false);
		assert.deepEqual(painted, []); // the typed field stays visible
		assert.deepEqual(delivered, [true, false]); // the frame captured before the UI was hidden is skipped
		assert.deepEqual(stream.tracks.map((t) => t.stopped > 0), [true, true]);
		assert.equal(uiHidden(), false);
	}));

test('with masking on, a shared tab gets the field covered (scaled 2x, 2 px margin)', () =>
	withFakeBrowser(async ({ settle, painted }) => {
		const result = takeScreenshot({ vendorUrl: 'unused', mask: true });
		settle().resolve(streamOf('browser'));
		assert.equal((await result).masked, true);
		assert.deepEqual(painted, [[16, 16, 48, 28]]); // x 8..32 and y 8..22 CSS px, times 2
	}));

test('with masking on, a window, a screen or an unreported surface is not masked', () =>
	withFakeBrowser(async ({ settle, painted }) => {
		for (const surface of ['window', 'monitor', undefined]) {
			const result = takeScreenshot({ vendorUrl: 'unused', mask: true });
			settle().resolve(streamOf(surface));
			const { method, masked } = await result;
			assert.equal(method, 'tab');
			assert.equal(masked, false);
		}
		assert.deepEqual(painted, []);
	}));

test('page in the background (another tab in front): no animation-frame wait, frame taken directly', () =>
	withFakeBrowser(
		async ({ settle, delivered, uiHidden }) => {
			const result = captureTab();
			settle().resolve(streamOf('browser'));
			const { method } = await result;
			assert.equal(method, 'tab');
			assert.deepEqual(delivered, []); // no video callbacks needed
			assert.equal(uiHidden(), false);
		},
		{ hidden: true }
	));

test('the plugin UI comes back after a failed grab', () =>
	withFakeBrowser(
		async ({ settle, uiHidden }) => {
			const result = captureTab();
			settle().resolve(streamOf('browser'));
			await assert.rejects(result, /cannot play/);
			assert.equal(uiHidden(), false);
		},
		{ video: { play: () => Promise.reject(new Error('cannot play')) } }
	));

test('the plugin UI comes back at once when the run is abandoned mid-grab', () => {
	let waitingForFrame = false;
	return withFakeBrowser(
		async ({ settle, uiHidden }) => {
			const controller = new AbortController();
			const result = captureTab({ signal: controller.signal });
			const stream = streamOf('browser');
			settle().resolve(stream);
			for (let i = 0; i < 50 && !waitingForFrame; i += 1) await new Promise((r) => setTimeout(r, 0));
			assert.equal(uiHidden(), true, 'UI hidden while grabbing');
			controller.abort(); // box unchecked: the pending frame never arrives
			assert.equal(uiHidden(), false, 'restored synchronously by the abort');
			await assert.rejects(result, { name: 'StaleCaptureError' });
			assert.deepEqual(stream.tracks.map((t) => t.stopped > 0), [true, true]);
		},
		{ video: { requestVideoFrameCallback() { waitingForFrame = true; } } } // a frame that never comes
	);
});

test('the page reconstruction reports method dom and the masking flag', async () => {
	const saved = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
	Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true, writable: true }); // no getDisplayMedia
	const html = { tagName: 'HTML' };
	Object.assign(globalThis, {
		document: { documentElement: html, body: null, querySelectorAll: () => [] },
		window: { innerWidth: 100, innerHeight: 50, scrollX: 0, scrollY: 0 },
		getComputedStyle: () => ({ backgroundColor: 'rgb(255, 255, 255)' }),
	});
	const vendor = `data:text/javascript,${encodeURIComponent(
		"export const createContext = async () => ({}); export const destroyContext = () => {}; export const domToCanvas = async () => ({ toBlob: (done) => done({ size: 5, type: 'image/jpeg' }) });"
	)}`;
	try {
		const plain = await takeScreenshot({ vendorUrl: vendor });
		assert.deepEqual({ method: plain.method, masked: plain.masked, type: plain.blob.type }, { method: 'dom', masked: false, type: 'image/jpeg' });
		const masked = await takeScreenshot({ vendorUrl: vendor, mask: true });
		assert.deepEqual({ method: masked.method, masked: masked.masked }, { method: 'dom', masked: true });
	} finally {
		for (const name of ['document', 'window', 'getComputedStyle']) delete globalThis[name];
		if (saved) Object.defineProperty(globalThis, 'navigator', saved);
	}
});
