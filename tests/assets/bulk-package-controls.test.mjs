import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadBulkControls(translations = {}) {
	const signature = '\tfunction initBulkPackageControls() {';
	const start = source.indexOf(signature);

	assert.notEqual(
		start,
		-1,
		'The bulk package controls function must exist.'
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
		'The bulk package controls function must be complete.'
	);

	const i18n = {
		__(text) {
			return translations[text] || text;
		},
		_n(singular, plural, count) {
			return (
				translations[count === 1 ? singular : plural] ||
				(count === 1 ? singular : plural)
			);
		},
		sprintf(template, ...values) {
			let index = 0;
			return template.replace(/%(?:\d+\$)?[ds]/g, function () {
				const value = values[index];
				index += 1;
				return String(value);
			});
		},
	};

	return Function(
		'__',
		'_n',
		'sprintf',
		`"use strict"; return (${source.slice(start, end)});`
	)(i18n.__, i18n._n, i18n.sprintf);
}

function fixture() {
	const listeners = new Map();
	const status = { textContent: '' };
	const apply = { disabled: false, textContent: 'Apply' };
	const action = {
		value: 'queue-update',
		addEventListener(type, listener) {
			listeners.set(`action:${type}`, listener);
		},
	};
	const selectAll = checkbox();
	const packages = [checkbox(), checkbox()];
	const form = {
		id: 'ran-booster-plugin-bulk-form',
		attributes: new Map(),
		addEventListener(type, listener) {
			listeners.set(`form:${type}`, listener);
		},
		getAttribute(name) {
			if (name === 'data-package-type-singular') {
				return 'branch package';
			}
			if (name === 'data-package-type-label') {
				return 'branch packages';
			}
			if (name === 'data-reinstall-confirm-singular') {
				return 'Reinstall the selected branch and overwrite local changes?';
			}
			if (name === 'data-reinstall-confirm-plural') {
				return 'Reinstall {count} selected branches and overwrite local changes?';
			}
			return null;
		},
		querySelector(selector) {
			if (selector === '[data-ran-booster-selection-status]') {
				return status;
			}
			if (selector === '[data-ran-booster-bulk-apply]') {
				return apply;
			}
			if (selector === 'select[name="ran_booster[bulk_action]"]') {
				return action;
			}
			return null;
		},
		setAttribute(name, value) {
			this.attributes.set(name, value);
		},
	};
	packages.forEach((item) => {
		item.form = form.id;
	});
	const document = {
		querySelector(selector) {
			if (selector === '[data-ran-booster-bulk-form]') {
				return form;
			}
			if (selector === '[data-ran-booster-select-all]') {
				return selectAll;
			}
			return null;
		},
		querySelectorAll(selector) {
			return selector === '[data-ran-booster-package-checkbox]'
				? packages
				: [];
		},
	};

	return {
		action,
		apply,
		document,
		form,
		listeners,
		packages,
		selectAll,
		status,
	};
}

function checkbox(eligibleForBranchReinstall = true) {
	const listeners = new Map();

	return {
		checked: false,
		disabled: false,
		focused: false,
		indeterminate: false,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
		dispatch(type) {
			listeners.get(type)?.();
		},
		focus() {
			this.focused = true;
		},
		getAttribute(name) {
			if (name === 'form') {
				return this.form;
			}
			if (name === 'data-ran-booster-branch-reinstall-eligible') {
				return eligibleForBranchReinstall ? '1' : '0';
			}
			return null;
		},
	};
}

test('branch reinstall only enables Apply when a selected package is eligible', () => {
	const fixtureState = fixture();
	fixtureState.packages[1] = checkbox(false);
	fixtureState.packages[1].form = fixtureState.form.id;
	globalThis.document = fixtureState.document;

	try {
		loadBulkControls()();
		fixtureState.packages[1].checked = true;
		fixtureState.packages[1].dispatch('change');

		assert.equal(fixtureState.apply.disabled, true);
		assert.equal(
			fixtureState.status.textContent,
			'1 branch package selected. 0 eligible for branch Reinstall.'
		);

		fixtureState.packages[0].checked = true;
		fixtureState.packages[0].dispatch('change');

		assert.equal(fixtureState.apply.disabled, false);
		assert.equal(
			fixtureState.status.textContent,
			'2 branch packages selected. 1 eligible for branch Reinstall.'
		);
	} finally {
		delete globalThis.document;
	}
});

test('select-all updates every externally associated package checkbox', () => {
	const fixtureState = fixture();
	globalThis.document = fixtureState.document;

	try {
		loadBulkControls()();
		fixtureState.selectAll.checked = true;
		fixtureState.selectAll.dispatch('change');

		assert.deepEqual(
			fixtureState.packages.map((item) => item.checked),
			[true, true]
		);
		assert.equal(
			fixtureState.status.textContent,
			'2 branch packages selected'
		);
		assert.equal(fixtureState.selectAll.indeterminate, false);
	} finally {
		delete globalThis.document;
	}
});

test('submit rejects an empty selection and focuses the first checkbox', () => {
	const fixtureState = fixture();
	globalThis.document = fixtureState.document;

	try {
		loadBulkControls()();
		const event = {
			defaultPrevented: false,
			preventDefault() {
				this.defaultPrevented = true;
			},
		};
		fixtureState.listeners.get('form:submit')(event);

		assert.equal(event.defaultPrevented, true);
		assert.equal(fixtureState.packages[0].focused, true);
		assert.equal(
			fixtureState.status.textContent,
			'Select at least one branch package.'
		);
		assert.equal(fixtureState.apply.disabled, true);
	} finally {
		delete globalThis.document;
	}
});

test('a valid submission leaves busy ownership to the shared runtime', () => {
	const fixtureState = fixture();
	globalThis.document = fixtureState.document;
	globalThis.window = { confirm: () => true };

	try {
		loadBulkControls()();
		fixtureState.packages[0].checked = true;
		const event = {
			defaultPrevented: false,
			preventDefault() {
				this.defaultPrevented = true;
			},
		};
		fixtureState.listeners.get('form:submit')(event);

		assert.equal(event.defaultPrevented, false);
		assert.equal(fixtureState.selectAll.indeterminate, true);
		assert.equal(fixtureState.apply.disabled, false);
		assert.equal(fixtureState.apply.textContent, 'Apply');
		assert.equal(fixtureState.form.attributes.has('aria-busy'), false);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('branch reinstall asks for count-aware confirmation before submitting', () => {
	const fixtureState = fixture();
	const messages = [];
	globalThis.document = fixtureState.document;
	globalThis.window = {
		confirm(message) {
			messages.push(message);
			return false;
		},
	};

	try {
		loadBulkControls()();
		fixtureState.packages.forEach((item) => {
			item.checked = true;
		});
		const event = {
			defaultPrevented: false,
			preventDefault() {
				this.defaultPrevented = true;
			},
		};
		fixtureState.listeners.get('form:submit')(event);

		assert.equal(event.defaultPrevented, true);
		assert.deepEqual(messages, [
			'Reinstall 2 selected branches and overwrite local changes?',
		]);
		assert.equal(fixtureState.status.textContent, 'Reinstall cancelled.');
		assert.equal(fixtureState.form.attributes.has('aria-busy'), false);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('selection status and validation use translated plurals without changing focus', () => {
	const fixtureState = fixture();
	fixtureState.packages[1] = checkbox(false);
	fixtureState.packages[1].form = fixtureState.form.id;
	globalThis.document = fixtureState.document;

	try {
		loadBulkControls({
			'%1$d %2$s selected': '%1$d %2$s sélectionnés',
			'%1$s. %2$d eligible for branch Reinstall.':
				'%1$s. %2$d paquets peuvent être réinstallés depuis la branche.',
			'Select at least one %s.': 'Sélectionnez au moins un %s.',
			'Reinstall cancelled.': 'Réinstallation annulée.',
		})();
		fixtureState.packages[0].checked = true;
		fixtureState.packages[0].dispatch('change');
		fixtureState.packages[1].checked = true;
		fixtureState.packages[1].dispatch('change');

		assert.equal(
			fixtureState.status.textContent,
			'2 branch packages sélectionnés. 1 paquets peuvent être réinstallés depuis la branche.'
		);

		fixtureState.packages.forEach((item) => {
			item.checked = false;
		});
		const event = { preventDefault() {} };
		fixtureState.listeners.get('form:submit')(event);
		assert.equal(
			fixtureState.status.textContent,
			'Sélectionnez au moins un branch package.'
		);
		assert.equal(fixtureState.packages[0].focused, true);
	} finally {
		delete globalThis.document;
	}
});
