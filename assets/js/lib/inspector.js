/* Element picker (spec 5, step 2). Mouse, touch and keyboard; page handlers never see the picking events.
 * Resolves with the chosen Element, 'page' (whole page) or null (cancelled). Leaves nothing behind. */
import { describeElement } from './element.js';
import { make, translator, UI_ATTR } from './dom.js';

const FOCUSABLE = [
	'a[href]', 'area[href]', 'button', 'input:not([type="hidden"])', 'select', 'textarea', 'iframe', 'summary',
	'audio[controls]', 'video[controls]', '[tabindex]', '[contenteditable]:not([contenteditable="false"])',
].join(',');
// Every event that could make the page react while the visitor is pointing at it.
const BLOCKED = ['pointerdown', 'pointerup', 'mousedown', 'mouseup', 'click', 'dblclick', 'auxclick', 'touchstart', 'touchend', 'focus', 'focusin', 'submit',
	// Typing, pasting, dropping or deleting into a page field that Tab focused.
	'beforeinput', 'paste', 'cut', 'drop'];
const EDIT_SHORTCUTS = new Set(['v', 'x', 'z', 'y']);

const TOUCH_GRACE_MS = 1000;
const isOwnUi = (node) => Boolean(node && node.nodeType === 1 && node.closest(`[${UI_ATTR}]`));
const isPickable = (node) => node && node.nodeType === 1 && node !== document.documentElement && !isOwnUi(node);

function pageFocusables() {
	return Array.from(document.querySelectorAll(FOCUSABLE)).filter(
		(el) => !isOwnUi(el) && !el.disabled && el.tabIndex >= 0 && el.getClientRects().length > 0 && !el.closest('[inert]')
	);
}

let active = null;

/** @param {{labels: Record<string,string>, sensitiveSelectors?: string[]}} options */
export function inspect({ labels = {}, sensitiveSelectors = [] } = {}) {
	if (active) {
		active.focusBar();
		return active.promise;
	}
	const t = translator(labels);

	let resolvePromise;
	const promise = new Promise((resolve) => {
		resolvePromise = resolve;
	});

	let current = null;
	let lastPointer = 'mouse';
	let lastTouchAt = 0;
	let frame = 0;
	const history = [];

	const box = make('div', { class: 'bugspot-highlight', 'aria-hidden': 'true' });
	const status = make('p', { class: 'bugspot-inspect-status', role: 'status', 'aria-live': 'polite', text: t('inspectHint') });
	const selectButton = make('button', { type: 'button', class: 'bugspot-btn bugspot-btn-primary', text: t('select') });
	const pageButton = make('button', { type: 'button', class: 'bugspot-btn', text: t('wholePage') });
	const cancelButton = make('button', { type: 'button', class: 'bugspot-btn', text: t('cancel') });
	selectButton.disabled = true;
	const bar = make('div', { class: 'bugspot-inspect-bar', role: 'group', 'aria-label': t('inspectHint'), tabindex: '-1' }, [
		status,
		make('div', { class: 'bugspot-inspect-actions' }, [selectButton, pageButton, cancelButton]),
	]);
	const root = make('div', { id: 'bugspot-inspector', class: 'bugspot-ui', [UI_ATTR]: true }, [box, bar]);

	function place() {
		frame = 0;
		if (!current || !current.isConnected) {
			box.style.display = 'none';
			return;
		}
		const r = current.getBoundingClientRect();
		Object.assign(box.style, { display: 'block', top: `${r.top}px`, left: `${r.left}px`, width: `${r.width}px`, height: `${r.height}px` });
	}
	const schedule = () => {
		if (!frame) {
			frame = requestAnimationFrame(place);
		}
	};

	function announce(el) {
		const d = describeElement(el, { sensitiveSelectors });
		const what = [d.role || d.tag, d.name || Array.from(d.text).slice(0, 80).join('')].filter(Boolean).join(' – ');
		const template = t('inspectHighlighted');
		status.textContent = template.includes('%s') ? template.replace('%s', what) : what;
	}

	function highlight(el, { keepHistory = false } = {}) {
		if (!isPickable(el) || el === current) {
			return;
		}
		if (!keepHistory) {
			history.length = 0;
		}
		current = el;
		selectButton.disabled = false;
		announce(el);
		schedule();
	}

	function finish(result) {
		cleanup();
		resolvePromise(result);
	}

	function block(event) {
		if (isOwnUi(event.target)) {
			return;
		}
		event.stopImmediatePropagation();
		if (event.type === 'touchstart' || event.type === 'touchend') {
			lastTouchAt = Date.now(); // no preventDefault: scrolling and the compatibility click must still happen
			return;
		}
		event.preventDefault();
		if (event.type === 'pointerdown') {
			lastPointer = event.pointerType || 'mouse';
			if (lastPointer === 'touch') {
				lastTouchAt = Date.now();
			}
		} else if (event.type === 'focusin') {
			highlight(event.target);
		} else if (event.type === 'click') {
			const target = event.target;
			if (lastPointer === 'touch' && target !== current) {
				highlight(target); // first tap highlights, second tap on the same element selects
				return;
			}
			if (isPickable(target)) {
				finish(target);
			}
		}
	}

	function onMove(event) {
		// Touch also fires emulated mouse moves just before its click: they must not count as a first tap.
		if (event.pointerType !== 'mouse' && event.pointerType !== 'pen') {
			return;
		}
		if (Date.now() - lastTouchAt < TOUCH_GRACE_MS || isOwnUi(event.target)) {
			return;
		}
		highlight(event.target);
	}

	function moveFocus(backwards) {
		const order = [selectButton, pageButton, cancelButton, ...pageFocusables()].filter((el) => !el.disabled);
		const index = order.indexOf(document.activeElement);
		const next = index === -1 ? (backwards ? order.length - 1 : 0) : (index + (backwards ? -1 : 1) + order.length) % order.length;
		const target = order[next];
		if (target) {
			target.focus();
			if (!isOwnUi(target)) {
				highlight(target); // focusin is blocked for page elements, so follow focus here too
			}
		}
	}

	function onKey(event) {
		const inUi = isOwnUi(event.target);
		switch (event.key) {
			case 'Escape':
				event.preventDefault();
				event.stopImmediatePropagation();
				finish(null);
				return;
			case 'Tab':
				event.preventDefault();
				event.stopImmediatePropagation();
				moveFocus(event.shiftKey);
				return;
			case 'ArrowUp':
				if (current && isPickable(current.parentElement)) {
					event.preventDefault();
					event.stopImmediatePropagation();
					const child = current;
					highlight(current.parentElement, { keepHistory: true });
					history.push(child);
				}
				return;
			case 'ArrowDown':
				if (history.length > 0) {
					event.preventDefault();
					event.stopImmediatePropagation();
					highlight(history.pop(), { keepHistory: true });
				}
				return;
			case 'Enter':
			case ' ':
				if (event.key === ' ' && (event.target === document.body || event.target === document.documentElement)) {
					event.stopImmediatePropagation(); // nothing focused: Space still scrolls the page
					return;
				}
				if (!inUi) {
					event.preventDefault();
					event.stopImmediatePropagation();
					if (event.key === 'Enter' && current) {
						finish(current);
					}
				}
				return;
			default:
				if (!inUi) {
					event.stopImmediatePropagation(); // page shortcuts stay quiet while picking
					const command = event.ctrlKey || event.metaKey;
					const printable = event.key.length === 1 && !command && !event.altKey;
					if (printable || event.key === 'Backspace' || event.key === 'Delete' || (command && EDIT_SHORTCUTS.has(event.key.toLowerCase()))) {
						event.preventDefault(); // never edit a page field while picking; scrolling keys still work
					}
				}
		}
	}

	const listeners = [
		...BLOCKED.map((type) => [document, type, block, { capture: true, passive: false }]),
		[document, 'pointermove', onMove, { capture: true, passive: true }],
		[document, 'keydown', onKey, { capture: true }],
		[window, 'scroll', schedule, { capture: true, passive: true }],
		[window, 'resize', schedule, { passive: true }],
	];

	function cleanup() {
		for (const [target, type, fn, opts] of listeners) {
			target.removeEventListener(type, fn, opts);
		}
		if (frame) {
			cancelAnimationFrame(frame);
		}
		root.remove();
		active = null;
	}

	selectButton.addEventListener('click', () => current && finish(current));
	pageButton.addEventListener('click', () => finish('page'));
	cancelButton.addEventListener('click', () => finish(null));

	document.body.appendChild(root);
	for (const [target, type, fn, opts] of listeners) {
		target.addEventListener(type, fn, opts);
	}
	bar.focus();

	active = { promise, focusBar: () => bar.focus() };
	return promise;
}
