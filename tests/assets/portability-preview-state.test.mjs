import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-portability.js', import.meta.url),
	'utf8'
);

function loadFunction(name) {
	const signature = `\tfunction ${name}() {`;
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

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

function checkbox(selector, checked = true) {
	const listeners = new Map();

	return {
		checked,
		indeterminate: false,
		listeners,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
		matches(candidate) {
			return candidate === selector;
		},
	};
}

function row(index, action, checked = true) {
	const choice =
		action === 'install' || action === 'adopt'
			? checkbox('[data-portability-select]', checked)
			: null;

	return {
		choice,
		getAttribute(name) {
			if (name === 'data-portability-row') {
				return String(index);
			}
			if (name === 'data-portability-action') {
				return action;
			}
			return null;
		},
		querySelector(selector) {
			return selector === '[data-portability-select]' ? choice : null;
		},
	};
}

function exportFixture(states) {
	const master = checkbox('[data-portability-export-select-all]');
	const submit = { disabled: false };
	const choices = states.map((checked) =>
		checkbox('[data-portability-export-select]', checked)
	);

	globalThis.document = {
		querySelector(selector) {
			return (
				{
					'[data-portability-export-select-all]': master,
					'[data-portability-export-submit]': submit,
				}[selector] || null
			);
		},
		querySelectorAll(selector) {
			return selector === '[data-portability-export-select]'
				? choices
				: [];
		},
	};

	return { choices, master, submit };
}

function progressButton(idleLabel, busyLabel, labelSelector) {
	const attributes = new Map();
	const classes = new Set([
		'button',
		'button-primary',
		'ran-booster-portability__progress-button',
	]);
	const label = { textContent: idleLabel };
	const button = {
		dataset: { busyLabel },
		disabled: false,
		classList: {
			contains(name) {
				return classes.has(name);
			},
			toggle(name, force) {
				if (force) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			},
		},
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		querySelector(selector) {
			return selector === labelSelector ? label : null;
		},
		removeAttribute(name) {
			attributes.delete(name);
		},
		setAttribute(name, value) {
			attributes.set(name, value);
		},
	};

	return { button, label };
}

function importFixture(fetchResponse, rowSets = {}) {
	const listeners = new Map();
	const events = [];
	const forms = [];
	const file = { files: [{ name: 'blueprint.zip' }] };
	const password = {
		matches(selector) {
			return selector === 'input[name="password"]';
		},
	};
	const targetCredential = {
		matches(selector) {
			return selector === '[data-portability-target-credential]';
		},
	};
	const previewProgress = progressButton(
		'Review blueprint',
		'Reviewing blueprint…',
		'[data-portability-preview-label]'
	);
	const previewLabel = previewProgress.label;
	const previewSubmit = previewProgress.button;
	const message = { className: '', hidden: true, textContent: '' };
	const applyProgress = progressButton(
		'Apply selected changes',
		'Applying…',
		'[data-portability-apply-label]'
	);
	const apply = {
		...applyProgress.button,
		disabled: false,
		addEventListener(type, listener) {
			listeners.set(`apply:${type}`, listener);
		},
	};
	const results = {
		clears: 0,
		items: [],
		appendChild(item) {
			this.items.push(item);
		},
		replaceChildren() {
			this.clears += 1;
			this.items = [];
		},
	};
	let html = '<table>empty</table>';
	let currentRows = [];
	let master = null;
	const review = {
		querySelector(selector) {
			return selector === '[data-portability-select-all]' ? master : null;
		},
		querySelectorAll(selector) {
			return selector ===
				'[data-portability-row][data-portability-action]'
				? currentRows
				: [];
		},
	};

	Object.defineProperty(review, 'innerHTML', {
		get() {
			return html;
		},
		set(value) {
			html = value;
			currentRows = (rowSets[value] || []).map((item) => row(...item));
			master = currentRows.some((item) => item.choice)
				? checkbox('[data-portability-select-all]')
				: null;
		},
	});

	const form = {
		dataset: { portabilityApplyNonce: 'apply-nonce' },
		addEventListener(type, listener) {
			listeners.set(`form:${type}`, listener);
		},
		querySelector(selector) {
			return (
				{
					'input[type="file"]': file,
					'[data-portability-preview-submit]': previewSubmit,
				}[selector] || null
			);
		},
	};
	const root = {
		addEventListener(type, listener) {
			listeners.set(`root:${type}`, listener);
		},
		querySelector(selector) {
			return (
				{
					'[data-portability-preview]': form,
					'[data-portability-review]': review,
					'[data-portability-preview-message]': message,
					'[data-portability-apply]': apply,
					'[data-portability-apply-results]': results,
				}[selector] || null
			);
		},
		querySelectorAll() {
			return [];
		},
	};

	globalThis.document = {
		dispatchEvent(event) {
			events.push(event);
		},
		createElement() {
			return {
				appendChild() {},
				className: '',
				textContent: '',
			};
		},
		querySelector(selector) {
			return selector === '.ran-booster-portability' ? root : null;
		},
	};
	globalThis.FormData = class {
		constructor() {
			this.values = new Map([
				['action', 'ran_booster_preview_blueprint'],
				['nonce', 'preview-nonce'],
			]);
			forms.push(this);
		}

		append(name, value) {
			this.values.set(name, value);
		}

		get(name) {
			return this.values.get(name);
		}

		set(name, value) {
			this.values.set(name, value);
		}
	};
	globalThis.window = {
		CustomEvent: class {
			constructor(type, options) {
				this.detail = options.detail;
				this.type = type;
			}
		},
		fetch: (url, options) => fetchResponse(options.body),
		ranBoosterPortability: { ajaxUrl: '/admin-ajax.php' },
	};

	return {
		apply,
		events,
		file,
		forms,
		listeners,
		master: () => master,
		message,
		password,
		applyLabel: applyProgress.label,
		previewLabel,
		previewSubmit,
		results,
		review,
		rows: () => currentRows,
		targetCredential,
	};
}

function success(html = '<table>review</table>', data = {}) {
	return {
		json: async () => ({
			success: true,
			data: { html, ...data },
		}),
	};
}

function tick() {
	return new Promise((resolve) => setImmediate(resolve));
}

function cleanup() {
	delete globalThis.document;
	delete globalThis.FormData;
	delete globalThis.window;
}

test('export master selection reflects checked, unchecked and mixed rows', () => {
	const state = exportFixture([true, false]);

	try {
		loadFunction('initPortabilityExportSelection')();

		assert.equal(state.master.checked, false);
		assert.equal(state.master.indeterminate, true);

		state.choices[1].checked = true;
		state.choices[1].listeners.get('change')();
		assert.equal(state.master.checked, true);
		assert.equal(state.master.indeterminate, false);

		state.master.checked = false;
		state.master.listeners.get('change')();
		assert.deepEqual(
			state.choices.map((choice) => choice.checked),
			[false, false]
		);
		assert.equal(state.master.indeterminate, false);
		assert.equal(state.submit.disabled, true);
	} finally {
		cleanup();
	}
});

test('changing the artifact or password resets saved import choices', async () => {
	const state = importFixture(async () => success(), {
		'<table>review</table>': [['0', 'install', true]],
	});

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		assert.equal(state.apply.disabled, true);

		state.listeners.get('form:change')({ target: state.file });
		assert.equal(state.review.innerHTML, '<table>empty</table>');
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.equal(state.rows()[0].choice.checked, true);

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		state.listeners.get('form:input')({ target: state.password });
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.equal(state.rows()[0].choice.checked, true);
	} finally {
		cleanup();
	}
});

test('preview shows the shared busy state and restores it after success', async () => {
	let finishFetch;
	const state = importFixture(
		() =>
			new Promise((resolve) => {
				finishFetch = resolve;
			})
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });

		assert.equal(state.previewSubmit.disabled, true);
		assert.equal(state.previewSubmit.getAttribute('aria-busy'), 'true');
		assert.equal(state.previewSubmit.getAttribute('aria-disabled'), 'true');
		assert.equal(
			state.previewSubmit.classList.contains(
				'ran-booster-update-is-active'
			),
			true
		);
		assert.equal(state.previewLabel.textContent, 'Reviewing blueprint…');

		finishFetch(success());
		await tick();

		assert.equal(state.previewSubmit.disabled, false);
		assert.equal(state.previewSubmit.getAttribute('aria-busy'), null);
		assert.equal(state.previewSubmit.getAttribute('aria-disabled'), null);
		assert.equal(
			state.previewSubmit.classList.contains(
				'ran-booster-update-is-active'
			),
			false
		);
		assert.equal(state.previewLabel.textContent, 'Review blueprint');
		assert.equal(state.events.length, 1);
		assert.equal(
			state.events[0].type,
			'ran-booster:admin-mutation-success'
		);
		assert.deepEqual(state.events[0].detail, {
			message: 'Transporter Blueprint reviewed.',
		});
	} finally {
		cleanup();
	}
});

test('a failed preview cannot leave an older review actionable', async () => {
	let finishFetch;
	const state = importFixture(
		() =>
			new Promise((resolve) => {
				finishFetch = resolve;
			})
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.review.innerHTML = '<table>stale</table>';
		state.apply.disabled = false;
		state.listeners.get('form:submit')({ preventDefault() {} });

		assert.equal(state.review.innerHTML, '<table>empty</table>');
		assert.equal(state.apply.disabled, true);
		assert.equal(state.results.clears, 1);
		assert.equal(state.previewSubmit.disabled, true);
		assert.equal(state.previewSubmit.getAttribute('aria-busy'), 'true');

		finishFetch({
			json: async () => ({
				success: false,
				data: { message: 'Safe blueprint read error.' },
			}),
		});
		await tick();

		assert.equal(state.message.textContent, 'Safe blueprint read error.');
		assert.equal(state.message.className, 'notice inline notice-error');
		assert.equal(state.review.innerHTML, '<table>empty</table>');
		assert.equal(state.apply.disabled, true);
		assert.equal(state.previewSubmit.disabled, false);
		assert.equal(state.previewSubmit.getAttribute('aria-busy'), null);
		assert.equal(
			state.previewSubmit.classList.contains(
				'ran-booster-update-is-active'
			),
			false
		);
		assert.equal(state.previewLabel.textContent, 'Review blueprint');
	} finally {
		cleanup();
	}
});

test('import master and apply state follow only checked actionable rows', async () => {
	const state = importFixture(async () => success(), {
		'<table>review</table>': [
			['0', 'install', true],
			['1', 'adopt', true],
			['2', 'protected', false],
		],
	});

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();

		assert.equal(state.master().checked, true);
		assert.equal(state.apply.disabled, false);

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		assert.equal(state.master().checked, false);
		assert.equal(state.master().indeterminate, true);

		state.master().checked = false;
		state.listeners.get('root:change')({ target: state.master() });
		assert.equal(state.rows()[1].choice.checked, false);
		assert.equal(state.master().indeterminate, false);
		assert.equal(state.apply.disabled, true);

		state.master().checked = true;
		state.listeners.get('root:change')({ target: state.master() });
		assert.equal(state.rows()[0].choice.checked, true);
		assert.equal(state.rows()[1].choice.checked, true);
		assert.equal(state.apply.disabled, false);
	} finally {
		cleanup();
	}
});

test('target credential previews preserve known choices and keep new defaults', async () => {
	let previews = 0;
	const state = importFixture(
		async () =>
			success(
				++previews === 1
					? '<table>first</table>'
					: '<table>second</table>'
			),
		{
			'<table>first</table>': [
				['0', 'install', true],
				['1', 'adopt', true],
			],
			'<table>second</table>': [
				['0', 'install', true],
				['1', 'adopt', true],
				['2', 'install', true],
			],
		}
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		state.listeners.get('root:change')({
			target: state.targetCredential,
		});
		await tick();

		assert.deepEqual(
			state.rows().map((item) => item.choice.checked),
			[false, true, true]
		);
		assert.equal(state.master().indeterminate, true);
	} finally {
		cleanup();
	}
});

test('apply sends only selected rows serially and preserves choices on review', async () => {
	const applied = [];
	let finishApply;
	let finishReview;
	let previews = 0;
	const state = importFixture(
		(data) => {
			if (data.get('action') === 'ran_booster_apply_blueprint') {
				applied.push({
					adopt: data.get('adopt'),
					row: data.get('row'),
				});
				return new Promise((resolve) => {
					finishApply = () =>
						resolve(
							success('', {
								message: 'Applied.',
								status: 'installed',
							})
						);
				});
			}
			if (++previews === 1) {
				return success('<table>review</table>');
			}
			return new Promise((resolve) => {
				finishReview = () => resolve(success('<table>review</table>'));
			});
		},
		{
			'<table>review</table>': [
				['0', 'install', true],
				['1', 'adopt', true],
			],
		}
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		const applying = state.listeners.get('apply:click')();

		assert.equal(state.apply.disabled, true);
		assert.equal(state.apply.getAttribute('aria-busy'), 'true');
		assert.equal(state.apply.getAttribute('aria-disabled'), 'true');
		assert.equal(
			state.apply.classList.contains('ran-booster-update-is-active'),
			true
		);
		assert.equal(state.applyLabel.textContent, 'Applying…');

		await state.listeners.get('apply:click')();
		assert.equal(applied.length, 1);

		finishApply();
		await tick();
		assert.equal(state.apply.getAttribute('aria-busy'), 'true');
		assert.equal(state.applyLabel.textContent, 'Applying…');
		assert.equal(state.previewSubmit.getAttribute('aria-busy'), null);
		assert.equal(state.previewLabel.textContent, 'Review blueprint');

		finishReview();
		await applying;

		assert.deepEqual(applied, [{ adopt: '1', row: '1' }]);
		assert.deepEqual(
			state.rows().map((item) => item.choice.checked),
			[false, true]
		);
		assert.equal(state.master().indeterminate, true);
		assert.equal(state.apply.disabled, false);
		assert.equal(state.apply.getAttribute('aria-busy'), null);
		assert.equal(state.apply.getAttribute('aria-disabled'), null);
		assert.equal(
			state.apply.classList.contains('ran-booster-update-is-active'),
			false
		);
		assert.equal(state.applyLabel.textContent, 'Apply selected changes');
	} finally {
		cleanup();
	}
});

test('apply restores a disabled idle state when the final review fails', async () => {
	let previews = 0;
	const state = importFixture(
		async (data) => {
			if (data.get('action') === 'ran_booster_apply_blueprint') {
				return success('', {
					message: 'Applied.',
					status: 'installed',
				});
			}
			if (++previews === 1) {
				return success('<table>review</table>');
			}
			return {
				json: async () => ({
					success: false,
					data: { message: 'Safe final review failure.' },
				}),
			};
		},
		{
			'<table>review</table>': [['0', 'install', true]],
		}
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();

		await state.listeners.get('apply:click')();

		assert.equal(state.message.textContent, 'Safe final review failure.');
		assert.equal(state.apply.disabled, true);
		assert.equal(state.apply.getAttribute('aria-busy'), null);
		assert.equal(
			state.apply.classList.contains('ran-booster-update-is-active'),
			false
		);
		assert.equal(state.applyLabel.textContent, 'Apply selected changes');
	} finally {
		cleanup();
	}
});
