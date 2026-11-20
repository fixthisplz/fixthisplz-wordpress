/* Promise helpers shared by the capture and captcha modules. */

/** Rejects with error() when promise has not settled after ms; the timer is always cleared. */
export function withTimeout(promise, ms, error) {
	let timer;
	return Promise.race([
		promise,
		new Promise((_, reject) => {
			timer = setTimeout(() => reject(error()), ms);
		}),
	]).finally(() => clearTimeout(timer));
}
