/* Fonts for the DOM-reconstruction screenshot (spec 5.2). Without the page's fonts, text is drawn with a wider
 * fallback inside boxes whose widths were copied from the page: lines wrap and what follows them moves (admin bar
 * badge and avatar dropped into the content). modern-screenshot embeds the @font-face rules it can read, for the
 * families of the text it cloned; this module adds what it misses:
 * - families used only by ::before/::after (icon fonts such as Dashicons), collected with addFamilies();
 * - fonts declared in cross-origin stylesheets (Google Fonts…), whose rules the page cannot read: their CSS is
 *   fetched again (usually from the browser cache), and only the faces of used families whose unicode-range covers a
 *   drawn character are inlined. */

const FONT_FACE_RE = /@font-face\s*\{([^}]*)\}/gi;
const URL_RE = /url\(\s*(['"]?)([^'")]+)\1\s*\)/;
// The whole src declaration: url(), quoted strings and inline data: URLs may hold ';'.
const SRC_RE = /(?:^|;)\s*src\s*:((?:url\([^)]*\)|"[^"]*"|'[^']*'|[^;])*)/i;

/** Same normalisation as the library: unquoted, trimmed, lower case. */
const familyNames = (value) =>
	String(value || '')
		.split(',')
		.map((name) => name.trim().replace(/["']/g, '').toLowerCase())
		.filter(Boolean);

/** Records each family of a font-family list with the characters drawn in it (the family counts even without text). */
export function addFamilies(used, fontFamily, text) {
	for (const family of familyNames(fontFamily)) {
		if (!used.has(family)) {
			used.set(family, new Set());
		}
		const chars = used.get(family);
		for (const char of String(text || '')) {
			chars.add(char);
		}
	}
}

function declaration(block, name) {
	const match = new RegExp(`(?:^|;)\\s*${name}\\s*:([^;]*)`, 'i').exec(block);
	return match ? match[1].trim() : '';
}

function coversAny(unicodeRange, chars) {
	if (!unicodeRange) {
		return true;
	}
	const ranges = unicodeRange.split(',').map((part) => {
		const [from, to = from] = part.trim().replace(/^u\+/i, '').split('-');
		// "U+4??" is a wildcard range.
		return [parseInt(from.replace(/\?/g, '0'), 16), parseInt(to.replace(/\?/g, 'F'), 16)];
	});
	for (const char of chars) {
		const code = char.codePointAt(0);
		if (ranges.some(([a, b]) => code >= a && code <= b)) {
			return true;
		}
	}
	return false;
}

/**
 * @param {string} cssText a stylesheet's text
 * @param {string} baseUrl its URL, for relative font URLs
 * @param {Map<string, Set<string>>} used family => characters drawn
 * @returns {{declarations: string, url: string}[]} the faces to inline: their declarations without `src`, and the
 *   URL of their first source (the format the browser prefers comes first).
 */
function selectFontFaces(cssText, baseUrl, used) {
	const faces = [];
	for (const [, block] of String(cssText || '').matchAll(FONT_FACE_RE)) {
		const families = familyNames(declaration(block, 'font-family')).filter((family) => used.has(family));
		if (!families.length) {
			continue;
		}
		// A family seen with no text (an empty element) cannot pick a subset: every face is kept.
		const chars = families.flatMap((family) => [...used.get(family)]);
		if (chars.length && !coversAny(declaration(block, 'unicode-range'), chars)) {
			continue;
		}
		const srcDeclaration = SRC_RE.exec(block);
		const src = srcDeclaration && URL_RE.exec(srcDeclaration[1]);
		if (!src) {
			continue;
		}
		const declarations = block
			.replace(srcDeclaration[0], ';')
			.split(';')
			.map((d) => d.trim())
			.filter((d) => d && !/^src\s*:/i.test(d))
			.join('; ');
		faces.push({ declarations, url: new URL(src[2], baseUrl).href });
	}
	return faces;
}

// Same bound as the library's own requests: a slow font server must not stall the capture.
const FETCH_TIMEOUT_MS = 5000;

async function fetchOk(url) {
	// bugspotInternal: the loader's fetch hook must not report the capture's own requests as page failures.
	const response = await fetch(url, { bugspotInternal: true, mode: 'cors', credentials: 'omit', signal: AbortSignal.timeout(FETCH_TIMEOUT_MS) });
	if (!response.ok) {
		throw new Error(`HTTP ${response.status}`);
	}
	return response;
}

// The font ends up inside url("…") in a <style>: its type comes from this list, never from the server's
// Content-Type (a crafted one could close the string and the rule), and every data URL must match SAFE_DATA_URL.
const FONT_TYPES = { woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf' };
const SAFE_DATA_URL = /^data:[\w.+/-]+(;[\w.+-]+=[\w.+-]+)*;base64,[A-Za-z0-9+/=]+$/;

async function fetchFontDataUrl(url) {
	const bytes = new Uint8Array(await (await fetchOk(url)).arrayBuffer());
	let binary = '';
	for (let i = 0; i < bytes.length; i += 32768) {
		binary += String.fromCharCode(...bytes.subarray(i, i + 32768));
	}
	const extension = new URL(url).pathname.split('.').pop().toLowerCase();
	return `data:${FONT_TYPES[extension] || 'application/octet-stream'};base64,${btoa(binary)}`;
}

/** URLs of the stylesheets whose rules the page cannot read (cross-origin without CORS). */
export function crossOriginSheets(styleSheets) {
	return Array.from(styleSheets || []).flatMap((sheet) => {
		try {
			void sheet.cssRules;
			return [];
		} catch {
			return sheet.href ? [sheet.href] : [];
		}
	});
}

/**
 * Appends the used faces of those stylesheets, with their font inlined, to the library's <style>. Never rejects:
 * a font that cannot be fetched is drawn with the fallback, as before.
 */
export async function embedCrossOriginFonts({ hrefs, used, styleElement, ownerDocument }) {
	if (!styleElement || !used.size) {
		return;
	}
	await Promise.all(
		hrefs.map(async (href) => {
			try {
				const faces = selectFontFaces(await (await fetchOk(href)).text(), href, used);
				const fonts = await Promise.all(
					faces.map(({ url }) => (url.startsWith('data:') ? url : fetchFontDataUrl(url).catch(() => '')))
				);
				const rules = faces.flatMap(({ declarations }, i) =>
					SAFE_DATA_URL.test(fonts[i]) ? [`@font-face{${declarations};src:url("${fonts[i]}")}`] : []
				);
				if (rules.length) {
					styleElement.appendChild(ownerDocument.createTextNode(`\n${rules.join('\n')}\n`));
				}
			} catch {
				// unreachable stylesheet or font: fallback font for it
			}
		})
	);
}
