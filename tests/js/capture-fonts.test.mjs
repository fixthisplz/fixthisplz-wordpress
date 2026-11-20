import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addFamilies, crossOriginSheets, embedCrossOriginFonts } from '../../assets/js/lib/capture-fonts.js';

/* What a Google Fonts css2 stylesheet looks like: one @font-face per subset, unicode-range on each. */
const GOOGLE_CSS = `
/* cyrillic */
@font-face {
  font-family: 'Roboto';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url(https://fonts.gstatic.com/s/roboto/v1/cyr.woff2) format('woff2');
  unicode-range: U+0301, U+0400-045F, U+0490-0491;
}
/* latin */
@font-face {
  font-family: 'Roboto';
  font-style: normal;
  font-weight: 400;
  src: url(https://fonts.gstatic.com/s/roboto/v1/latin.woff2) format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+2000-206F;
}
@font-face {
  font-family: "Other Font";
  src: url("../fonts/other.woff2") format("woff2"), url("../fonts/other.woff") format("woff");
}
@font-face {
  font-family: Icons;
  src: url(icons.woff2);
}
`;

const FONT_BYTES = new Uint8Array([0x77, 0x4f, 0x46, 0x32]); // "wOF2"
const FONT_B64 = 'd09GMg==';

/**
 * Runs embedCrossOriginFonts against a fake network: `responses` maps a URL to a CSS string or to
 * { bytes, type } (a font). Returns the URLs requested and the CSS appended to the library's <style>.
 */
async function embed({ hrefs, used, responses, styleElement }) {
	const requested = [];
	const appended = [];
	const saved = globalThis.fetch;
	globalThis.fetch = async (url) => {
		requested.push(url);
		const body = responses[url];
		if (body === undefined) throw new TypeError('Failed to fetch');
		return typeof body === 'string'
			? new Response(body, { headers: { 'content-type': 'text/css' } })
			: new Response(body.bytes, { headers: { 'content-type': body.type } });
	};
	try {
		await embedCrossOriginFonts({
			hrefs,
			used,
			styleElement: styleElement === undefined ? { appendChild: (n) => appended.push(n.data) } : styleElement,
			ownerDocument: { createTextNode: (data) => ({ data }) },
		});
	} finally {
		globalThis.fetch = saved;
	}
	return { requested, css: appended.join('') };
}

const GOOGLE = 'https://fonts.googleapis.com/css2?family=Roboto';
const font = (type = 'font/woff2') => ({ bytes: FONT_BYTES, type });

test('addFamilies records every family of a font-family list with the characters drawn, case-folded', () => {
	const used = new Map();
	addFamilies(used, '-apple-system, "Segoe UI", Roboto, sans-serif', 'Ab');
	addFamilies(used, "'Roboto'", 'c');
	addFamilies(used, '', 'ignored');
	addFamilies(used, 'dashicons', '');
	assert.deepEqual([...used.get('roboto')].sort(), ['A', 'b', 'c']);
	assert.ok(used.has('segoe ui') && used.has('-apple-system') && used.has('sans-serif'));
	assert.ok(used.has('dashicons'), 'a family with no text yet is still a used family');
});

test('embedCrossOriginFonts inlines only used faces whose unicode-range covers a drawn character, first source only', async () => {
	const { requested, css } = await embed({
		hrefs: [GOOGLE],
		used: new Map([['roboto', new Set(['B', 'é'])], ['icons', new Set([''])]]),
		responses: { [GOOGLE]: GOOGLE_CSS, 'https://fonts.gstatic.com/s/roboto/v1/latin.woff2': font(), 'https://fonts.googleapis.com/icons.woff2': font() },
	});
	assert.deepEqual(
		requested,
		[GOOGLE, 'https://fonts.gstatic.com/s/roboto/v1/latin.woff2', 'https://fonts.googleapis.com/icons.woff2'],
		'cyrillic subset skipped (no character in range), Other Font unused'
	);
	const rules = css.trim().split('\n');
	assert.equal(rules.length, 2);
	assert.ok(/^@font-face\{[^}]*font-family: 'Roboto'[^}]*font-weight: 400[^}]*unicode-range: U\+0000-00FF[^}]*;src:url\("data:font\/woff2;base64,d09GMg=="\)\}$/.test(rules[0]), rules[0]);
	assert.equal((rules[0].match(/src:/g) || []).length, 1, 'the original source is replaced');
});

test('embedCrossOriginFonts resolves relative font URLs against the stylesheet and requests nothing needlessly', async () => {
	const site = 'https://cdn.example.com/css/site.css';
	const other = await embed({
		hrefs: [site],
		used: new Map([['other font', new Set(['x'])]]),
		responses: { [site]: GOOGLE_CSS, 'https://cdn.example.com/fonts/other.woff2': font() },
	});
	assert.deepEqual(other.requested, [site, 'https://cdn.example.com/fonts/other.woff2']);
	assert.equal((await embed({ hrefs: [site], used: new Map(), responses: {} })).requested.length, 0, 'nothing used');
	const noStyle = await embed({ hrefs: [site], used: new Map([['roboto', new Set(['a'])]]), responses: {}, styleElement: null });
	assert.equal(noStyle.requested.length, 0, 'no style element');
});

test('embedCrossOriginFonts survives an unreachable stylesheet or font', async () => {
	const { css } = await embed({
		hrefs: [GOOGLE, 'https://down.test/x.css'],
		used: new Map([['roboto', new Set(['a'])]]),
		responses: { [GOOGLE]: GOOGLE_CSS }, // the font itself fails
	});
	assert.equal(css, '');
});

test('embedCrossOriginFonts never trusts the server Content-Type: the MIME comes from the file extension', async () => {
	const hostile = 'font/woff2;x="}b{c:d}"';
	const css = `@font-face{font-family:a;src:url(https://f.test/a.woff2)}@font-face{font-family:b;src:url(https://f.test/b.php)}`;
	const { css: out } = await embed({
		hrefs: ['https://f.test/f.css'],
		used: new Map([['a', new Set()], ['b', new Set()]]),
		responses: { 'https://f.test/f.css': css, 'https://f.test/a.woff2': font(hostile), 'https://f.test/b.php': font(hostile) },
	});
	assert.ok(out.includes(`src:url("data:font/woff2;base64,${FONT_B64}")`), out);
	assert.ok(out.includes(`src:url("data:application/octet-stream;base64,${FONT_B64}")`), out);
	assert.ok(!out.includes('b{c:d}') && !out.includes('x='), out);
});

test('embedCrossOriginFonts keeps a well-formed inline data: source whole, without a request, and drops a hostile one', async () => {
	const css =
		'@font-face { font-family: dashicons; src: url("data:application/x-font-woff;charset=utf-8;base64,d09GRgAB") format("woff"), url(x.ttf); font-weight: normal }' +
		// A trailing backslash would escape the closing quote of url("…") and spill into the next rule.
		'@font-face { font-family: evil; src: url(data:font/woff2;base64,QUJD\\) }';
	const { requested, css: out } = await embed({
		hrefs: ['https://cdn.example.com/a.css'],
		used: new Map([['dashicons', new Set()], ['evil', new Set()]]),
		responses: { 'https://cdn.example.com/a.css': css },
	});
	assert.deepEqual(requested, ['https://cdn.example.com/a.css'], 'data: sources are not fetched');
	assert.equal(out.trim(), '@font-face{font-family: dashicons; font-weight: normal;src:url("data:application/x-font-woff;charset=utf-8;base64,d09GRgAB")}');
});

test('crossOriginSheets lists only the stylesheets whose rules the page cannot read', () => {
	const unreadable = {
		href: 'https://fonts.googleapis.com/css2?family=Roboto',
		get cssRules() {
			throw new DOMException('cross-origin', 'SecurityError');
		},
	};
	const sheets = [{ href: 'http://localhost/a.css', cssRules: [] }, unreadable, { href: null, cssRules: [] }];
	assert.deepEqual(crossOriginSheets(sheets), ['https://fonts.googleapis.com/css2?family=Roboto']);
});
