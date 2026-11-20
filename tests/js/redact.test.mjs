import { test } from 'node:test';
import assert from 'node:assert/strict';
import { redactText, redactUrl } from '../../assets/js/lib/redact.js';

// Ported 1:1 from tests/php/test-redactor.php (same inputs => same outputs).

test('redactText masks email jwt bearer long tokens', () => {
	const out = redactText('contact jean@ex.test Bearer abc.def eyJa.b.c ' + 'a'.repeat(40), 5000);
	assert.ok(!out.includes('jean@'), 'email leaked');
	assert.ok(!out.includes('abc.def'), 'bearer leaked');
	assert.ok(!out.includes('eyJa'), 'jwt leaked');
	assert.ok(!out.includes('aaaa'), 'long run leaked');
	assert.ok(out.includes('[email]') && out.includes('[token]'));
	assert.ok(out.includes('Bearer [token]'));
});

test('redactText keeps short alphanumeric runs', () => {
	assert.equal(redactText('abc ' + 'b'.repeat(31), 5000), 'abc ' + 'b'.repeat(31));
});

test('redactUrl strips values fragment credentials', () => {
	assert.equal(redactUrl('https://u:p@site.test/p?token=abc&x=1#frag', 2048), 'https://site.test/p?token=…&x=…');
});

test('redactUrl masks email and token in path', () => {
	assert.equal(redactUrl('https://site.test/u/jean@ex.test/' + 'k'.repeat(40), 2048), 'https://site.test/u/[email]/[token]');
});

test('redactUrl returns empty when unparsable', () => {
	assert.equal(redactUrl('not a url', 2048), '');
	assert.equal(redactUrl('javascript:alert(1)', 2048), '');
	assert.equal(redactUrl('http:///nohost', 2048), '');
	assert.equal(redactUrl('/relative/path', 2048), '');
	assert.equal(redactUrl('', 2048), '');
});

test('redactText truncates by code points', () => {
	const out = redactText('é😀'.repeat(3000), 5000);
	assert.equal(Array.from(out).length, 5000);
	assert.equal(out, out.toWellFormed());
});

test('redactText replaces lone surrogates', () => {
	const out = redactText('bad \uD800 chars', 100);
	assert.equal(out, out.toWellFormed());
	assert.equal(out, 'bad ? chars');
});

test('redactText truncates after redaction so no token fragment survives', () => {
	assert.ok(!redactText('x ' + 'a'.repeat(40), 10).includes('aaaa'));
});

test('redactUrl masks percent-encoded email in path', () => {
	assert.equal(redactUrl('https://h.test/path/jean%40ex.test', 2048), 'https://h.test/path/[email]');
});

test('redactUrl masks percent-encoded email in query name', () => {
	assert.equal(redactUrl('https://h.test/p?jean%40ex.test=1&ok=2', 2048), 'https://h.test/p?[email]=…&ok=…');
});

test('redactText hostile email-like input is not lost', () => {
	const out = redactText('Title a@' + 'a.'.repeat(25000), 255);
	assert.ok(out !== '' && out.startsWith('Title '), 'input was dropped');
	assert.ok(Array.from(out).length <= 255);
	const url = redactUrl('https://h.test/a@' + 'a.'.repeat(25000), 2048);
	assert.ok(url.startsWith('https://h.test/'), 'url was dropped');
});

test('redactText handles non-strings and max 0', () => {
	assert.equal(redactText(null, 10), '');
	assert.equal(redactText(12345, 10), '12345');
	assert.equal(redactText('abc', 0), '');
});

// Fix round 1: parity probed against PHP 8.5 / PCRE 10.44.

test('redactText masks e-mails whose local part has combining marks or connector punctuation', () => {
	assert.equal(redactText('je\u0301an@ex.test', 100), '[email]');
	assert.equal(redactText('jean\u203Fx@ex.test', 100), '[email]');
});

test('redactUrl rejects invalid ports and drops an empty one', () => {
	assert.equal(redactUrl('http://h:abc/x', 2048), '');
	assert.equal(redactUrl('http://h:99999/x', 2048), '');
	assert.equal(redactUrl('https://h.test:65536/x', 2048), '');
	assert.equal(redactUrl('http://h:/x', 2048), 'http://h/x');
	assert.equal(redactUrl('http://h:8080/x', 2048), 'http://h:8080/x');
	assert.equal(redactUrl('http://[::1]:8080/x', 2048), 'http://[::1]:8080/x');
	assert.equal(redactUrl('http://[::1]/x', 2048), 'http://[::1]/x');
});

test('redactUrl turns invalid percent-encoded bytes into ?', () => {
	assert.equal(redactUrl('https://h.test/%FF', 2048), 'https://h.test/?');
	assert.equal(redactUrl('https://h.test/%6A%65%61%6E%40%FFex.test', 2048), 'https://h.test/jean@?ex.test');
	assert.equal(redactUrl('https://h.test/%C3%A9', 2048), 'https://h.test/\u00e9');
});
