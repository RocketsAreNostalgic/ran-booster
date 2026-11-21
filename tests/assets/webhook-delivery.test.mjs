import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-webhook-delivery.js', import.meta.url),
	'utf8'
);
const SECTION = '.ran-booster-repository-webhook-section';
const PARTS = [
	'#ran-booster-repository-webhook-automation-heading',
	'.ran-booster-repository-webhook-lifecycle',
	'.ran-booster-repository-webhook-management__result',
];
const STATUS = 'https://example.test/wp-admin/admin-ajax.php?action=status';
const SETTLE = 'https://example.test/wp-admin/admin.php?page=ran-booster';
const PENDING =
	'Webhook set up. Reload to check that its first delivery arrived.';

// A result notice as the panel view prints it while a delivery is awaited.
function notice(section, attributes = {}) {
	const values = new Map(
		Object.entries({
			'data-ran-booster-webhook-listen': STATUS,
			'data-ran-booster-webhook-settle': SETTLE,
			'data-ran-booster-webhook-listen-label':
				'Listening for webhook delivery…',
			'data-ran-booster-ui-notice-intent': 'warning',
			...attributes,
		})
	);
	const target = { textContent: PENDING };
	const node = {
		isConnected: true,
		target,
		getAttribute: (name) => values.get(name) ?? null,
		hasAttribute: (name) => values.has(name),
		removeAttribute: (name) => values.delete(name),
		matches: () => false,
		closest: (selector) => (selector === SECTION ? section : null),
		querySelector(selector) {
			if (selector === '[data-ran-booster-webhook-listen-text]') {
				return target;
			}
			return selector === '[data-ran-booster-ui-notice-content]'
				? { textContent: ` ${target.textContent} ` }
				: null;
		},
		contains: (other) => other === node,
	};
	return node;
}

function install({ readyState = 'complete', feedbackShows = true } = {}) {
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
	const parts = new Map(
		PARTS.map((part) => [
			part,
			{ part, contains: (node) => node?.inPart === part },
		])
	);
	const section = {
		get ownerDocument() {
			return document;
		},
		querySelector(selector) {
			if (selector === PARTS[2] + ' [data-ran-booster-ui-notice]') {
				return parts.get(PARTS[2]).notice ?? null;
			}
			return parts.get(selector) ?? null;
		},
	};
	const document = {
		readyState,
		activeElement: null,
		addEventListener: (name, callback) => {
			(listeners[name] ||= []).push(callback);
		},
		querySelectorAll: (selector) =>
			selector === '[data-ran-booster-webhook-listen]'
				? notices.filter((node) =>
						node.hasAttribute('data-ran-booster-webhook-listen')
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
						selector.startsWith(SECTION + ' ') &&
						!(
							window.missingPart &&
							selector.endsWith(window.missingPart)
						)
							? {
									outerHTML:
										'new ' +
										selector.slice(SECTION.length + 1),
								}
							: null,
				};
			}
		},
		htmx: {
			swap(current, html, spec) {
				swaps.push({ part: current.part, html, spec });
				if (current.part === PARTS[2]) {
					parts.get(PARTS[2]).notice = {
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

	// Runs the next timer at its time, or at a later time when the tab hid it.
	async function tick(lateBy = 0) {
		timers.sort((a, b) => a.at - b.at);
		const timer = timers.shift();
		assert.ok(timer, 'A check is scheduled.');
		clock = timer.at + lateBy;
		timer.callback();
		await flush();
		return timer;
	}

	return {
		add(attributes) {
			const node = notice(section, attributes);
			notices.push(node);
			return node;
		},
		run,
		tick,
		flush,
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
		document,
		window,
	};
}

test('listening shows the waiting text in the one waiting intent and drops its marker', () => {
	const page = install();
	const node = page.add();
	page.run();

	assert.equal(node.target.textContent, 'Listening for webhook delivery…');
	assert.deepEqual(
		page.intents.map(({ intent }) => intent),
		['info']
	);
	assert.equal(node.hasAttribute('data-ran-booster-webhook-listen'), false);
	assert.equal(page.timers.length, 1);
	assert.equal(page.timers[0].delay, 1000);
});

test('no delivery: six record reads at 1, 2, 3, 5, 7 and 10 seconds, then the pending sentence and a warning pointer', async () => {
	const page = install();
	const node = page.add();
	page.run();
	for (let i = 0; i < 6; i++) {
		await page.tick();
	}

	assert.deepEqual(
		page.fetches.map(({ url, at }) => [url, at]),
		[1000, 2000, 3000, 5000, 7000, 10000].map((at) => [STATUS, at])
	);
	assert.equal(page.timers.length, 0);
	assert.equal(node.target.textContent, PENDING);
	assert.deepEqual(
		page.intents.map(({ intent }) => intent),
		['info', 'warning']
	);
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

test('a tab hidden past the window makes one late read, then settles', async () => {
	const page = install();
	page.add();
	page.run();
	await page.tick(60000);
	await page.tick();

	assert.deepEqual(
		page.fetches.map(({ at }) => at),
		[61000, 61000]
	);
	assert.equal(page.timers.length, 0);
	assert.equal(page.pointers.length, 1);
});

test('a confirmed record renders the panel once, swaps three parts and announces the result once', async () => {
	const page = install();
	const node = page.add();
	page.responses.push(
		{ ok: true, json: { success: true, data: { delivered: false } } },
		{ ok: true, json: { success: true, data: { delivered: true } } }
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
	// The swap replaced the result, so the waiting text is not put back.
	assert.equal(node.target.textContent, 'Listening for webhook delivery…');
	// Focus was outside the replaced parts, so it stays where it was.
	assert.deepEqual(page.focused, []);
});

test('focus inside a replaced part moves to the new result', async () => {
	const page = install();
	page.add();
	page.document.activeElement = { inPart: PARTS[1] };
	page.responses.push({
		ok: true,
		json: { success: true, data: { delivered: true } },
	});
	page.run();
	await page.tick();

	assert.equal(page.swaps.length, 3);
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
	page.responses.push({
		ok: true,
		json: { success: true, data: { delivered: true } },
	});
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
	assert.equal(node.target.textContent, PENDING);
});

test('a failed status read stops at once with the pending sentence', async () => {
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
		assert.equal(node.target.textContent, PENDING);
		assert.equal(page.pointers.length, 1);
	}
});

test('a settle render that fails or lacks a part keeps the pending sentence', async () => {
	for (const setup of [
		(page) =>
			page.responses.push(
				{
					ok: true,
					json: { success: true, data: { delivered: true } },
				},
				{ ok: false, text: '' }
			),
		(page) => {
			page.responses.push({
				ok: true,
				json: { success: true, data: { delivered: true } },
			});
			page.window.missingPart = PARTS[1];
		},
	]) {
		const page = install();
		const node = page.add();
		setup(page);
		page.run();
		await page.tick();

		assert.deepEqual(page.swaps, []);
		assert.equal(node.target.textContent, PENDING);
		assert.equal(page.pointers.length, 1);
	}
});

test('leaving the panel stops listening silently', async () => {
	for (const leave of [
		(page, node) =>
			page.listeners['htmx:beforeCleanupElement'].forEach((callback) =>
				callback({ detail: { elt: node } })
			),
		(page) =>
			page.listeners['htmx:beforeHistorySave'].forEach((callback) =>
				callback()
			),
		(page) =>
			page.windowListeners.pagehide.forEach((callback) => callback()),
	]) {
		const page = install();
		const node = page.add();
		page.run();
		leave(page, node);
		await page.flush();

		assert.equal(page.timers.length, 0);
		assert.equal(node.target.textContent, PENDING);
		assert.deepEqual(page.pointers, []);
		assert.deepEqual(page.spoken, []);
	}
});

test('a result removed from the page stops at its next check without a read', async () => {
	const page = install();
	const node = page.add();
	page.run();
	node.isConnected = false;
	await page.tick();

	assert.deepEqual(page.fetches, []);
	assert.deepEqual(page.pointers, []);
});

test('only one result listens: a newer one replaces the earlier', async () => {
	const page = install();
	const first = page.add();
	page.run();
	const second = page.add();
	page.listeners['htmx:load'].forEach((callback) =>
		callback({
			detail: {
				elt: {
					matches: () => false,
					querySelectorAll: () => [second],
				},
			},
		})
	);

	assert.equal(first.target.textContent, PENDING);
	assert.equal(second.target.textContent, 'Listening for webhook delivery…');
	assert.equal(page.timers.length, 1);
	await page.tick();
	assert.equal(page.fetches.length, 1);
});

test('a page that loads twice starts one listener', () => {
	const page = install();
	page.add();
	page.run();
	page.listeners['htmx:load'].forEach((callback) =>
		callback({
			detail: {
				elt: {
					matches: () => false,
					querySelectorAll: () => [],
				},
			},
		})
	);

	assert.equal(page.timers.length, 1);
});
