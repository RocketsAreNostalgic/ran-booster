import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);

function loadFunction(name, dependencies = {}) {
	dependencies = {
		window: {
			location: new URL(
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh'
			),
			addEventListener() {},
		},
		...dependencies,
	};
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
		setAttribute(name, value) {
			attributes.set(name, value);
		},
		removeAttribute(name) {
			attributes.delete(name);
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

test('history misses reload only when provider navigation is present', () => {
	const state = fixture();
	const htmx = { config: { refreshOnHistoryMiss: false } };
	const init = loadFunction('initProviderTaskNavigation', {
		document: state.document,
		htmx,
	});
	init();
	assert.equal(htmx.config.refreshOnHistoryMiss, true);

	htmx.config.refreshOnHistoryMiss = false;
	const detail = loadFunction('initProviderTaskNavigation', {
		document: {
			...state.document,
			getElementById: (id) =>
				id === 'ran-booster-provider-profile-region'
					? { querySelector: () => ({}) }
					: null,
		},
		htmx,
	});
	detail();
	assert.equal(htmx.config.refreshOnHistoryMiss, true);

	htmx.config.refreshOnHistoryMiss = false;
	const absent = loadFunction('initProviderTaskNavigation', {
		document: { getElementById: () => null },
		htmx,
	});
	absent();
	assert.equal(htmx.config.refreshOnHistoryMiss, false);
});

test('repeated provider traversal reloads an unmarked destination only when the rendered provider view differs', () => {
	const state = fixture();
	let reloads = 0;
	const listeners = new Map();
	const location = {
		href: 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=status',
		reload() {
			reloads++;
		},
	};
	const init = loadFunction('initProviderTaskNavigation', {
		document: state.document,
		htmx: { config: {} },
		window: {
			location,
			addEventListener: (name, fn) => listeners.set(name, fn),
		},
		CustomEvent: TestCustomEvent,
		initProviderRepositoryFilter() {},
	});
	init();
	const pop = (entry) => listeners.get('popstate')({ state: entry });
	location.href += '#same-panel';
	pop(null);
	assert.equal(reloads, 0);
	location.href = location.href.replace('panel=status', 'panel=repositories');
	pop({ htmx: true });
	assert.equal(reloads, 0);
	const detail = { target: state.panel, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail });
	state.listeners.get('htmx:afterSwap')({ detail });
	pop({ idx: 0 });
	assert.equal(reloads, 0);
	location.href = location.href.replace('panel=repositories', 'panel=status');
	pop({ idx: 0 });
	assert.equal(reloads, 1);
	location.href = location.href.replace('tab=gh', 'tab=overview');
	pop(null);
	assert.equal(reloads, 1);
});

test('provider navigation initializes without htmx or its config', () => {
	const state = fixture();
	for (const htmx of [undefined, {}]) {
		const init = loadFunction('initProviderTaskNavigation', {
			document: state.document,
			htmx,
		});
		assert.doesNotThrow(init);
	}
});

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

	assert.equal(state.attributes.get('aria-busy'), 'true');
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

test('committed navigation delegates to the shared Link owner before focus without a second writer', () => {
	for (const handled of [true, false]) {
		const state = fixture();
		const focused = [];
		const live = { focus: () => focused.push('focus') };
		let nativeWrites = 0;
		state.region.querySelectorAll = () => {
			nativeWrites++;
			return [];
		};
		const calls = [];
		const init = loadFunction('initProviderTaskNavigation', {
			CustomEvent: TestCustomEvent,
			document: state.document,
			initProviderRepositoryFilter() {},
			window: {
				location: new URL('https://example.test/?panel=repositories'),
				addEventListener() {},
				ranBoosterUI: {
					setNavigationCurrent(region, task) {
						calls.push([region, task]);
						focused.push('commit');
						return handled ? live : null;
					},
				},
			},
		});
		init();
		const oldRequest = { target: state.panel, xhr: {} };
		const detail = { target: state.panel, xhr: {} };
		state.listeners.get('htmx:beforeRequest')({ detail: oldRequest });
		state.listeners.get('htmx:beforeRequest')({ detail });
		state.listeners.get('htmx:afterSwap')({ detail: oldRequest });
		assert.equal(calls.length, 0);
		state.listeners.get('htmx:afterSwap')({ detail });
		assert.deepEqual(calls, [[state.region, 'repositories']]);
		assert.equal(nativeWrites, 0);
		assert.deepEqual(focused, handled ? ['commit', 'focus'] : ['commit']);
		assert.equal(
			state.dispatched[0].type,
			'ran-booster:provider-tasks-ready'
		);
	}
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
