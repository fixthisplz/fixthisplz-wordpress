/* Keeps keyboard focus inside the modal report dialog (spec 5, accessibility): Tab and Shift+Tab wrap around,
 * focus that escapes is brought back, Escape asks the dialog to close. */

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([tabindex="-1"]), textarea:not([disabled]), select:not([disabled]), summary, iframe, [tabindex]:not([tabindex="-1"])';
// Anti-bot challenges open their own popups (appended to body, iframes): focus must be allowed to go there.
const PROVIDER_FRAMES = 'iframe[src*="recaptcha"], iframe[src*="hcaptcha.com"], iframe[src*="challenges.cloudflare.com"]';
const PROVIDER_DEPTH = 3;

/** True when node is an iframe, or sits within PROVIDER_DEPTH levels of an anti-bot provider frame. */
export function isProviderFocus(node) {
	if (!node || node.nodeType !== 1) {
		return false;
	}
	if (node.tagName === 'IFRAME') {
		return true;
	}
	let n = node;
	for (let i = 0; i < PROVIDER_DEPTH && n && n !== document.body; i += 1, n = n.parentElement) {
		if (n.querySelector(PROVIDER_FRAMES)) {
			return true;
		}
	}
	return false;
}

/** Focusable elements of panel that are rendered and not inside a [hidden] part. */
export function focusables(panel) {
	return Array.from(panel.querySelectorAll(FOCUSABLE)).filter((n) => n.getClientRects().length > 0 && !n.closest('[hidden]'));
}

/** Where Tab must send focus to stay inside, or null when the browser's own move is fine. list is not empty. */
export function wrapTarget(list, active, inside, backwards) {
	const first = list[0];
	const last = list[list.length - 1];
	if (backwards && (active === first || !inside)) {
		return last;
	}
	if (!backwards && (active === last || !inside)) {
		return first;
	}
	return null;
}

/**
 * Starts the trap on root (the dialog root) around panel. isActive() says whether focus must be held right now.
 * @returns {() => void} Stops it.
 */
export function trapFocus({ root, panel, isActive, onEscape }) {
	function onKeydown(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			onEscape();
			return;
		}
		if (event.key !== 'Tab') {
			return;
		}
		const list = focusables(panel);
		if (list.length === 0) {
			event.preventDefault();
			return;
		}
		const target = wrapTarget(list, document.activeElement, panel.contains(document.activeElement), event.shiftKey);
		if (target) {
			event.preventDefault();
			target.focus();
		}
	}
	function onFocusIn(event) {
		if (isActive() && !root.contains(event.target) && !isProviderFocus(event.target)) {
			(focusables(panel)[0] || panel).focus();
		}
	}
	root.addEventListener('keydown', onKeydown);
	document.addEventListener('focusin', onFocusIn, true);
	return () => {
		root.removeEventListener('keydown', onKeydown);
		document.removeEventListener('focusin', onFocusIn, true);
	};
}
