import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadInitializer() {
	const signature = '\tfunction initPackageSourceControls() {';
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, 'The package source initializer must exist.');

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
		'The package source initializer must be complete.'
	);

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

function classListFixture(initial = []) {
	const classes = new Set(initial);

	return {
		contains(name) {
			return classes.has(name);
		},
		toggle(name, active) {
			if (active) {
				classes.add(name);
			} else {
				classes.delete(name);
			}
		},
	};
}

function tabFixture(sourceName, selected = false) {
	const listeners = new Map();
	const attributes = new Map([
		['data-ran-booster-source-choice', sourceName],
		['aria-pressed', selected ? 'true' : 'false'],
	]);
	const tab = {
		classList: classListFixture(selected ? ['is-selected'] : []),
		disabled: false,
		click() {
			listeners.get('click')?.();
		},
		focus() {},
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		setAttribute(name, value) {
			attributes.set(name, value);
		},
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
	};

	return { attributes, listeners, tab };
}

function fixture(repositoryValue = '') {
	const repositoryListeners = new Map();
	const formListeners = new Map();
	const branch = tabFixture('branch', true);
	const release = tabFixture('release_asset');
	const branchPane = {
		hidden: false,
		getAttribute() {
			return 'branch';
		},
	};
	const releasePane = {
		hidden: true,
		getAttribute() {
			return 'release_asset';
		},
	};
	const sourceEvents = [];
	const sourceControls = {
		dataset: {},
		disabled: false,
		closest() {
			return form;
		},
		dispatchEvent(event) {
			sourceEvents.push(event);
		},
		querySelectorAll(selector) {
			return selector === '[data-ran-booster-source-choice]'
				? [branch.tab, release.tab]
				: [branchPane, releasePane];
		},
	};
	const repository = {
		value: repositoryValue,
		checkValidity() {
			return this.value.length <= 512;
		},
		addEventListener(type, listener) {
			repositoryListeners.set(type, listener);
		},
	};
	const guidance = { hidden: false };
	const installButtons = [{ disabled: false }, { disabled: false }];
	const installActions = {
		hidden: false,
		querySelectorAll() {
			return installButtons;
		},
	};
	const form = {
		dataset: {},
		getAttribute() {
			return '1';
		},
		querySelector(selector) {
			return {
				'.ran-booster-repository-input': repository,
				'[data-ran-booster-source-controls]': sourceControls,
				'[data-ran-booster-source-repository-guidance]': guidance,
				'[data-ran-booster-branch-install-actions]': installActions,
			}[selector];
		},
		addEventListener(type, listener) {
			formListeners.set(type, listener);
		},
	};

	globalThis.document = {
		querySelectorAll(selector) {
			return selector === '[data-ran-booster-package-create="1"]'
				? [form]
				: [sourceControls];
		},
	};
	globalThis.window = {
		CustomEvent: class {
			constructor(type, options) {
				this.type = type;
				this.detail = options.detail;
			}
		},
	};

	return {
		branch,
		branchPane,
		guidance,
		installButtons,
		release,
		releasePane,
		repository,
		repositoryListeners,
		sourceControls,
		sourceEvents,
		installActions,
	};
}

test('source and install controls wait for a valid repository', () => {
	const state = fixture();

	try {
		loadInitializer()();

		assert.equal(state.sourceControls.disabled, true);
		assert.equal(state.guidance.hidden, false);
		assert.equal(
			state.installButtons.every((button) => button.disabled),
			true
		);

		state.repository.value = 'owner/package';
		state.repositoryListeners.get('input')();

		assert.equal(state.sourceControls.disabled, false);
		assert.equal(state.guidance.hidden, true);
		assert.equal(
			state.installButtons.every((button) => !button.disabled),
			true
		);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('package source choices reveal their matching panels', () => {
	const state = fixture('owner/package');

	try {
		loadInitializer()();
		state.release.tab.click();

		assert.equal(state.release.attributes.get('aria-pressed'), 'true');
		assert.equal(state.branch.attributes.get('aria-pressed'), 'false');
		assert.equal(state.branchPane.hidden, true);
		assert.equal(state.releasePane.hidden, false);
		assert.equal(state.installActions.hidden, true);
		assert.equal(
			state.sourceEvents.at(-1).type,
			'ran-booster:package-source-changed'
		);
		assert.deepEqual(state.sourceEvents.at(-1).detail, {
			source: 'release_asset',
		});
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('package source navigation waits for the authoritative response', () => {
	const state = fixture('owner/package');
	state.release.attributes.set(
		'href',
		'https://example.test/wp-admin/admin.php?source_view=release_asset'
	);

	try {
		loadInitializer()();
		state.release.tab.click();

		assert.equal(state.release.attributes.get('aria-pressed'), 'false');
		assert.equal(state.branch.attributes.get('aria-pressed'), 'true');
		assert.equal(state.branchPane.hidden, false);
		assert.equal(state.releasePane.hidden, true);
		assert.equal(state.installActions.hidden, false);
		assert.deepEqual(state.sourceEvents, []);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});
