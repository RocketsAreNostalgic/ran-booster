import assert from 'node:assert/strict';
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

test('authoritative provider profile swaps rebind only the replaced controls', () => {
	const calls = [];
	const handleProviderProfileSwap = loadFunction(
		'handleProviderProfileSwap',
		{
			activeCredentialButton: { id: 'detached-trigger' },
			document: {
				body: {
					classList: {
						remove(className) {
							calls.push(['body-class-remove', className]);
						},
					},
				},
			},
			initCredentialSettings() {
				calls.push(['credentials']);
			},
			initAccessSecretTools(target) {
				calls.push(['access-tools', target.id]);
			},
			initWebhookSecretTools() {
				calls.push(['secret-tools']);
			},
			initWebhookUrlCopy(target) {
				calls.push(['webhook-copy', target.id]);
			},
		}
	);

	handleProviderProfileSwap({
		detail: { target: { id: 'unrelated-region' } },
	});
	assert.deepEqual(calls, []);

	handleProviderProfileSwap({
		detail: { target: { id: 'ran-booster-provider-profile-region' } },
	});
	assert.deepEqual(calls, [
		['body-class-remove', 'ran-booster-repository-picker-open'],
		['secret-tools'],
		['access-tools', 'ran-booster-provider-profile-region'],
		['webhook-copy', 'ran-booster-provider-profile-region'],
		['credentials'],
	]);
});

test('credential secret visibility reveals only entered text and resets to masked', () => {
	const listeners = new Map();
	const classes = new Set(['dashicons-visibility']);
	const attributes = new Map();
	const expiryInput = {
		dataset: {
			originalValue: '2026-10-19',
			providerMax: '2026-10-19',
			replacementStarted: 'false',
		},
		max: '2026-10-19',
		value: '2026-10-19',
	};
	const input = {
		dataset: { saved: 'true' },
		type: 'password',
		value: '',
		focusCount: 0,
		form: {
			elements: { 'ran_booster[expires_on]': expiryInput },
		},
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
		focus() {
			this.focusCount += 1;
		},
	};
	const visibility = {
		dataset: { hideLabel: 'Hide token', showLabel: 'Show token' },
		disabled: false,
		addEventListener(type, listener) {
			listeners.set(`visibility:${type}`, listener);
		},
		setAttribute(name, value) {
			attributes.set(name, String(value));
		},
	};
	const icon = {
		classList: {
			add(name) {
				classes.add(name);
			},
			remove(name) {
				classes.delete(name);
			},
			toggle(name, force) {
				if (force) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			},
		},
	};
	const tools = {
		dataset: {},
		querySelector(selector) {
			return {
				'.ran-booster-secret-input': input,
				'[data-access-secret-visibility]': visibility,
				'[data-access-secret-visibility-icon]': icon,
			}[selector];
		},
	};
	const accessSecretToolResets = new WeakMap();
	const initSecretVisibility = loadFunction('initSecretVisibility');
	const initAccessSecretTools = loadFunction('initAccessSecretTools', {
		accessSecretToolResets,
		document: null,
		initSecretVisibility,
	});

	initAccessSecretTools({
		querySelectorAll(selector) {
			assert.equal(selector, '[data-access-secret-tools]');
			return [tools];
		},
	});
	assert.equal(visibility.disabled, true);
	assert.equal(visibility.hidden, true);
	assert.equal(input.type, 'password');

	input.value = 'github_pat_test-value';
	listeners.get('input')();
	assert.equal(visibility.disabled, false);
	assert.equal(visibility.hidden, false);
	assert.equal(expiryInput.max, '');
	assert.equal(expiryInput.value, '');
	input.value = '';
	listeners.get('input')();
	assert.equal(expiryInput.max, '2026-10-19');
	assert.equal(expiryInput.value, '2026-10-19');
	input.value = 'github_pat_test-value';
	listeners.get('input')();
	listeners.get('visibility:click')();
	assert.equal(input.type, 'text');
	assert.equal(attributes.get('aria-label'), 'Hide token');
	assert.equal(input.focusCount, 1);

	const resetCredentialModal = loadFunction('resetCredentialModal', {
		accessSecretToolResets,
		resetWebhookSecretTools() {
			assert.fail('An access modal must not reset webhook tools.');
		},
	});
	resetCredentialModal({
		querySelector(selector) {
			return {
				form: {
					reset() {
						input.value = '';
					},
				},
				'[data-access-secret-tools]': tools,
				'[data-webhook-secret-tools]': null,
			}[selector];
		},
	});
	assert.equal(input.value, '');
	assert.equal(input.type, 'password');
	assert.equal(visibility.hidden, true);
	assert.equal(attributes.get('aria-pressed'), 'false');
	assert.equal(attributes.get('aria-label'), 'Show token');
	assert.equal(classes.has('dashicons-visibility'), true);
	assert.equal(classes.has('dashicons-hidden'), false);
});

test('only verified provider profile success operations restore focus', () => {
	let focusCount = 0;
	globalThis.document = {
		querySelector(selector) {
			assert.equal(
				selector,
				'#ran-booster-provider-profile-region [data-ran-booster-provider-profile-focus]'
			);

			return {
				focus(options) {
					assert.deepEqual(options, { preventScroll: true });
					focusCount += 1;
				},
			};
		},
	};

	try {
		const handleProviderProfileSuccess = loadFunction(
			'handleProviderProfileSuccess'
		);

		handleProviderProfileSuccess({
			detail: {
				operation: 'repository-webhook-management:manage-webhook',
			},
		});
		handleProviderProfileSuccess({ detail: {} });
		assert.equal(focusCount, 0);

		for (const operation of [
			'core:save-access-profile',
			'core:delete-access-profile',
			'core:save-webhook-profile',
		]) {
			handleProviderProfileSuccess({ detail: { operation } });
		}
		handleProviderProfileSuccess({
			detail: {
				value: { operation: 'core:delete-webhook-profile' },
			},
		});
		assert.equal(focusCount, 4);
	} finally {
		delete globalThis.document;
	}
});

test('the lifecycle handlers are registered on the post-swap and safe success events', () => {
	assert.match(
		source,
		/document\.addEventListener\('htmx:afterSwap', handleProviderProfileSwap\)/
	);
	assert.match(
		source,
		/'ran-booster:admin-mutation-success',\s+handleProviderProfileSuccess/
	);
	assert.match(source, /secretInput\.dataset\.saved === 'true'/);
	assert.match(source, /secretHelp\.dataset\.editMessage/);
	assert.match(
		source,
		/resetCredentialModal\(modal\);\s+modal\.setAttribute/
	);
});
