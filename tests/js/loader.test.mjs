import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/js/loader.js', import.meta.url), 'utf8');

/** Runs the loader against a fake page whose fetch rejects with `error`; returns the logged network entries. */
async function failedRequests(calls) {
	const context = vm.createContext({ console, URL });
	context.window = context;
	context.location = { href: 'http://site.test/page/' };
	context.document = { readyState: 'loading', addEventListener() {} };
	context.bugSpotterConfig = { collect: { network: true }, restUrl: 'http://site.test/wp-json/bug-spotter/v1/' };
	context.fetch = (input, init) => Promise.reject(Object.assign(new Error('x'), { name: init?.failWith ?? 'TypeError' }));
	vm.runInContext(SOURCE, context);
	for (const [url, init] of calls) {
		await context.fetch(url, init).catch(() => {});
	}
	await new Promise((resolve) => setImmediate(resolve));
	return Array.from(vm.runInContext("globalThis[Symbol.for('bugspot.diagnostics')].network", context), (entry) => entry.url);
}

test('loader logs a page fetch that fails', async () => {
	assert.deepEqual(await failedRequests([['http://cdn.test/a.css', {}]]), ['http://cdn.test/a.css']);
});

test('loader does not log a fetch made by the capture itself', async () => {
	assert.deepEqual(await failedRequests([['http://cdn.test/a.css', { bugspotInternal: true }]]), []);
});

test('loader does not log aborted or timed-out fetches', async () => {
	const calls = [
		['http://cdn.test/abort', { failWith: 'AbortError' }],
		['http://cdn.test/timeout', { failWith: 'TimeoutError' }],
	];
	assert.deepEqual(await failedRequests(calls), []);
});

test('loader stays within its size budget', () => {
	assert.ok(Buffer.byteLength(SOURCE) <= 6144);
});
