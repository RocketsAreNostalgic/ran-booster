import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadFunction(name) {
	const signature = `\tfunction ${name}(`;
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `The ${name} function must exist.`);

	let depth = 0;
	let end = -1;
	for (
		let index = source.indexOf('{', start);
		index < source.length;
		index++
	) {
		if (source[index] === '{') {
			depth += 1;
		} else if (source[index] === '}') {
			depth -= 1;
			if (depth === 0) {
				end = index + 1;
				break;
			}
		}
	}

	assert.notEqual(end, -1, `The ${name} function must be complete.`);

	return Function(
		'__',
		'sprintf',
		`"use strict"; return (${source.slice(start, end)});`
	)(
		(message) => message,
		(template, ...values) => {
			let index = 0;
			return template.replace(/%(?:\d+\$)?[ds]/g, () =>
				String(values[index++])
			);
		}
	);
}

function element(initialAttributes = {}) {
	const attributes = new Map(Object.entries(initialAttributes));
	const classes = new Set();

	return {
		attributes,
		className: '',
		classList: {
			add(...names) {
				names.forEach((name) => classes.add(name));
			},
			remove(...names) {
				names.forEach((name) => classes.delete(name));
			},
			toggle(name, enabled) {
				if (enabled) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			},
			contains(name) {
				return classes.has(name);
			},
		},
		disabled: true,
		hidden: false,
		textContent: '',
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		removeAttribute(name) {
			attributes.delete(name);
		},
		setAttribute(name, value) {
			attributes.set(name, String(value));
		},
	};
}

function rowFixture(id, state = 'queued', packageSource = 'branch') {
	const reference = id.toString(16).padStart(32, '0');
	const row = element({
		'data-attempt-id': String(id),
		'data-attempt-reference': reference,
		'data-attempt-state': state,
		'data-package-source': packageSource,
	});
	const badge = element();
	const button = element({
		'data-idle-label': 'Reinstall plugin',
		'data-update-can-run': '1',
	});
	const label = element();
	const message = element();
	const activityState = element();
	const children = new Map([
		['[data-ran-booster-activity-badge]', badge],
		['[data-ran-booster-update-button]', button],
		['[data-ran-booster-update-label]', label],
		['[data-ran-booster-update-message]', message],
	]);

	row.querySelector = (selector) => children.get(selector) ?? null;
	row.nextElementSibling = {
		querySelector(selector) {
			return selector === '[data-ran-booster-activity-state]'
				? activityState
				: null;
		},
	};

	return {
		activityState,
		badge,
		button,
		label,
		message,
		reference,
		row,
	};
}

function environment(rows, responses) {
	const timers = [];
	const events = [];
	const requests = [];
	let request = 0;
	const summary = element({
		'data-queued': String(rows.length),
		'data-skipped': '0',
	});
	const summaryMessage = element();
	summary.querySelector = (selector) =>
		selector === '[data-ran-booster-update-summary-message]'
			? summaryMessage
			: null;
	const settings = {
		action: 'ran_booster_package_update_progress',
		ajaxUrl: '/wp-admin/admin-ajax.php',
		interval: 1000,
		maxPolls: 10,
		nonce: 'nonce',
		labels: {
			attentionMessage: 'Needs attention.',
			failed: 'Failed',
			failureMessage: 'Reinstall failed.',
			needsAttention: 'Needs attention',
			queued: 'Queued',
			queuedButton: 'Reinstall queued',
			running: 'Running',
			succeeded: 'Succeeded',
			successMessage: 'Package reinstalled.',
			summaryActive:
				'Booster reinstalls are in progress. Queued: {queued}. Reinstalling: {running}. Skipped: {skipped}.',
			summaryFinished:
				'Booster reinstalls have finished. Skipped: {skipped}.',
			unavailableMessage: 'Refresh to check progress.',
			updatingButton: 'Reinstall in progress…',
		},
	};
	const document = {
		dispatchEvent(event) {
			events.push(event);
		},
		hidden: false,
		querySelector(selector) {
			if (selector === '[data-ran-booster-bulk-form]') {
				return {
					getAttribute() {
						return 'plugin';
					},
				};
			}
			if (selector === '[data-ran-booster-update-summary]') {
				return summary;
			}
			return null;
		},
		querySelectorAll(selector) {
			return selector ===
				'[data-ran-booster-package-progress][data-package-source="branch"]'
				? rows
						.filter(
							(item) =>
								item.row.getAttribute('data-package-source') ===
								'branch'
						)
						.map((item) => item.row)
				: [];
		},
	};
	const window = {
		CustomEvent: class {
			constructor(type, options) {
				this.detail = options.detail;
				this.type = type;
			}
		},
		URLSearchParams,
		ranBoosterPackageProgress: settings,
		setTimeout(callback) {
			timers.push(callback);
		},
		async fetch(_url, options) {
			const body = new URLSearchParams(options.body);
			requests.push(body);
			const response = responses[request++];
			if (typeof response === 'function') {
				return response(body);
			}
			if (response instanceof Error) {
				throw response;
			}
			const items = {};
			rows.forEach((item) => {
				const state =
					typeof response === 'string'
						? response
						: response[item.row.getAttribute('data-attempt-id')];
				items[item.row.getAttribute('data-attempt-id')] = {
					attempt_id: Number(
						item.row.getAttribute('data-attempt-id')
					),
					reference: item.reference,
					state,
				};
			});

			return {
				ok: true,
				async json() {
					return { data: { items }, success: true };
				},
			};
		},
	};

	return {
		document,
		events,
		requests,
		summary,
		summaryMessage,
		timers,
		window,
	};
}

async function runTimer(timers) {
	const callback = timers.shift();
	assert.ok(callback, 'A status poll must be scheduled.');
	await callback();
}

test('published-release rows are excluded from branch activity polling', () => {
	const row = rowFixture(6, 'running', 'release_asset');
	const fixture = environment([row], ['succeeded']);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;

	try {
		loadFunction('initPackageUpdateProgress')();

		assert.equal(fixture.timers.length, 0);
		assert.equal(row.badge.textContent, '');
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('running update becomes a visible success and restores its button', async () => {
	const row = rowFixture(7);
	const fixture = environment([row], ['running', 'succeeded']);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;

	try {
		loadFunction('initPackageUpdateProgress')();
		await runTimer(fixture.timers);

		assert.equal(row.button.disabled, true);
		assert.equal(row.button.getAttribute('aria-busy'), 'true');
		assert.equal(row.button.getAttribute('aria-disabled'), 'true');
		assert.equal(
			row.button.classList.contains('ran-booster-update-is-active'),
			true
		);
		assert.equal(row.label.textContent, '');
		assert.equal(row.badge.textContent, 'Deployment: Running');
		assert.equal(
			fixture.summaryMessage.textContent,
			'Booster reinstalls are in progress. Queued: 0. Reinstalling: 1. Skipped: 0.'
		);

		await runTimer(fixture.timers);

		assert.equal(row.button.disabled, false);
		assert.equal(row.button.getAttribute('aria-busy'), null);
		assert.equal(row.button.getAttribute('aria-disabled'), null);
		assert.equal(
			row.button.classList.contains('ran-booster-update-is-active'),
			false
		);
		assert.equal(row.label.textContent, 'Reinstall plugin');
		assert.equal(
			row.badge.className,
			'ran-booster-badge ran-booster-badge--ok'
		);
		assert.equal(row.badge.textContent, 'Deployment: Succeeded');
		assert.equal(row.message.textContent, 'Package reinstalled.');
		assert.equal(
			fixture.summaryMessage.textContent,
			'Booster reinstalls have finished. Skipped: 0.'
		);
		assert.equal(
			fixture.summary.classList.contains('notice-success'),
			true
		);
		assert.equal(fixture.timers.length, 0);
		assert.equal(fixture.events.length, 1);
		assert.equal(
			fixture.events[0].type,
			'ran-booster:admin-mutation-success'
		);
		assert.deepEqual(fixture.events[0].detail, {
			message: 'Package reinstalled.',
		});
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('failure is retryable while needs-attention remains blocked', async () => {
	const failed = rowFixture(8);
	const attention = rowFixture(9);
	const fixture = environment(
		[failed, attention],
		[{ 8: 'failed', 9: 'needs_attention' }]
	);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;

	try {
		loadFunction('initPackageUpdateProgress')();
		await runTimer(fixture.timers);

		assert.equal(failed.button.disabled, false);
		assert.equal(failed.message.textContent, 'Reinstall failed.');
		assert.equal(attention.button.disabled, true);
		assert.equal(attention.label.textContent, 'Needs attention');
		assert.equal(attention.message.textContent, 'Needs attention.');
		assert.equal(
			fixture.summary.classList.contains('notice-warning'),
			true
		);
		assert.equal(fixture.timers.length, 0);
		assert.equal(fixture.events.length, 0);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('repeated transport failure stops polling with an honest status', async () => {
	const row = rowFixture(10);
	const fixture = environment(
		[row],
		[new Error('offline'), new Error('offline'), new Error('offline')]
	);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;

	try {
		loadFunction('initPackageUpdateProgress')();
		await runTimer(fixture.timers);
		await runTimer(fixture.timers);
		await runTimer(fixture.timers);

		assert.equal(row.button.disabled, true);
		assert.equal(row.message.textContent, 'Refresh to check progress.');
		assert.equal(fixture.timers.length, 0);
		assert.equal(fixture.events.length, 0);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('manual update leaves busy ownership to the shared runtime', () => {
	const button = element();
	const label = element();
	button.disabled = false;
	button.querySelector = (selector) =>
		selector === '[data-ran-booster-update-label]' ? label : null;
	const markSubmitting = loadFunction('markPackageUpdateSubmitting');

	assert.equal(markSubmitting(button), true);
	assert.equal(button.disabled, false);
	assert.equal(button.getAttribute('aria-busy'), null);
	assert.equal(button.getAttribute('aria-disabled'), null);
	assert.equal(
		button.classList.contains('ran-booster-update-is-active'),
		false
	);
	assert.equal(label.textContent, '');
	assert.equal(markSubmitting(button), true);
});

test('branch reinstall requires explicit overwrite confirmation', () => {
	const button = element({
		'data-reinstall-confirm-message':
			'Reinstall from the saved branch and overwrite local changes?',
	});
	const messages = [];
	globalThis.window = {
		confirm(message) {
			messages.push(message);
			return false;
		},
	};

	try {
		const confirmReinstall = loadFunction('confirmPackageReinstall');

		assert.equal(confirmReinstall(button), false);
		assert.deepEqual(messages, [
			'Reinstall from the saved branch and overwrite local changes?',
		]);
	} finally {
		delete globalThis.window;
	}
});

function boundedProgressResponse(body, state = 'succeeded') {
	const references = Array.from(body.entries()).filter(([key]) =>
		key.startsWith('attempts[')
	);
	const items = Object.fromEntries(
		references.map(([key, reference]) => {
			const id = key.slice(9, -1);
			return [id, { attempt_id: Number(id), reference, state }];
		})
	);
	return {
		ok: references.length <= 20,
		async json() {
			return { success: references.length <= 20, data: { items } };
		},
	};
}

for (const count of [20, 21, 50]) {
	test(`progress polling covers ${count} rows in requests of at most 20`, async () => {
		const rows = Array.from({ length: count }, (_, i) => rowFixture(i + 1));
		const fixture = environment(
			rows,
			Array(3).fill(boundedProgressResponse)
		);
		globalThis.document = fixture.document;
		globalThis.window = fixture.window;
		try {
			loadFunction('initPackageUpdateProgress')();
			await runTimer(fixture.timers);
			const requested = fixture.requests.flatMap((body) =>
				Array.from(body.keys()).filter((key) =>
					key.startsWith('attempts[')
				)
			);
			assert.deepEqual(
				requested,
				rows.map((_, i) => `attempts[${i + 1}]`)
			);
			assert.ok(fixture.requests.every((body) => body.size - 3 <= 20));
			assert.equal(fixture.events.length, count);
			assert.equal(fixture.timers.length, 0);
		} finally {
			delete globalThis.document;
			delete globalThis.window;
		}
	});
}

test('a later chunk failure retains earlier success and stops before remaining chunks', async () => {
	const rows = Array.from({ length: 50 }, (_, i) => rowFixture(i + 1));
	const fixture = environment(rows, [
		boundedProgressResponse,
		new Error('offline'),
		new Error('offline'),
		new Error('offline'),
	]);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;
	try {
		loadFunction('initPackageUpdateProgress')();
		for (let i = 0; i < 3; i++) {
			await runTimer(fixture.timers);
		}
		assert.equal(fixture.requests.length, 4);
		assert.ok(
			fixture.requests
				.slice(1)
				.every(
					(body) =>
						body.has('attempts[21]') && !body.has('attempts[41]')
				)
		);
		assert.equal(fixture.events.length, 20);
		assert.ok(
			rows
				.slice(0, 20)
				.every(
					(row) => row.message.textContent === 'Package reinstalled.'
				)
		);
		assert.ok(
			rows
				.slice(20)
				.every(
					(row) =>
						row.message.textContent ===
							'Refresh to check progress.' && row.button.disabled
				)
		);
		assert.equal(
			fixture.summary.classList.contains('notice-warning'),
			true
		);
		assert.equal(fixture.timers.length, 0);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('pending successful chunks do not reset repeated later-chunk failures', async () => {
	const rows = Array.from({ length: 21 }, (_, i) => rowFixture(i + 1));
	const pending = (body) => boundedProgressResponse(body, 'running');
	const unavailable = () => ({
		ok: false,
		json: async () => ({ success: false }),
	});
	const fixture = environment(rows, [
		pending,
		unavailable,
		pending,
		unavailable,
		pending,
		unavailable,
	]);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;
	try {
		loadFunction('initPackageUpdateProgress')();
		for (let i = 0; i < 3; i++) {
			await runTimer(fixture.timers);
		}
		assert.equal(fixture.requests.length, 6);
		assert.equal(fixture.events.length, 0);
		assert.equal(fixture.timers.length, 0);
		assert.ok(
			rows.every(
				(row) =>
					row.message.textContent === 'Refresh to check progress.'
			)
		);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('hidden time consumes no poll budget and one visible poll covers every chunk', async () => {
	const rows = Array.from({ length: 50 }, (_, i) => rowFixture(i + 1));
	const pending = (body) => boundedProgressResponse(body, 'running');
	const fixture = environment(rows, Array(3).fill(pending));
	fixture.window.ranBoosterPackageProgress.maxPolls = 1;
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;
	try {
		loadFunction('initPackageUpdateProgress')();
		fixture.document.hidden = true;
		await runTimer(fixture.timers);
		assert.equal(fixture.requests.length, 0);
		fixture.document.hidden = false;
		await runTimer(fixture.timers);
		assert.equal(fixture.requests.length, 3);
		assert.equal(fixture.timers.length, 0);
		assert.equal(fixture.events.length, 0);
		assert.ok(
			rows.every(
				(row) =>
					row.message.textContent === 'Refresh to check progress.'
			)
		);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('chunk responses cannot complete a row with a different correlation reference', async () => {
	const rows = Array.from({ length: 21 }, (_, i) => rowFixture(i + 1));
	const mismatched = (body) => {
		const result = boundedProgressResponse(body);
		const json = result.json;
		result.json = async () => {
			const payload = await json();
			Object.values(payload.data.items).forEach((item) => {
				item.reference = 'f'.repeat(32);
			});
			return payload;
		};
		return result;
	};
	const fixture = environment(rows, [
		boundedProgressResponse,
		mismatched,
		boundedProgressResponse,
	]);
	globalThis.document = fixture.document;
	globalThis.window = fixture.window;
	try {
		loadFunction('initPackageUpdateProgress')();
		await runTimer(fixture.timers);
		assert.equal(fixture.events.length, 20);
		assert.equal(rows[20].row.getAttribute('data-attempt-state'), 'queued');
		await runTimer(fixture.timers);
		assert.equal(fixture.events.length, 21);
		assert.equal(fixture.requests[2].size - 3, 1);
		assert.equal(fixture.timers.length, 0);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});
