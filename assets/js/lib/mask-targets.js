/* Geometry of the OPT-IN masks on the real tab capture (off by default, best effort):
 * which elements to cover and where they are on screen (CSS px of the viewport).
 * Known limits: fields inside CLOSED shadow roots are not reached; pinch-zoom and transformed frames are not
 * compensated. */
import { FIELD_TAGS, KEEP_VALUE_TYPES, isEditable, matchesAny, sensitiveList } from './sensitive.js';
import { UI_ATTR } from './dom.js';

const MARGIN = 2;

function isMaskedField(node) {
	const tag = String(node.tagName).toUpperCase();
	if (!FIELD_TAGS.has(tag)) {
		return isEditable(node);
	}
	const type = (node.getAttribute('type') || 'text').toLowerCase();
	return tag !== 'INPUT' || (type !== 'hidden' && !KEEP_VALUE_TYPES.has(type));
}

function frameDocument(node) {
	try {
		return node.contentDocument || null;
	} catch {
		return null;
	}
}

/**
 * Every element to cover: { el, frames (same-origin frames it sits in, outermost first) }. Walks open shadow
 * roots; a frame whose document is not readable (other origin) is covered whole, since it may hold fields.
 */
export function listMaskTargets(root, sensitiveSelectors = []) {
	const selectors = sensitiveList(sensitiveSelectors);
	const targets = [];
	const stack = [{ node: root, frames: [], inSensitive: false }];
	while (stack.length) {
		const { node, frames, inSensitive } = stack.pop();
		if (!node || (node.nodeType !== 1 && node.nodeType !== 11)) {
			continue;
		}
		let sensitive = inSensitive;
		const children = Array.from(node.childNodes || []);
		if (node.nodeType === 1) {
			if (node.getAttribute(UI_ATTR) !== null) {
				continue;
			}
			sensitive = inSensitive || matchesAny(node, selectors);
			if (sensitive || isMaskedField(node)) {
				targets.push({ el: node, frames });
			}
			if (String(node.tagName).toUpperCase() === 'IFRAME') {
				const doc = frameDocument(node);
				if (!doc) {
					if (!sensitive) targets.push({ el: node, frames });
				} else if (doc.documentElement) {
					stack.push({ node: doc.documentElement, frames: [...frames, node], inSensitive: sensitive });
				}
			}
			if (node.shadowRoot) {
				children.push(node.shadowRoot);
			}
		}
		for (let i = children.length - 1; i >= 0; i -= 1) {
			stack.push({ node: children[i], frames, inSensitive: sensitive });
		}
	}
	return targets;
}

/**
 * Viewport rectangle (CSS px, top document) of a target, shifted and clipped through its frames; empty when it
 * has no visible box. Best effort (opt-in masking): no pinch-zoom or transformed-frame handling.
 * @returns {{left:number, top:number, width:number, height:number}[]}
 */
export function measureTarget({ el, frames }, getStyle = (n) => getComputedStyle(n)) {
	if (el.getClientRects().length === 0) {
		return [];
	}
	const r = el.getBoundingClientRect();
	let b = { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
	for (let i = frames.length - 1; i >= 0; i -= 1) {
		const frame = frames[i];
		const f = frame.getBoundingClientRect();
		const style = getStyle(frame);
		const x = f.left + frame.clientLeft + parseFloat(style.paddingLeft || '0');
		const y = f.top + frame.clientTop + parseFloat(style.paddingTop || '0');
		b = {
			left: Math.max(b.left + x, f.left),
			top: Math.max(b.top + y, f.top),
			right: Math.min(b.right + x, f.right),
			bottom: Math.min(b.bottom + y, f.bottom),
		};
	}
	if (b.right <= b.left || b.bottom <= b.top) {
		return [];
	}
	return [{ left: b.left, top: b.top, width: b.right - b.left, height: b.bottom - b.top }];
}

/** CSS-pixel viewport rectangles → image rectangles (margin around each, clamped, empty ones dropped). */
export function scaleRects(rects, image, viewport, margin = MARGIN) {
	const sx = image.width / viewport.width;
	const sy = image.height / viewport.height;
	const out = [];
	for (const r of rects) {
		const x1 = Math.max(0, Math.floor((r.left - margin) * sx));
		const y1 = Math.max(0, Math.floor((r.top - margin) * sy));
		const x2 = Math.min(image.width, Math.ceil((r.left + r.width + margin) * sx));
		const y2 = Math.min(image.height, Math.ceil((r.top + r.height + margin) * sy));
		if (x2 > x1 && y2 > y1) {
			out.push({ x: x1, y: y1, width: x2 - x1, height: y2 - y1 });
		}
	}
	return out;
}
