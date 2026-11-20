/* Describes the element a visitor designated (spec 5.1 / 5.2). Never reads a field value: only tag, role,
 * accessible name and visible text outside fields, editable regions and sensitive subtrees. */

import { truncate } from './redact.js';
import { FIELD_TAGS, closestAny, isEditable, matchesAny, sensitiveList } from './sensitive.js';

const MASKED = '[masqué]';
// Subtrees whose text is never "visible text" of a selection.
const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'TEXTAREA', 'SELECT', 'INPUT', 'OPTION', 'SVG']);
const STABLE_DATA_ATTRS = ['data-testid', 'data-test-id', 'data-test', 'data-cy', 'data-qa'];
const TEXT_MAX = 300;
const NAME_MAX = 200;
const WALK_LIMIT = 2000;
// ARIA roles whose accessible name comes from their content when no aria-label / aria-labelledby is set.
const NAME_FROM_CONTENT = new Set([
	'link', 'button', 'heading', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'option', 'tab', 'treeitem',
	'cell', 'gridcell', 'columnheader', 'rowheader', 'switch', 'checkbox', 'radio', 'tooltip',
]);

const IMPLICIT_ROLES = {
	A: (el) => (el.hasAttribute('href') ? 'link' : ''),
	BUTTON: () => 'button',
	SELECT: () => 'combobox',
	TEXTAREA: () => 'textbox',
	IMG: () => 'img',
	NAV: () => 'navigation',
	MAIN: () => 'main',
	HEADER: () => 'banner',
	FOOTER: () => 'contentinfo',
	FORM: () => 'form',
	UL: () => 'list',
	OL: () => 'list',
	LI: () => 'listitem',
	TABLE: () => 'table',
	DIALOG: () => 'dialog',
	H1: () => 'heading',
	H2: () => 'heading',
	H3: () => 'heading',
	H4: () => 'heading',
	H5: () => 'heading',
	H6: () => 'heading',
	INPUT: (el) => {
		const type = (el.getAttribute('type') || 'text').toLowerCase();
		const map = { checkbox: 'checkbox', radio: 'radio', button: 'button', submit: 'button', reset: 'button', image: 'button', range: 'slider', search: 'searchbox', number: 'spinbutton' };
		return map[type] || (type === 'hidden' ? '' : 'textbox');
	},
};

const collapse = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();

/** Ids produced by frameworks or counters are useless across page loads: 4+ digits, hex runs, uuids, React ":r1:". */
function isGeneratedId(id) {
	return (
		id === '' ||
		/\d{4,}/.test(id) ||
		/[:\s]/.test(id) ||
		/^\d/.test(id) ||
		/[0-9a-f]{8}-[0-9a-f]{4}-/i.test(id) ||
		(/^[0-9a-f]{8,}$/i.test(id) && /\d/.test(id)) ||
		(/[-_][0-9a-z]{6,}$/i.test(id) && /\d/.test(id.slice(id.search(/[-_][0-9a-z]{6,}$/i))))
	);
}

function escapeCss(value) {
	if (globalThis.CSS && typeof globalThis.CSS.escape === 'function') {
		return globalThis.CSS.escape(value);
	}
	return String(value).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
}

const escapeAttr = (value) => String(value).replace(/["\\]/g, '\\$&');

function stableId(el) {
	const id = el.getAttribute('id');
	return id !== null && !isGeneratedId(id) ? id : null;
}

function segment(el) {
	const tag = el.tagName.toLowerCase();
	const parent = el.parentElement;
	if (!parent || !parent.children) {
		return tag;
	}
	const same = Array.from(parent.children).filter((c) => c.tagName === el.tagName);
	return same.length > 1 ? `${tag}:nth-of-type(${same.indexOf(el) + 1})` : tag;
}

/** tag:nth-of-type path, at most maxDepth segments, anchored on the closest stable id when there is one. */
export function cssPath(el, maxDepth = 5) {
	const parts = [];
	for (let node = el; node && node.nodeType === 1 && parts.length < maxDepth; node = node.parentElement) {
		const id = stableId(node);
		if (id !== null) {
			parts.unshift(`#${escapeCss(id)}`);
			break;
		}
		const tag = node.tagName.toLowerCase();
		if (tag === 'html') {
			break;
		}
		parts.unshift(segment(node));
	}
	return parts.join(' > ');
}

function selectorFor(el) {
	const id = stableId(el);
	if (id !== null) {
		return `#${escapeCss(id)}`;
	}
	for (const attr of STABLE_DATA_ATTRS) {
		const value = el.getAttribute(attr);
		if (value !== null && value !== '' && !isGeneratedId(value)) {
			return `${el.tagName.toLowerCase()}[${attr}="${escapeAttr(value)}"]`;
		}
	}
	return cssPath(el);
}

/** Visible text of a subtree, skipping fields, editable regions, scripts and masking sensitive parts. */
function visibleText(root, selectors) {
	const out = [];
	let budget = WALK_LIMIT;
	const walk = (node) => {
		if (budget-- <= 0) {
			return;
		}
		if (node.nodeType === 3) {
			out.push(node.data ?? node.textContent ?? '');
			return;
		}
		if (node.nodeType !== 1) {
			return;
		}
		// isContentEditable also covers regions that inherit editability from an ancestor.
		if (SKIP_TAGS.has(String(node.tagName).toUpperCase()) || isEditable(node) || node.isContentEditable === true) {
			out.push(' ');
			return;
		}
		if (node !== root && matchesAny(node, selectors)) {
			out.push(` ${MASKED} `);
			return;
		}
		for (const child of Array.from(node.childNodes || [])) {
			walk(child);
		}
	};
	walk(root);
	return collapse(out.join(''));
}

function labelledBy(el, selectors) {
	const ids = el.getAttribute('aria-labelledby');
	const doc = el.ownerDocument;
	if (!ids || !doc || typeof doc.getElementById !== 'function') {
		return '';
	}
	return collapse(
		ids
			.split(/\s+/)
			.map((id) => doc.getElementById(id))
			.filter(Boolean)
			.map((n) => (closestAny(n, selectors) ? MASKED : visibleText(n, selectors)))
			.join(' ')
	);
}

function fieldLabel(el, selectors) {
	const labels = el.labels && el.labels.length ? Array.from(el.labels) : [];
	if (labels.length === 0 && typeof el.closest === 'function') {
		const wrapping = el.closest('label');
		if (wrapping) {
			labels.push(wrapping);
		}
	}
	const text = collapse(labels.map((l) => visibleText(l, selectors)).join(' '));
	return text || collapse(el.getAttribute('placeholder')) || collapse(el.getAttribute('title')) || '';
}

function accessibleName(el, isField, editable, role, selectors) {
	const aria = collapse(el.getAttribute('aria-label')) || labelledBy(el, selectors);
	if (aria) {
		return aria;
	}
	if (isField) {
		// Never the value attribute (for submit buttons it may still be page data).
		return fieldLabel(el, selectors);
	}
	if (NAME_FROM_CONTENT.has(role) && !editable) {
		const content = truncate(visibleText(el, selectors), NAME_MAX);
		if (content) {
			return content;
		}
	}
	return collapse(el.getAttribute('alt')) || collapse(el.getAttribute('title')) || '';
}

function roleOf(el) {
	const explicit = collapse(el.getAttribute('role')).split(' ')[0];
	if (explicit) {
		return explicit;
	}
	const implicit = IMPLICIT_ROLES[String(el.tagName).toUpperCase()];
	return implicit ? implicit(el) : '';
}

function rectOf(el) {
	let r = {};
	try {
		r = el.getBoundingClientRect() || {};
	} catch {
		r = {};
	}
	const n = (v) => (typeof v === 'number' && Number.isFinite(v) ? Math.round(v) : 0);
	return { x: n(r.x ?? r.left), y: n(r.y ?? r.top), width: n(r.width), height: n(r.height) };
}

export function describeElement(el, { sensitiveSelectors } = {}) {
	const selectors = sensitiveList(sensitiveSelectors);
	const tagUpper = String(el.tagName).toUpperCase();
	const isField = FIELD_TAGS.has(tagUpper);
	let editable = !isField && Boolean(el.isContentEditable);
	for (let n = el; !isField && !editable && n && n.nodeType === 1; n = n.parentElement) {
		editable = isEditable(n);
	}
	const sensitive = closestAny(el, selectors);

	const role = roleOf(el);
	// Content typed in an editable region is never a name (spec 5.2).
	let name = accessibleName(el, isField, editable, role, selectors);
	let text = '';
	if (sensitive) {
		text = MASKED;
		name = isField || name === '' ? name : MASKED;
	} else if (!isField && !editable) {
		text = visibleText(el, selectors);
	}

	return {
		selector: selectorFor(el),
		tag: tagUpper.toLowerCase(),
		role,
		name: truncate(name, NAME_MAX),
		text: truncate(text, TEXT_MAX),
		rect: rectOf(el),
	};
}

/** One-line summary of a described element: "role – name" (or the start of its text). */
export function summarizeElement(d) {
	return [d.role || d.tag, d.name || truncate(d.text, 80)].filter(Boolean).join(' – ');
}
