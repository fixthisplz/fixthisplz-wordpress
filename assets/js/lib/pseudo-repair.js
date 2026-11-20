/* Pseudo-element repair for the DOM-reconstruction screenshot (spec 5.2), split from capture.js: modern-screenshot
 * copies ::before/::after content as a quote-stripped string; this puts the exact value back on the clone and
 * reports the fonts those pseudo-elements draw with. */

import { addFamilies } from './capture-fonts.js';

const PSEUDO_ATTR = 'data-bugspot-pseudo';
const MASKED_ATTR = 'data-bugspot-masked';
const PSEUDO_ELEMENTS = ['::before', '::after'];
// What the library copies right: a plain string. It writes any value as `content: '<value minus every quote>'`,
// so url("data:…"), alt text ("x" / ""), counter() or attr() would be drawn as literal text.
const isCopiedRight = (value) => !value || value === 'none' || value === 'normal' || /^"[^"'\\]*"$/.test(value);

/**
 * Puts back, on the clone, the exact computed `content` of the ::before/::after the library mangled. enter() gets
 * every source element the filter keeps, leave() every clone from onCloneEachNode (it comes after the subtree, so a
 * stack pairs them); apply() writes the overrides once the tree is masked, into the library's own <style> (a <style>
 * inside the cloned tree doubles the embedding time when it holds images). <iframe> elements are never paired: the
 * library replaces them with the frame's document, whose clones belong to another document.
 */
export function createPseudoRepair({ ownerDocument, getStyle = (node, pseudo) => getComputedStyle(node, pseudo) }) {
	const sources = [];
	const repairs = [];
	// The library records the fonts of an element's own text only: an icon font set on ::before would be left out.
	const fonts = new Map();
	const pairs = (node) => node.nodeType === 1 && node.ownerDocument === ownerDocument && node.tagName !== 'IFRAME';
	return {
		/** Family => characters drawn by the pseudo-elements copied so far. */
		fonts,
		enter(node) {
			if (pairs(node)) {
				sources.push(node);
			}
		},
		leave(clone) {
			if (!pairs(clone) || !sources.length) {
				return; // the root (never filtered) or a frame document
			}
			const source = sources.pop();
			if (clone.getAttribute('class') === source.getAttribute('class')) {
				return; // the library added no pseudo-element class: nothing copied
			}
			for (const pseudo of PSEUDO_ELEMENTS) {
				const { content: value, fontFamily } = getStyle(source, pseudo);
				// Plain strings only (not url()/counter()/attr()), and not "" (clearfix): it would pull every face.
				const drawn = /^"([^"]+)"$/.exec(value || '');
				if (drawn) {
					addFamilies(fonts, fontFamily, drawn[1]);
				}
				if (!isCopiedRight(value)) {
					clone.setAttribute(PSEUDO_ATTR, String(repairs.length));
					repairs.push({ clone, pseudo, value });
				}
			}
		},
		/**
		 * @param hidden clones filled as sensitive: no pseudo-element of theirs, or of their descendants, shows
		 * anything (an explicit pseudo color would otherwise escape the inherited transparent text).
		 */
		apply(styleElement, hidden = []) {
			if (!styleElement || (!repairs.length && !hidden.length)) {
				return;
			}
			const masked = (clone) => hidden.some((h) => h === clone || (h.contains && h.contains(clone)));
			const css = repairs.map(({ clone, pseudo, value }) => {
				const id = clone.getAttribute(PSEUDO_ATTR);
				return `[${PSEUDO_ATTR}="${id}"]${pseudo}{content:${masked(clone) ? '""' : value} !important}`;
			});
			if (hidden.length) {
				hidden.forEach((h) => h.setAttribute(MASKED_ATTR, ''));
				const all = PSEUDO_ELEMENTS.flatMap((p) => [`[${MASKED_ATTR}]${p}`, `[${MASKED_ATTR}] *${p}`]);
				css.push(`${all.join(',')}{content:"" !important}`);
			}
			styleElement.appendChild(ownerDocument.createTextNode(`\n${css.join('\n')}\n`));
		},
	};
}
