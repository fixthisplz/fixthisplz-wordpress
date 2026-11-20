/* Opt-in masking of the DOM-reconstruction screenshot (spec 5.2, decision David 2026-11-20): runs on the library's
 * CLONE only, never on the page. Field values become bullets, editable regions too, and sensitive parts get a solid
 * fill. The rules of what is masked come from sensitive.js. */
import { FIELD_TAGS, KEEP_VALUE_TYPES, MASK_FILL, isEditable, matchesAny, sensitiveList } from './sensitive.js';

const BULLETS = '••••••';
const REPLACED_TAGS = new Set(['IMG', 'VIDEO', 'CANVAS', 'IFRAME', 'SVG', 'OBJECT', 'EMBED', 'PICTURE']);

/** Sets an inline style that wins over the page's own !important rules. */
export function important(node, name, value) {
	node.style.setProperty(name, value, 'important');
}

function replaceText(node, text) {
	while (node.firstChild) {
		node.removeChild(node.firstChild);
	}
	if (text) {
		node.appendChild(node.ownerDocument.createTextNode(text));
	}
}

function maskField(node) {
	const tag = String(node.tagName).toUpperCase();
	if (tag === 'INPUT') {
		const type = (node.getAttribute('type') || 'text').toLowerCase();
		if (type === 'password') {
			node.setAttribute('value', BULLETS); // always, so not even the length shows
		} else if (type === 'hidden' || type === 'file') {
			node.setAttribute('value', '');
		} else if (!KEEP_VALUE_TYPES.has(type) && (node.getAttribute('value') || '') !== '') {
			node.setAttribute('value', BULLETS);
		}
	} else if (tag === 'TEXTAREA') {
		const had = (node.getAttribute('value') || node.textContent || '') !== '';
		node.removeAttribute('value');
		replaceText(node, had ? BULLETS : '');
	} else if (tag === 'SELECT') {
		node.removeAttribute('value');
		replaceText(node, '');
		const option = node.ownerDocument.createElement('option');
		option.textContent = BULLETS;
		option.setAttribute('selected', '');
		node.appendChild(option);
	}
}

function fillSensitive(node) {
	// Same box, solid fill; everything inside is hidden (descendants included).
	important(node, 'background', MASK_FILL);
	important(node, 'color', 'transparent');
	important(node, '-webkit-text-fill-color', 'transparent');
	important(node, 'text-shadow', 'none');
	important(node, 'border-color', MASK_FILL);
	if (REPLACED_TAGS.has(String(node.tagName).toUpperCase())) {
		important(node, 'opacity', '0');
	}
	const stack = Array.from(node.childNodes || []);
	while (stack.length) {
		const child = stack.pop();
		if (child.nodeType === 1) {
			important(child, 'visibility', 'hidden');
			stack.push(...Array.from(child.childNodes || []));
		}
	}
}

/** Runs on the cloned tree, never on the page; returns the boxes it filled. */
function maskClone(root, sensitiveSelectors = []) {
	if (!root || root.nodeType !== 1) {
		return [];
	}
	const selectors = sensitiveList(sensitiveSelectors);
	const sensitive = [];
	const stack = [root];
	while (stack.length) {
		const node = stack.pop();
		if (node.nodeType !== 1) {
			continue;
		}
		if (FIELD_TAGS.has(String(node.tagName).toUpperCase())) {
			maskField(node);
		} else if (isEditable(node)) {
			replaceText(node, BULLETS);
		}
		if (matchesAny(node, selectors)) {
			sensitive.push(node);
		}
		stack.push(...Array.from(node.childNodes || []));
	}
	sensitive.forEach(fillSensitive);
	return sensitive;
}

/** What runs on each cloned subtree: maskClone when the site enabled masking, nothing otherwise. */
export const cloneMasker = (mask, sensitiveSelectors = []) => (mask === true ? (clone) => maskClone(clone, sensitiveSelectors) : () => []);
