import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function loadFunction(name, dependencies = {}) {
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
		...Object.keys(dependencies),
		`"use strict"; return (${source.slice(start, end)});`
	)(...Object.values(dependencies));
}

const generateSecureBase64Url = loadFunction('generateSecureBase64Url');
const initSecretVisibility = loadFunction('initSecretVisibility');
const initGeneratedSecretTools = loadFunction('initGeneratedSecretTools', {
	generateSecureBase64Url,
	initSecretVisibility,
});

function control(value = '') {
	const listeners = new Map();
	const attributes = new Map();

	return {
		attributes,
		classList: classes(),
		dataset: {},
		disabled: false,
		focusCount: 0,
		listeners,
		selectCount: 0,
		type: 'button',
		value,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
		focus() {
			this.focusCount += 1;
		},
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		removeAttribute(name) {
			attributes.delete(name);
		},
		select() {
			this.selectCount += 1;
		},
		setAttribute(name, attributeValue) {
			attributes.set(name, String(attributeValue));
		},
	};
}

function classes(...initial) {
	const values = new Set(initial);

	return {
		add(...names) {
			names.forEach((name) => values.add(name));
		},
		contains(name) {
			return values.has(name);
		},
		remove(...names) {
			names.forEach((name) => values.delete(name));
		},
		toggle(name, force) {
			if (force === undefined ? !values.has(name) : force) {
				values.add(name);
				return true;
			}
			values.delete(name);
			return false;
		},
	};
}

function fixture({ clipboard, crypto, passwordValue = '' } = {}) {
	const form = control();
	const credentialChoice = control();
	credentialChoice.checked = true;
	const password = control(passwordValue);
	password.type = 'password';
	const confirmation = control();
	confirmation.type = 'password';
	const visibility = control();
	visibility.dataset = {
		hideLabel: 'Hide password',
		showLabel: 'Show password',
	};
	const visibilityIcon = { classList: classes('dashicons-visibility') };
	const generate = control();
	const copy = control();
	copy.dataset = {
		copiedLabel: 'Password copied',
		copyLabel: 'Copy password',
	};
	const copyIcon = control();
	const copySuccessIcon = control();
	copySuccessIcon.setAttribute('hidden', '');
	const status = {
		classList: classes(),
		dataset: {
			copiedMessage: 'Password copied.',
			copyFailedMessage: 'Clipboard failed; copy manually.',
			generatedMessage: 'Password generated.',
			generationFailedMessage: 'Secure generation unavailable.',
		},
		textContent: '',
	};
	const validation = control();
	validation.dataset = {
		mismatchMessage: 'Passwords do not match.',
		requiredMessage: 'Choose a password.',
	};
	validation.hidden = true;
	const validationMessage = control();
	const details = control();

	globalThis.document = {
		getElementById() {
			return details;
		},
		querySelectorAll(selector) {
			return selector === '[data-portability-export-credential]'
				? [credentialChoice]
				: [];
		},
		querySelector(selector) {
			return (
				{
					'[data-portability-export-form]': form,
					'[data-portability-password]': password,
					'[data-portability-password-confirmation]': confirmation,
					'[data-portability-password-visibility]': visibility,
					'[data-portability-password-visibility-icon]':
						visibilityIcon,
					'[data-portability-password-generate]': generate,
					'[data-portability-password-copy]': copy,
					'[data-portability-password-copy-icon]': copyIcon,
					'[data-portability-password-copy-success-icon]':
						copySuccessIcon,
					'[data-portability-password-status]': status,
					'[data-portability-password-validation]': validation,
					'[data-portability-password-validation-message]':
						validationMessage,
				}[selector] || null
			);
		},
	};
	globalThis.window = {
		btoa(binary) {
			return Buffer.from(binary, 'binary').toString('base64');
		},
		crypto,
		navigator: { clipboard },
	};

	return {
		confirmation,
		copy,
		copyIcon,
		copySuccessIcon,
		credentialChoice,
		form,
		generate,
		password,
		status,
		visibility,
		visibilityIcon,
		validation,
		validationMessage,
	};
}

function initialize(options) {
	const state = fixture(options);
	loadFunction('initPortabilityPasswordTools', {
		initGeneratedSecretTools,
	})();

	return state;
}

function cleanup() {
	delete globalThis.document;
	delete globalThis.window;
}

test('secure generation fills and replaces both password fields', () => {
	let calls = 0;
	const state = initialize({
		crypto: {
			getRandomValues(bytes) {
				assert.ok(bytes instanceof Uint8Array);
				assert.equal(bytes.length, 24);
				bytes.fill(++calls === 1 ? 251 : 0);
				return bytes;
			},
		},
	});

	try {
		assert.equal(state.copy.disabled, true);

		state.generate.listeners.get('click')();
		assert.equal(state.password.value, '-_v7-_v7-_v7-_v7-_v7-_v7-_v7-_v7');
		assert.equal(state.confirmation.value, state.password.value);
		assert.match(state.password.value, /^[A-Za-z0-9_-]{32}$/);
		assert.equal(state.copy.disabled, false);
		assert.equal(state.status.textContent, 'Password generated.');
		assert.equal(
			state.status.classList.contains('screen-reader-text'),
			true
		);
		assert.doesNotMatch(
			state.status.textContent,
			new RegExp(state.password.value)
		);

		state.generate.listeners.get('click')();
		assert.equal(state.password.value, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
		assert.equal(state.confirmation.value, state.password.value);

		const confirmation = state.confirmation.value;
		state.password.value = 'manual-password-value';
		state.password.listeners.get('input')();
		assert.equal(state.confirmation.value, confirmation);
		assert.equal(state.status.textContent, '');
	} finally {
		cleanup();
	}
});

test('copy preserves the exact manual password and tracks empty input', async () => {
	const copied = [];
	const state = initialize({
		clipboard: {
			async writeText(value) {
				copied.push(value);
			},
		},
		crypto: { getRandomValues() {} },
	});

	try {
		state.password.value = '  exact manual password value  ';
		state.password.listeners.get('input')();
		assert.equal(state.copy.disabled, false);

		await state.copy.listeners.get('click')();
		assert.deepEqual(copied, ['  exact manual password value  ']);
		assert.equal(state.status.textContent, 'Password copied.');
		assert.equal(
			state.status.classList.contains('screen-reader-text'),
			true
		);
		assert.equal(state.copy.getAttribute('aria-label'), 'Password copied');
		assert.equal(state.copy.getAttribute('title'), 'Password copied');
		assert.equal(state.copyIcon.getAttribute('hidden'), '');
		assert.equal(state.copySuccessIcon.getAttribute('hidden'), null);
		assert.doesNotMatch(
			state.status.textContent,
			/exact manual password value/
		);

		state.password.value = '';
		state.password.listeners.get('input')();
		assert.equal(state.copy.disabled, true);
		assert.equal(state.status.textContent, '');
		assert.equal(
			state.status.classList.contains('screen-reader-text'),
			false
		);
		assert.equal(state.copy.getAttribute('aria-label'), 'Copy password');
		assert.equal(state.copyIcon.getAttribute('hidden'), null);
		assert.equal(state.copySuccessIcon.getAttribute('hidden'), '');
	} finally {
		cleanup();
	}
});

test('the visibility control reveals and re-masks only the primary field', () => {
	const state = initialize({
		crypto: { getRandomValues() {} },
		passwordValue: 'visible-password-value',
	});

	try {
		assert.equal(state.password.type, 'password');
		assert.equal(state.confirmation.type, 'password');

		state.visibility.listeners.get('click')();
		assert.equal(state.password.type, 'text');
		assert.equal(state.confirmation.type, 'password');
		assert.equal(state.visibility.getAttribute('aria-pressed'), 'true');
		assert.equal(
			state.visibility.getAttribute('aria-label'),
			'Hide password'
		);
		assert.equal(state.visibility.getAttribute('title'), 'Hide password');
		assert.equal(
			state.visibilityIcon.classList.contains('dashicons-hidden'),
			true
		);
		assert.equal(state.password.focusCount, 1);

		state.visibility.listeners.get('click')();
		assert.equal(state.password.type, 'password');
		assert.equal(state.visibility.getAttribute('aria-pressed'), 'false');
		assert.equal(
			state.visibility.getAttribute('aria-label'),
			'Show password'
		);
		assert.equal(state.visibility.getAttribute('title'), 'Show password');
		assert.equal(
			state.visibilityIcon.classList.contains('dashicons-visibility'),
			true
		);
	} finally {
		cleanup();
	}
});

test('generation failure leaves existing values untouched', () => {
	const failures = [
		undefined,
		{
			getRandomValues() {
				throw new Error('unavailable');
			},
		},
	];

	for (const crypto of failures) {
		const state = initialize({
			crypto,
			passwordValue: 'existing-password-value',
		});
		state.confirmation.value = 'existing-confirmation-value';

		try {
			state.generate.listeners.get('click')();
			assert.equal(state.password.value, 'existing-password-value');
			assert.equal(
				state.confirmation.value,
				'existing-confirmation-value'
			);
			assert.equal(
				state.status.textContent,
				'Secure generation unavailable.'
			);
			assert.equal(
				state.status.classList.contains('screen-reader-text'),
				false
			);
			assert.doesNotMatch(state.status.textContent, /existing-/);
		} finally {
			cleanup();
		}
	}
});

test('clipboard failure selects the still-masked password for manual copy', async () => {
	const failures = [
		undefined,
		{
			async writeText() {
				throw new Error('denied');
			},
		},
	];

	for (const clipboard of failures) {
		const state = initialize({
			clipboard,
			crypto: { getRandomValues() {} },
			passwordValue: 'copy-failure-password',
		});

		try {
			await state.copy.listeners.get('click')();
			assert.equal(state.password.focusCount, 1);
			assert.equal(state.password.selectCount, 1);
			assert.equal(
				state.status.textContent,
				'Clipboard failed; copy manually.'
			);
			assert.equal(
				state.status.classList.contains('screen-reader-text'),
				false
			);
			assert.doesNotMatch(
				state.status.textContent,
				/copy-failure-password/
			);

			state.visibility.listeners.get('click')();
			assert.equal(state.password.type, 'text');
			assert.equal(state.password.focusCount, 2);
			assert.equal(state.password.selectCount, 2);
		} finally {
			cleanup();
		}
	}
});

test('the ready callback initializes password tools without a weak fallback', () => {
	assert.match(source, /\t\tinitPortabilityPasswordTools\(\);/);
	assert.doesNotMatch(source, /Math\.random/);
});

test('protected export keeps missing and mismatched password warnings inline', () => {
	const state = initialize({
		crypto: { getRandomValues() {} },
	});

	try {
		const missingEvent = {
			prevented: false,
			preventDefault() {
				this.prevented = true;
			},
		};
		state.form.listeners.get('submit')(missingEvent);
		assert.equal(missingEvent.prevented, true);
		assert.equal(state.validation.hidden, false);
		assert.equal(state.validationMessage.textContent, 'Choose a password.');
		assert.equal(state.password.getAttribute('aria-invalid'), 'true');
		assert.equal(state.password.focusCount, 1);

		state.password.value = 'correct-horse-battery-staple';
		state.password.listeners.get('input')();
		assert.equal(state.validation.hidden, true);
		assert.equal(state.password.getAttribute('aria-invalid'), null);

		state.confirmation.value = 'different-password-value';
		const mismatchEvent = {
			prevented: false,
			preventDefault() {
				this.prevented = true;
			},
		};
		state.form.listeners.get('submit')(mismatchEvent);
		assert.equal(mismatchEvent.prevented, true);
		assert.equal(state.validation.hidden, false);
		assert.equal(
			state.validationMessage.textContent,
			'Passwords do not match.'
		);
		assert.equal(state.confirmation.getAttribute('aria-invalid'), 'true');
		assert.equal(state.confirmation.focusCount, 1);

		state.confirmation.value = state.password.value;
		state.confirmation.listeners.get('input')();
		const validEvent = {
			prevented: false,
			preventDefault() {
				this.prevented = true;
			},
		};
		state.form.listeners.get('submit')(validEvent);
		assert.equal(validEvent.prevented, false);
		assert.equal(state.validation.hidden, true);
	} finally {
		cleanup();
	}
});

test('unprotected export bypasses password checks and clears stale warnings', () => {
	const state = initialize({
		crypto: { getRandomValues() {} },
	});

	try {
		const protectedEvent = {
			prevented: false,
			preventDefault() {
				this.prevented = true;
			},
		};
		state.form.listeners.get('submit')(protectedEvent);
		assert.equal(protectedEvent.prevented, true);

		state.password.value = 'discarded-password-value';
		state.confirmation.value = 'discarded-password-value';
		state.credentialChoice.checked = false;
		state.credentialChoice.listeners.get('change')();
		assert.equal(state.validation.hidden, true);
		assert.equal(state.validationMessage.textContent, '');
		assert.equal(state.password.value, '');
		assert.equal(state.confirmation.value, '');
		assert.equal(state.password.disabled, true);
		assert.equal(state.confirmation.disabled, true);
		assert.equal(state.visibility.disabled, true);
		assert.equal(state.generate.disabled, true);

		const unprotectedEvent = {
			prevented: false,
			preventDefault() {
				this.prevented = true;
			},
		};
		state.form.listeners.get('submit')(unprotectedEvent);
		assert.equal(unprotectedEvent.prevented, false);

		state.credentialChoice.checked = true;
		state.credentialChoice.listeners.get('change')();
		assert.equal(state.password.disabled, false);
		assert.equal(state.confirmation.disabled, false);
	} finally {
		cleanup();
	}
});
