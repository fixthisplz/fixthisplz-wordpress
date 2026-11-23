/* Screenshot of the visible part of the page (spec 5.2), the fallback without real capture. Works on
 * modern-screenshot's CLONE only; the plugin UI is filtered out. When the site enables masking (opt-in, decision
 * David 2026-11-20), clone-mask.js masks the clone. This module and the vendored library are both imported lazily,
 * on first use.
 *
 * Speed: the library clones and styles every node it is given. Only boxes that intersect the viewport (and
 * their ancestors) are cloned deeply; any other element is cloned alone (its computed width/height are copied,
 * so the layout does not move) with its children and its images dropped, except inline-level boxes.
 *
 * Fidelity: the page's fonts are embedded (capture-fonts.js), icon fonts of pseudo-elements and cross-origin
 * stylesheets included; text in another font wraps differently and moves what follows it. */

import { encodeJpeg } from './jpeg.js';
import { addFamilies, crossOriginSheets, embedCrossOriginFonts } from './capture-fonts.js';
import { createPseudoRepair } from './pseudo-repair.js';
import { UI_ATTR } from './dom.js';
import { cloneMasker, important } from './clone-mask.js';

// Images already on screen are loaded: do not wait for lazy images further down the page.
const WAIT_FOR_LOAD_MS = 300;
// A slow or dead image must not stall the capture (library default: 30 s).
const ASSET_TIMEOUT_MS = 5000;

let vendorPromise = null;
function loadVendor(vendorUrl) {
	if (!vendorPromise) {
		vendorPromise = import(vendorUrl).catch((error) => {
			vendorPromise = null;
			throw error;
		});
	}
	return vendorPromise;
}

const parentOf = (node) => (node.parentNode && node.parentNode.nodeType === 11 ? node.parentNode.host : node.parentNode);

const SVG_NS = 'http://www.w3.org/2000/svg';
// Referenced through url(#…) or <use>: they have no box, but a visible graphic may need them.
const SVG_DEFINITIONS = 'defs, symbol, linearGradient, lineargradient, radialGradient, radialgradient, clipPath, clippath, mask, filter, pattern, marker';
const isSvg = (node) => Boolean(node && node.nodeType === 1 && node.namespaceURI === SVG_NS);

function markPath(set, from) {
	for (let n = from; n && n.nodeType === 1 && !set.has(n); n = parentOf(n)) {
		set.add(n);
	}
}

/**
 * One read-only pass. visible: boxes that intersect the viewport, plus their ancestors. needed: the path to every
 * <svg> holding definitions (sprites, gradients…), which may sit in a hidden or off-screen container.
 */
function viewportTree(width, height) {
	const visible = new WeakSet();
	const needed = new WeakSet();
	const roots = [document];
	while (roots.length) {
		const root = roots.pop();
		for (const el of root.querySelectorAll('*')) {
			if (el.shadowRoot) {
				roots.push(el.shadowRoot);
			}
			if (el.tagName.toLowerCase() === 'svg' && isSvg(el) && el.querySelector(SVG_DEFINITIONS)) {
				markPath(needed, el);
			}
			if (el.getClientRects().length === 0) {
				continue; // display:none / contents: no box of its own
			}
			const r = el.getBoundingClientRect();
			if (r.right < 0 || r.bottom < 0 || r.left > width || r.top > height) {
				continue;
			}
			markPath(visible, el);
		}
	}
	visible.add(document.documentElement);
	if (document.body) {
		visible.add(document.body);
	}
	return { visible, needed };
}

/**
 * The library's filter. An element whose parent keeps its children but which is itself off-screen is cloned
 * alone (the library copies its computed width/height, so the layout holds): takeShallow() tells the very next
 * onCloneEachNode call that this clone is that lone element, since none of its children get cloned. Inline-level
 * boxes are the exception, cloned whole: emptied, an inline loses its text (the paragraph reflows) and an
 * inline-block sits on its bottom edge instead of its text baseline (the whole line moves down).
 */
export function createCutFilter({ visible, needed, ownerDocument, isInline = (n) => getComputedStyle(n).display.startsWith('inline') }) {
	let shallow = false;
	const whole = new WeakSet();
	const keepsChildren = (n) => visible.has(n) || needed.has(n) || whole.has(n);
	return {
		filter(node) {
			if (node.nodeType === 1 && node.hasAttribute(UI_ATTR)) {
				return false;
			}
			if (node.ownerDocument !== ownerDocument) {
				return true; // same-origin iframe content: rects are not in our coordinates
			}
			const parent = parentOf(node);
			const hasParent = Boolean(parent && parent.nodeType === 1);
			if (hasParent && isSvg(parent)) {
				return true; // SVG content is never cut: <defs>, gradients, clip paths, masks have no box
			}
			if (hasParent && whole.has(parent)) {
				if (node.nodeType === 1) {
					whole.add(node);
				}
				return true;
			}
			if (hasParent && !keepsChildren(parent)) {
				return false; // inside an off-screen box
			}
			if (node.nodeType !== 1) {
				return !hasParent || visible.has(parent);
			}
			if (keepsChildren(node) || isSvg(node)) {
				return true; // on screen, on the way to an SVG sprite, or an <svg> (never cut)
			}
			if (hasParent && !visible.has(parent)) {
				return false; // off-screen content next to a sprite path
			}
			if (isInline(node)) {
				whole.add(node);
				return true;
			}
			shallow = node.tagName !== 'IFRAME';
			return true;
		},
		takeShallow() {
			const was = shallow;
			shallow = false;
			return was;
		},
	};
}

/** A shallow clone of an off-screen element keeps its box but loads nothing. */
function stripResources(clone) {
	for (const attr of ['src', 'srcset', 'poster', 'href', 'xlink:href', 'data']) {
		if (clone.hasAttribute && clone.hasAttribute(attr)) {
			clone.removeAttribute(attr);
		}
	}
	if (clone.style) {
		for (const prop of ['background-image', 'border-image-source', 'list-style-image', 'mask-image', '-webkit-mask-image']) {
			important(clone, prop, 'none');
		}
	}
}

/**
 * Styles of the cloned <html>: pulled up and left by the page scroll with margins (a transform would make fixed
 * boxes scroll away), on top of the page's own <html> margin (WordPress adds margin-top: 32px for the admin bar on
 * the front end: overriding it would slide the content under the bar).
 */
export function rootShift({ scrollX, scrollY, height, marginTop, marginLeft }) {
	const px = (value) => parseFloat(value) || 0;
	return {
		height: 'auto',
		'min-height': `${scrollY + height}px`,
		'margin-top': `${px(marginTop) - scrollY}px`,
		'margin-left': `${px(marginLeft) - scrollX}px`,
		overflow: 'visible',
	};
}

function pageBackground() {
	for (const el of [document.documentElement, document.body]) {
		if (!el) continue;
		const color = getComputedStyle(el).backgroundColor;
		if (color && color !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(color)) {
			return color;
		}
	}
	return '#ffffff';
}

/**
 * @param {{vendorUrl: string, mask?: boolean, sensitiveSelectors?: string[]}} options
 * @returns {Promise<Blob>} JPEG of the viewport, <= 5 MB.
 */
export async function captureVisible({ vendorUrl, mask = false, sensitiveSelectors = [] }) {
	const { createContext, destroyContext, domToCanvas } = await loadVendor(vendorUrl);
	const width = window.innerWidth;
	const height = window.innerHeight;
	const { marginTop, marginLeft } = getComputedStyle(document.documentElement);
	const shift = rootShift({ scrollX: window.scrollX, scrollY: window.scrollY, height, marginTop, marginLeft });
	const cut = createCutFilter({ ...viewportTree(width, height), ownerDocument: document });
	const bodyTransforms = new WeakMap();
	const pseudo = createPseudoRepair({ ownerDocument: document });
	const maskTree = cloneMasker(mask, sensitiveSelectors);

	const context = await createContext(document.documentElement, {
		width,
		height,
		scale: 1,
		timeout: WAIT_FOR_LOAD_MS,
		backgroundColor: pageBackground(),
		// Inner scroll containers keep their position (the document's own scroll is handled below).
		features: { restoreScrollPosition: true },
		// Tagged so the loader's network hook does not log the library's own asset fetches as page failures.
		fetch: { requestInit: { cache: 'force-cache', fixThisPlzInternal: true } },
		filter: (node) => {
			const keep = cut.filter(node);
			if (keep) {
				pseudo.enter(node);
			}
			return keep;
		},
		onCloneEachNode: (clone) => {
			pseudo.leave(clone);
			if (clone.nodeType !== 1) {
				return;
			}
			if (clone.tagName === 'BODY') {
				bodyTransforms.set(clone, clone.style.transform);
			}
			if (cut.takeShallow()) {
				stripResources(clone);
			}
		},
		onCloneNode: async (clone) => {
			const filled = maskTree(clone);
			if (clone.nodeType !== 1) {
				return;
			}
			pseudo.apply(context.svgStyleElement, filled); // filled with the copied pseudo-elements by now
			// The library embeds the readable @font-face rules of these families right after this hook.
			pseudo.fonts.forEach((chars, family) => addFamilies(context.fontFamilies, family, [...chars].join('')));
			await embedCrossOriginFonts({
				hrefs: crossOriginSheets(document.styleSheets),
				used: context.fontFamilies,
				styleElement: context.svgStyleElement,
				ownerDocument: document,
			});
			// restoreScrollPosition also translated <body> by the page scroll: undo it, rootShift() moves the document.
			for (const child of Array.from(clone.children)) {
				if (child.tagName === 'BODY') {
					child.style.transform = bodyTransforms.get(child) || '';
				}
			}
			Object.entries(shift).forEach(([name, value]) => important(clone, name, value));
		},
	});
	context.timeout = ASSET_TIMEOUT_MS; // the short wait above was only for the page's own images

	let canvas;
	try {
		canvas = await domToCanvas(context);
	} finally {
		destroyContext(context);
	}

	return encodeJpeg(canvas);
}
