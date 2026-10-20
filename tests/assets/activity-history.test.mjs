import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);
const filterKey = 'a'.repeat(64);
const otherFilterKey = 'b'.repeat(64);

function cursor(id, key = filterKey) {
	return `v1|${key}|2026-10-12 17:00:00|activity|${id}`;
}

function element(attributes = {}) {
	const listeners = new Map();
	const values = new Map(Object.entries(attributes));
	return {
		children: [],
		dataset: {},
		disabled: false,
		hidden: false,
		textContent: '',
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
		append(...nodes) {
			this.children.push(...nodes);
		},
		focus() {
			this.focused = true;
		},
		getAttribute(name) {
			return values.get(name) ?? null;
		},
		remove() {
			this.removed = true;
		},
		setAttribute(name, value) {
			values.set(name, String(value));
		},
		removeAttribute(name) {
			values.delete(name);
		},
		trigger(type, fields = {}) {
			let prevented = false;
			listeners.get(type)?.({
				currentTarget: this,
				...fields,
				preventDefault() {
					prevented = true;
				},
			});
			return prevented;
		},
	};
}

function activityRow(id, open = false) {
	const row = element({ 'data-ran-booster-activity-row': String(id) });
	row.details = { open };
	return row;
}

function activityRoot({
	cursor: before = cursor(25),
	filter = filterKey,
	hasCursor = true,
	exhausted = false,
	includeList = true,
	rows = Array.from({ length: 25 }, (_, index) => String(index + 1)),
	selectedPackage = '["gh","plugin","example/example.php"]',
	selectedProvider = 'gh',
	unavailable = false,
	hasRunner = false,
} = {}) {
	const list = element();
	list.children = rows.map((id, index) => activityRow(id, index === 0));
	list.querySelectorAll = (selector) =>
		selector === '[data-ran-booster-activity-row]' ? list.children : [];
	const loadOlder = before
		? element({
				href: `https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting&panel=activity&activity_provider=${selectedProvider}&activity_package=${encodeURIComponent(selectedPackage)}&before=${before}`,
			})
		: null;
	if (loadOlder) {
		loadOlder.parentElement = element();
	}
	const progress = element();
	const error = element();
	const announcement = element();
	const exhaustedMessage = exhausted ? element() : null;
	let runner = hasRunner
		? element({
				action: 'https://example.test/wp-admin/admin-post.php',
				method: 'post',
			})
		: null;
	const table = element();
	progress.hidden = true;
	error.hidden = true;
	announcement.hidden = true;
	const provider = { value: selectedProvider };
	const subject = { value: selectedPackage };
	const root = element();
	table.before = function (node) {
		runner = node;
		this.inserted = node;
	};
	root.isConnected = true;
	root.dataset = {
		ranBoosterActivityFilter: filter,
		ranBoosterActivityHasCursor: hasCursor ? '1' : '0',
		ranBoosterActivityPackage: selectedPackage,
		ranBoosterActivityProvider: selectedProvider,
		ranBoosterActivityUnavailable: unavailable ? '1' : '0',
	};
	root.querySelector = (selector) =>
		({
			'[data-ran-booster-activity-list]': includeList ? list : null,
			'[data-ran-booster-activity-load-older]': loadOlder,
			'[data-ran-booster-activity-progress]': progress,
			'[data-ran-booster-activity-error]': error,
			'[data-ran-booster-activity-announcement]': announcement,
			'[data-ran-booster-activity-exhausted]': exhaustedMessage,
			'[data-ran-booster-activity-runner]': runner,
			'.ran-booster-attempt-table': table,
			'[name="activity_provider"]': provider,
			'[name="activity_package"]': subject,
		})[selector] ?? null;
	return {
		announcement,
		error,
		list,
		loadOlder,
		progress,
		provider,
		root,
		get runner() {
			return runner;
		},
		table,
		subject,
	};
}

function responseRoot(options = {}) {
	return activityRoot({
		cursor: cursor(50),
		rows: Array.from({ length: 25 }, (_, index) => String(index + 26)),
		...options,
	});
}

function successfulResponse(responseURL = '', status = 200) {
	return {
		xhr: {
			responseText: '<section data-ran-booster-activity></section>',
			responseURL,
			status,
		},
	};
}

function environment(responses, parsedRoots = [], htmxEnabled = true) {
	const documentListeners = new Map();
	const initial = activityRoot();
	const requests = [];
	const location = new URL(
		'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting&panel=activity'
	);
	const document = {
		activeElement: null,
		readyState: 'loading',
		querySelector(selector) {
			return selector === '[data-ran-booster-activity]'
				? initial.root
				: null;
		},
		addEventListener(type, listener) {
			documentListeners.set(type, [
				...(documentListeners.get(type) ?? []),
				listener,
			]);
		},
		getElementById() {
			return null;
		},
		querySelectorAll() {
			return [];
		},
	};
	initial.loadOlder.ownerDocument = document;
	const removeLoadOlder = initial.loadOlder.parentElement.remove;
	initial.loadOlder.parentElement.remove = function () {
		if (
			initial.loadOlder.ownerDocument.activeElement === initial.loadOlder
		) {
			initial.loadOlder.ownerDocument.activeElement = document;
		}
		removeLoadOlder.call(this);
	};
	const htmx = !htmxEnabled
		? undefined
		: {
				ajax(method, url, context) {
					requests.push({ context, method, url });
					const response = responses.shift();
					return Promise.resolve(
						typeof response === 'function' ? response() : response
					).then((resolved) => {
						if (!resolved.xhr.responseURL) {
							resolved.xhr.responseURL = url;
						}
						context.handler(context.source, resolved);
						return resolved;
					});
				},
			};
	const window = { location };
	const DOMParser = class {
		parseFromString() {
			const root = parsedRoots.shift();
			return {
				querySelector(selector) {
					return selector === '[data-ran-booster-activity]'
						? (root?.root ?? null)
						: null;
				},
				querySelectorAll(selector) {
					return selector === '[data-ran-booster-activity]' &&
						root?.root
						? [root.root]
						: [];
				},
			};
		}
	};
	return {
		DOMParser,
		document,
		documentListeners,
		htmx,
		initial,
		requests,
		window,
	};
}

function initialize(fixture) {
	const context = {
		DOMParser: fixture.DOMParser,
		URL,
		document: fixture.document,
		window: fixture.window,
		wp: {
			i18n: {
				__(text) {
					return text;
				},
			},
		},
	};
	if (fixture.htmx) {
		context.htmx = fixture.htmx;
	}
	context.globalThis = context;
	vm.runInNewContext(source, context);
	for (const listener of fixture.documentListeners.get('DOMContentLoaded') ??
		[]) {
		listener();
	}
}

async function settle() {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
}

test('Load older appends the next 25 Activity rows without replacing open details or existing nodes', async () => {
	const fixture = environment(
		[successfulResponse(), successfulResponse()],
		[
			responseRoot(),
			responseRoot({
				cursor: null,
				rows: Array.from({ length: 5 }, (_, index) =>
					String(index + 51)
				),
			}),
		]
	);
	initialize(fixture);
	const firstRow = fixture.initial.list.children[0];

	assert.equal(fixture.initial.loadOlder.trigger('click'), true);
	await settle();
	assert.equal(fixture.initial.loadOlder.trigger('click'), true);
	await settle();

	assert.equal(fixture.initial.list.children.length, 55);
	assert.equal(fixture.initial.list.children[0], firstRow);
	assert.equal(firstRow.details.open, true);
	assert.deepEqual(
		fixture.initial.list.children.map((row) =>
			row.getAttribute('data-ran-booster-activity-row')
		),
		Array.from({ length: 55 }, (_, index) => String(index + 1))
	);
	assert.equal(
		new URL(fixture.requests[0].url).searchParams.get('before'),
		cursor(25)
	);
	assert.equal(
		new URL(fixture.requests[1].url).searchParams.get('before'),
		cursor(50)
	);
	assert.equal(fixture.initial.loadOlder.parentElement.removed, true);
	assert.equal(fixture.requests[0].method, 'GET');
	assert.equal(fixture.requests[0].context.source, fixture.initial.loadOlder);
	assert.equal(fixture.requests[0].context.target, fixture.initial.root);
});

test('Load older ignores a duplicate activation and a late page after live filters changed', async () => {
	let resolveResponse;
	const pending = new Promise((resolve) => {
		resolveResponse = resolve;
	});
	const fixture = environment([pending], [responseRoot()]);
	initialize(fixture);

	assert.equal(fixture.initial.loadOlder.trigger('click'), true);
	assert.equal(fixture.initial.root.getAttribute('aria-busy'), 'true');
	assert.equal(fixture.initial.progress.hidden, false);
	assert.equal(fixture.initial.loadOlder.trigger('click'), true);
	fixture.initial.provider.value = 'bb';
	resolveResponse(successfulResponse());
	await settle();

	assert.equal(fixture.requests.length, 1);
	assert.equal(fixture.initial.list.children.length, 25);
	assert.equal(fixture.initial.root.getAttribute('aria-busy'), 'false');
	assert.equal(fixture.initial.progress.hidden, true);
});

test('Load older preserves rows and offers retry after an HTTP 200 login document lacks Activity', async () => {
	const fixture = environment([successfulResponse()], []);
	initialize(fixture);
	fixture.initial.loadOlder.trigger('click');
	await settle();

	assert.equal(fixture.initial.list.children.length, 25);
	assert.equal(fixture.initial.loadOlder.removed, undefined);
	assert.equal(fixture.initial.error.hidden, false);
	assert.equal(fixture.initial.error.focused, true);
});

test('Load older rejects mismatched, reset, duplicate, and unavailable fragments without changing rows', async () => {
	for (const [http, response, responseUrl = ''] of [
		[500, responseRoot()],
		[200, responseRoot({ cursor: cursor(25) })],
		[200, responseRoot({ cursor: 'garbage' })],
		[200, responseRoot({ exhausted: true })],
		[
			200,
			responseRoot({
				includeList: false,
				cursor: null,
				rows: [],
			}),
		],
		[
			200,
			responseRoot(),
			'https://example.test/wp-admin/admin.php?before=other',
		],
		[200, responseRoot({ selectedProvider: 'bb' })],
		[200, responseRoot({ selectedPackage: '["bb","theme","example"]' })],
		[200, responseRoot({ filter: otherFilterKey })],
		[200, responseRoot({ hasCursor: false })],
		[
			200,
			responseRoot({
				rows: Array.from({ length: 25 }, (_, index) =>
					String(index + 1)
				),
			}),
		],
		[200, responseRoot({ rows: ['26', '26'] })],
		[
			200,
			responseRoot({
				rows: Array.from({ length: 26 }, (_, index) =>
					String(index + 26)
				),
			}),
		],
		[200, responseRoot({ unavailable: true })],
	]) {
		const fixture = environment(
			[successfulResponse(responseUrl, http)],
			[response]
		);
		initialize(fixture);
		fixture.initial.loadOlder.trigger('click');
		await settle();
		assert.equal(fixture.initial.list.children.length, 25);
		assert.equal(fixture.initial.error.hidden, false);
	}
});

test('Load older leaves modified and non-enhanced navigation to the browser', () => {
	const modified = environment([successfulResponse()], [responseRoot()]);
	initialize(modified);
	const listeners = modified.initial.loadOlder;
	assert.equal(listeners.trigger('click', { ctrlKey: true }), false);
	assert.equal(listeners.trigger('click', { metaKey: true }), false);
	assert.equal(listeners.trigger('click', { button: 1 }), false);

	const native = environment([], [], false);
	initialize(native);
	assert.equal(native.initial.loadOlder.trigger('click'), false);
});

test('Load older imports a queued deployment runner once without replacing its native form', async () => {
	const first = responseRoot({ hasRunner: true });
	const second = responseRoot({
		cursor: null,
		hasRunner: true,
		rows: Array.from({ length: 5 }, (_, index) => String(index + 51)),
	});
	const fixture = environment(
		[successfulResponse(), successfulResponse()],
		[first, second]
	);
	initialize(fixture);
	const original = first.runner;
	fixture.initial.loadOlder.trigger('click');
	await settle();

	assert.equal(fixture.initial.runner, original);
	assert.equal(fixture.initial.table.inserted, original);
	assert.equal(original.getAttribute('method'), 'post');
	fixture.initial.loadOlder.trigger('click');
	await settle();
	assert.equal(fixture.initial.runner, original);
	assert.equal(fixture.initial.table.inserted, original);
	assert.equal(fixture.initial.list.children.length, 55);
	assert.equal(fixture.initial.error.hidden, true);
	assert.equal(fixture.requests.length, 2);
});

test('Load older keeps rows and restores retry control after a network failure', async () => {
	const fixture = environment([() => Promise.reject(new Error('offline'))]);
	initialize(fixture);
	fixture.initial.loadOlder.ownerDocument.activeElement =
		fixture.initial.loadOlder;
	fixture.initial.loadOlder.trigger('click');
	await settle();

	assert.equal(fixture.initial.list.children.length, 25);
	assert.equal(fixture.initial.loadOlder.removed, undefined);
	assert.equal(fixture.initial.loadOlder.disabled, false);
	assert.equal(fixture.initial.error.hidden, false);
	assert.equal(fixture.initial.error.focused, true);
});

test('Load older removes its exhausted control and announces the final appended rows', async () => {
	const fixture = environment(
		[successfulResponse()],
		[
			responseRoot({
				cursor: null,
				exhausted: true,
				includeList: false,
				rows: [],
			}),
		]
	);
	initialize(fixture);
	fixture.initial.loadOlder.ownerDocument.activeElement =
		fixture.initial.loadOlder;
	fixture.initial.loadOlder.trigger('click');
	await settle();

	assert.equal(fixture.initial.list.children.length, 25);
	assert.equal(fixture.initial.loadOlder.parentElement.removed, true);
	assert.equal(fixture.initial.announcement.hidden, false);
	assert.equal(fixture.initial.announcement.focused, true);
});
