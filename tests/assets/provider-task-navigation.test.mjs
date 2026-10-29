import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
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

function fixture() {
	const listeners = new Map();
	const attributes = new Map();
	const progress = { hidden: true };
	const error = {
		focusOptions: null,
		hidden: true,
		focus(options) {
			this.focusOptions = options;
		},
	};
	const currentAttributes = new Map();
	const current = {
		classList: { toggle() {} },
		dataset: { ranBoosterProviderTask: 'repositories' },
		focusOptions: null,
		focus(options) {
			this.focusOptions = options;
		},
		removeAttribute(name) {
			currentAttributes.delete(name);
		},
		setAttribute(name, value) {
			currentAttributes.set(name, value);
		},
	};
	const otherAttributes = new Map([['aria-current', 'page']]);
	const other = {
		classList: { toggle() {} },
		dataset: { ranBoosterProviderTask: 'status' },
		removeAttribute(name) {
			otherAttributes.delete(name);
		},
		setAttribute(name, value) {
			otherAttributes.set(name, value);
		},
	};
	const panel = {
		dataset: { ranBoosterProviderTask: 'repositories' },
		id: 'ran-booster-provider-task-panel',
	};
	const repositoryTab = {
		focusOptions: null,
		focus(options) {
			this.focusOptions = options;
		},
	};
	let activeRepositoryTab = null;
	const dispatched = [];
	const region = {
		id: 'ran-booster-provider-tasks',
		dispatchEvent(event) {
			dispatched.push(event);
		},
		querySelector(selector) {
			if (selector.includes('task-progress')) {
				return progress;
			}
			if (selector.includes('task-error')) {
				return error;
			}
			if (selector === '#ran-booster-provider-task-panel') {
				return panel;
			}
			if (
				selector ===
				'.ran-booster-repository-detail__tabs [aria-current="page"]'
			) {
				return activeRepositoryTab;
			}
			return null;
		},
		querySelectorAll(selector) {
			return selector === '.ran-booster-provider-task-tab'
				? [current, other]
				: [];
		},
		toggleAttribute(name, force) {
			if (force) {
				attributes.set(name, '');
			} else {
				attributes.delete(name);
			}
		},
	};
	const document = {
		addEventListener(name, handler) {
			listeners.set(name, handler);
		},
		getElementById(id) {
			return id === region.id ? region : null;
		},
	};

	return {
		attributes,
		current,
		currentAttributes,
		dispatched,
		document,
		error,
		listeners,
		otherAttributes,
		panel,
		progress,
		repositoryTab,
		setActiveRepositoryTab(tab) {
			activeRepositoryTab = tab;
		},
		region,
	};
}

class TestCustomEvent {
	constructor(type, options) {
		this.type = type;
		this.bubbles = options.bubbles;
		this.detail = options.detail;
	}
}

test('provider task requests expose progress and notify swapped controls', () => {
	const state = fixture();
	const calls = [];
	const init = loadFunction('initProviderTaskNavigation', {
		CustomEvent: TestCustomEvent,
		document: state.document,
		initProviderRepositoryFilter(root) {
			calls.push(['repositories', root]);
		},
	});

	init();
	const detail = { target: state.panel, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail });

	assert.equal(state.attributes.has('aria-busy'), true);
	assert.equal(state.progress.hidden, false);

	state.listeners.get('htmx:afterRequest')({ detail });
	state.listeners.get('htmx:afterSwap')({ detail });

	assert.equal(state.attributes.has('aria-busy'), false);
	assert.equal(state.progress.hidden, true);
	assert.deepEqual(calls, [['repositories', state.region]]);
	assert.deepEqual(state.current.focusOptions, { preventScroll: true });
	assert.equal(state.currentAttributes.get('aria-current'), 'page');
	assert.equal(state.otherAttributes.has('aria-current'), false);
	assert.equal(state.dispatched[0].type, 'ran-booster:provider-tasks-ready');
	assert.equal(state.dispatched[0].detail.panel, state.panel);
	assert.equal(state.dispatched[0].detail.root, state.region);
});

test('repository task swaps restore focus when HTMX reports the swapped region as elt', () => {
	const state = fixture();
	const init = loadFunction('initProviderTaskNavigation', {
		CustomEvent: TestCustomEvent,
		document: state.document,
		initProviderRepositoryFilter() {},
	});

	state.setActiveRepositoryTab(state.repositoryTab);
	init();
	const target = { id: 'ran-booster-provider-profile-region' };
	const link = {
		closest: (selector) =>
			selector === '.ran-booster-repository-detail__tabs',
	};
	const detail = { target, elt: link, requestConfig: { elt: link }, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail });
	state.listeners.get('htmx:afterSwap')({
		detail: { ...detail, elt: target },
	});

	assert.deepEqual(state.repositoryTab.focusOptions, { preventScroll: true });
	assert.equal(state.current.focusOptions, null);
});

test('provider task request failures retain the view and expose its alert', () => {
	const state = fixture();
	const init = loadFunction('initProviderTaskNavigation', {
		CustomEvent: TestCustomEvent,
		document: state.document,
		initProviderRepositoryFilter() {},
	});

	init();
	const detail = { target: state.panel, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail });
	state.listeners.get('htmx:sendError')({ detail });

	assert.equal(state.attributes.has('aria-busy'), false);
	assert.equal(state.progress.hidden, true);
	assert.equal(state.error.hidden, false);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
});

test('only the owning provider request can clear progress or report failure', () => {
	const state = fixture();
	const init = loadFunction('initProviderTaskNavigation', {
		CustomEvent: TestCustomEvent,
		document: state.document,
		initProviderRepositoryFilter() {},
	});
	init();
	const oldRequest = { target: state.panel, xhr: {} };
	const newRequest = { target: state.panel, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail: oldRequest });
	state.listeners.get('htmx:beforeRequest')({ detail: newRequest });
	state.listeners.get('htmx:sendError')({ detail: oldRequest });
	state.listeners.get('htmx:afterRequest')({ detail: oldRequest });
	state.listeners.get('htmx:afterSwap')({ detail: oldRequest });
	assert.equal(state.progress.hidden, false);
	assert.equal(state.error.hidden, true);
	state.listeners.get('htmx:afterRequest')({ detail: newRequest });
	assert.equal(state.progress.hidden, true);
	assert.equal(state.attributes.has('aria-busy'), false);
});

test('provider repository filter formats contextual plural translations in JavaScript', () => {
	const listeners = new Map();
	const input = {
		dataset: {},
		value: '',
		addEventListener(name, listener) {
			listeners.set(name, listener);
		},
	};
	const count = { textContent: '' };
	const rows = [
		{
			hidden: false,
			getAttribute() {
				return 'first';
			},
		},
		{
			hidden: false,
			getAttribute() {
				return 'second';
			},
		},
	];
	const root = {
		querySelector(selector) {
			return selector.includes('filter') ? input : count;
		},
		querySelectorAll() {
			return rows;
		},
	};
	const calls = [];
	const filter = loadFunction('initProviderRepositoryFilter', {
		document: root,
		wp: {
			i18n: {
				_nx(single, plural, visible, context, domain) {
					calls.push([single, plural, visible, context, domain]);
					return visible === 1
						? '%d dépôt affiché'
						: '%d dépôts affichés';
				},
				sprintf(template, visible) {
					return template.replace('%d', String(visible));
				},
			},
		},
	});

	filter(root);
	listeners.get('input')();

	assert.equal(count.textContent, '2 dépôts affichés');
	assert.deepEqual(calls, [
		[
			'%d repository shown',
			'%d repositories shown',
			2,
			'Provider table repository count',
			'ran-booster',
		],
	]);
	assert.equal(input.dataset.ranBoosterRepositoryFilterBound, 'true');
});
