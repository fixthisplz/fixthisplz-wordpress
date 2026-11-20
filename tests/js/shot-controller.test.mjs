import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { createShotController } from '../../assets/js/lib/shot-controller.js';
import { CaptureTooLargeError } from '../../assets/js/lib/jpeg.js';

// Just what the controller touches on the dialog's elements (no jsdom).
function node() {
	const listeners = {};
	return {
		hidden: false,
		checked: false,
		textContent: '',
		attrs: {},
		addEventListener: (type, fn) => (listeners[type] = fn),
		fire: (type) => listeners[type](),
		focus() {
			globalThis.document.activeElement = this;
		},
		removeAttribute(name) {
			delete this.attrs[name];
		},
		set src(value) {
			this.attrs.src = value;
		},
		get src() {
			return this.attrs.src;
		},
	};
}

let revoked;
beforeEach(() => {
	globalThis.document = { activeElement: null };
	revoked = [];
	let n = 0;
	URL.createObjectURL = () => `blob:${(n += 1)}`;
	URL.revokeObjectURL = (url) => revoked.push(url);
});

const t = (key) => `[${key}]`;
const original = new Blob(['original'], { type: 'image/jpeg' });
const annotated = new Blob(['annotated'], { type: 'image/jpeg' });
const arrow = { type: 'arrow', x1: 0, y1: 0, x2: 50, y2: 50 };
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

/** Controller on fake elements; editResult() answers each editor session (it receives the session's options). */
function setup(editResult) {
	const view = Object.fromEntries(['root', 'shotBox', 'shotStatus', 'shotImage', 'shotPreview', 'annotateButton', 'retakeButton', 'removeButton'].map((k) => [k, node()]));
	view.panel = { contains: (n) => Object.values(view).includes(n) };
	const sessions = [];
	const editor = async () => ({
		editScreenshot: async (options) => {
			sessions.push({ ...options, dialogHidden: view.root.hidden });
			return editResult(options);
		},
	});
	const take = async () => ({ blob: original, method: 'tab', masked: false });
	const shots = createShotController({ view, t, capture: {}, isClosed: () => false, onChange() {}, take, editor });
	return { view, shots, sessions };
}

async function withShot(editResult) {
	const ctx = setup(editResult);
	ctx.view.shotBox.checked = true;
	ctx.view.shotBox.fire('change');
	await tick();
	assert.equal(ctx.shots.blob, original);
	return ctx;
}

test('Apply replaces the previewed and sent image with the annotated one', async () => {
	const { view, shots, sessions } = await withShot(() => ({ shapes: [arrow], blob: annotated }));
	view.annotateButton.fire('click');
	await tick();
	assert.equal(sessions[0].original, original);
	assert.deepEqual(sessions[0].shapes, []);
	assert.equal(sessions[0].dialogHidden, true, 'the dialog is hidden while the editor is open');
	assert.equal(shots.blob, annotated);
	assert.equal(view.shotImage.src, 'blob:2');
	assert.deepEqual(revoked, ['blob:1'], 'the previous preview URL is released');
	assert.equal(view.root.hidden, false);
	assert.equal(document.activeElement, view.annotateButton, 'focus comes back to Annotate');
});

test('reopening Annotate starts from the original with the shapes already applied, never the burnt image', async () => {
	const results = [{ shapes: [arrow], blob: annotated }, { shapes: [], blob: original }];
	const { view, shots, sessions } = await withShot(() => results.shift());
	view.annotateButton.fire('click');
	await tick();
	view.annotateButton.fire('click');
	await tick();
	assert.equal(sessions[1].original, original);
	assert.deepEqual(sessions[1].shapes, [arrow]);
	assert.equal(shots.blob, original, 'every shape undone: the original is sent again');
});

test('Cancel keeps the previous image and shapes', async () => {
	const results = [{ shapes: [arrow], blob: annotated }, null, { shapes: [arrow], blob: annotated }];
	const { view, shots, sessions } = await withShot(() => results.shift());
	view.annotateButton.fire('click');
	await tick();
	view.annotateButton.fire('click');
	await tick();
	assert.equal(shots.blob, annotated);
	assert.equal(view.root.hidden, false);
	view.annotateButton.fire('click');
	await tick();
	assert.deepEqual(sessions[2].shapes, [arrow]);
});

test('a result over 5 MB shows the size message and keeps the previous state', async () => {
	const { view, shots } = await withShot(() => {
		throw new CaptureTooLargeError();
	});
	view.annotateButton.fire('click');
	await tick();
	assert.equal(shots.blob, original);
	assert.equal(view.shotStatus.textContent, '[errorCaptureTooLarge]');
	assert.equal(view.root.hidden, false);
	assert.equal(document.activeElement, view.annotateButton);
});

test('any other failure (editor not loaded, encode error) says the annotations were not applied', async () => {
	const { view, shots } = await withShot(() => {
		throw new Error('Canvas export failed.');
	});
	view.annotateButton.fire('click');
	await tick();
	assert.equal(shots.blob, original);
	assert.equal(view.shotStatus.textContent, '[errorAnnotate]');
	assert.equal(view.root.hidden, false);
});

test('Remove, unchecking and Retake drop the original, the shapes and the annotated image', async () => {
	for (const act of [
		(view) => view.removeButton.fire('click'),
		(view) => ((view.shotBox.checked = false), view.shotBox.fire('change')),
		(view) => view.retakeButton.fire('click'),
	]) {
		const { view, shots, sessions } = await withShot(() => ({ shapes: [arrow], blob: annotated }));
		view.annotateButton.fire('click');
		await tick();
		const urls = revoked.length;
		act(view);
		assert.equal(revoked.length, urls + 1, 'the annotated preview URL is released');
		await tick();
		if (shots.blob) {
			assert.equal(shots.blob, original, 'Retake: a fresh capture, nothing annotated');
			view.annotateButton.fire('click');
			await tick();
			assert.deepEqual(sessions.at(-1).shapes, [], 'the old shapes are gone');
		} else {
			assert.equal(view.shotPreview.hidden, true);
		}
	}
});

test('closing the dialog drops everything', async () => {
	const { view, shots } = await withShot(() => ({ shapes: [arrow], blob: annotated }));
	view.annotateButton.fire('click');
	await tick();
	shots.drop();
	assert.equal(shots.blob, null);
	assert.equal(view.shotImage.src, undefined);
	assert.equal(revoked.at(-1), 'blob:2');
});
