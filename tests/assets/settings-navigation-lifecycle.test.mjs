import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Run the actual production scripts with the existing focused DOM substitutes.
function loadHarness(file, names, additions = {}) {
	let code = fs.readFileSync(new URL(file, import.meta.url), 'utf8');
	code = code.slice(code.indexOf('const source'), code.indexOf('\ntest('));
	code = code.replaceAll('import.meta.url', 'base');
	if (additions.shell) {
		code = code.replace('fail = false,', 'shell = false,\n\tfail = false,');
		code = code.replace('return li;', 'return shell ? link : li;');
	}
	if (additions.settings) {
		code = code.replace(
			'CustomEvent: class {',
			'Event: class { constructor(type) { this.type = type; } }, CustomEvent: class {'
		);
		code = code.replace(
			'this.bubbles = options.bubbles;',
			'this.bubbles = options.bubbles; this.detail = options.detail;'
		);
	}
	return new Function(
		'fs',
		'vm',
		'assert',
		'base',
		`${code}\nreturn { ${names} };`
	)(fs, vm, assert, import.meta.url);
}
const { fixture } = loadHarness('./navigation-lifecycle.test.mjs', 'fixture', {
	shell: true,
});
const { harness, start } = loadHarness(
	'./settings-dirty-state.test.mjs',
	'harness, start',
	{ settings: true }
);

function scenario(initial, desired, focus, focusOnly = false) {
	const nav = fixture({ shell: true });
	const Node = nav.host.constructor;
	const shell = nav.doc.body.appendChild(
		new Node('nav', { class: 'ran-admin-shell__navigation' })
	);
	const owner = {
		previousElementSibling: {
			matches: (selector) => selector === 'header.ran-admin-shell',
			querySelector: () => shell,
		},
	};
	nav.doc.querySelectorAll = (selector) =>
		selector === '.ran-booster-admin[data-ran-booster-shell-navigation]'
			? [owner]
			: [];
	let forwardSettings = () => {};
	let feedbackCleanup = () => {};
	const cleanupEvents = [];
	const dispatch = (event, target) => {
		event.target = target;
		if (event.type === 'htmx:beforeCleanupElement') {
			cleanupEvents.push(event);
			feedbackCleanup(event);
		}
		nav.fire(event.type, {
			target,
			detail: event.detail || {},
			bubbles: event.bubbles,
		});
		forwardSettings(event);
		return true;
	};
	nav.doc.dispatchEvent = (event) => dispatch(event, nav.doc);
	shell.dispatchEvent = (event) => dispatch(event, shell);
	const link = (label, href) => {
		const anchor = new Node('a', { href });
		anchor.textContent = label;
		return anchor;
	};
	const anchors = (github) => [
		link('Overview', '/overview'),
		...(github ? [link('GitHub', '/github')] : []),
		link('Plugins', '/plugins'),
	];
	const originalReplace = shell.replaceChildren.bind(shell);
	const originalQueryAll = shell.querySelectorAll.bind(shell);
	shell.querySelectorAll = (selector) =>
		selector === 'a[href]'
			? shell.children.filter(
					(node) => node.tagName === 'A' && node.hasAttribute('href')
				)
			: originalQueryAll(selector);
	shell.querySelector = (selector) => {
		if (selector === 'a[aria-current="page"]') {
			return (
				shell.children.find(
					(node) =>
						node.tagName === 'A' &&
						node.getAttribute('aria-current') === 'page'
				) || null
			);
		}
		return selector === 'a[href]'
			? shell.querySelectorAll(selector)[0] || null
			: null;
	};
	shell.replaceChildren = (...nodes) => {
		if (shell.contains(nav.doc.activeElement)) {
			nav.doc.activeElement = nav.doc.body;
		}
		originalReplace(...nodes.flatMap((node) => node.fragment || [node]));
	};
	shell.replaceChildren(...anchors(!initial));
	nav.window.navigationTest.mountNavigation(shell, false);
	assert.equal(nav.window.navigationTest.roots.has(shell), true);
	const before = shell.children.find(
		(node) => node.getAttribute('href') === '/github'
	);
	if (focus === 'github') {
		before.focus();
	}
	if (focus === 'remaining') {
		shell.children
			.find((node) => node.getAttribute('href') === '/plugins')
			.focus();
	}
	const outside = nav.doc.body.appendChild(new Node('button'));
	let snackbarDisposed = false;
	const feedbackSource = fs.readFileSync(
		new URL(
			'../../assets/ran-booster-enhanced-mutations.js',
			import.meta.url
		),
		'utf8'
	);
	const cleanupName = feedbackSource.indexOf("'htmx:beforeCleanupElement'");
	const cleanupStart = feedbackSource.lastIndexOf(
		'document.addEventListener(',
		cleanupName
	);
	const cleanupEnd = feedbackSource.indexOf(
		"document.addEventListener('htmx:beforeTransition'",
		cleanupName
	);
	vm.runInNewContext(feedbackSource.slice(cleanupStart, cleanupEnd), {
		document: {
			addEventListener: (_name, callback) => {
				feedbackCleanup = callback;
			},
		},
		window: nav.window,
		snackbar: { host: outside, region: outside },
		restoreSnackbarFocus: () => {
			snackbarDisposed = true;
		},
		disposeSnackbar: () => {
			snackbarDisposed = true;
		},
	});
	if (focus === 'outside') {
		outside.focus();
	}

	const settings = harness();
	settings.document.body = nav.doc.body;
	Object.defineProperty(settings.document, 'activeElement', {
		get: () => nav.doc.activeElement,
		set: (element) => {
			nav.doc.activeElement = element;
		},
	});
	forwardSettings = (event) => {
		settings.doc(event.type, {
			target: event.target === nav.doc ? settings.document : event.target,
			detail: event.detail || {},
		});
	};
	settings.document.dispatchEvent = nav.doc.dispatchEvent;
	settings.window.htmx.trigger = (target, type, detail = {}) =>
		nav.fire(type, { target, detail });
	const form = settings.toggle;
	form.toggle.checked = desired;
	if (focus === 'toggle' || focus.startsWith('moved-')) {
		form.toggle.focus();
	}
	const previousQuery = settings.document.querySelector;
	settings.document.querySelector = (selector) =>
		selector === '.ran-admin-shell__navigation'
			? shell
			: previousQuery(selector);
	const xhr = start(settings, form);
	if (focus === 'moved-github') {
		before.focus();
	} else if (focus === 'moved-remaining') {
		shell.children
			.find((node) => node.getAttribute('href') === '/plugins')
			.focus();
	} else if (focus === 'moved-outside') {
		outside.focus();
	}
	settings.doc('htmx:beforeOnLoad', { detail: { xhr } });
	const region = settings.make('hide', {
		checked: desired,
		confirmed: desired,
		saved: true,
	});
	region.closest = (selector) =>
		selector === '.ran-booster-admin[data-ran-booster-shell-navigation]'
			? owner
			: null;
	const previousRegionQuery = region.querySelector.bind(region);
	let removed = false;
	region.querySelector = (selector) =>
		selector === 'template[data-ran-booster-general-navigation]' && !removed
			? {
					content: {
						cloneNode: () => ({ fragment: anchors(!desired) }),
					},
					remove: () => {
						removed = true;
					},
				}
			: previousRegionQuery(selector);
	settings.replace(region);
	settings.doc('htmx:afterSwap', { detail: { xhr } });
	settings.doc('htmx:afterRequest', { detail: { xhr } });
	nav.flush();
	assert.equal(cleanupEvents.length, 1);
	assert.equal(cleanupEvents[0].target, shell);
	assert.equal(cleanupEvents[0].detail.elt, shell);
	assert.equal(cleanupEvents[0].bubbles, true);
	assert.equal(shell.contains(outside), false);
	assert.equal(snackbarDisposed, false);
	const focusAfterSwap = nav.doc.activeElement;
	const focusWasConnected = Boolean(focusAfterSwap?.isConnected);
	const focusHref = focusAfterSwap?.getAttribute?.('href');
	if (focusOnly) {
		if (focus === 'outside' || focus === 'moved-outside') {
			assert.equal(focusAfterSwap, outside);
		} else if (focus === 'toggle') {
			assert.equal(focusAfterSwap, region.form.toggle);
		} else {
			assert.equal(
				focusWasConnected,
				true,
				'replacement must leave connected focus'
			);
			assert.notEqual(
				focusAfterSwap,
				nav.doc.body,
				'replacement must leave meaningful focus'
			);
			if (focus === 'remaining' || focus === 'moved-remaining') {
				assert.equal(focusHref, '/plugins');
			}
			if (focus === 'github' || focus === 'moved-github') {
				assert.equal(focusHref, '/overview');
			}
		}
		return;
	}
	const hrefs = () => shell.children.map((node) => node.getAttribute('href'));
	const expected = [
		'/overview',
		...(!desired ? ['/github'] : []),
		'/plugins',
	];
	assert.deepEqual(
		hrefs(),
		expected,
		'confirmed response replaces the visible Shell links'
	);
	assert.equal(
		shell.children.filter(
			(node) => node.getAttribute('aria-current') === 'page'
		).length,
		0
	);
	assert.equal(region.form.toggle.checked, desired);
	if (focus === 'outside') {
		assert.equal(nav.doc.activeElement, outside);
	}
	nav.fire('htmx:beforeHistorySave');
	if (focus === 'outside') {
		assert.equal(nav.doc.activeElement, outside);
	}
	assert.deepEqual(
		hrefs(),
		expected,
		'history serialization must retain the confirmed Shell links'
	);
	nav.fire('htmx:historyRestore');
	nav.flush();
	if (
		!nav.window.navigationTest.roots.has(shell) &&
		shell.contains(nav.doc.activeElement)
	) {
		outside.focus();
		nav.fire('focusout');
		nav.flush();
	}
	const entry = nav.window.navigationTest.roots.get(shell);
	assert.ok(entry, 'history restores the public navigation owner');
	assert.ok(entry.sectionNavigation, 'the indicator behavior is attached');
	assert.deepEqual(hrefs(), expected);
	if (focus === 'remaining') {
		assert.equal(focusWasConnected, true);
		assert.equal(focusHref, '/plugins');
	}
	if (focus === 'github') {
		assert.equal(focusWasConnected, true);
		assert.notEqual(focusAfterSwap, nav.doc.body);
	}
}

test('confirmed hide survives shared navigation history cleanup', () =>
	scenario(false, true, 'github'));
test('confirmed show survives shared navigation history cleanup without stealing outside focus', () =>
	scenario(true, false, 'outside'));
test('confirmed hide retains the remaining navigation through history', () =>
	scenario(false, true, 'remaining'));
test('a focused removed GitHub link gets meaningful connected focus', () =>
	scenario(false, true, 'github', true));
test('a focused remaining link retains its destination', () =>
	scenario(false, true, 'remaining', true));
test('an unrelated focused control remains focused', () =>
	scenario(true, false, 'outside', true));
test('confirmed toggle keeps its focus', () =>
	scenario(false, true, 'toggle', true));
test('focus moved to a surviving link during the request stays there', () =>
	scenario(false, true, 'moved-remaining', true));
test('focus moved to removed GitHub during the request has a fallback', () =>
	scenario(false, true, 'moved-github', true));
test('focus moved outside during the request is not reclaimed', () =>
	scenario(false, true, 'moved-outside', true));
