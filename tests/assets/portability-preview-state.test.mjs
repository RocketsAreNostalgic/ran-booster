import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-portability.js', import.meta.url),
	'utf8'
);

function sprintf(format, ...values) {
	let next = 0;

	return format.replace(/%(?:(\d+)\$)?[ds]/g, (match, position) => {
		const index = position ? Number(position) - 1 : next++;
		return String(values[index] ?? match);
	});
}

function loadFunction(name, translations = {}) {
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

	return Function(
		'__',
		'_n',
		'sprintf',
		`"use strict"; return (${source.slice(start, end)});`
	)(
		(message) => translations[message] || message,
		(single, plural, count) =>
			translations[count === 1 ? single : plural] ||
			(count === 1 ? single : plural),
		sprintf
	);
}

function checkbox(selector, checked = true, exportIndex = null) {
	const listeners = new Map();

	return {
		checked,
		indeterminate: false,
		listeners,
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
		getAttribute(attribute) {
			return attribute === 'data-portability-export-package-index'
				? exportIndex
				: null;
		},
		matches(candidate) {
			return candidate === selector;
		},
	};
}

function row(
	index,
	action,
	checked = true,
	type = 'Plugin',
	name = `Package ${index}`,
	identifier = `package-${index}/package.php`,
	credentialOrdinal = null,
	credentialRecovery = false
) {
	const choice =
		action === 'install' || action === 'adopt' || credentialRecovery
			? checkbox('[data-portability-select]', checked)
			: null;

	const item = {
		choice,
		getAttribute(attribute) {
			if (attribute === 'data-portability-row') {
				return String(index);
			}
			if (attribute === 'data-portability-action') {
				return action;
			}
			if (attribute === 'data-portability-package-type') {
				return type;
			}
			if (attribute === 'data-portability-package-name') {
				return name;
			}
			if (attribute === 'data-portability-package-identifier') {
				return identifier;
			}
			if (attribute === 'data-portability-credential-ordinal') {
				return credentialOrdinal;
			}
			if (attribute === 'data-portability-credential-recovery') {
				return credentialRecovery ? 'true' : null;
			}
			return null;
		},
		querySelector(selector) {
			return selector === '[data-portability-select]' ? choice : null;
		},
	};
	if (choice) {
		choice.closest = () => item;
	}

	return item;
}

function exportFixture(states) {
	const master = checkbox('[data-portability-export-select-all]');
	const submit = { disabled: false };
	const choices = states.map((checked, index) =>
		checkbox('[data-portability-export-select]', checked, String(index))
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

function credentialExportFixture() {
	const packageMaster = checkbox('[data-portability-export-select-all]');
	const packages = [
		checkbox('[data-portability-export-select]', true, '0'),
		checkbox('[data-portability-export-select]', true, '1'),
	];
	const credentials = [
		checkbox('[data-portability-export-credential]', false),
		checkbox('[data-portability-export-credential]', false),
	];
	credentials.forEach((choice) => {
		choice.disabled = false;
	});
	const rows = ['0', '1'].map((packageIndex, index) => ({
		dataset: { portabilityCredentialPackages: packageIndex },
		hidden: false,
		querySelector(selector) {
			return selector === '[data-portability-export-credential]'
				? credentials[index]
				: null;
		},
	}));
	rows.push({
		dataset: { portabilityCredentialPackages: '' },
		hidden: false,
		querySelector() {
			return null;
		},
	});
	const submit = { disabled: false };
	const events = [];
	const form = {
		dispatchEvent(event) {
			events.push(event.type);
		},
	};
	const summary = {
		dataset: {
			credentialPlural: '%d selected repository credential profiles',
			credentialSingular: '1 selected repository credential profile',
			packageOnlyTemplate:
				'Create a Transporter Blueprint for %s without repository credentials.',
			packagePlural: '%d packages',
			packageSingular: '1 package',
			protectedTemplate:
				'Create a Transporter Blueprint for %1$s using %2$s. Credential permissions have not been assessed.',
		},
		textContent: '',
	};

	globalThis.document = {
		querySelector(selector) {
			return (
				{
					'[data-portability-export-select-all]': packageMaster,
					'[data-portability-export-submit]': submit,
					'[data-portability-export-form]': form,
					'[data-portability-export-summary]': summary,
				}[selector] || null
			);
		},
		querySelectorAll(selector) {
			return (
				{
					'[data-portability-export-select]': packages,
					'[data-portability-export-credential-row]': rows,
					'[data-portability-export-credential]': credentials,
				}[selector] || []
			);
		},
	};
	globalThis.window = {
		CustomEvent: class {
			constructor(type) {
				this.type = type;
			}
		},
	};

	return {
		credentials,
		events,
		packageMaster,
		packages,
		rows,
		summary,
	};
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

function importFixture(fetchResponse, rowSets = {}, options = {}) {
	const listeners = new Map();
	const events = [];
	const forms = [];
	const htmxProcesses = [];
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
		disabled: true,
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
	const applySummary = { textContent: '' };
	const reviewError = { hidden: true };
	let html = '<table>empty</table>';
	let currentRows = [];
	let master = null;
	const reviewAttributes = new Map();
	const reviewClasses = new Set();
	const control = (selectors, value = '') => {
		const attributes = new Map();
		return {
			attributes,
			disabled: false,
			focusCalls: 0,
			value,
			closest(selector) {
				return selector === '[data-portability-credential-group]'
					? group
					: null;
			},
			dispatchEvent() {},
			focus() {
				this.focusCalls += 1;
			},
			getAttribute(name) {
				return attributes.get(name) ?? null;
			},
			matches(selector) {
				return selectors.includes(selector);
			},
			setAttribute(name, attributeValue) {
				attributes.set(name, attributeValue);
			},
		};
	};
	const credentialActions = {
		import: control(
			[
				'[data-portability-credential-action]',
				'[data-portability-credential-refresh]',
			],
			'import'
		),
		target: control(['[data-portability-credential-action]'], 'target'),
		leave: control(
			[
				'[data-portability-credential-action]',
				'[data-portability-credential-refresh]',
			],
			'leave'
		),
	};
	const credentialTarget = control(
		[
			'[data-portability-credential-target]',
			'[data-portability-credential-refresh]',
		],
		options.targetValue || ''
	);
	credentialTarget.disabled = true;
	credentialTarget.dispatchedChanges = 0;
	credentialTarget.dispatchEvent = function () {
		this.dispatchedChanges += 1;
		listeners.get('root:change')?.({ target: this });
	};
	const group = {
		getAttribute(name) {
			return name === 'data-portability-credential-ordinal' ? '0' : null;
		},
		querySelector(selector) {
			if (selector === '[data-portability-credential-target]') {
				return credentialTarget;
			}
			const match = selector.match(
				/^\[data-portability-credential-action\]\[value="(import|target|leave)"\]$/
			);
			return match ? credentialActions[match[1]] : null;
		},
	};
	const credentialRefreshControls = [
		credentialActions.import,
		credentialActions.leave,
		credentialTarget,
	];
	function setRows(rows) {
		currentRows = rows.map((item) => row(...item));
		master = currentRows.some((item) => item.choice)
			? checkbox('[data-portability-select-all]')
			: null;
	}
	const review = {
		classList: {
			contains(name) {
				return reviewClasses.has(name);
			},
			remove(name) {
				reviewClasses.delete(name);
			},
			toggle(name, force) {
				if (force) {
					reviewClasses.add(name);
				} else {
					reviewClasses.delete(name);
				}
			},
		},
		getAttribute(name) {
			return reviewAttributes.get(name) ?? null;
		},
		querySelector(selector) {
			if (selector === '[data-portability-select-all]') {
				return master;
			}
			return selector === '[data-portability-credential-ordinal="0"]'
				? group
				: null;
		},
		querySelectorAll(selector) {
			if (
				selector === '[data-portability-row][data-portability-action]'
			) {
				return currentRows;
			}
			if (selector === '[data-portability-credential-refresh]') {
				return credentialRefreshControls;
			}
			if (
				selector ===
				'[data-portability-select], [data-portability-select-all]'
			) {
				return [
					...currentRows.map((item) => item.choice).filter(Boolean),
					...(master ? [master] : []),
				];
			}
			return [];
		},
		removeAttribute(name) {
			reviewAttributes.delete(name);
		},
		toggleAttribute(name, force) {
			if (force) {
				reviewAttributes.set(name, '');
			} else {
				reviewAttributes.delete(name);
			}
		},
	};

	Object.defineProperty(review, 'innerHTML', {
		get() {
			return html;
		},
		set(value) {
			html = value;
			setRows(rowSets[value] || []);
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
					'[data-portability-apply-summary]': applySummary,
					'[data-portability-review-error]': reviewError,
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
				children: [],
				appendChild(child) {
					this.children.push(child);
					child.parentNode = this;
				},
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
			constructor(type, eventOptions) {
				this.detail = eventOptions.detail;
				this.type = type;
			}
		},
		fetch: (url, requestOptions) => fetchResponse(requestOptions.body),
		ranBoosterPortability: { ajaxUrl: '/admin-ajax.php' },
	};
	if (options.htmx) {
		globalThis.window.htmx = {
			process(scope) {
				htmxProcesses.push(scope);
			},
		};
	}

	return {
		apply,
		applySummary,
		events,
		file,
		forms,
		credentialActions,
		credentialRefreshControls,
		credentialTarget,
		group,
		htmxProcesses,
		listeners,
		master: () => master,
		message,
		password,
		applyLabel: applyProgress.label,
		previewLabel,
		previewSubmit,
		results,
		review,
		reviewError,
		rows: () => currentRows,
		swapRows(rows) {
			setRows(rows);
		},
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

test('credential export selection follows package relevance and reports selected profiles', () => {
	const state = credentialExportFixture();

	try {
		loadFunction('initPortabilityExportSelection')();
		assert.equal(
			state.summary.textContent,
			'Create a Transporter Blueprint for 2 packages without repository credentials.'
		);
		assert.equal(state.rows[2].hidden, false);

		state.credentials[0].checked = true;
		state.credentials[0].listeners.get('change')();
		assert.equal(
			state.summary.textContent,
			'Create a Transporter Blueprint for 2 packages using 1 selected repository credential profile. Credential permissions have not been assessed.'
		);

		state.packages[0].checked = false;
		state.packages[0].listeners.get('change')();
		assert.equal(state.rows[0].hidden, true);
		assert.equal(state.credentials[0].checked, false);
		assert.equal(state.credentials[0].disabled, true);
		assert.equal(state.rows[2].hidden, false);
		assert.deepEqual(state.events, [
			'ran-booster:portability-credentials-changed',
		]);

		state.credentials[1].checked = true;
		state.credentials[1].listeners.get('change')();
		assert.equal(state.credentials[1].checked, true);
		assert.equal(
			state.summary.textContent,
			'Create a Transporter Blueprint for 1 package using 1 selected repository credential profile. Credential permissions have not been assessed.'
		);
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
		state.listeners.get('root:change')({
			target: state.credentialActions.import,
		});
		await tick();
		assert.equal(
			state.forms.at(-1).get('credential_decisions[0][action]'),
			'import'
		);

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		assert.equal(state.apply.disabled, true);

		state.listeners.get('form:change')({ target: state.file });
		assert.equal(state.review.innerHTML, '<table>empty</table>');
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.equal(state.rows()[0].choice.checked, true);
		assert.equal(
			state.forms.at(-1).get('credential_decisions[0][action]'),
			undefined
		);

		state.listeners.get('root:change')({
			target: state.credentialActions.import,
		});
		await tick();
		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		state.listeners.get('form:input')({ target: state.password });
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.equal(state.rows()[0].choice.checked, true);
		assert.equal(
			state.forms.at(-1).get('credential_decisions[0][action]'),
			undefined
		);
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

test('HTMX credential refresh keeps the full review and restores selections after a narrow swap', async () => {
	const state = importFixture(
		async () => success('<table>review</table>'),
		{
			'<table>review</table>': [
				[
					'0',
					'install',
					true,
					'Plugin',
					'Package 0',
					'package-0/package.php',
					'0',
				],
				['1', 'adopt', true],
			],
		},
		{ htmx: true }
	);

	try {
		loadFunction('initPortabilityPreview')();
		assert.deepEqual(
			state.credentialRefreshControls.map((control) =>
				control.getAttribute('hx-post')
			),
			['/admin-ajax.php', '/admin-ajax.php', '/admin-ajax.php']
		);
		assert.deepEqual(state.htmxProcesses, [state.review]);

		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.deepEqual(state.htmxProcesses, [state.review, state.review]);
		assert.equal(state.apply.disabled, false);

		state.rows()[0].choice.checked = false;
		state.listeners.get('root:change')({ target: state.rows()[0].choice });
		const fullReview = state.review.innerHTML;
		state.listeners.get('root:change')({
			target: state.credentialActions.import,
		});
		assert.equal(state.apply.disabled, true);
		assert.equal(state.review.innerHTML, fullReview);

		const xhr = {};
		const refreshDetail = {
			requestConfig: { elt: state.credentialActions.import },
			xhr,
		};
		state.listeners.get('root:htmx:beforeRequest')({
			detail: refreshDetail,
		});
		assert.equal(state.review.innerHTML, fullReview);
		assert.equal(state.review.getAttribute('aria-busy'), '');
		assert.equal(state.review.classList.contains('is-updating'), true);
		assert.equal(state.apply.disabled, true);
		assert.equal(state.rows()[0].choice.disabled, true);

		const resultClears = state.results.clears;
		state.swapRows([
			[
				'0',
				'install',
				true,
				'Plugin',
				'Package 0',
				'package-0/package.php',
				'0',
			],
			['1', 'adopt', true],
			['2', 'install', true],
		]);
		state.listeners.get('root:htmx:afterSwap')({ detail: refreshDetail });

		assert.equal(state.review.innerHTML, fullReview);
		assert.deepEqual(
			state.rows().map((item) => item.choice.checked),
			[false, true, true]
		);
		assert.equal(state.master().indeterminate, true);
		assert.equal(state.apply.disabled, false);
		assert.equal(state.review.getAttribute('aria-busy'), null);
		assert.equal(state.review.classList.contains('is-updating'), false);
		assert.equal(state.reviewError.hidden, true);
		assert.equal(state.results.clears, resultClears + 1);
	} finally {
		cleanup();
	}
});

test('failed HTMX credential refresh retains the review and keeps Apply disabled', async () => {
	const state = importFixture(
		async () => success('<table>review</table>'),
		{
			'<table>review</table>': [['0', 'install', true]],
		},
		{ htmx: true }
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		const fullReview = state.review.innerHTML;
		state.results.items.push({ retained: true });

		state.listeners.get('root:change')({
			target: state.credentialActions.leave,
		});
		const xhr = {};
		const refreshDetail = {
			requestConfig: { elt: state.credentialActions.leave },
			xhr,
		};
		state.listeners.get('root:htmx:beforeRequest')({
			detail: refreshDetail,
		});
		state.listeners.get('root:htmx:afterRequest')({
			detail: { ...refreshDetail, successful: false },
		});

		assert.equal(state.review.innerHTML, fullReview);
		assert.equal(state.rows().length, 1);
		assert.equal(state.rows()[0].choice.disabled, true);
		assert.equal(state.apply.disabled, true);
		assert.equal(state.reviewError.hidden, false);
		assert.equal(state.review.getAttribute('aria-busy'), null);
		assert.equal(state.review.classList.contains('is-updating'), false);
		assert.deepEqual(state.results.items, [{ retained: true }]);
	} finally {
		cleanup();
	}
});

test('target action without an ID only reveals and focuses its select', async () => {
	const state = importFixture(
		async () => success('<table>review</table>'),
		{
			'<table>review</table>': [
				[
					'0',
					'install',
					true,
					'Plugin',
					'Package 0',
					'package-0/package.php',
					'0',
				],
			],
		},
		{ htmx: true }
	);

	try {
		loadFunction('initPortabilityPreview', {
			'%d package change': '%d modification de paquet',
			'%d package changes': '%d modifications de paquet',
			'%d repository credential': '%d identifiant de dépôt',
			'%d repository credentials': '%d identifiants de dépôt',
			'%d saved credential': '%d identifiant enregistré',
			'%d saved credentials': '%d identifiants enregistrés',
			'Apply %1$s, import %2$s and use %3$s.':
				'Appliquer %1$s, importer %2$s et utiliser %3$s.',
			'Deployment will remain Disabled.':
				'Le déploiement restera désactivé.',
			'Credential permissions have not been assessed.':
				'Les autorisations des identifiants n’ont pas été évaluées.',
		})();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		const formCount = state.forms.length;
		const fullReview = state.review.innerHTML;

		state.listeners.get('root:change')({
			target: state.credentialActions.target,
		});
		assert.equal(state.credentialTarget.disabled, false);
		assert.equal(state.credentialTarget.focusCalls, 1);
		assert.equal(state.credentialTarget.dispatchedChanges, 0);
		assert.equal(state.forms.length, formCount);
		assert.equal(state.review.innerHTML, fullReview);
		assert.equal(state.apply.disabled, true);
		assert.equal(
			state.applySummary.textContent,
			'Appliquer 1 modification de paquet, importer 0 identifiants de dépôt et utiliser 0 identifiants enregistrés. Le déploiement restera désactivé. Les autorisations des identifiants n’ont pas été évaluées.'
		);

		state.credentialTarget.value = 'target-profile';
		state.listeners.get('root:change')({
			target: state.credentialActions.target,
		});
		assert.equal(state.credentialTarget.dispatchedChanges, 1);
		assert.equal(state.credentialTarget.focusCalls, 1);
		assert.equal(state.forms.length, formCount);
		assert.equal(state.apply.disabled, true);
		assert.equal(
			state.applySummary.textContent,
			'Appliquer 1 modification de paquet, importer 0 identifiants de dépôt et utiliser 1 identifiant enregistré. Le déploiement restera désactivé. Les autorisations des identifiants n’ont pas été évaluées.'
		);
	} finally {
		cleanup();
	}
});

test('credential decisions have no default and the same exact choice reaches Preview and Apply', async () => {
	const submitted = [];
	const state = importFixture(
		async (data) => {
			submitted.push(data);
			return data.get('action') === 'ran_booster_apply_blueprint'
				? success('', {
						credential_state: 'transferred_available',
						message: 'Applied.',
						status: 'installed',
					})
				: success();
		},
		{
			'<table>review</table>': [
				[
					'0',
					'install',
					true,
					'Plugin',
					'Package 0',
					'package-0/package.php',
					'0',
				],
			],
		}
	);
	const target = { disabled: true, value: '' };
	const group = {
		getAttribute(name) {
			return name === 'data-portability-credential-ordinal' ? '0' : null;
		},
		querySelector(selector) {
			return selector === '[data-portability-credential-target]'
				? target
				: null;
		},
	};
	const importChoice = {
		value: 'import',
		closest() {
			return group;
		},
		matches(selector) {
			return selector === '[data-portability-credential-action]';
		},
	};

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.equal(
			submitted[0].get('credential_decisions[0][action]'),
			undefined
		);

		state.listeners.get('root:change')({ target: importChoice });
		await tick();
		assert.equal(
			submitted[1].get('credential_decisions[0][action]'),
			'import'
		);
		assert.equal(
			submitted[1].get('credential_decisions[0][target_id]'),
			undefined
		);

		state.listeners.get('apply:click')();
		await tick();
		assert.equal(
			submitted[2].get('credential_decisions[0][action]'),
			'import'
		);
		assert.equal(
			state.results.items[0].children[1].textContent,
			'Transferred credential is available on this site.'
		);
	} finally {
		cleanup();
	}
});

test('managed credential recovery enables Apply without claiming a package change', async () => {
	const submitted = [];
	const state = importFixture(
		async (data) => {
			submitted.push(data);
			return data.get('action') === 'ran_booster_apply_blueprint'
				? success('', {
						credential_state: 'transferred_available',
						message:
							'The repository credential is available on this site. The managed package settings were not changed.',
						status: 'credential_available',
					})
				: success(
						data.get('credential_decisions[0][action]') === 'import'
							? '<table>managed recovery</table>'
							: '<table>managed</table>'
					);
		},
		{
			'<table>managed</table>': [
				[
					'0',
					'managed',
					true,
					'Plugin',
					'Package 0',
					'package-0/package.php',
					'0',
					false,
				],
			],
			'<table>managed recovery</table>': [
				[
					'0',
					'managed',
					true,
					'Plugin',
					'Package 0',
					'package-0/package.php',
					'0',
					true,
				],
			],
		}
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();
		assert.equal(state.apply.disabled, true);

		state.listeners.get('root:change')({
			target: state.credentialActions.import,
		});
		await tick();
		assert.equal(state.apply.disabled, false);
		assert.equal(
			state.applySummary.textContent,
			'Apply 0 package changes, import 1 repository credential and use 0 saved credentials. Managed package settings will remain unchanged. Credential permissions have not been assessed.'
		);

		await state.listeners.get('apply:click')();
		const applyData = submitted.find(
			(data) => data.get('action') === 'ran_booster_apply_blueprint'
		);
		assert.ok(applyData);
		assert.equal(applyData.get('row'), '0');
		assert.equal(applyData.get('review_action'), 'managed');
		assert.equal(
			applyData.get('credential_decisions[0][action]'),
			'import'
		);
		assert.equal(
			state.results.items[0].className.includes('notice-success'),
			true
		);
		assert.equal(
			state.results.items[0].children[1].textContent,
			'Transferred credential is available on this site.'
		);
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
		assert.equal(
			state.results.items[0].children[0].textContent,
			'Plugin “Package 1” (package-1/package.php) — Applied.'
		);
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

test('apply identifies the exact package where a serial chain is pending', async () => {
	let finishApply;
	let applies = 0;
	const state = importFixture(
		(data) => {
			if (data.get('action') === 'ran_booster_apply_blueprint') {
				if (++applies === 1) {
					return success('', {
						message: 'Installed with deployment disabled.',
						status: 'installed',
					});
				}
				return new Promise((resolve) => {
					finishApply = () =>
						resolve(
							success('', {
								message: 'Adopted: deployment disabled',
								status: 'adopted',
							})
						);
				});
			}

			return success('<table>review</table>');
		},
		{
			'<table>review</table>': [
				[
					'0',
					'install',
					true,
					'Plugin',
					'Forms Plugin',
					'forms/forms.php',
				],
				[
					'1',
					'adopt',
					true,
					'Theme',
					'Campaign Theme',
					'campaign-theme',
				],
			],
		}
	);

	try {
		loadFunction('initPortabilityPreview')();
		state.listeners.get('form:submit')({ preventDefault() {} });
		await tick();

		const applying = state.listeners.get('apply:click')();
		await tick();
		assert.equal(state.results.items.length, 2);
		assert.equal(
			state.results.items[0].children[0].textContent,
			'Plugin “Forms Plugin” (forms/forms.php) — Installed with deployment disabled.'
		);
		assert.equal(
			state.results.items[1].children[0].textContent,
			'Theme “Campaign Theme” (campaign-theme) — Applying…'
		);

		finishApply();
		await applying;
		assert.equal(state.results.items.length, 2);
		assert.equal(
			state.results.items[1].children[0].textContent,
			'Theme “Campaign Theme” (campaign-theme) — Adopted: deployment disabled'
		);
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
