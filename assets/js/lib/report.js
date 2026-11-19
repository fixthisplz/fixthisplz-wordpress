/* Report draft: builds the exact object that is shown, sent and downloaded, plus its export formats.
 * Bounds mirror includes/Context_Validator.php (spec 5.1). Pure functions except readEnvironment()/download(). */
import { redactText, redactUrl } from './redact.js';

export const DESCRIPTION_MAX = 5000;
const EMAIL_MAX = 254;
const MAX_ENTRIES = 20;
const COORD_LIMIT = 1000000;
const FIELD_TAGS = new Set(['input', 'textarea', 'select']);

// Last-resort English headings; real ones come from config.i18n (same key names).
const FALLBACK_LABELS = {
	reportTitle: 'Bug report',
	description: 'Description',
	email: 'Contact e-mail',
	selectedElement: 'Selected element',
	wholePage: 'Whole page',
	technicalContext: 'Technical details',
	console: 'Console',
	network: 'Failed requests',
	generatedAt: 'Generated at',
};

const scalar = (v) => (typeof v === 'string' ? v : typeof v === 'number' && Number.isFinite(v) ? String(v) : '');
// PHP trim() strips only " \t\n\r\0\x0B", not Unicode spaces or BOM.
const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;
const phpTrim = (s) => s.replace(PHP_TRIM, '');
// PHP is_numeric(): decimal or exponent notation, surrounding whitespace allowed, no hex.
const NUMERIC = /^[ \t\n\r\v\f]*[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?[ \t\n\r\v\f]*$/;
const text = (v, max) => phpTrim(redactText(scalar(v), max));
const truncate = (s, max) => Array.from(s).slice(0, max).join('');

function toInt(v) {
	const n = typeof v === 'string' && NUMERIC.test(v) ? Number(v) : v;
	return typeof n === 'number' && Number.isFinite(n) ? Math.trunc(Math.max(-COORD_LIMIT, Math.min(COORD_LIMIT, n))) : 0;
}

function buildElement(el) {
	if (!el || typeof el !== 'object') {
		return null;
	}
	const tag = text(el.tag, 32);
	const isField = el.isField === true || FIELD_TAGS.has(tag.toLowerCase());
	const rect = el.rect && typeof el.rect === 'object' ? el.rect : {};
	return {
		selector: text(el.selector, 512),
		tag,
		role: text(el.role, 64),
		name: text(el.name, 200),
		text: isField ? '' : text(el.text, 300), // never a field value (spec 5.2)
		rect: { x: toInt(rect.x), y: toInt(rect.y), width: toInt(rect.width), height: toInt(rect.height) },
	};
}

function buildConsole(entries) {
	return (Array.isArray(entries) ? entries : [])
		.filter((e) => e && typeof e === 'object' && ['error', 'warn'].includes(scalar(e.level).toLowerCase()))
		.map((e) => ({ level: scalar(e.level).toLowerCase(), message: text(e.message, 500), at: text(e.at, 32) }))
		.slice(-MAX_ENTRIES);
}

function buildStatus(v) {
	const n = typeof v === 'string' && /^\d{1,3}$/.test(v) ? Number(v) : v;
	return Number.isInteger(n) && n >= 0 && n <= 599 ? n : null;
}

function buildNetwork(entries) {
	const out = [];
	for (const e of Array.isArray(entries) ? entries : []) {
		const status = e && typeof e === 'object' ? buildStatus(e.status) : null;
		if (status === null) {
			continue;
		}
		out.push({
			method: scalar(e.method).replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 10),
			url: redactUrl(scalar(e.url), 512),
			status,
			at: text(e.at, 32),
		});
	}
	return out.slice(-MAX_ENTRIES);
}

/** The reporter's own description is stored as written (bounded only); the context is redacted and bounded. */
export function buildDraft({ description, reporterEmail, element, diagnostics, env, config, screenshot }) {
	const e = env || {};
	const cfg = config || {};
	const diag = diagnostics || {};
	return {
		description: truncate(scalar(description), DESCRIPTION_MAX),
		reporterEmail: truncate(phpTrim(scalar(reporterEmail)), EMAIL_MAX),
		context: {
			url: redactUrl(scalar(e.href), 2048),
			title: text(e.title, 255),
			locale: text(e.locale, 16),
			timezone: text(e.timezone, 64),
			user_agent: text(e.userAgent, 512),
			viewport: text(e.viewport, 32),
			plugin_version: text(cfg.version, 32),
			wp_page: text(cfg.wpPage, 64),
			element: buildElement(element),
			console: buildConsole(diag.console),
			network: buildNetwork(diag.network),
		},
		screenshot: Boolean(screenshot),
		generatedAt: new Date().toISOString(),
		number: '',
	};
}

/** The only function that reads the browser. */
export function readEnvironment() {
	let timezone = '';
	try {
		timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	} catch {
		timezone = '';
	}
	return {
		href: window.location.href,
		title: document.title,
		locale: navigator.language || '',
		timezone,
		userAgent: navigator.userAgent,
		viewport: `${window.innerWidth}x${window.innerHeight}`,
	};
}

const label = (labels, key) => (labels && typeof labels[key] === 'string' && labels[key] !== '' ? labels[key] : FALLBACK_LABELS[key]);
const inline = (v) => `\`${String(v).replace(/`/g, "'")}\``;

function elementLines(draft, labels, code) {
	const el = draft.context.element;
	if (!el) {
		return [label(labels, 'wholePage')];
	}
	const wrap = code ? inline : String;
	const lines = [`selector: ${wrap(el.selector)}`, `tag: ${wrap(el.tag)}`];
	if (el.role) lines.push(`role: ${wrap(el.role)}`);
	if (el.name) lines.push(`name: ${wrap(el.name)}`);
	if (el.text) lines.push(`text: ${wrap(el.text)}`);
	lines.push(`rect: ${el.rect.x},${el.rect.y} ${el.rect.width}x${el.rect.height}`);
	return lines;
}

function contextLines(draft, code) {
	const c = draft.context;
	const wrap = code ? inline : String;
	return ['url', 'title', 'locale', 'timezone', 'user_agent', 'viewport', 'plugin_version', 'wp_page']
		.filter((k) => c[k] !== '')
		.map((k) => `${k}: ${wrap(c[k])}`);
}

function diagnosticLines(draft, labels, heading) {
	const c = draft.context;
	const out = [];
	if (c.console.length > 0) {
		out.push('', heading(label(labels, 'console')), ...c.console.map((e) => `- [${e.level}] ${e.at} ${e.message}`.trimEnd()));
	}
	if (c.network.length > 0) {
		out.push('', heading(label(labels, 'network')), ...c.network.map((e) => `- ${e.method} ${e.url} -> ${e.status} ${e.at}`.trimEnd()));
	}
	return out;
}

export function toMarkdown(draft, labels) {
	const title = draft.number ? `${label(labels, 'reportTitle')} ${draft.number}` : label(labels, 'reportTitle');
	const lines = [`# ${title}`, '', `## ${label(labels, 'description')}`, '', draft.description];
	if (draft.reporterEmail) {
		lines.push('', `## ${label(labels, 'email')}`, '', draft.reporterEmail);
	}
	lines.push('', `## ${label(labels, 'selectedElement')}`, '', ...elementLines(draft, labels, true).map((l) => `- ${l}`));
	lines.push('', `## ${label(labels, 'technicalContext')}`, '', ...contextLines(draft, true).map((l) => `- ${l}`));
	lines.push(...diagnosticLines(draft, labels, (h) => `### ${h}`));
	lines.push('', `_${label(labels, 'generatedAt')} ${draft.generatedAt}_`, '');
	return lines.join('\n');
}

export function toText(draft, labels) {
	const title = draft.number ? `${label(labels, 'reportTitle')} ${draft.number}` : label(labels, 'reportTitle');
	const lines = [title, '', `${label(labels, 'description')}:`, draft.description];
	if (draft.reporterEmail) {
		lines.push('', `${label(labels, 'email')}: ${draft.reporterEmail}`);
	}
	lines.push('', `${label(labels, 'selectedElement')}:`, ...elementLines(draft, labels, false).map((l) => `  ${l}`));
	lines.push('', `${label(labels, 'technicalContext')}:`, ...contextLines(draft, false).map((l) => `  ${l}`));
	lines.push(...diagnosticLines(draft, labels, (h) => `${h}:`));
	lines.push('', `${label(labels, 'generatedAt')} ${draft.generatedAt}`, '');
	return lines.join('\n');
}

export function toJson(draft) {
	return JSON.stringify(draft, null, 2);
}

/** signalement-AAAAMMJJ-HHmm.{ext}, local time. */
export function reportFileName(date, ext) {
	const p = (n) => String(n).padStart(2, '0');
	const stamp = `${date.getFullYear()}${p(date.getMonth() + 1)}${p(date.getDate())}-${p(date.getHours())}${p(date.getMinutes())}`;
	return `signalement-${stamp}.${ext}`;
}

/** Blob + temporary link; the object URL is released after the click has been handled. */
export function download(filename, content, mime) {
	const url = URL.createObjectURL(new Blob([content], { type: mime }));
	const link = document.createElement('a');
	link.href = url;
	link.download = filename;
	link.style.display = 'none';
	document.body.appendChild(link);
	link.click();
	link.remove();
	setTimeout(() => URL.revokeObjectURL(url), 1000);
}
