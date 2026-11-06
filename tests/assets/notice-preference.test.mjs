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
			if (type === 'click') {
				assert.equal(capture, true);
				handler = callback;
			}
		},
		querySelectorAll() {
			return [];
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
			getAttribute() {
				return null;
			},
			setAttribute() {},
			removeAttribute() {},
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

function developmentLifecycle(fetch) {
	const handlers = {};
	let current;
	const heading = {
		attributes: {},
		setAttribute(name, value) {
			this.attributes[name] = value;
		},
		focus() {
			this.ownerDocument.activeElement = this;
		},
	};
	const document = {
		activeElement: null,
		addEventListener(type, callback) {
			(handlers[type] ||= []).push(callback);
		},
		querySelectorAll() {
			return current && !current.removed ? [current] : [];
		},
		getElementById() {
			return heading;
		},
		createElement() {
			return { dataset: {}, setAttribute() {} };
		},
	};
	heading.ownerDocument = document;
	const window = {
		fetch,
		ranBoosterDevelopmentSafetyNotice: {
			ajaxUrl: '/ajax',
			action: 'acknowledge',
			nonce: 'nonce',
			errorMessage: 'Retry',
		},
	};
	const context = { window, document, URLSearchParams, WeakSet, Error };
	function mount(version = 'local-development-v2') {
		const button = {
			attributes: {},
			getAttribute(name) {
				return this.attributes[name] ?? null;
			},
			setAttribute(name, value) {
				this.attributes[name] = value;
			},
			removeAttribute(name) {
				delete this.attributes[name];
			},
			hidden: true,
			style: { display: 'none' },
			ownerDocument: document,
			_disabled: false,
			get disabled() {
				return this._disabled;
			},
			set disabled(value) {
				this._disabled = value;
				if (value && this.ownerDocument.activeElement === this) {
					this.ownerDocument.activeElement = null;
				}
			},
			dataset: {},
			closest: () => current,
		};
		const notice = {
			ownerDocument: document,
			dataset: { ranBoosterNoticeState: version },
			removed: false,
			error: null,
			setAttribute() {},
			removeAttribute() {},
			querySelectorAll() {
				return [button];
			},
			querySelector(selector) {
				return selector === '.notice-dismiss' ? button : this.error;
			},
			append(error) {
				this.error = error;
			},
			contains(element) {
				return element === button;
			},
			remove() {
				this.removed = true;
			},
		};
		button.closest = (selector) =>
			selector.includes('development-safety') ? notice : null;
		current = notice;
		return { notice, button };
	}
	function fire(type) {
		for (const handler of handlers[type] || []) {
			handler({});
		}
	}
	function click(button) {
		for (const handler of handlers.click || []) {
			handler({
				target: { closest: () => button },
				preventDefault() {},
				stopImmediatePropagation() {},
			});
		}
	}
	function init() {
		vm.runInNewContext(source, context);
	}
	return { mount, fire, click, init, document, heading, handlers };
}

test('development initialization is once per document and reveals the existing button', () => {
	const state = developmentLifecycle(() => {});
	const { button } = state.mount();
	state.init();
	state.init();
	assert.equal(button.hidden, false);
	assert.equal(button.style.display, '');
	assert.equal(state.handlers.click.length, 1);
	assert.equal(state.handlers['htmx:load'].length, 1);
});

test('confirmed development acknowledgement restores focus and suppresses cached same event', async () => {
	const state = developmentLifecycle(async () => ({
		ok: true,
		json: async () => ({ success: true }),
	}));
	const first = state.mount();
	state.init();
	state.document.activeElement = first.button;
	state.click(first.button);
	await flush();
	assert.equal(first.notice.removed, true);
	assert.equal(state.document.activeElement, state.heading);
	assert.equal(state.heading.attributes.tabindex, '-1');
	const restored = state.mount();
	state.fire('htmx:historyRestore');
	assert.equal(restored.notice.removed, true);
	const newer = state.mount('local-development-v3');
	state.fire('htmx:load');
	assert.equal(newer.notice.removed, false);
	assert.equal(newer.button.hidden, false);
});

test('delayed success affects its original event and does not steal focus', async () => {
	let resolve;
	const state = developmentLifecycle(
		() =>
			new Promise((done) => {
				resolve = done;
			})
	);
	const first = state.mount();
	state.init();
	state.click(first.button);
	const newer = state.mount('local-development-v3');
	const elsewhere = {};
	state.document.activeElement = elsewhere;
	resolve({ ok: true, json: async () => ({ success: true }) });
	await flush();
	assert.equal(first.notice.removed, true);
	assert.equal(newer.notice.removed, false);
	assert.equal(state.document.activeElement, elsewhere);
	state.fire('htmx:historyRestore');
	assert.equal(newer.notice.removed, false);
});

test('failed development acknowledgement remains retryable and is not remembered', async () => {
	let calls = 0;
	const state = developmentLifecycle(async () => ({
		ok: ++calls > 1,
		json: async () => ({ success: calls > 1 }),
	}));
	const first = state.mount();
	state.init();
	state.click(first.button);
	await flush();
	assert.equal(first.notice.removed, false);
	assert.equal(first.notice.error.textContent, 'Retry');
	assert.equal(first.button.disabled, false);
	const restored = state.mount();
	state.fire('htmx:historyRestore');
	assert.equal(restored.notice.removed, false);
	state.click(restored.button);
	await flush();
	assert.equal(restored.notice.removed, true);
});

test('delayed success removes a mounted restore of the same event and restores its focus', async () => {
	let resolve;
	const state = developmentLifecycle(
		() =>
			new Promise((done) => {
				resolve = done;
			})
	);
	const first = state.mount();
	state.init();
	state.click(first.button);
	const restored = state.mount();
	state.fire('htmx:historyRestore');
	state.document.activeElement = restored.button;
	resolve({ ok: true, json: async () => ({ success: true }) });
	await flush();
	assert.equal(first.notice.removed, true);
	assert.equal(restored.notice.removed, true);
	assert.equal(state.document.activeElement, state.heading);
});

test('pending development dismissal retains focus and blocks duplicate activation', async () => {
	let resolve;
	let calls = 0;
	const state = developmentLifecycle(() => {
		calls++;
		return new Promise((done) => {
			resolve = done;
		});
	});
	const { notice, button } = state.mount();
	state.init();
	state.document.activeElement = button;
	state.click(button);
	state.click(button);
	assert.equal(calls, 1);
	assert.equal(button.disabled, false);
	assert.equal(button.getAttribute('aria-disabled'), 'true');
	assert.equal(state.document.activeElement, button);
	resolve({ ok: true, json: async () => ({ success: true }) });
	await flush();
	assert.equal(notice.removed, true);
	assert.equal(state.document.activeElement, state.heading);
	assert.equal(button.getAttribute('aria-disabled'), null);
});
