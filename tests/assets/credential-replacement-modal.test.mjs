import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function loadCredentialSettings(document, window) {
	const signature = '\tfunction initCredentialSettings() {';
	const start = source.indexOf(signature);

	assert.notEqual(
		start,
		-1,
		'Credential settings initialization must exist.'
	);

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

	assert.notEqual(
		end,
		-1,
		'Credential settings initialization must be complete.'
	);

	return Function(
		'document',
		'window',
		'populateCredentialModal',
		'openRequestedWebhookSecretModal',
		`"use strict";
		let activeCredentialButton = null;
		let credentialRequestHandled = false;
		return (${source.slice(start, end)});`
	)(
		document,
		window,
		() => {},
		() => {}
	);
}

function button(attributes) {
	return {
		dataset: {},
		listeners: new Map(),
		clickCount: 0,
		addEventListener(type, callback) {
			this.listeners.set(type, callback);
		},
		click() {
			this.clickCount += 1;
		},
		getAttribute(name) {
			return attributes[name] ?? null;
		},
	};
}

function fixture(replacement) {
	const requested = button({
		'data-id': replacement,
		'data-modal': 'access',
	});
	const other = button({
		'data-id': 'other-profile',
		'data-modal': 'access',
	});
	const focusTarget = {
		focusCount: 0,
		focus() {
			this.focusCount += 1;
		},
	};
	const modal = {
		querySelector(selector) {
			return selector === '.ran-booster-secret-input'
				? focusTarget
				: null;
		},
	};
	const document = {
		body: {
			classList: {
				add() {},
			},
		},
		querySelector(selector) {
			return selector === '[data-credential-modal="access"]'
				? modal
				: null;
		},
		querySelectorAll(selector) {
			if (selector === '.ran-booster-open-credential-modal') {
				return [requested, other];
			}
			if (selector === '.ran-booster-open-delete-credential-modal') {
				return [];
			}
			return [];
		},
	};
	const window = {
		location: {
			search: `?replace_credential=${encodeURIComponent(replacement)}`,
		},
	};

	return { document, focusTarget, other, requested, window };
}

test('requested credential opens only its exact rendered editor', () => {
	const state = fixture('later-page-profile');

	loadCredentialSettings(state.document, state.window)();

	assert.equal(state.requested.clickCount, 1);
	assert.equal(state.other.clickCount, 0);
	assert.equal(state.focusTarget.focusCount, 1);
});

test('unknown requested credential opens no editor', () => {
	const state = fixture('later-page-profile');
	state.window.location.search = '?replace_credential=unknown-profile';

	loadCredentialSettings(state.document, state.window)();

	assert.equal(state.requested.clickCount, 0);
	assert.equal(state.other.clickCount, 0);
	assert.equal(state.focusTarget.focusCount, 0);
});
