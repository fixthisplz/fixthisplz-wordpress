/* Browser-side redaction. Mirrors includes/Redactor.php rule for rule (same order, same bounds);
 * keep both files in sync. Lengths are counted in code points, like PHP mb_*. */

const VALUE_MASK = '…';
// PCRE \s / \S are ASCII-only without /u; spelled out so JS Unicode spaces behave the same.
const WS = '\\t\\n\\v\\f\\r ';
const STEPS = [
	[new RegExp(`\\bBearer[${WS}]+[^${WS}]+`, 'gi'), 'Bearer [token]'],
	[/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/g, '[token]'],
	// Every repetition bounded on purpose (see Redactor.php). PHP \w under /u (UCP) is letters, numbers, "_",
	// combining marks (Mn, Mc) and connector punctuation (Pc): same set here. Node's Unicode tables can be newer
	// than PCRE's, so JS may mask very slightly more than PHP (safe direction).
	[/[\p{L}\p{N}\p{Mn}\p{Mc}\p{Pc}.+%-]{1,64}@[\p{L}\p{N}\p{Mn}\p{Mc}\p{Pc}-]{1,63}(?:\.[\p{L}\p{N}\p{Mn}\p{Mc}\p{Pc}-]{1,63}){1,20}/gu, '[email]'],
	[/[A-Za-z0-9_-]{32,}/g, '[token]'],
];
const CONTROL_AND_SPACE = /[\x00-\x20\x7f]+/g;
// A lone surrogate is the JS equivalent of invalid UTF-8; replaced by "?" exactly like PHP's mb_scrub.
const LONE_SURROGATE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g;

function scrub(s) {
	return String(s ?? '').replace(LONE_SURROGATE, '?');
}

/** First max code points of s (never splits a surrogate pair). */
export function truncate(s, max) {
	return Array.from(s).slice(0, Math.max(0, max)).join('');
}

/** Redact first, truncate last: a token can never be cut into a visible fragment. Fails closed ('' on error). */
export function redactText(s, max) {
	try {
		let out = scrub(s);
		for (const [pattern, replacement] of STEPS) {
			out = out.replace(pattern, replacement);
		}
		return truncate(out, max);
	} catch {
		return '';
	}
}

const STRICT_UTF8 = new TextDecoder('utf-8', { fatal: true });
const LENIENT_UTF8 = new TextDecoder('utf-8', { fatal: false });

function decodeRun(run) {
	const bytes = Uint8Array.from(run.match(/%([0-9A-Fa-f]{2})/g), (h) => parseInt(h.slice(1), 16));
	try {
		return STRICT_UTF8.decode(bytes);
	} catch {
		// Invalid bytes become "?" like rawurldecode + mb_scrub on the PHP side.
		return LENIENT_UTF8.decode(bytes).replace(/\uFFFD/g, '?');
	}
}

/** Percent-decodes, then drops control characters and whitespace again (a decoded %00 or %20 must not survive). */
function decode(part) {
	return part.replace(/(?:%[0-9A-Fa-f]{2})+/g, decodeRun).replace(CONTROL_AND_SPACE, '');
}

/** parse_url rules: [host]:port or host:port; empty port is dropped; non-numeric or > 65535 rejects. Returns null if rejected. */
function splitHostPort(hostPort) {
	const close = hostPort.startsWith('[') ? hostPort.indexOf(']') : -1;
	const colon = hostPort.indexOf(':', close === -1 ? 0 : close);
	if (colon === -1) {
		return hostPort;
	}
	const host = hostPort.slice(0, colon);
	const port = hostPort.slice(colon + 1);
	if (host === '' || !/^\d*$/.test(port) || Number(port) > 65535) {
		return null;
	}
	return port === '' ? host : `${host}:${port}`;
}

const URL_PATTERN = /^([A-Za-z][A-Za-z0-9+.-]*):\/\/([^/?#]*)([^?#]*)(?:\?([^#]*))?(?:#.*)?$/;

/** Scheme and host required (else ''); credentials, fragment and query values dropped; path/param names decoded. */
export function redactUrl(url, max) {
	try {
		const cleaned = scrub(url).replace(CONTROL_AND_SPACE, '');
		const match = URL_PATTERN.exec(cleaned);
		if (!match) {
			return '';
		}
		const [, scheme, authority, path, query] = match;
		const hostPort = authority.slice(authority.lastIndexOf('@') + 1);
		const normalized = hostPort === '' ? null : splitHostPort(hostPort);
		if (normalized === null) {
			return '';
		}
		let built = `${scheme.toLowerCase()}://${normalized}${decode(path)}`;
		if (query) {
			const pairs = query.split('&').filter((pair) => pair !== '').map((pair) => `${decode(pair.split('=')[0])}=${VALUE_MASK}`);
			if (pairs.length > 0) {
				built += `?${pairs.join('&')}`;
			}
		}
		return redactText(built, max);
	} catch {
		return '';
	}
}
