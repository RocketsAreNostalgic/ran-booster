import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadInitializer() {
	const signature = '\tfunction initPackageOperationControls() {';
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, 'The package operation initializer must exist.');

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
		'The package operation initializer must be complete.'
	);

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

function fixture(policy, capable = true) {
	const listeners = new Map();
	const select = {
		value: policy,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
	};
	const notice = { hidden: false };
	const guidance = { hidden: false };
	const attributes = new Map([
		['data-ran-booster-reinstall-capable', capable ? '1' : '0'],
	]);
	const reinstall = {
		disabled: false,
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		setAttribute(name, value) {
			attributes.set(name, value);
		},
	};
	const operation = {
		querySelector(selector) {
			if (selector === '[data-ran-booster-settings-reinstall]') {
				return reinstall;
			}
			if (selector === '[data-ran-booster-reinstall-guidance]') {
				return guidance;
			}
			return null;
		},
	};
	const field = {
		dataset: {},
		querySelector(selector) {
			if (selector === '.ran-booster-deployment-policy-input') {
				return select;
			}
			if (selector === '[data-ran-booster-local-development-warning]') {
				return notice;
			}
			return null;
		},
		closest(selector) {
			return selector === '.ran-booster-package-operation-settings'
				? operation
				: null;
		},
	};

	globalThis.document = {
		querySelectorAll(selector) {
			return selector === '.ran-booster-deployment-policy-field'
				? [field]
				: [];
		},
	};

	return { attributes, guidance, listeners, notice, reinstall, select };
}

test('disabled automation hides the local overwrite warning', () => {
	const state = fixture('disabled');

	try {
		loadInitializer()();

		assert.equal(state.notice.hidden, true);
	} finally {
		delete globalThis.document;
	}
});

test('the warning follows Automation changes without a reload', () => {
	const state = fixture('manual');

	try {
		loadInitializer()();
		assert.equal(state.notice.hidden, false);

		state.select.value = 'disabled';
		state.listeners.get('change')();
		assert.equal(state.notice.hidden, true);

		state.select.value = 'automatic';
		state.listeners.get('change')();
		assert.equal(state.notice.hidden, false);
	} finally {
		delete globalThis.document;
	}
});

test('choosing an enabled policy makes a capable reinstall actionable', () => {
	const state = fixture('disabled');

	try {
		loadInitializer()();
		assert.equal(state.reinstall.disabled, true);
		assert.equal(state.guidance.hidden, false);

		state.select.value = 'manual';
		state.listeners.get('change')();
		assert.equal(state.reinstall.disabled, false);
		assert.equal(state.guidance.hidden, true);
		assert.equal(state.attributes.get('data-update-can-run'), '1');

		state.select.value = 'disabled';
		state.listeners.get('change')();
		assert.equal(state.reinstall.disabled, true);
		assert.equal(state.guidance.hidden, false);
		assert.equal(state.attributes.get('data-update-can-run'), '0');
	} finally {
		delete globalThis.document;
	}
});

test('an unavailable mutation route stays disabled for enabled policies', () => {
	const state = fixture('automatic', false);

	try {
		loadInitializer()();
		assert.equal(state.reinstall.disabled, true);
		assert.equal(state.guidance.hidden, false);
		assert.equal(state.attributes.get('data-update-can-run'), '0');
	} finally {
		delete globalThis.document;
	}
});
