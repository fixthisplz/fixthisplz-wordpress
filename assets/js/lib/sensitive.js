/* What is a field, an editable region and a sensitive part (spec 5.2). The single source for both the report
 * text (element.js, report.js) and the opt-in masks (clone-mask.js, mask-targets.js, tab-capture.js): if these
 * rules differed, the collected text and the masked image would not cover the same things. */

const SENSITIVE_ATTR = '[data-ftp-mask]';
export const FIELD_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);
// Input types whose value attribute is page-authored (labels, flags) rather than chosen or typed.
export const KEEP_VALUE_TYPES = new Set(['button', 'submit', 'reset', 'image', 'checkbox', 'radio']);
// Solid fill drawn over sensitive parts, on the reconstruction and on the real capture alike.
export const MASK_FILL = '#3c434a';

export function isEditable(node) {
	const v = node.getAttribute('contenteditable');
	return v !== null && v.toLowerCase() !== 'false';
}

/** Built-in marker plus the configured selectors (non-strings and blanks dropped). */
export function sensitiveList(sensitiveSelectors = []) {
	return [SENSITIVE_ATTR, ...(Array.isArray(sensitiveSelectors) ? sensitiveSelectors : [])].filter(
		(s) => typeof s === 'string' && s.trim() !== ''
	);
}

// An invalid selector from the settings is ignored, never fatal.
const safely = (test) => {
	try {
		return test();
	} catch {
		return false;
	}
};

/** True when node itself matches one of the selectors. */
export function matchesAny(node, selectors) {
	return selectors.some((selector) => safely(() => node.matches(selector)));
}

/** True when node or one of its ancestors matches one of the selectors. */
export function closestAny(node, selectors) {
	return selectors.some((selector) => safely(() => Boolean(node.closest(selector))));
}
