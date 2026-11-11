import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-portability.js', import.meta.url),
	'utf8'
);
const announceSource = fs.readFileSync(
	new URL('../../assets/ran-booster-announce.js', import.meta.url),
	'utf8'
);

function loadFunction(name, translations = {}) {
	const signature = `\tfunction ${name}()`;
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
		`"use strict"; return (${source.slice(start, end)});`
	)((message) => translations[message] || message);
}

function control() {
	const attributes = new Map();
	const listeners = new Map();

	return {
		attributes,
		disabled: false,
		listeners,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
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

function fixture(fetch) {
	const form = control();
	const submit = control();
	const message = control();
	message.hidden = true;
	const messageText = { textContent: '' };
	const noticeUpdates = [];
	const intents = [];
	message.dispatchEvent = (event) => {
		noticeUpdates.push({
			type: event.type,
			bubbles: event.bubbles,
			intent: message.getAttribute('data-ran-booster-ui-notice-intent'),
			text: messageText.textContent,
			hidden: message.hidden,
		});
	};
	const root = {
		querySelector(selector) {
			return (
				{
					'[data-portability-export-form]': form,
					'[data-portability-export-message]': message,
				}[selector] || null
			);
		},
	};
	form.querySelector = (selector) =>
		selector === '[data-portability-export-submit]' ? submit : null;
	message.querySelector = (selector) =>
		selector === '[data-portability-export-message-text]'
			? messageText
			: null;
	const links = [];
	const events = [];
	const spoken = [];
	const politeness = [];

	globalThis.document = {
		dispatchEvent(event) {
			events.push(event);
		},
		body: {
			appendChild(link) {
				links.push(link);
			},
		},
		createElement() {
			return {
				clicks: 0,
				click() {
					this.clicks += 1;
				},
				remove() {},
			};
		},
		querySelector(selector) {
			return selector === '.ran-booster-portability' ? root : null;
		},
	};
	globalThis.FormData = class {
		constructor(value) {
			this.value = value;
			this.entries = new Map();
		}
		append(key, value) {
			this.entries.set(key, value);
		}
	};
	globalThis.window = {
		wp: {
			a11y: {
				speak(text, level) {
					spoken.push(text);
					politeness.push(level);
				},
			},
		},
		CustomEvent: class {
			constructor(type, options) {
				this.detail = options.detail;
				this.bubbles = options.bubbles;
				this.type = type;
			}
		},
		DOMParser: globalThis.DOMParser,
		fetch,
		ranBoosterPortability: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
		},
		ranBoosterNotice: {
			setIntent(host, intent) {
				intents.push({ host, intent });
				host.setAttribute('data-ran-booster-ui-notice-intent', intent);
				return true;
			},
		},
		URL: {
			createObjectURL() {
				return 'blob:blueprint';
			},
			revokeObjectURL() {},
		},
	};

	Function('window', announceSource)(globalThis.window);

	return {
		events,
		form,
		links,
		message,
		messageText,
		noticeUpdates,
		intents,
		politeness,
		spoken,
		submit,
	};
}

function submitEvent(defaultPrevented = false) {
	return {
		defaultPrevented,
		preventDefault() {
			this.defaultPrevented = true;
		},
	};
}

function cleanup() {
	delete globalThis.FormData;
	delete globalThis.document;
	delete globalThis.window;
}

test('the export download handler is initialized with the rest of the portability controls', () => {
	assert.match(source, /initPortabilityExportDownload\(\);/);
});

test('a rejected export stays inline and keeps every package error visible', async () => {
	let request;
	const state = fixture(async (url, options) => {
		request = { url, ...options };
		return {
			headers: { get: () => 'application/json' },
			ok: false,
			json: async () => ({
				data: {
					message:
						'Blueprint export cannot include: Plugin “Dummy” manages its own updates and cannot also be managed by Booster; Theme “Example” manages its own updates and cannot also be managed by Booster. Deselect those packages and try again.',
				},
			}),
		};
	});

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());

		assert.equal(
			request.url,
			'https://example.test/wp-admin/admin-ajax.php'
		);
		assert.equal(request.body.entries.get('response_format'), 'json');
		assert.equal(state.message.hidden, false);
		assert.equal(
			state.messageText.textContent,
			'Blueprint export cannot include: Plugin “Dummy” manages its own updates and cannot also be managed by Booster; Theme “Example” manages its own updates and cannot also be managed by Booster. Deselect those packages and try again.'
		);
		assert.equal(state.links.length, 0);
		assert.equal(state.submit.disabled, false);
	} finally {
		cleanup();
	}
});

test('an unstructured export error uses the safe fallback message', async () => {
	const state = fixture(async () => ({
		headers: { get: () => 'text/html' },
		ok: false,
		json: async () => {
			throw new Error('Not JSON');
		},
	}));

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());

		assert.equal(
			state.messageText.textContent,
			'Booster could not export this Blueprint. Please try again.'
		);
	} finally {
		cleanup();
	}
});

test('a successful export downloads the Blueprint without leaving the Portability page', async () => {
	const state = fixture(async () => ({
		blob: async () => new Blob(['zip']),
		headers: new Headers({
			'content-type': 'application/zip',
			'content-disposition':
				'attachment; filename="20261028-100000_examplecom_ran_booster_blueprint.zip"',
			'X-RAN-Blueprint-Requirement-Warnings': '1',
		}),
		ok: true,
	}));

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());

		assert.equal(state.message.hidden, false);
		assert.equal(state.links.length, 1);
		assert.equal(
			state.links[0].download,
			'20261028-100000_examplecom_ran_booster_blueprint.zip'
		);
		assert.equal((state.links[0].download.match(/\./g) || []).length, 1);
		assert.equal(state.links[0].clicks, 1);
		assert.equal(state.events.length, 1);
		assert.equal(
			state.events[0].type,
			'ran-booster:admin-mutation-success'
		);
		assert.deepEqual(state.events[0].detail, {
			message:
				'Blueprint download started. Some installed requirement declarations could not be read or normalized and were omitted. Review requirements on the destination; this export does not prove exact reproduction.',
		});
		assert.deepEqual(state.intents.at(-1), {
			host: state.message,
			intent: 'warning',
		});
		assert.equal(
			state.messageText.textContent,
			state.events[0].detail.message
		);
		assert.deepEqual(state.spoken, [state.messageText.textContent]);
		assert.deepEqual(state.politeness, ['polite']);
		assert.equal(state.message.getAttribute('role'), null);
		assert.equal(state.message.getAttribute('aria-live'), null);
		assert.equal(state.submit.disabled, false);
	} finally {
		cleanup();
	}
});

test('export status and errors use one spoken path without live-region attributes', async () => {
	let calls = 0;
	const state = fixture(async () =>
		++calls === 1
			? {
					blob: async () => new Blob(['zip']),
					headers: new Headers({ 'content-type': 'application/zip' }),
					ok: true,
				}
			: {
					headers: new Headers({
						'content-type': 'application/json',
					}),
					ok: false,
					json: async () => ({ data: { message: 'Export failed.' } }),
				}
	);
	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());
		assert.equal(state.message.hidden, false);
		assert.deepEqual(state.spoken, ['Blueprint download started.']);
		assert.deepEqual(state.politeness, ['polite']);
		await state.form.listeners.get('submit')(submitEvent());
		assert.equal(state.messageText.textContent, 'Export failed.');
		assert.deepEqual(state.spoken, [
			'Blueprint download started.',
			'Export failed.',
		]);
		assert.deepEqual(state.politeness, ['polite', 'assertive']);
		assert.equal(state.message.getAttribute('role'), null);
		assert.equal(state.message.getAttribute('aria-live'), null);
	} finally {
		cleanup();
	}
});

test('a valid 213-character hostname is retained in the enhanced download', async () => {
	const filename =
		'20261028-100000_' +
		'a'.repeat(200) +
		'b'.repeat(13) +
		'_ran_booster_blueprint.zip';
	const state = fixture(async () => ({
		blob: async () => new Blob(['zip']),
		headers: new Headers({
			'content-type': 'application/zip',
			'content-disposition': `attachment; filename="${filename}"`,
		}),
		ok: true,
	}));

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());
		assert.equal(state.links[0].download, filename);
	} finally {
		cleanup();
	}
});

test('an encoded international hostname is downloaded exactly as the server names it', async () => {
	const filename =
		'20261028-100000_utf8-42c39c434845522e6465_ran_booster_blueprint.zip';
	const state = fixture(async () => ({
		blob: async () => new Blob(['zip']),
		headers: new Headers({
			'content-type': 'application/zip',
			'content-disposition': `attachment; filename="${filename}"`,
		}),
		ok: true,
	}));

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());
		assert.equal(state.links[0].download, filename);
	} finally {
		cleanup();
	}
});

test('an encoded IPv6 hostname is downloaded exactly as the server names it', async () => {
	const filename =
		'20261028-100000_ipv6---ffff-192021_ran_booster_blueprint.zip';
	const state = fixture(async () => ({
		blob: async () => new Blob(['zip']),
		headers: new Headers({
			'content-type': 'application/zip',
			'content-disposition': `attachment; filename="${filename}"`,
		}),
		ok: true,
	}));

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());
		assert.equal(state.links[0].download, filename);
	} finally {
		cleanup();
	}
});

for (const disposition of [
	'',
	'attachment; filename="../../secret.zip"',
	'attachment; filename="20261028-100000_example.com_ran_booster_blueprint.zip"',
	'attachment; filename="20261028-100000_utf8_42f_ran_booster_blueprint.zip"',
	'attachment; filename="20261028-100000_utf8_gg_ran_booster_blueprint.zip"',
	'attachment; filename="20261028-100000_ipv6_--1-x_ran_booster_blueprint.zip"',
	'attachment; filename="20261028-100000_examplecom_ran_booster_blueprint.zip"; filename="other.zip"',
	'attachment; filename="20261028-100000_' +
		'a'.repeat(214) +
		'_ran_booster_blueprint.zip"',
	'attachment; filename="20261028-100000_examplecom_ran_booster_blueprint.zip"\r\nX-Forged: true',
]) {
	test(`an absent or invalid filename header uses the safe fallback: ${JSON.stringify(disposition)}`, async () => {
		const state = fixture(async () => ({
			blob: async () => new Blob(['zip']),
			headers: {
				get(name) {
					return (
						{
							'content-type': 'application/zip',
							'content-disposition': disposition,
						}[name] || null
					);
				},
			},
			ok: true,
		}));

		try {
			loadFunction('initPortabilityExportDownload')();
			await state.form.listeners.get('submit')(submitEvent());
			assert.equal(state.links[0].download, 'ran-booster-blueprint.zip');
			assert.equal(state.links[0].clicks, 1);
		} finally {
			cleanup();
		}
	});
}

test('a client-side password validation failure does not start a second export request', async () => {
	let fetches = 0;
	const state = fixture(async () => {
		fetches += 1;
		throw new Error('The request must not run.');
	});

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent(true));

		assert.equal(fetches, 0);
		assert.equal(state.message.hidden, true);
	} finally {
		cleanup();
	}
});

for (const count of [
	'1',
	'128',
	null,
	'0',
	'-1',
	'129',
	'1.5',
	'01',
	' 1',
	'1x',
	'1e2',
]) {
	test(`requirement warning header ${JSON.stringify(count)} preserves download and reports only valid counts`, async () => {
		const headers = new Headers({ 'content-type': 'application/zip' });
		if (count !== null) {
			headers.set('X-RAN-Blueprint-Requirement-Warnings', count);
		}
		const state = fixture(async () => ({
			blob: async () => new Blob(['zip']),
			headers,
			ok: true,
		}));
		const warning =
			'Some installed requirement declarations could not be read or normalized and were omitted. Review requirements on the destination; this export does not prove exact reproduction.';
		// Fetch Headers normalizes surrounding HTTP whitespace before consumers read it.
		const expectedWarning = ['1', '128', ' 1'].includes(count)
			? warning
			: '';

		try {
			loadFunction('initPortabilityExportDownload')();
			await state.form.listeners.get('submit')(submitEvent());
			assert.equal(state.links.length, 1);
			assert.equal(state.links[0].clicks, 1);
			assert.equal(state.message.hidden, false);
			assert.deepEqual(state.intents.at(-1), {
				host: state.message,
				intent: expectedWarning ? 'warning' : 'success',
			});
			assert.equal(
				state.events[0].detail.message,
				'Blueprint download started.' +
					(expectedWarning ? ' ' + expectedWarning : '')
			);
			assert.equal(
				state.messageText.textContent,
				state.events[0].detail.message
			);
			assert.deepEqual(state.spoken, [state.messageText.textContent]);
			assert.equal(state.submit.disabled, false);
		} finally {
			cleanup();
		}
	});
}

test('export Notice intent updates keep the content node and announcement owner', async () => {
	let calls = 0;
	const state = fixture(async () =>
		++calls === 1
			? {
					blob: async () => new Blob(['zip']),
					headers: new Headers({
						'content-type': 'application/zip',
						'X-RAN-Blueprint-Requirement-Warnings': '1',
					}),
					ok: true,
				}
			: {
					headers: new Headers({
						'content-type': 'application/json',
					}),
					ok: false,
					json: async () => ({ data: { message: 'Export failed.' } }),
				}
	);
	const originalText = state.messageText;
	Object.defineProperty(state.message, 'textContent', {
		set() {
			throw new Error('The Notice content node must remain intact.');
		},
	});
	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());
		assert.equal(state.messageText, originalText);
		assert.equal(
			state.message.getAttribute('data-ran-booster-ui-notice-intent'),
			'warning'
		);
		assert.equal(state.message.hidden, false);
		assert.equal(state.message.getAttribute('aria-live'), null);
		assert.equal(state.spoken.length, 1);
		await state.form.listeners.get('submit')(submitEvent());
		// The static Notice redraws through the helper; nothing remounts it.
		assert.deepEqual(state.noticeUpdates, []);
		assert.deepEqual(state.intents.at(-1), {
			host: state.message,
			intent: 'error',
		});
		assert.equal(originalText.textContent, 'Export failed.');
		assert.equal(state.message.hidden, false);
		assert.equal(state.message.getAttribute('role'), null);
		assert.equal(state.message.getAttribute('aria-live'), null);
		assert.deepEqual(state.politeness, ['polite', 'assertive']);
		assert.equal(state.spoken.at(-1), 'Export failed.');
		assert.equal(state.links.length, 1);
		assert.equal(state.submit.disabled, false);
	} finally {
		cleanup();
	}
});
