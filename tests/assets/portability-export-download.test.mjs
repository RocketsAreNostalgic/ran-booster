import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-portability.js', import.meta.url),
	'utf8'
);

function loadFunction(name) {
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

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
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
		CustomEvent: class {
			constructor(type, options) {
				this.detail = options.detail;
				this.type = type;
			}
		},
		DOMParser: globalThis.DOMParser,
		fetch,
		ranBoosterPortability: {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
		},
		URL: {
			createObjectURL() {
				return 'blob:blueprint';
			},
			revokeObjectURL() {},
		},
	};

	return { events, form, links, message, messageText, submit };
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
		headers: { get: () => 'application/zip' },
		ok: true,
	}));

	try {
		loadFunction('initPortabilityExportDownload')();
		await state.form.listeners.get('submit')(submitEvent());

		assert.equal(state.message.hidden, true);
		assert.equal(state.links.length, 1);
		assert.equal(state.links[0].download, 'ran-booster-blueprint.zip');
		assert.equal(state.links[0].clicks, 1);
		assert.equal(state.events.length, 1);
		assert.equal(
			state.events[0].type,
			'ran-booster:admin-mutation-success'
		);
		assert.deepEqual(state.events[0].detail, {
			message: 'Transporter Blueprint download started.',
		});
		assert.equal(state.submit.disabled, false);
	} finally {
		cleanup();
	}
});

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
