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

function control(value = '') {
	const attributes = new Map();
	const listeners = new Map();

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

function fixture({ clipboard, crypto, secretValue = '' } = {}) {
	const secret = control(secretValue);
	secret.type = 'password';
	secret.dataset.addPlaceholder = 'Long random secret';
	const visibility = control();
	visibility.dataset = {
		hideLabel: 'Hide secret',
		showLabel: 'Show secret',
	};
	const visibilityIcon = { classList: classes('dashicons-visibility') };
	const generate = control();
	const copy = control();
	copy.dataset = {
		copiedLabel: 'Secret copied',
		copyLabel: 'Copy secret',
	};
	const copyIcon = control();
	const copySuccessIcon = control();
	copySuccessIcon.setAttribute('hidden', '');
	const status = {
		classList: classes(),
		dataset: {
			copiedMessage: 'Secret copied.',
			copyFailedMessage: 'Clipboard failed; copy manually.',
			generatedMessage: 'Secret generated.',
			generationFailedMessage: 'Secure generation unavailable.',
		},
		textContent: '',
	};
	const controls = {
		'[data-webhook-secret-input]': secret,
		'[data-webhook-secret-visibility]': visibility,
		'[data-webhook-secret-visibility-icon]': visibilityIcon,
		'[data-webhook-secret-generate]': generate,
		'[data-webhook-secret-copy]': copy,
		'[data-webhook-secret-copy-icon]': copyIcon,
		'[data-webhook-secret-copy-success-icon]': copySuccessIcon,
		'[data-webhook-secret-status]': status,
	};
	const form = control();
	const root = {
		closest(selector) {
			return selector === 'form' ? form : null;
		},
		querySelector(selector) {
			return controls[selector] || null;
		},
	};

	globalThis.document = {
		querySelectorAll(selector) {
			return selector === '[data-webhook-secret-tools]' ? [root] : [];
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
		copy,
		copyIcon,
		copySuccessIcon,
		form,
		generate,
		root,
		secret,
		status,
		visibility,
		visibilityIcon,
	};
}

const generateSecureBase64Url = loadFunction('generateSecureBase64Url');
const initSecretVisibility = loadFunction('initSecretVisibility');
const initGeneratedSecretTools = loadFunction('initGeneratedSecretTools', {
	generateSecureBase64Url,
	initSecretVisibility,
});
const accessSecretToolResets = new WeakMap();
const webhookSecretToolResets = new WeakMap();
const resetWebhookSecretTools = loadFunction('resetWebhookSecretTools', {
	webhookSecretToolResets,
});
const resetCredentialModal = loadFunction('resetCredentialModal', {
	accessSecretToolResets,
	resetWebhookSecretTools,
});
const initWebhookSecretTools = loadFunction('initWebhookSecretTools', {
	initGeneratedSecretTools,
	webhookSecretToolResets,
});
const updateWebhookFields = loadFunction('updateWebhookFields');
const initWebhookUrlCopy = loadFunction('initWebhookUrlCopy');

function initialize(options) {
	const state = fixture(options);
	initWebhookSecretTools();

	return state;
}

function cleanup() {
	delete globalThis.document;
	delete globalThis.window;
}

test('secure generation fills a 64-character webhook secret', () => {
	const state = initialize({
		crypto: {
			getRandomValues(bytes) {
				assert.ok(bytes instanceof Uint8Array);
				assert.equal(bytes.length, 48);
				bytes.fill(251);
				return bytes;
			},
		},
	});

	try {
		assert.equal(state.copy.disabled, true);
		assert.equal(state.visibility.disabled, true);
		assert.equal(state.visibility.hidden, true);

		state.generate.listeners.get('click')();
		assert.match(state.secret.value, /^[A-Za-z0-9_-]{64}$/);
		assert.equal(state.copy.disabled, false);
		assert.equal(state.visibility.disabled, false);
		assert.equal(state.visibility.hidden, false);
		assert.equal(state.status.textContent, 'Secret generated.');
		assert.equal(
			state.status.classList.contains('screen-reader-text'),
			true
		);
		assert.doesNotMatch(
			state.status.textContent,
			new RegExp(state.secret.value)
		);
	} finally {
		cleanup();
	}
});

test('copy and submission match the server-normalized secret', async () => {
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
		state.secret.value = '  exact-manual-webhook-secret-value  ';
		state.secret.listeners.get('input')();
		assert.equal(state.copy.disabled, false);

		await state.copy.listeners.get('click')();
		assert.deepEqual(copied, ['exact-manual-webhook-secret-value']);
		assert.equal(state.secret.value, 'exact-manual-webhook-secret-value');
		assert.equal(state.status.textContent, 'Secret copied.');
		assert.equal(state.copy.getAttribute('aria-label'), 'Secret copied');
		assert.equal(state.copyIcon.getAttribute('hidden'), '');
		assert.equal(state.copySuccessIcon.getAttribute('hidden'), null);
		assert.doesNotMatch(state.status.textContent, /exact-manual/);

		state.secret.value = '';
		state.secret.listeners.get('input')();
		assert.equal(state.copy.disabled, true);
		assert.equal(state.visibility.disabled, true);
		assert.equal(state.visibility.hidden, true);
		assert.equal(state.status.textContent, '');
		assert.equal(state.copy.getAttribute('aria-label'), 'Copy secret');
		assert.equal(state.copyIcon.getAttribute('hidden'), null);
		assert.equal(state.copySuccessIcon.getAttribute('hidden'), '');

		state.secret.value = '  submitted-webhook-secret-value  ';
		state.form.listeners.get('submit')();
		assert.equal(state.secret.value, 'submitted-webhook-secret-value');
	} finally {
		cleanup();
	}
});

test('visibility and modal reset restore the masked empty state', () => {
	const state = initialize({
		crypto: { getRandomValues() {} },
		secretValue: 'existing-webhook-secret-value',
	});

	try {
		state.visibility.listeners.get('click')();
		assert.equal(state.secret.type, 'text');
		assert.equal(state.visibility.getAttribute('aria-pressed'), 'true');
		assert.equal(
			state.visibilityIcon.classList.contains('dashicons-hidden'),
			true
		);
		assert.equal(state.secret.focusCount, 1);

		state.secret.value = '';
		state.status.textContent = 'Secret generated.';
		state.status.classList.add('screen-reader-text');
		state.copyIcon.setAttribute('hidden', '');
		state.copySuccessIcon.removeAttribute('hidden');
		resetWebhookSecretTools(state.root);

		assert.equal(state.secret.type, 'password');
		assert.equal(state.visibility.getAttribute('aria-pressed'), 'false');
		assert.equal(
			state.visibility.getAttribute('aria-label'),
			'Show secret'
		);
		assert.equal(
			state.visibilityIcon.classList.contains('dashicons-visibility'),
			true
		);
		assert.equal(state.copy.disabled, true);
		assert.equal(state.visibility.disabled, true);
		assert.equal(state.visibility.hidden, true);
		assert.equal(state.copy.getAttribute('aria-label'), 'Copy secret');
		assert.equal(state.copyIcon.getAttribute('hidden'), null);
		assert.equal(state.copySuccessIcon.getAttribute('hidden'), '');
		assert.equal(state.status.textContent, '');
		assert.equal(
			state.status.classList.contains('screen-reader-text'),
			false
		);
	} finally {
		cleanup();
	}
});

test('generation and clipboard failures remain secret-free', async () => {
	const state = initialize({
		clipboard: undefined,
		crypto: {
			getRandomValues() {
				throw new Error('unavailable');
			},
		},
		secretValue: 'existing-webhook-secret-value',
	});

	try {
		state.generate.listeners.get('click')();
		assert.equal(state.secret.value, 'existing-webhook-secret-value');
		assert.equal(
			state.status.textContent,
			'Secure generation unavailable.'
		);
		assert.doesNotMatch(state.status.textContent, /existing-webhook/);

		await state.copy.listeners.get('click')();
		assert.equal(
			state.status.textContent,
			'Clipboard failed; copy manually.'
		);
		assert.equal(state.secret.focusCount, 1);
		assert.equal(state.secret.selectCount, 1);
		assert.doesNotMatch(state.status.textContent, /existing-webhook/);
	} finally {
		cleanup();
	}
});

test('the ready callback initializes webhook tools without a weak fallback', () => {
	assert.match(source, /\t\tinitWebhookSecretTools\(\);/);
	assert.match(
		source,
		/function resetCredentialModal\(modal\)[\s\S]+resetWebhookSecretTools\(webhookTools\);/
	);
	assert.match(source, /resetCredentialModal\(modal\);/);
	assert.doesNotMatch(source, /Math\.random/);
});

test('the strict webhook-secret deep link opens and preselects its repository', () => {
	const openRequestedWebhookSecretModal = loadFunction(
		'openRequestedWebhookSecretModal',
		{
			window: {
				location: {
					search: '?add_webhook_secret=1&webhook_scope=repository&webhook_target=owner%2Frepository',
				},
			},
		}
	);
	const edit = control();
	const add = control();
	let addClicks = 0;

	edit.setAttribute('data-modal', 'webhook');
	edit.setAttribute('data-id', 'wh_existing');
	edit.click = function () {
		assert.fail('The edit control must not be opened.');
	};
	add.setAttribute('data-modal', 'webhook');
	add.click = function () {
		addClicks += 1;
	};

	openRequestedWebhookSecretModal([edit, add]);

	assert.equal(addClicks, 1);
	assert.equal(add.getAttribute('data-scope'), 'repository');
	assert.equal(add.getAttribute('data-target'), 'owner/repository');
});

test('webhook-secret deep links accept owner scope without trusting other scope values', () => {
	const button = control();
	button.setAttribute('data-modal', 'webhook');
	button.click = function () {};

	loadFunction('openRequestedWebhookSecretModal', {
		window: {
			location: {
				search: '?add_webhook_secret=1&webhook_scope=owner&webhook_target=owner',
			},
		},
	})([button]);

	assert.equal(button.getAttribute('data-scope'), 'owner');
	assert.equal(button.getAttribute('data-target'), 'owner');

	button.removeAttribute('data-scope');
	button.removeAttribute('data-target');
	loadFunction('openRequestedWebhookSecretModal', {
		window: {
			location: {
				search: '?add_webhook_secret=1&webhook_scope=global&webhook_target=owner',
			},
		},
	})([button]);

	assert.equal(button.getAttribute('data-scope'), null);
	assert.equal(button.getAttribute('data-target'), null);
});

test('invalid or absent webhook-secret deep links do nothing', () => {
	const button = control();
	let clicks = 0;
	button.setAttribute('data-modal', 'webhook');
	button.click = function () {
		clicks += 1;
	};

	for (const search of [
		'',
		'?add_webhook_secret=0',
		'?add_webhook_secret=webhook',
	]) {
		const openRequestedWebhookSecretModal = loadFunction(
			'openRequestedWebhookSecretModal',
			{
				window: {
					location: {
						search,
					},
				},
			}
		);
		openRequestedWebhookSecretModal([button]);
	}

	assert.equal(clicks, 0);
});

test('scope switches between the closed managed owner and repository choices', () => {
	function option(value) {
		return { parentElement: null, value };
	}

	function group(scope, options) {
		const attributes = new Map();
		const targetGroup = {
			dataset: { webhookTargetOptions: scope },
			disabled: false,
			options,
			querySelector(selector) {
				return selector === 'option[value=""]' ? options[0] : null;
			},
			toggleAttribute(name, force) {
				if (force) {
					attributes.set(name, '');
				} else {
					attributes.delete(name);
				}
			},
		};
		options.forEach((choice) => {
			choice.parentElement = targetGroup;
		});

		return targetGroup;
	}

	const ownerOptions = [option(''), option('managed-owner')];
	const repositoryOptions = [option(''), option('managed-owner/repository')];
	const ownerGroup = group('owner', ownerOptions);
	const repositoryGroup = group('repository', repositoryOptions);
	const target = control();
	target.options = [...ownerOptions, ...repositoryOptions];
	target.selectedIndex = 1;
	target.querySelectorAll = function (selector) {
		return selector === '[data-webhook-target-options]'
			? [ownerGroup, repositoryGroup]
			: [];
	};
	const label = control();
	const help = control();
	const field = control();
	field.toggleAttribute = function (name, force) {
		if (force) {
			this.setAttribute(name, '');
		} else {
			this.removeAttribute(name);
		}
	};
	field.querySelector = function (selector) {
		return (
			{
				'[data-webhook-target]': target,
				'.ran-booster-webhook-target-label': label,
				'.ran-booster-webhook-target-help': help,
			}[selector] || null
		);
	};
	const ownerScope = control('owner');
	ownerScope.setAttribute('data-requires-target', '1');
	ownerScope.setAttribute('data-target-label', 'Owner');
	ownerScope.setAttribute('data-description', 'Choose a managed owner.');
	const repositoryScope = control('repository');
	repositoryScope.setAttribute('data-requires-target', '1');
	repositoryScope.setAttribute('data-target-label', 'Repository');
	repositoryScope.setAttribute(
		'data-description',
		'Choose a managed repository.'
	);
	const scope = control();
	scope.options = [ownerScope, repositoryScope];
	scope.selectedIndex = 0;
	const modal = {
		querySelector(selector) {
			return selector === '.ran-booster-webhook-scope' ? scope : field;
		},
	};

	updateWebhookFields(modal);
	assert.equal(target.selectedIndex, 1);
	assert.equal(ownerGroup.disabled, false);
	assert.equal(repositoryGroup.disabled, true);
	assert.equal(label.textContent, 'Owner');

	scope.selectedIndex = 1;
	updateWebhookFields(modal);
	assert.equal(target.selectedIndex, 2);
	assert.equal(ownerGroup.disabled, true);
	assert.equal(repositoryGroup.disabled, false);
	assert.equal(label.textContent, 'Repository');
	assert.equal(help.textContent, 'Choose a managed repository.');

	target.selectedIndex = 3;
	updateWebhookFields(modal);
	assert.equal(target.selectedIndex, 3);
});

test('add and edit modal population clear stale generated secret state', () => {
	const state = initialize({
		crypto: {
			getRandomValues(bytes) {
				bytes.fill(123);
				return bytes;
			},
		},
	});
	const fields = {
		'ran_booster[id]': control(),
		'ran_booster[label]': control(),
		'ran_booster[scope]': control(),
		'ran_booster[target]': control(),
	};
	state.form.elements = fields;
	state.form.reset = function () {
		state.secret.value = '';
	};
	const title = control();
	const modal = {
		getAttribute(name) {
			return (
				{
					'data-credential-modal': 'webhook',
					'data-provider-label': 'GitHub',
				}[name] || null
			);
		},
		querySelector(selector) {
			return (
				{
					form: state.form,
					'.ran-booster-dialog__title': title,
					'.ran-booster-secret-input': state.secret,
					'[data-webhook-secret-input]': state.secret,
					'[data-webhook-secret-tools]': state.root,
				}[selector] || null
			);
		},
	};
	let webhookFieldUpdates = 0;
	const populateCredentialModal = loadFunction('populateCredentialModal', {
		resetCredentialModal,
		updateWebhookFields() {
			webhookFieldUpdates += 1;
		},
	});
	const button = control();

	try {
		state.generate.listeners.get('click')();
		state.visibility.listeners.get('click')();
		assert.equal(state.secret.value.length, 64);
		assert.equal(state.secret.type, 'text');

		populateCredentialModal(modal, button);
		assert.equal(state.secret.value, '');
		assert.equal(state.secret.type, 'password');
		assert.equal(state.secret.required, true);
		assert.equal(state.secret.dataset.saved, 'false');
		assert.equal(state.secret.placeholder, 'Long random secret');
		assert.equal(state.copy.disabled, true);
		assert.equal(state.visibility.hidden, true);
		assert.equal(title.textContent, 'Add Push-to-Deploy secret');

		state.secret.value = 'stale-secret-value';
		state.visibility.listeners.get('click')();
		button.setAttribute('data-id', 'webhook-profile');
		button.setAttribute('data-label', 'Production webhooks');
		button.setAttribute('data-scope', 'repository');
		button.setAttribute('data-target', 'owner/repository');
		populateCredentialModal(modal, button);

		assert.equal(fields['ran_booster[id]'].value, 'webhook-profile');
		assert.equal(fields['ran_booster[label]'].value, 'Production webhooks');
		assert.equal(fields['ran_booster[scope]'].value, 'repository');
		assert.equal(fields['ran_booster[target]'].value, 'owner/repository');
		assert.equal(state.secret.value, '');
		assert.equal(state.secret.type, 'password');
		assert.equal(state.secret.required, false);
		assert.equal(state.secret.dataset.saved, 'true');
		assert.equal(state.secret.placeholder, '••••••••••');
		assert.equal(state.copy.disabled, true);
		assert.equal(state.visibility.hidden, true);
		assert.equal(title.textContent, 'Edit Push-to-Deploy secret');
		assert.equal(webhookFieldUpdates, 2);
	} finally {
		cleanup();
	}
});

test('payload URL copy uses the exact displayed endpoint', async () => {
	const copied = [];
	const input = control(
		'https://example.test/wp-json/ran-booster/v1/webhooks/gh'
	);
	const copy = control();
	copy.dataset = { copiedLabel: 'URL copied', copyLabel: 'Copy URL' };
	copy.textContent = 'Copy URL';
	const status = control();
	status.dataset = {
		copiedMessage: 'Payload URL copied.',
		copyFailedMessage: 'Copy failed.',
	};
	const root = {
		dataset: {},
		querySelector(selector) {
			return (
				{
					'[data-webhook-url]': input,
					'[data-webhook-url-copy]': copy,
					'[data-webhook-url-status]': status,
				}[selector] || null
			);
		},
	};
	globalThis.document = {
		querySelectorAll(selector) {
			return selector === '[data-webhook-url-tools]' ? [root] : [];
		},
	};
	globalThis.window = {
		navigator: {
			clipboard: {
				async writeText(value) {
					copied.push(value);
				},
			},
		},
	};

	try {
		initWebhookUrlCopy();
		await copy.listeners.get('click')();
		assert.deepEqual(copied, [input.value]);
		assert.equal(copy.textContent, 'URL copied');
		assert.equal(status.textContent, 'Payload URL copied.');
		assert.equal(status.classList.contains('screen-reader-text'), true);
	} finally {
		cleanup();
	}
});

test('payload URL copy failure selects the URL for manual copying', async () => {
	const input = control('https://example.test/webhook');
	const copy = control();
	copy.dataset = { copiedLabel: 'URL copied', copyLabel: 'Copy URL' };
	const status = control();
	status.dataset = {
		copiedMessage: 'Payload URL copied.',
		copyFailedMessage: 'Copy failed; use the browser command.',
	};
	const root = {
		dataset: {},
		querySelector(selector) {
			return (
				{
					'[data-webhook-url]': input,
					'[data-webhook-url-copy]': copy,
					'[data-webhook-url-status]': status,
				}[selector] || null
			);
		},
	};
	globalThis.document = {
		querySelectorAll(selector) {
			return selector === '[data-webhook-url-tools]' ? [root] : [];
		},
	};
	globalThis.window = { navigator: {} };

	try {
		initWebhookUrlCopy();
		await copy.listeners.get('click')();
		assert.equal(input.focusCount, 1);
		assert.equal(input.selectCount, 1);
		assert.equal(
			status.textContent,
			'Copy failed; use the browser command.'
		);
	} finally {
		cleanup();
	}
});
