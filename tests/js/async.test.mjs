import { test } from 'node:test';
import assert from 'node:assert/strict';
import { withTimeout } from '../../assets/js/lib/async.js';

test('withTimeout lets a fast promise through and rejects with the given error after the deadline', async () => {
	assert.equal(await withTimeout(Promise.resolve('fast'), 50, () => new Error('late')), 'fast');
	await assert.rejects(withTimeout(new Promise(() => {}), 10, () => new Error('late')), /late/);
});
