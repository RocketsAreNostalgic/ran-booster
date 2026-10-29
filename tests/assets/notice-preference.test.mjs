import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/notice-preference.js', import.meta.url),
	'utf8'
);
function setup(kind, fetch) {
	let handler;
	const errors = [];
	const buttons = [{ disabled: false }, { disabled: true }];
	const notice = {
		dataset: {
			ranBoosterNoticeState:
				kind === 'development-safety' ? '' : 'signed-event',
			ranBoosterNoticeScope: 'booster',
		},
		attributes: {},
		setAttribute(name, value) {
			this.attributes[name] = value;
		},
		removeAttribute(name) {
			delete this.attributes[name];
		},
		querySelectorAll() {
			return buttons;
		},
		removed: false,
		remove() {
			this.removed = true;
		},
		querySelector() {
			return errors[0];
		},
		append(node) {
			errors.push(node);
		},
	};
	const config = {
		ajaxUrl: '/ajax',
		action: kind,
		nonce: 'nonce',
		errorMessage: 'Localized fallback',
	};
	const document = {
		addEventListener(type, callback, capture) {
			assert.equal(capture, true);
			handler = callback;
		},
		createElement() {
			return { dataset: {}, setAttribute() {} };
		},
	};
	const window = {
		fetch,
		ranBoosterCredentialExpiryNotice: config,
		ranBoosterBackgroundFailureNotice: config,
		ranBoosterDevelopmentSafetyNotice: config,
	};
	vm.runInNewContext(source, {
		window,
		document,
		URLSearchParams,
		WeakSet,
		Error,
	});
	function click(mode) {
		const button = {
			dataset: mode ? { ranBoosterNoticePreferenceMode: mode } : {},
			closest(selector) {
				return selector.includes(kind) ? notice : null;
			},
		};
		const event = {
			target: {
				closest() {
					return button;
				},
			},
			preventDefault() {},
			stopImmediatePropagation() {},
		};
		handler(event);
	}
	return { click, notice, errors, buttons };
}
const flush = () => new Promise((resolve) => setImmediate(resolve));
for (const [kind, mode, expected] of [
	['credential-expiry', undefined, 'dismiss'],
	['credential-expiry', 'snooze', 'snooze'],
	['background-failure', undefined, 'acknowledge'],
	['development-safety', undefined, 'acknowledge'],
]) {
	test(`${kind} ${expected} waits for success and blocks duplicate clicks`, async () => {
		let resolve;
		const requests = [];
		const state = setup(kind, (url, options) => {
			requests.push(options);
			return new Promise((done) => {
				resolve = done;
			});
		});
		state.click(mode);
		state.click(mode);
		assert.equal(requests.length, 1);
		assert.equal(
			new URLSearchParams(requests[0].body).get('mode'),
			expected
		);
		assert.equal(state.notice.removed, false);
		assert.equal(state.notice.attributes['aria-busy'], 'true');
		assert.deepEqual(
			state.buttons.map((item) => item.disabled),
			[true, true]
		);
		const body = new URLSearchParams(requests[0].body);
		if (kind !== 'development-safety') {
			assert.equal(body.get('state'), 'signed-event');
		} else {
			assert.equal(body.has('state'), false);
		}
		if (kind === 'credential-expiry') {
			assert.equal(body.get('scope'), 'booster');
		} else {
			assert.equal(body.has('scope'), false);
		}
		resolve({ ok: true, json: async () => ({ success: true }) });
		await flush();
		assert.equal(state.notice.removed, true);
	});
}
for (const [name, fetch] of [
	[
		'HTTP error',
		async () => ({
			ok: false,
			json: async () => ({
				success: false,
				data: { message: 'Server says no' },
			}),
		}),
	],
	[
		'server error',
		async () => ({
			ok: true,
			json: async () => ({
				success: false,
				data: { message: 'Try again' },
			}),
		}),
	],
	[
		'invalid JSON',
		async () => ({
			ok: false,
			json: async () => {
				throw new Error('parse');
			},
		}),
	],
	[
		'network error',
		async () => {
			throw new Error('Offline');
		},
	],
]) {
	test(`${name} keeps notice visible and enables retry`, async () => {
		let calls = 0;
		const state = setup('credential-expiry', (...args) => {
			calls++;
			return fetch(...args);
		});
		state.click();
		await flush();
		assert.equal(state.notice.removed, false);
		const messages = {
			'HTTP error': 'Server says no',
			'server error': 'Try again',
		};
		assert.equal(
			state.errors[0].textContent,
			messages[name] || 'Localized fallback'
		);
		assert.equal(state.notice.attributes['aria-busy'], undefined);
		assert.deepEqual(
			state.buttons.map((item) => item.disabled),
			[false, true]
		);
		state.click();
		assert.equal(state.errors[0].textContent, '');
		await flush();
		assert.equal(calls, 2);
	});
}
