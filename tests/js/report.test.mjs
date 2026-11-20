import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DESCRIPTION_MAX, buildDraft, toMarkdown, toJson, toText, reportFileName } from '../../assets/js/lib/report.js';

const env = { href: 'https://site.test/page?token=abc#x', title: 'My page', locale: 'fr-FR', timezone: 'Europe/Paris', userAgent: 'UA/1.0', viewport: '1280x720' };
const config = { version: '1.2.3', wpPage: 'page:42' };
const diagnostics = { console: [], network: [] };
const base = { description: 'Button broken', reporterEmail: ' a@b.test ', element: null, diagnostics, env, config };

test('buildDraft builds a redacted bounded context', () => {
	const d = buildDraft(base);
	assert.equal(d.description, 'Button broken');
	assert.equal(d.reporterEmail, 'a@b.test');
	assert.equal(d.context.url, 'https://site.test/page?token=…');
	assert.equal(d.context.plugin_version, '1.2.3');
	assert.equal(d.context.wp_page, 'page:42');
	assert.equal(d.context.user_agent, 'UA/1.0');
	assert.equal(d.context.element, null);
	assert.equal(d.screenshot, false);
	assert.equal(d.number, '');
	assert.ok(!Number.isNaN(Date.parse(d.generatedAt)));
});

test('buildDraft bounds the description by code points without redacting it', () => {
	const d = buildDraft({ ...base, description: 'me@x.test ' + '😀'.repeat(6000) });
	assert.equal(Array.from(d.description).length, DESCRIPTION_MAX);
	assert.ok(d.description.startsWith('me@x.test'));
});

test('buildDraft keeps only last 20 console entries', () => {
	const entries = Array.from({ length: 30 }, (_, i) => ({ level: 'error', message: 'm' + i, at: 't' }));
	const d = buildDraft({ ...base, diagnostics: { console: entries, network: [] } });
	assert.equal(d.context.console.length, 20);
	assert.equal(d.context.console[0].message, 'm10');
	assert.equal(d.context.console[19].message, 'm29');
});

test('buildDraft filters console levels and invalid network entries', () => {
	const d = buildDraft({ ...base, diagnostics: {
		console: [{ level: 'log', message: 'x', at: '' }, { level: 'WARN', message: 'w', at: '' }],
		network: [{ method: 'get1', url: 'https://a.test/x?k=v', status: 500, at: '' }, { method: 'GET', url: 'https://a.test', status: 700, at: '' }],
	} });
	assert.deepEqual(d.context.console.map((e) => e.level), ['warn']);
	assert.equal(d.context.network.length, 1);
	assert.equal(d.context.network[0].method, 'GET');
	assert.equal(d.context.network[0].url, 'https://a.test/x?k=…');
});

test('buildDraft never includes field values', () => {
	for (const tag of ['input', 'textarea', 'select', 'INPUT']) {
		const d = buildDraft({ ...base, element: { selector: '#f', tag, text: 'secret', name: 'Field', rect: { x: 1.9, y: 2, width: 3, height: 4 } } });
		assert.ok(!JSON.stringify(d).includes('secret'), tag);
		assert.equal(d.context.element.text, '');
	}
});

test('buildDraft keeps text of ordinary elements and ints the rect', () => {
	const d = buildDraft({ ...base, element: { selector: 'button.a', tag: 'button', role: 'button', name: 'Go', text: 'Go now', rect: { x: 1.9, y: -2.2, width: 1e9, height: 'x' } } });
	assert.equal(d.context.element.text, 'Go now');
	assert.deepEqual(d.context.element.rect, { x: 1, y: -2, width: 1000000, height: 0 });
});

test('toMarkdown contains description url and element selector', () => {
	const d = buildDraft({ ...base, element: { selector: '#main > a', tag: 'a', text: 'Link' } });
	const md = toMarkdown(d, { description: 'Description' });
	assert.ok(md.includes('Button broken'));
	assert.ok(md.includes('https://site.test/page'));
	assert.ok(md.includes('#main > a'));
	assert.ok(toText(d, {}).includes('#main > a'));
	assert.ok(toMarkdown(buildDraft(base), {}).length > 0);
});

test('toJson round-trips', () => {
	const d = buildDraft(base);
	assert.deepEqual(JSON.parse(toJson(d)), d);
});

test('reportFileName formats 2026-11-19 09:05 as signalement-20261119-0905.md', () => {
	assert.equal(reportFileName(new Date(2026, 10, 19, 9, 5), 'md'), 'signalement-20261119-0905.md');
	assert.equal(reportFileName(new Date(2026, 0, 2, 23, 59), 'json'), 'signalement-20260102-2359.json');
});

test('buildDraft trims like PHP and parses numeric rect strings like is_numeric', () => {
	const d = buildDraft({ ...base, reporterEmail: '\u00a0a@b.test\u00a0', element: { selector: '\uFEFFx\u00a0', tag: 'p', rect: { x: '0x10', y: ' 7 ', width: '1e2', height: '.5' } } });
	assert.equal(d.reporterEmail, '\u00a0a@b.test\u00a0');
	assert.equal(d.context.element.selector, '\uFEFFx\u00a0');
	assert.deepEqual(d.context.element.rect, { x: 0, y: 7, width: 100, height: 0 });
});

const shot = { ...base, screenshot: new Blob(['x']) };

test('buildDraft adds the capture method to the context only when a screenshot is attached', () => {
	assert.deepEqual(buildDraft({ ...shot, capture: { method: 'tab', masked: true } }).context.capture, { method: 'tab', masked: true });
	assert.deepEqual(buildDraft({ ...shot, capture: { method: 'dom', masked: 'yes', extra: 1 } }).context.capture, { method: 'dom', masked: false });
	assert.ok(!('capture' in buildDraft({ ...base, capture: { method: 'tab', masked: false } }).context), 'no screenshot, no capture key');
	assert.ok(!('capture' in buildDraft({ ...shot, capture: { method: 'window', masked: false } }).context), 'unknown method dropped');
	assert.ok(!('capture' in buildDraft({ ...shot, capture: null }).context));
});

test('exports state the capture method and masking, and nothing without a screenshot', () => {
	const labels = { screenshot: 'Capture', captureTab: 'Vraie capture', captureDom: 'Reconstruction', captureMasked: 'masquée' };
	const masked = buildDraft({ ...shot, capture: { method: 'dom', masked: true } });
	assert.match(toMarkdown(masked, labels), /- Capture: Reconstruction \(masquée\)/);
	assert.match(toText(masked, labels), /^ {2}Capture: Reconstruction \(masquée\)$/m);
	assert.deepEqual(JSON.parse(toJson(masked)).context.capture, { method: 'dom', masked: true });
	const plain = buildDraft({ ...shot, capture: { method: 'tab', masked: false } });
	assert.match(toMarkdown(plain), /- Screenshot: Real capture\n/);
	assert.doesNotMatch(toMarkdown(buildDraft(base), labels), /Capture:|Screenshot:/);
	assert.doesNotMatch(toText(buildDraft(base)), /Screenshot:/);
});
