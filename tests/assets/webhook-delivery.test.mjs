import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-webhook-delivery.js', import.meta.url),
	'utf8'
);
const SECTION = '.ran-booster-repository-webhook-section';
const RESULT = SECTION + ' .ran-booster-repository-webhook-management__result';
const HISTORY = '.ran-booster-repository-detail__history';
const PARTS = [
	SECTION + ' #ran-booster-repository-webhook-automation-heading',
	SECTION + ' .ran-booster-repository-webhook-lifecycle',
	RESULT,
	HISTORY,
];
const LISTEN = 'data-ran-booster-webhook-listen';
const STATUS = 'https://example.test/wp-admin/admin-ajax.php?action=status';
const SETTLE = 'https://example.test/wp-admin/admin.php?page=ran-booster';
const LABEL = 'Listening for webhook delivery…';
const PENDING =
	'Webhook set up. Reload to check that its first delivery arrived.';
const DELIVERED = {
	ok: true,
	json: { success: true, data: { delivered: true } },
};

// A notice node: its attributes, its listening text and its place in the page.
function element(document, values, text) {
	const target = { textContent: text };
	const node = {
		isConnected: false,
		target,
		ownerDocument: document,
		getAttribute: (name) => values.get(name) ?? null,
		hasAttribute: (name) => values.has(name),
		removeAttribute: (name) => values.delete(name),
		matches: () => false,
		querySelector(selector) {
			if (selector === '[data-ran-booster-webhook-listen-text]') {
				return target;
			}
			return selector === '[data-ran-booster-ui-notice-content]'
				? { textContent: ` ${target.textContent} ` }
				: null;
		},
		contains: (other) => other === node,
		cloneNode: () => element(document, new Map(values), target.textContent),
		replaceWith(other) {
			node.isConnected = false;
			other.isConnected = true;
			document.replaced.push([node, other]);
		},
	};
	return node;
}

function install({
	readyState = 'complete',
	feedbackShows = true,
	history = true,
} = {}) {
	let clock = 0;
	let timerId = 0;
	const timers = [];
	const listeners = {};
	const windowListeners = {};
	const fetches = [];
	const responses = [];
	const swaps = [];
	const intents = [];
	const spoken = [];
	const pointers = [];
	const notices = [];
	const focused = [];
	const missing = new Set();
	const parts = new Map(
		PARTS.filter((part) => history || part !== HISTORY).map((part) => [
			part,
			{ part, contains: (node) => node?.inPart === part },
		])
	);
	const document = {
		readyState,
		activeElement: null,
		replaced: [],
		addEventListener: (name, callback) => {
			(listeners[name] ||= []).push(callback);
		},
		querySelector(selector) {
			if (selector === RESULT + ' [data-ran-booster-ui-notice]') {
				return parts.get(RESULT).notice ?? null;
			}
			return parts.get(selector) ?? null;
		},
		querySelectorAll: (selector) =>
			selector === '[' + LISTEN + ']'
				? notices.filter(
						(node) => node.isConnected && node.hasAttribute(LISTEN)
					)
				: [],
	};
	const window = {
		setTimeout(callback, delay) {
			timers.push({ id: ++timerId, at: clock + delay, delay, callback });
			return timerId;
		},
		clearTimeout(id) {
			const index = timers.findIndex((timer) => timer.id === id);
			if (index >= 0) {
				timers.splice(index, 1);
			}
		},
		addEventListener: (name, callback) => {
			(windowListeners[name] ||= []).push(callback);
		},
		fetch(url) {
			fetches.push({ url, at: clock });
			const next =
				responses.shift() ||
				(url === STATUS
					? {
							ok: true,
							json: { success: true, data: { delivered: false } },
						}
					: { ok: true, text: '<html></html>' });
			return Promise.resolve({
				ok: next.ok,
				json: async () => next.json,
				text: async () => next.text,
			});
		},
		DOMParser: class {
			parseFromString() {
				return {
					querySelector: (selector) =>
						PARTS.includes(selector) && !missing.has(selector)
							? { outerHTML: 'new ' + selector }
							: null,
				};
			}
		},
		htmx: {
			swap(current, html, spec) {
				swaps.push({ part: current.part, html, spec });
				if (current.part === RESULT) {
					parts.get(RESULT).notice = {
						querySelector: () => ({
							textContent: '  Signed delivery received ',
						}),
						setAttribute: (name, value) =>
							focused.push(['attribute', name, value]),
						focus: () => focused.push(['focus']),
					};
				}
			},
		},
		ranBoosterNotice: {
			setIntent: (host, intent) => intents.push({ host, intent }),
		},
		ranBoosterAnnounce: (message) => spoken.push(message),
		ranBoosterFeedback: {
			show(message, options) {
				pointers.push({ message, options });
				return feedbackShows;
			},
		},
	};
	const run = () =>
		vm.runInNewContext(source, {
			window,
			document,
			Date: { now: () => clock },
		});

	async function flush() {
		for (let i = 0; i < 20; i++) {
			await new Promise((resolve) => setImmediate(resolve));
		}
	}

	// Runs the next timer at its time, or at a later time when the tab held it.
	async function tick(lateBy = 0) {
		timers.sort((a, b) => a.at - b.at);
		const timer = timers.shift();
		assert.ok(timer, 'A check is scheduled.');
		clock = timer.at + lateBy;
		timer.callback();
		await flush();
		return timer;
	}

	function fire(name, detail = {}) {
		(listeners[name] || []).forEach((callback) => callback({ detail }));
	}

	return {
		// A result notice as the panel view prints it while a delivery is awaited.
		add(attributes = {}) {
			const node = element(
				document,
				new Map(
					Object.entries({
						[LISTEN]: STATUS,
						'data-ran-booster-webhook-settle': SETTLE,
						'data-ran-booster-webhook-listen-label': LABEL,
						'data-ran-booster-ui-notice-intent': 'warning',
						...attributes,
					})
				),
				PENDING
			);
			node.isConnected = true;
			notices.push(node);
			return node;
		},
		// The waiting copy standing in for a notice, if one does.
		waiting: (node) =>
			document.replaced.find(([from]) => from === node)?.[1] ?? null,
		run,
		tick,
		flush,
		fire,
		timers,
		listeners,
		windowListeners,
		fetches,
		responses,
		swaps,
		intents,
		spoken,
		pointers,
		focused,
		missing,
		document,
		window,
	};
}

test('listening puts a waiting copy in the one waiting intent in place of the untouched notice', () => {
	const page = install();
	const node = page.add();
	page.run();
	const waiting = page.waiting(node);

	assert.ok(waiting);
	assert.equal(waiting.isConnected, true);
	assert.equal(waiting.target.textContent, LABEL);
	assert.deepEqual(page.intents, [{ host: waiting, intent: 'info' }]);
	// The server's notice is set aside as rendered, without its marker.
	assert.equal(node.isConnected, false);
	assert.equal(node.target.textContent, PENDING);
	assert.equal(
		node.getAttribute('data-ran-booster-ui-notice-intent'),
		'warning'
	);
	assert.equal(node.hasAttribute(LISTEN), false);
	assert.equal(page.timers.length, 1);
	assert.equal(page.timers[0].delay, 1000);
});

test('a result swapped in is claimed on the swap, before htmx settles and paints it', () => {
	const page = install();
	page.run();
	const node = page.add();
	page.fire('htmx:afterSwap');

	assert.equal(page.waiting(node)?.target.textContent, LABEL);
	assert.equal(page.timers.length, 1);
});

test('a page still parsing is claimed when the script runs, and only once', () => {
	const page = install({ readyState: 'loading' });
	const node = page.add();
	page.run();

	assert.ok(page.waiting(node));
	page.fire('DOMContentLoaded');
	assert.equal(page.document.replaced.length, 1);
	assert.equal(page.timers.length, 1);
});

test('no delivery: six record reads at 1, 2, 3, 5, 7 and 10 seconds, then the server notice returns with a warning pointer', async () => {
	const page = install();
	const node = page.add();
	page.run();
	const waiting = page.waiting(node);
	for (let i = 0; i < 6; i++) {
		await page.tick();
	}

	assert.deepEqual(
		page.fetches.map(({ url, at }) => [url, at]),
		[1000, 2000, 3000, 5000, 7000, 10000].map((at) => [STATUS, at])
	);
	assert.equal(page.timers.length, 0);
	// The notice comes back as the server rendered it, icon and all; it is never redrawn.
	assert.equal(node.isConnected, true);
	assert.equal(waiting.isConnected, false);
	assert.equal(node.target.textContent, PENDING);
	assert.deepEqual(page.intents, [{ host: waiting, intent: 'info' }]);
	assert.equal(page.pointers.length, 1);
	assert.equal(page.pointers[0].message, PENDING);
	assert.equal(page.pointers[0].options.intent, 'warning');
	assert.equal(page.pointers[0].options.target, node);
	// The host's pointer is silent, so the settled sentence is announced once here.
	assert.deepEqual(page.spoken, [PENDING]);
	assert.deepEqual(page.swaps, []);
});

test('without a feedback host the settled pending sentence is announced once', async () => {
	const page = install({ feedbackShows: false });
	page.add();
	page.run();
	for (let i = 0; i < 6; i++) {
		await page.tick();
	}

	assert.deepEqual(page.spoken, [PENDING]);
});

test('a hidden tab delays checks but never restarts the window', async () => {
	const page = install();
	page.add();
	page.run();
	// The first check runs four seconds late, as a throttled hidden tab would, so the missed slots are skipped.
	await page.tick(4000);

	assert.deepEqual(
		page.timers.map(({ at }) => at),
		[7000]
	);
	await page.tick();
	await page.tick();
	assert.deepEqual(
		page.fetches.map(({ at }) => at),
		[5000, 7000, 10000]
	);
	assert.equal(page.timers.length, 0);
	assert.equal(page.pointers.length, 1);
});

test('a check held past the window is the one late read, then the result settles', async () => {
	const page = install();
	const node = page.add();
	page.run();
	// Timers held for twenty seconds.
	await page.tick(19000);

	assert.deepEqual(
		page.fetches.map(({ at }) => at),
		[20000]
	);
	assert.equal(page.timers.length, 0);
	assert.equal(node.isConnected, true);
	assert.equal(page.pointers.length, 1);
});

test('a confirmed record renders the panel once, swaps every part showing the webhook and announces the result once', async () => {
	const page = install();
	const node = page.add();
	page.responses.push(
		{ ok: true, json: { success: true, data: { delivered: false } } },
		DELIVERED
	);
	page.run();
	await page.tick();
	await page.tick();

	assert.deepEqual(
		page.fetches.map(({ url }) => url),
		[STATUS, STATUS, SETTLE]
	);
	assert.deepEqual(
		page.swaps.map(({ part, html }) => [part, html]),
		PARTS.map((part) => [part, 'new ' + part])
	);
	assert.equal(page.swaps[0].spec.swapStyle, 'outerHTML');
	assert.deepEqual(page.spoken, ['Signed delivery received']);
	assert.deepEqual(page.pointers, []);
	assert.equal(page.timers.length, 0);
	// The swap replaced the result, so the server's pending notice is not put back.
	assert.equal(node.isConnected, false);
	// Focus was outside the replaced parts, so it stays where it was.
	assert.deepEqual(page.focused, []);
});

test('a screen without the sidebar history swaps the other parts', async () => {
	const page = install({ history: false });
	page.add();
	page.missing.add(HISTORY);
	page.responses.push(DELIVERED);
	page.run();
	await page.tick();

	assert.deepEqual(
		page.swaps.map(({ part }) => part),
		PARTS.slice(0, 3)
	);
	assert.deepEqual(page.spoken, ['Signed delivery received']);
});

test('focus inside a replaced part moves to the new result', async () => {
	const page = install();
	page.add();
	page.document.activeElement = { inPart: PARTS[1] };
	page.responses.push(DELIVERED);
	page.run();
	await page.tick();

	assert.equal(page.swaps.length, 4);
	assert.deepEqual(page.focused, [
		['attribute', 'tabindex', '-1'],
		['focus'],
	]);
	// Focus reads the result, so it is not announced as well.
	assert.deepEqual(page.spoken, []);
});

test('leaving while a read is in flight ends quietly when it returns', async () => {
	const page = install();
	const node = page.add();
	let release;
	page.responses.push(DELIVERED);
	const fetch = page.window.fetch;
	page.window.fetch = (url) =>
		new Promise((resolve) => {
			release = () => resolve(fetch(url));
		});
	page.run();
	const tick = page.tick();
	await page.flush();
	page.windowListeners.pagehide.forEach((callback) => callback());
	release();
	await tick;

	assert.deepEqual(page.swaps, []);
	assert.deepEqual(page.pointers, []);
	assert.deepEqual(page.spoken, []);
	assert.equal(page.timers.length, 0);
	assert.equal(node.isConnected, true);
});

test('a failed status read stops at once with the server notice', async () => {
	for (const failure of [
		{ ok: false, json: { success: false } },
		{ ok: true, json: { success: false } },
	]) {
		const page = install();
		const node = page.add();
		page.responses.push(failure);
		page.run();
		await page.tick();

		assert.equal(page.fetches.length, 1);
		assert.equal(page.timers.length, 0);
		assert.equal(node.isConnected, true);
		assert.equal(page.pointers.length, 1);
	}
});

test('a settle render that fails or lacks a part keeps the server notice', async () => {
	for (const setup of [
		(page) => page.responses.push(DELIVERED, { ok: false, text: '' }),
		(page) => {
			page.responses.push(DELIVERED);
			page.missing.add(PARTS[1]);
		},
		(page) => {
			// The page shows the history card, but the render lacks it.
			page.responses.push(DELIVERED);
			page.missing.add(HISTORY);
		},
	]) {
		const page = install();
		const node = page.add();
		setup(page);
		page.run();
		await page.tick();

		assert.deepEqual(page.swaps, []);
		assert.equal(node.isConnected, true);
		assert.equal(page.pointers.length, 1);
	}
});

test('leaving the panel stops listening silently and puts the server notice back', async () => {
	for (const leave of [
		(page, node) =>
			page.fire('htmx:beforeCleanupElement', {
				elt: { contains: (other) => other === page.waiting(node) },
			}),
		(page) => page.fire('htmx:beforeHistorySave'),
		(page) =>
			page.windowListeners.pagehide.forEach((callback) => callback()),
	]) {
		const page = install();
		const node = page.add();
		page.run();
		leave(page, node);
		await page.flush();

		assert.equal(page.timers.length, 0);
		assert.equal(node.isConnected, true);
		assert.equal(node.target.textContent, PENDING);
		assert.deepEqual(page.pointers, []);
		assert.deepEqual(page.spoken, []);
	}
});

test('a result removed from the page stops at its next check without a read', async () => {
	const page = install();
	const node = page.add();
	page.run();
	page.waiting(node).isConnected = false;
	await page.tick();

	assert.deepEqual(page.fetches, []);
	assert.deepEqual(page.pointers, []);
});

test('only one result listens: a newer one replaces the earlier', async () => {
	const page = install();
	const first = page.add();
	page.run();
	const second = page.add();
	page.fire('htmx:load', {
		elt: { matches: () => false, querySelectorAll: () => [second] },
	});

	assert.equal(first.isConnected, true);
	assert.equal(page.waiting(second)?.target.textContent, LABEL);
	assert.equal(page.timers.length, 1);
	await page.tick();
	assert.equal(page.fetches.length, 1);
});

test('a page that loads or swaps again starts one listener', () => {
	const page = install();
	page.add();
	page.run();
	page.fire('htmx:afterSwap');
	page.fire('htmx:load', {
		elt: { matches: () => false, querySelectorAll: () => [] },
	});

	assert.equal(page.document.replaced.length, 1);
	assert.equal(page.timers.length, 1);
});
