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

function fixture(
	policy,
	capable = true,
	deferred = false,
	standalone = false,
	guarded = true
) {
	const listeners = new Map();
	const documentListeners = new Map();
	const select = {
		value: policy,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
	};
	const notice = { hidden: false };
	const reason = {
		hidden: false,
		id: 'ran-booster-reinstall-reason',
		textContent: 'Reason',
	};
	const wrapperAttributes = new Map([
		['data-ran-booster-reason-tooltip', ''],
	]);
	const wrapper = {
		setAttribute(name, value) {
			wrapperAttributes.set(name, value);
		},
		removeAttribute(name) {
			wrapperAttributes.delete(name);
		},
		querySelector(selector) {
			return selector === '[role="tooltip"]' ? reason : null;
		},
	};
	const attributes = new Map([
		['data-ran-booster-reinstall-capable', capable ? '1' : '0'],
	]);
	const reinstall = {
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		setAttribute(name, value) {
			attributes.set(name, value);
		},
		removeAttribute(name) {
			attributes.delete(name);
		},
		closest(selector) {
			return selector === '.ran-booster-reason-tooltip' ? wrapper : null;
		},
	};
	const operation = {
		querySelector(selector) {
			if (selector === '[data-ran-booster-local-development-warning]') {
				return notice;
			}
			if (selector === '[data-ran-booster-settings-reinstall]') {
				return reinstall;
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
				return deferred ? null : notice;
			}
			return null;
		},
		closest(selector) {
			return !standalone &&
				selector === '.ran-booster-package-operation-settings'
				? operation
				: null;
		},
	};

	globalThis.document = {
		addEventListener(type, listener) {
			documentListeners.set(type, listener);
		},
		querySelectorAll(selector) {
			return selector === '.ran-booster-deployment-policy-field'
				? [field]
				: [];
		},
	};
	globalThis.window = { ranBoosterReasonTooltipInitialized: guarded };

	return {
		attributes,
		documentListeners,
		listeners,
		notice,
		reason,
		reinstall,
		select,
		wrapperAttributes,
	};
}

test('disabled automation hides the local overwrite warning', () => {
	const state = fixture('disabled');

	try {
		loadInitializer()();

		assert.equal(state.notice.hidden, true);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('a standalone policy field still controls its warning', () => {
	const state = fixture('disabled', true, false, true);
	try {
		loadInitializer()();
		assert.equal(state.notice.hidden, true);
		state.select.value = 'manual';
		state.listeners.get('change')();
		assert.equal(state.notice.hidden, false);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('the warning follows Automation changes without a reload', () => {
	const state = fixture('manual', true, true);

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
		delete globalThis.window;
	}
});

test('choosing an enabled policy makes a capable reinstall actionable', () => {
	const state = fixture('disabled');

	try {
		loadInitializer()();
		assert.equal(state.attributes.get('aria-disabled'), 'true');
		assert.equal(state.attributes.get('aria-describedby'), state.reason.id);
		assert.equal(
			state.wrapperAttributes.has('data-ran-booster-reason-tooltip'),
			true
		);

		state.select.value = 'manual';
		state.listeners.get('change')();
		assert.equal(state.attributes.get('aria-disabled'), 'false');
		assert.equal(state.attributes.has('aria-describedby'), false);
		assert.equal(
			state.wrapperAttributes.has('data-ran-booster-reason-tooltip'),
			false
		);
		assert.equal(state.reason.hidden, true);
		assert.equal(state.attributes.get('data-update-can-run'), '1');

		state.select.value = 'disabled';
		state.listeners.get('change')();
		assert.equal(state.attributes.get('aria-disabled'), 'true');
		assert.equal(
			state.wrapperAttributes.has('data-ran-booster-reason-tooltip'),
			true
		);
		assert.equal(state.attributes.get('data-update-can-run'), '0');
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('an unavailable mutation route stays disabled for enabled policies', () => {
	const state = fixture('automatic', false);

	try {
		loadInitializer()();
		assert.equal(state.attributes.get('aria-disabled'), 'true');
		assert.equal(
			state.wrapperAttributes.has('data-ran-booster-reason-tooltip'),
			true
		);
		assert.equal(state.attributes.get('data-update-can-run'), '0');
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('without the activation guard an unavailable reinstall stays natively disabled', () => {
	const state = fixture('disabled', true, false, false, false);

	try {
		loadInitializer()();
		assert.equal(state.reinstall.disabled, true);
		assert.equal(state.attributes.get('title'), 'Reason');
		assert.equal(
			state.documentListeners.has('ran-booster:reason-tooltip-ready'),
			true
		);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('once the activation guard is ready the control swaps native disabled for aria-disabled', () => {
	const state = fixture('disabled', true, false, false, false);

	try {
		loadInitializer()();
		assert.equal(state.reinstall.disabled, true);

		globalThis.window.ranBoosterReasonTooltipInitialized = true;
		state.documentListeners.get('ran-booster:reason-tooltip-ready')();
		assert.equal(state.reinstall.disabled, false);
		assert.equal(state.attributes.get('aria-disabled'), 'true');
		assert.equal(state.attributes.has('title'), false);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('with the guard installed an unavailable reinstall is focusable and an available one is enabled', () => {
	const state = fixture('disabled');

	try {
		loadInitializer()();
		assert.equal(state.reinstall.disabled, false);
		assert.equal(state.attributes.get('aria-disabled'), 'true');
		assert.equal(state.documentListeners.size, 0);

		state.select.value = 'manual';
		state.listeners.get('change')();
		assert.equal(state.reinstall.disabled, false);
		assert.equal(state.attributes.get('aria-disabled'), 'false');
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});
