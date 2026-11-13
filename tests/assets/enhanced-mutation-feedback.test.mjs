import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-enhanced-mutations.js', import.meta.url),
	'utf8'
);
const announceSource = fs.readFileSync(
	new URL('../../assets/ran-booster-announce.js', import.meta.url),
	'utf8'
);
const legacySource = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);
const packageSource = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);
const css = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/15-enhanced-mutations.css',
		import.meta.url
	),
	'utf8'
);
const buttonCss = fs.readFileSync(
	new URL('../../assets/ran-booster/10-buttons.css', import.meta.url),
	'utf8'
);

function extractFunction(name) {
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

	return source.slice(start, end);
}

function loadFunction(name, dependencies = {}) {
	const localizedDependencies = {
		wp: { i18n: { __: (text) => text } },
		...dependencies,
	};

	// The shared busy-label helpers live beside, not inside, the loaded function.
	return Function(
		...Object.keys(localizedDependencies),
		`"use strict"; ${extractFunction('busyStatus')}\n${extractFunction('showBusyLabel')}\n${extractFunction('restoreBusyLabel')}\nreturn (${extractFunction(name)});`
	)(...Object.values(localizedDependencies));
}

function classList() {
	const classes = new Set();

	return {
		add(...names) {
			names.forEach((name) => classes.add(name));
		},
		contains(name) {
			return classes.has(name);
		},
		remove(...names) {
			names.forEach((name) => classes.delete(name));
		},
	};
}

function fixture() {
	const listeners = new Map();
	const announcements = [];
	const noticeIntents = [];
	const animationFrames = [];
	const replacedUrls = [];
	const scrollCalls = [];
	let packageDisclosures = [
		{ id: 'ran-booster-advanced-source-settings-disclosure', open: false },
		{ id: 'ran-booster-package-danger-zone', open: false },
	];
	let renderedBranchCheckErrors = [];
	let renderedErrors = [];
	const errorAttributes = new Map();
	const formAttributes = new Map();
	const buttonAttributes = new Map();
	const button = {
		classList: classList(),
		disabled: false,
		textContent: 'Save changes',
		getAttribute(name) {
			return buttonAttributes.get(name) ?? null;
		},
		removeAttribute(name) {
			buttonAttributes.delete(name);
		},
		setAttribute(name, value) {
			buttonAttributes.set(name, String(value));
		},
	};
	const secondaryAttributes = new Map([['aria-disabled', 'true']]);
	const secondary = {
		disabled: true,
		getAttribute(name) {
			return secondaryAttributes.get(name) ?? null;
		},
		removeAttribute(name) {
			secondaryAttributes.delete(name);
		},
		setAttribute(name, value) {
			secondaryAttributes.set(name, String(value));
		},
	};
	const error = {
		appendedChildren: [],
		attributes: errorAttributes,
		focusOptions: null,
		hidden: true,
		textContent: 'The setting could not be saved.',
		focus(options) {
			this.focusOptions = options;
		},
		hasAttribute(name) {
			return errorAttributes.has(name);
		},
		getAttribute(name) {
			return errorAttributes.get(name) ?? null;
		},
		removeAttribute(name) {
			errorAttributes.delete(name);
		},
		append(child) {
			this.appendedChildren.push(child);
		},
		setAttribute(name, value) {
			errorAttributes.set(name, String(value));
		},
		querySelector(selector) {
			if (selector !== 'p') {
				return null;
			}

			return {
				get textContent() {
					return error.textContent;
				},
				set textContent(value) {
					error.textContent = String(value);
				},
			};
		},
	};
	function formElements(items) {
		items.namedItem = () => null;
		return items;
	}
	const form = {
		classList: classList(),
		dataset: { ranBoosterErrorTarget: '#ran-booster-local-error' },
		elements: formElements([button, secondary]),
		getAttribute(name) {
			return formAttributes.get(name) ?? null;
		},
		hasAttribute() {
			return false;
		},
		matches(selector) {
			return selector === '[data-ran-booster-enhanced-mutation]';
		},
		querySelector(selector) {
			return selector === '[type="submit"]:not([disabled])'
				? button
				: null;
		},
		removeAttribute(name) {
			formAttributes.delete(name);
		},
		setAttribute(name, value) {
			formAttributes.set(name, String(value));
		},
	};
	const sourceProgress = { hidden: true };
	const sourceLinkAttributes = new Map();
	const sourceLink = {
		classList: classList(),
		dataset: { ranBoosterErrorTarget: '#ran-booster-local-error' },
		hasAttribute(name) {
			return sourceLinkAttributes.has(name);
		},
		getAttribute(name) {
			return sourceLinkAttributes.get(name) ?? null;
		},
		matches(selector) {
			return (
				selector === '[data-ran-booster-enhanced-mutation]' ||
				selector === 'a, button'
			);
		},
		removeAttribute(name) {
			sourceLinkAttributes.delete(name);
		},
		setAttribute(name, value) {
			sourceLinkAttributes.set(name, String(value));
		},
	};
	const swapTarget = {
		contains: (element) => element === error,
		querySelectorAll: (selector) =>
			selector.includes('repository-branch-check')
				? renderedBranchCheckErrors
				: renderedErrors,
		closest() {
			return null;
		},
		matches() {
			return false;
		},
	};
	const document = {
		addEventListener(name, handler) {
			listeners.set(name, handler);
		},
		createElement() {
			return {
				className: '',
				setAttribute() {},
			};
		},
		getElementById(id) {
			return id === 'ran-booster-local-error' ? error : null;
		},
		querySelector(selector) {
			if (selector === '[data-ran-booster-source-progress]') {
				return sourceProgress;
			}

			return null;
		},
		querySelectorAll(selector) {
			if (
				selector ===
				'[data-ran-booster-package-disclosure], [data-ran-booster-logging-disclosure]'
			) {
				return packageDisclosures;
			}
			if (
				selector ===
				'#wpbody-content [data-ran-booster-repository-branch-check]'
			) {
				return renderedBranchCheckErrors;
			}
			if (
				selector ===
				'#wpbody-content .notice-error, #wpbody-content .error'
			) {
				return [error, ...renderedErrors];
			}

			return [];
		},
	};
	const window = {
		addEventListener(name, handler) {
			listeners.set(`window:${name}`, handler);
		},
		clearTimeout() {},
		innerWidth: 2000,
		history: {
			state: { htmx: true },
			replaceState(state, title, url) {
				replacedUrls.push({ state, title, url: String(url) });
			},
		},
		location: {
			href: 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
		},
		scrollY: 0,
		scrollTo(x, y) {
			scrollCalls.push({ x, y });
		},
		requestAnimationFrame(callback) {
			animationFrames.push(callback);
			return animationFrames.length;
		},
		wp: {
			a11y: {
				speak(message, type) {
					announcements.push({ message, type });
				},
			},
		},
		// Records each in-place intent change and mirrors its class and
		// attribute writes; the helper itself is covered by notice.test.mjs.
		ranBoosterNotice: {
			setIntent(host, intent) {
				noticeIntents.push({ host, intent });
				['notice-error', 'notice-success', 'notice-warning'].forEach(
					(name) => host.classList?.remove(name)
				);
				host.classList?.add(`notice-${intent}`);
				host.setAttribute?.(
					'data-ran-booster-ui-notice-intent',
					intent
				);
				return true;
			},
		},
	};
	Function('window', announceSource)(window);

	return {
		noticeIntents,
		announcements,
		animationFrames,
		button,
		document,
		error,
		errorAttributes,
		form,
		formAttributes,
		listeners,
		replacedUrls,
		secondary,
		scrollCalls,
		setPackageDisclosures(disclosures) {
			packageDisclosures = disclosures;
		},
		setRenderedErrors(errors) {
			renderedErrors = errors;
		},
		setRenderedBranchCheckErrors(errors) {
			renderedBranchCheckErrors = errors;
		},
		swapTarget,
		sourceLink,
		sourceLinkAttributes,
		sourceProgress,
		window,
	};
}

test('enhanced mutation feedback is opt-in and restores the submitter after a request', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});

	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });

	assert.equal(state.formAttributes.get('aria-busy'), 'true');
	assert.equal(state.button.disabled, true);
	assert.equal(state.secondary.disabled, true);
	assert.equal(state.button.textContent, 'Save changes');
	assert.equal(
		state.button.classList.contains(
			'ran-booster-enhanced-mutation__submitter--busy'
		),
		true
	);
	assert.equal(
		state.button.classList.contains('ran-booster-update-is-active'),
		true
	);

	state.listeners.get('htmx:afterRequest')({ detail: { elt: state.form } });

	assert.equal(state.formAttributes.has('aria-busy'), false);
	assert.equal(state.button.disabled, false);
	assert.equal(state.secondary.disabled, true);
	assert.equal(state.secondary.getAttribute('aria-disabled'), 'true');
	assert.equal(state.button.textContent, 'Save changes');
	assert.equal(
		state.button.classList.contains('ran-booster-update-is-active'),
		false
	);
});

test('enhanced source links use the same busy and restore lifecycle', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});

	init();
	state.listeners.get('htmx:beforeRequest')({
		detail: { elt: state.sourceLink },
	});

	assert.equal(state.sourceLinkAttributes.get('aria-busy'), 'true');
	assert.equal(state.sourceLinkAttributes.get('aria-disabled'), 'true');
	assert.equal(
		state.sourceLink.classList.contains(
			'ran-booster-enhanced-mutation-is-busy'
		),
		true
	);

	state.listeners.get('htmx:afterRequest')({
		detail: { elt: state.sourceLink },
	});

	assert.equal(state.sourceLinkAttributes.has('aria-busy'), false);
	assert.equal(state.sourceLinkAttributes.has('aria-disabled'), false);
	assert.equal(
		state.sourceLink.classList.contains(
			'ran-booster-enhanced-mutation-is-busy'
		),
		false
	);
});

test('source navigation progress belongs to the newest request', () => {
	const state = fixture();
	state.sourceLinkAttributes.set('data-ran-booster-source-choice', 'branch');
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	init();
	const oldRequest = { elt: state.sourceLink, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail: oldRequest });
	assert.equal(state.sourceProgress.hidden, false);
	assert.equal(
		state.sourceLink.classList.contains('ran-booster-update-is-active'),
		false
	);
	// A replacement request can originate from the other source link.
	state.sourceLinkAttributes.delete('aria-busy');
	const newRequest = { elt: state.sourceLink, xhr: {} };
	state.listeners.get('htmx:beforeRequest')({ detail: newRequest });
	state.listeners.get('htmx:afterRequest')({ detail: oldRequest });
	state.listeners.get('htmx:sendError')({ detail: oldRequest });
	state.listeners.get('htmx:afterSwap')({ detail: oldRequest });
	state.listeners.get('htmx:afterSettle')({ detail: oldRequest });
	assert.equal(state.sourceProgress.hidden, false);
	assert.equal(state.sourceLinkAttributes.get('aria-busy'), 'true');
	assert.equal(
		state.sourceLink.classList.contains(
			'ran-booster-enhanced-mutation-is-busy'
		),
		true
	);
	state.listeners.get('htmx:afterRequest')({ detail: newRequest });
	assert.equal(state.sourceProgress.hidden, true);
});

test('an external enhanced submitter does not continue with a native form submission', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const submitter = {
		matches(selector) {
			return (
				selector === '[data-ran-booster-enhanced-mutation]' ||
				selector === '[type="submit"][form]'
			);
		},
	};
	let prevented = false;

	init();
	state.listeners.get('click')({
		preventDefault() {
			prevented = true;
		},
		target: submitter,
	});

	assert.equal(prevented, true);
});

test('enhanced mutations preserve viewport and package disclosure states', () => {
	for (const open of [
		[false, false, false],
		[true, true, true],
		[true, false, true],
		[false, true, false],
	]) {
		const state = fixture();
		const init = loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		});
		const ids = [
			'ran-booster-advanced-source-settings-disclosure',
			'ran-booster-release-track-settings',
			'ran-booster-package-danger-zone',
		];
		const replacementDetails = open.map((value, index) => ({
			id: ids[index],
			open: !value,
		}));
		state.window.scrollY = 512;
		state.setPackageDisclosures(
			open.map((value, index) => ({ id: ids[index], open: value }))
		);

		init();
		state.listeners.get('htmx:beforeRequest')({
			detail: { elt: state.form },
		});
		state.setPackageDisclosures(replacementDetails);
		state.window.scrollY = 900;
		state.listeners.get('htmx:afterSwap')({
			detail: {
				elt: state.swapTarget,
				xhr: { status: 200 },
			},
		});

		assert.deepEqual(
			replacementDetails.map((details) => details.open),
			open
		);
		assert.deepEqual(state.scrollCalls, []);

		state.listeners.get('htmx:afterSettle')({
			detail: { elt: state.swapTarget },
		});

		assert.deepEqual(state.scrollCalls, []);
		state.animationFrames.shift()();
		assert.deepEqual(state.scrollCalls, [{ x: 0, y: 512 }]);
	}
});

test('logging swaps restore the open details and viewport', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const replacement = { id: 'ran-booster-logging-details', open: false };
	state.setPackageDisclosures([{ id: replacement.id, open: true }]);
	state.window.scrollY = 317;
	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.setPackageDisclosures([replacement]);
	state.window.scrollY = 500;
	state.listeners.get('htmx:afterSwap')({
		detail: { elt: state.swapTarget, xhr: { status: 200 } },
	});
	assert.equal(replacement.open, true);
	state.listeners.get('htmx:afterSettle')({
		detail: { elt: state.swapTarget },
	});
	state.animationFrames.shift()();
	assert.deepEqual(state.scrollCalls, [{ x: 0, y: 317 }]);
});

test('enhanced disclosure restoration is keyed by stable IDs and ignores absent replacements', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const reversedReplacement = [
		{ id: 'ran-booster-package-danger-zone', open: false },
		{ id: 'ran-booster-advanced-source-settings-disclosure', open: true },
	];

	state.setPackageDisclosures([
		{ id: 'ran-booster-advanced-source-settings-disclosure', open: false },
		{ id: 'ran-booster-package-danger-zone', open: true },
	]);
	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.setPackageDisclosures(reversedReplacement);
	state.listeners.get('htmx:afterSwap')({
		detail: { elt: state.swapTarget, xhr: { status: 200 } },
	});

	assert.deepEqual(
		reversedReplacement.map((details) => details.open),
		[true, false]
	);
	state.setPackageDisclosures([]);
	assert.doesNotThrow(() =>
		state.listeners.get('htmx:afterSwap')({
			detail: { elt: state.swapTarget, xhr: { status: 200 } },
		})
	);
});

test('re-evaluating feedback initialization preserves the request lifecycle', () => {
	const state = fixture();
	const handlers = new Map();
	state.document.addEventListener = (name, handler) => {
		const callbacks = handlers.get(name) ?? [];
		callbacks.push(handler);
		handlers.set(name, callbacks);
	};
	const dependencies = { document: state.document, window: state.window };
	loadFunction('initEnhancedMutationFeedback', dependencies)();
	loadFunction('initEnhancedMutationFeedback', dependencies)();

	function dispatch(name) {
		const event = {
			defaultPrevented: false,
			detail: { elt: state.form },
			preventDefault() {
				this.defaultPrevented = true;
			},
		};
		for (const handler of handlers.get(name) ?? []) {
			handler(event);
		}
		return event;
	}

	assert.equal(dispatch('htmx:beforeRequest').defaultPrevented, false);
	assert.equal(state.formAttributes.get('aria-busy'), 'true');
	assert.equal(state.button.disabled, true);
	assert.equal(dispatch('htmx:beforeRequest').defaultPrevented, true);
	dispatch('htmx:afterRequest');
	assert.equal(state.formAttributes.has('aria-busy'), false);
	assert.equal(state.button.disabled, false);
	assert.equal(dispatch('htmx:beforeRequest').defaultPrevented, false);
	assert.equal(state.button.disabled, true);
});

test('General feedback requires the matching response and falls back when the shared host is unavailable', () => {
	for (const mode of ['ready', 'host-absent', 'host-refused']) {
		const state = fixture();
		const sourceListeners = new Map();
		const xhrListeners = new Map();
		let region = {
			id: 'ran-booster-general-region',
			isConnected: true,
			contains: () => true,
		};
		state.form.isConnected = true;
		const nativeFeedback = [];
		const shown = [];
		state.form.matches = (selector) =>
			selector === '[data-ran-booster-general-form]' ||
			selector === '[data-ran-booster-enhanced-mutation]';
		state.form.addEventListener = (name, callback) =>
			sourceListeners.set(name, callback);
		state.form.removeEventListener = (name) => sourceListeners.delete(name);
		state.document.getElementById = (id) =>
			id === 'ran-booster-general-region' ? region : state.error;
		if (mode !== 'host-absent') {
			state.window.ranBoosterFeedback = {
				show(message, options) {
					shown.push({ message, options });
					return mode === 'ready';
				},
			};
		}
		const xhr = {
			addEventListener: (name, callback) =>
				xhrListeners.set(name, callback),
			getResponseHeader: () =>
				JSON.stringify({
					'ran-booster:admin-mutation-success': { message: 'Saved.' },
				}),
		};
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		state.listeners.get('htmx:beforeRequest')({
			detail: { elt: state.form, target: region, xhr },
		});
		sourceListeners.get('htmx:beforeOnLoad')({
			detail: { xhr },
			preventDefault() {
				assert.fail('live response vetoed');
			},
		});
		region.isConnected = false;
		region = {
			id: 'ran-booster-general-region',
			hasAttribute: () => true,
			querySelectorAll: () => [],
			append(child) {
				nativeFeedback.push(child);
			},
		};
		state.listeners.get('htmx:afterSwap')({
			detail: { elt: state.form, xhr },
		});
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message: 'Saved.' },
		});
		assert.equal(shown.length, mode === 'host-absent' ? 0 : 1, mode);
		if (mode !== 'host-absent') {
			assert.equal(shown[0].message, 'Saved.', mode);
		}
		if (mode === 'ready') {
			assert.deepEqual(state.announcements, [], mode);
			assert.equal(nativeFeedback.length, 0, mode);
		} else {
			assert.deepEqual(
				state.announcements,
				[{ message: 'Saved.', type: 'polite' }],
				mode
			);
			assert.equal(nativeFeedback.length, 1, mode);
			assert.equal(nativeFeedback[0].textContent, 'Saved.', mode);
		}
		xhrListeners.get('loadend')();
		assert.equal(sourceListeners.has('htmx:beforeOnLoad'), false);
	}
});

test('General response from a detached source cannot replace a newer target', () => {
	const state = fixture();
	const handlers = new Map();
	const original = {
		id: 'ran-booster-general-region',
		isConnected: true,
		contains: () => false,
	};
	state.form.isConnected = false;
	state.form.matches = (selector) =>
		selector === '[data-ran-booster-general-form]' ||
		selector === '[data-ran-booster-enhanced-mutation]';
	state.form.addEventListener = (name, handler) =>
		handlers.set(name, handler);
	state.document.getElementById = () => original;
	const xhr = { addEventListener() {} };
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:beforeRequest')({
		detail: { elt: state.form, target: original, xhr },
	});
	let vetoed = false;
	handlers.get('htmx:beforeOnLoad')({
		detail: { xhr },
		preventDefault() {
			vetoed = true;
		},
	});
	assert.equal(vetoed, true);
});

test('enhanced mutation feedback rejects a duplicate request from its busy form', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	let prevented = false;

	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:beforeRequest')({
		detail: { elt: state.form },
		preventDefault() {
			prevented = true;
		},
	});

	assert.equal(prevented, true);
});

test('enhanced mutations suppress the competing HTMX View Transition layer', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	let prevented = false;

	init();
	state.listeners.get('htmx:beforeTransition')({
		detail: { elt: state.form },
		preventDefault() {
			prevented = true;
		},
	});

	assert.equal(prevented, true);
});

test('enhanced mutation feedback allows only declared 422 error swaps and keeps errors local', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const detail = {
		elt: state.swapTarget,
		requestConfig: { elt: state.form },
		xhr: { status: 422 },
	};

	init();
	state.listeners.get('htmx:beforeSwap')({ detail });
	state.listeners.get('htmx:afterSwap')({ detail });

	assert.equal(detail.shouldSwap, true);
	assert.equal(detail.swapOverride, undefined);
	assert.equal(state.error.hidden, false);
	assert.equal(state.errorAttributes.has('role'), false);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{ message: 'The setting could not be saved.', type: 'assertive' },
	]);
});

test('credential 422 and 500 failures focus and announce only their own result', () => {
	for (const status of [422, 500]) {
		const state = fixture();
		state.form.hasAttribute = (name) =>
			name === 'data-ran-booster-credential-validation';
		const result = { textContent: `Credential failure ${status}` };
		state.error.textContent = `Expiry unknown ${result.textContent}`;
		state.error.querySelector = (selector) =>
			selector === '[data-ran-booster-credential-result]' ? result : null;
		state.errorAttributes.set('role', 'alert');
		state.setRenderedErrors([
			{
				textContent: 'Other credential failure',
				focus() {
					throw new Error('wrong focus');
				},
			},
		]);
		const init = loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		});
		init();
		const detail = {
			elt: state.swapTarget,
			requestConfig: { elt: state.form },
			xhr: { status },
		};
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('htmx:responseError')({ detail });
		assert.deepEqual(state.error.focusOptions, { preventScroll: true });
		assert.equal(state.errorAttributes.has('role'), false);
		assert.deepEqual(state.announcements, [
			{ message: `Credential failure ${status}`, type: 'assertive' },
		]);
	}
});

function credentialResultRowFixture(noticeHidden) {
	const state = fixture();
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-credential-validation';
	const row = { hidden: true };
	const notice = { hidden: noticeHidden };
	const result = {
		textContent: 'The repository provider rejected this credential.',
		closest: (selector) =>
			selector === '[data-ran-booster-ui-notice]' ? notice : null,
	};
	state.error.hidden = false;
	state.error.closest = (selector) =>
		selector === '.ran-booster-provider-management-table__result'
			? row
			: null;
	state.error.querySelector = (selector) =>
		({
			'[data-ran-booster-credential-result]': result,
			'[data-ran-booster-ui-notice]': notice,
		})[selector] || null;
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	return { state, row, notice };
}

test('credential failure reveals its result row once, after the out-of-band label swap', () => {
	const { state, row } = credentialResultRowFixture(false);
	const detail = {
		elt: state.swapTarget,
		requestConfig: { elt: state.form },
		xhr: { status: 422 },
	};
	const afterSwap = state.listeners.get('htmx:afterSwap');
	afterSwap({ target: { id: 'ran-booster-credential-health-one' }, detail });
	assert.equal(row.hidden, true);
	assert.equal(state.error.focusOptions, null);
	assert.deepEqual(state.announcements, []);

	afterSwap({ target: state.error, detail });
	assert.equal(row.hidden, false);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{
			message: 'The repository provider rejected this credential.',
			type: 'assertive',
		},
	]);
});

test('credential success shows its result row only while the Notice is visible', () => {
	for (const noticeHidden of [false, true]) {
		const { state, row } = credentialResultRowFixture(noticeHidden);
		state.listeners.get('htmx:afterSwap')({
			target: state.error,
			detail: {
				elt: state.swapTarget,
				requestConfig: { elt: state.form },
				xhr: { status: 200 },
			},
		});
		assert.equal(row.hidden, noticeHidden);
		assert.equal(state.error.focusOptions, null);
	}
});

test('credential transport failure preserves expiry and announces the replacement result', () => {
	const state = fixture();
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-credential-validation';
	const result = { textContent: 'old success', hidden: false, className: '' };
	state.error.textContent = 'Expiry unknown';
	state.error.querySelector = (selector) =>
		selector === '[data-ran-booster-credential-result]' ? result : null;
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	init();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: { status: 0 } },
	});
	assert.equal(state.error.textContent, 'Expiry unknown');
	assert.equal(
		result.textContent,
		'We could not complete that request. Please try again.'
	);
	assert.equal(result.className, 'ran-booster-credential-health__error');
	assert.equal(result.hidden, false);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.equal(state.errorAttributes.has('role'), false);
	assert.deepEqual(state.announcements, [
		{ message: result.textContent, type: 'assertive' },
	]);
});

test('removing a credential validation form aborts its own request only', () => {
	const state = fixture();
	const calls = [];
	state.window.htmx = { trigger: (...args) => calls.push(args) };
	state.form.matches = (selector) =>
		selector === '[data-ran-booster-credential-validation]';
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	init();
	state.listeners.get('htmx:beforeCleanupElement')({ target: state.form });
	state.listeners.get('htmx:beforeCleanupElement')({
		target: { matches: () => false },
	});
	assert.deepEqual(calls, [[state.form, 'htmx:abort']]);
});

test('a package failure focuses and announces its rendered notice without copying it globally', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	state.error.textContent = '';
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';
	const renderedNotice = {
		hidden: false,
		textContent:
			'The GitHub release must contain exactly one uploaded ZIP asset. Why this happened and how to fix it.',
		focusOptions: null,
		closest: (selector) => (selector === '.ran-booster-admin' ? {} : null),
		attributes: new Map(),
		focus(options) {
			this.focusOptions = options;
		},
		hasAttribute(name) {
			return this.attributes.has(name);
		},
		setAttribute(name, value) {
			this.attributes.set(name, String(value));
		},
		querySelector(selector) {
			return selector === 'p'
				? {
						textContent:
							'The GitHub release must contain exactly one uploaded ZIP asset.',
					}
				: null;
		},
	};
	state.setRenderedErrors([renderedNotice]);

	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:afterSwap')({
		detail: { elt: state.swapTarget, xhr: { status: 200 } },
	});

	assert.equal(state.error.hidden, true);
	assert.equal(state.error.textContent, '');
	assert.equal(state.error.focusOptions, null);
	assert.equal(renderedNotice.attributes.has('role'), false);
	assert.equal(renderedNotice.attributes.get('tabindex'), '-1');
	assert.deepEqual(renderedNotice.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{
			message:
				'The GitHub release must contain exactly one uploaded ZIP asset.',
			type: 'assertive',
		},
	]);
});

test('a branch check leaves an unrelated persistent error untouched', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	state.error.textContent = '';
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-relocate-rendered-error';
	let persistentErrorRemoved = false;
	const persistentError = {
		hidden: false,
		remove() {
			persistentErrorRemoved = true;
		},
		textContent: 'An unrelated persistent error.',
	};
	state.setRenderedErrors([persistentError]);

	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:afterSwap')({
		detail: { elt: state.swapTarget, xhr: { status: 200 } },
	});

	assert.equal(persistentErrorRemoved, false);
	assert.equal(state.error.hidden, true);
	assert.equal(state.error.textContent, '');
});

test('scoped failures prefer their own target and exclude recreated static notices', () => {
	for (const populated of [true, false]) {
		const state = fixture();
		state.error.textContent = populated ? 'Operation failed.' : '';
		const statics = [
			'data-ran-booster-provider-storage-notice',
			'data-ran-booster-assistance-site-notice',
		].map((marker) => ({
			hidden: false,
			textContent: 'Static problem',
			hasAttribute: (name) => name === marker,
			focus() {
				throw new Error('Static notice focused');
			},
		}));
		state.setRenderedErrors(statics);
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		state.listeners.get('htmx:beforeRequest')({
			detail: { elt: state.form },
		});
		state.listeners.get('htmx:afterSwap')({
			detail: { elt: state.swapTarget, xhr: { status: 422 } },
		});
		assert.equal(
			state.error.textContent,
			populated
				? 'Operation failed.'
				: 'We could not complete that request. Please try again.'
		);
		assert.deepEqual(state.error.focusOptions, { preventScroll: true });
		assert.deepEqual(
			statics.map((notice) => notice.textContent),
			['Static problem', 'Static problem']
		);
	}
});

test('a package response never reveals an empty global error banner', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	state.error.textContent = '';
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';

	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:afterSwap')({
		detail: { elt: state.swapTarget, xhr: { status: 200 } },
	});

	assert.equal(state.error.hidden, true);
	assert.equal(state.error.focusOptions, null);
});

test('a redirected failure retains its originating form and focuses without scrolling', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});

	init();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:afterSwap')({
		detail: { elt: state.swapTarget, xhr: { status: 500 } },
	});

	assert.equal(state.error.hidden, false);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.scrollCalls, []);
});

test('a 422 swaps even before the local error host exists', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const detail = {
		elt: state.swapTarget,
		requestConfig: { elt: state.form },
		xhr: { status: 422 },
	};
	state.document.getElementById = () => null;

	init();
	state.listeners.get('htmx:beforeSwap')({ detail });

	assert.equal(detail.shouldSwap, true);
});

test('a package mutation swaps a server-rendered 400 failure instead of navigating', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const detail = {
		elt: state.swapTarget,
		requestConfig: { elt: state.form },
		xhr: { status: 400 },
	};

	init();
	state.listeners.get('htmx:beforeSwap')({ detail });

	assert.equal(detail.shouldSwap, true);
});

test('a stale package edit swaps its server-rendered 409 conflict locally', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const detail = {
		elt: state.swapTarget,
		requestConfig: { elt: state.form },
		xhr: { status: 409 },
	};

	init();
	state.listeners.get('htmx:beforeSwap')({ detail });
	state.listeners.get('htmx:afterSwap')({ detail });

	assert.equal(detail.shouldSwap, true);
	assert.equal(state.error.hidden, false);
});

test('enhanced mutation feedback also swaps the Core-redacted 500 error locally', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const detail = {
		elt: state.swapTarget,
		requestConfig: { elt: state.form },
		xhr: { status: 500 },
	};

	init();
	state.listeners.get('htmx:beforeSwap')({ detail });
	state.listeners.get('htmx:afterSwap')({ detail });

	assert.equal(detail.shouldSwap, true);
	assert.equal(state.error.hidden, false);
	assert.deepEqual(state.announcements, [
		{ message: 'The setting could not be saved.', type: 'assertive' },
	]);
});

test('source navigation HTTP failures retain the view and announce an error', () => {
	for (const status of [400, 409, 422, 500]) {
		const state = fixture();
		state.sourceLinkAttributes.set(
			'data-ran-booster-source-choice',
			'branch'
		);
		const init = loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
			wp: { i18n: { __: (message) => message } },
		});
		init();
		const detail = {
			elt: state.sourceLink,
			xhr: { status },
			shouldSwap: true,
		};
		state.listeners.get('htmx:beforeRequest')({ detail });
		state.listeners.get('htmx:beforeSwap')({ detail });
		assert.equal(detail.shouldSwap, false, status);
		state.listeners.get('htmx:responseError')({ detail });
		state.listeners.get('htmx:afterRequest')({ detail });
		assert.equal(state.sourceProgress.hidden, true, status);
		assert.equal(
			state.sourceLinkAttributes.has('aria-busy'),
			false,
			status
		);
		assert.equal(state.error.hidden, false, status);
		assert.deepEqual(
			state.error.focusOptions,
			{ preventScroll: true },
			status
		);
		assert.deepEqual(
			state.announcements,
			[
				{
					message:
						'We could not complete that request. Please try again.',
					type: 'assertive',
				},
			],
			status
		);
	}
});

test('Notice transport fallback preserves the owned subtree and uses only its alert boundary', () => {
	const state = fixture();
	const primary = { textContent: 'The previous attempt was rejected.' };
	const detail = {
		hidden: false,
		textContent: 'Previous saved value.',
		setAttribute() {},
	};
	const field = {
		id: 'field-error',
		textContent: 'Previous validation error.',
	};
	const configuration = {
		id: 'configuration-error',
		textContent: 'Configuration remains locked.',
	};
	const content = { primary, detail, field, configuration };
	const mount = { sentinel: true };
	state.error.content = content;
	state.error.mount = mount;
	state.error.attributes.set('data-ran-booster-ui-notice', '');
	Object.defineProperty(state.error, 'textContent', {
		get: () => primary.textContent,
		set() {
			throw new Error('The Notice host cannot be replaced.');
		},
	});
	state.error.querySelector = (selector) =>
		selector === '[data-ran-booster-ui-notice-primary]' ? primary : null;
	state.error.querySelectorAll = (selector) =>
		selector === '[data-ran-booster-ui-notice-detail]' ? [detail] : [field];
	state.form.dataset.ranBoosterSettingsScope = 'numeric';
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: {} },
	});
	assert.match(primary.textContent, /could not complete/);
	assert.equal(state.error.content, content);
	assert.equal(state.error.mount, mount);
	assert.equal(field.id, 'field-error');
	assert.equal(field.textContent, '');
	assert.equal(configuration.textContent, 'Configuration remains locked.');
	assert.equal(detail.hidden, true);
	assert.equal(state.error.hidden, false);
	assert.equal(state.error.attributes.has('role'), false);
	assert.deepEqual(state.announcements, [
		{ message: primary.textContent, type: 'assertive' },
	]);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
});

test('enhanced mutation transport failures reset busy state and keep an error local', () => {
	for (const eventName of [
		'htmx:responseError',
		'htmx:sendError',
		'htmx:swapError',
		'htmx:targetError',
		'htmx:timeout',
	]) {
		const state = fixture();
		const translations = [];
		const translatedFailure =
			'Nous n’avons pas pu effectuer cette demande. Veuillez réessayer.';
		const init = loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
			wp: {
				i18n: {
					__: (message, domain) => {
						translations.push({ message, domain });
						return translatedFailure;
					},
				},
			},
		});

		init();
		state.listeners.get('htmx:beforeRequest')({
			detail: { elt: state.form },
		});
		state.listeners.get(eventName)({
			detail: {
				elt: state.form,
				xhr: { status: 503 },
			},
		});

		assert.equal(state.formAttributes.has('aria-busy'), false, eventName);
		assert.deepEqual(translations, [
			{
				message:
					'We could not complete that request. Please try again.',
				domain: 'ran-booster',
			},
		]);
		assert.equal(state.button.disabled, false, eventName);
		assert.equal(state.error.hidden, false, eventName);
		assert.equal(state.error.textContent, translatedFailure, eventName);
		assert.deepEqual(
			state.announcements,
			[
				{
					message: translatedFailure,
					type: 'assertive',
				},
			],
			eventName
		);
	}
});

test('a settings scope timeout releases busy and leaves the unconfirmed message to the settings runtime', () => {
	const state = fixture();
	state.form.dataset.ranBoosterSettingsScope = 'numeric';
	state.window.ranBoosterSettingsInitialized = true;
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:beforeRequest')({
		detail: { elt: state.form },
	});
	state.listeners.get('htmx:timeout')({
		detail: { elt: state.form, xhr: { status: 0 } },
	});

	assert.equal(state.formAttributes.has('aria-busy'), false);
	assert.equal(state.button.disabled, false);
	// No competing generic failure and no success announcement.
	assert.deepEqual(state.announcements, []);
	assert.equal(state.error.hidden, true);
});

test('a package mutation retains its success notice and cleans its result URL', () => {
	const state = fixture();
	state.window.location.href =
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&ran_booster_result=edit&ran_booster_package=example%2Fexample.php&_ran_booster_notice_nonce=signed&package=example%2Fexample.php';
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	let removed = false;
	const successNotice = {
		textContent: 'Plugin was successfully updated.',
		remove() {
			removed = true;
		},
	};
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';
	state.document.querySelectorAll = (selector) =>
		selector ===
		'#wpbody-content [data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
			? [successNotice]
			: [];
	state.document.dispatchEvent = (event) => {
		state.listeners.get(event.type)?.(event);
	};
	state.window.CustomEvent = class {
		constructor(type, options) {
			this.detail = options.detail;
			this.type = type;
		}
	};

	init();
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: state.swapTarget,
			requestConfig: { elt: state.form },
			xhr: { status: 200 },
		},
	});
	state.listeners.get('htmx:afterSettle')({
		detail: { requestConfig: { elt: state.form } },
	});

	assert.equal(removed, false);

	assert.deepEqual(state.replacedUrls, [
		{
			state: { htmx: true },
			title: '',
			url: 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php',
		},
	]);
});

test('a rendered provider release workflow result consumes only its signed PRG query values', () => {
	const state = fixture();
	state.window.location.href =
		'https://example.test/wp-admin/admin.php?page=ran-booster&keep=present&ran_booster_release_workflow_result=workflow_preflight_unavailable&ran_booster_release_workflow_success=0&ran_booster_release_workflow_type=plugin&ran_booster_release_workflow_package=example%2Fexample.php&ran_booster_release_workflow_failure_stage=release_preflight&ran_booster_release_workflow_diagnostic=provider_unavailable&ran_booster_release_workflow_diagnostic_available=1&ran_booster_release_workflow_reference=abc&ran_booster_release_workflow_channel=github&ran_booster_release_workflow_preview=none&ran_booster_release_workflow_source_revision=3&ran_booster_release_workflow_provider=fixture&ran_booster_release_workflow_repository=repo123&ran_booster_release_workflow_message=Provider%20unavailable&ran_booster_release_workflow_remediation=Reconnect%20the%20provider&ran_booster_release_workflow_result_nonce=signed&unrelated=retained';
	state.document.querySelector = (selector) =>
		selector ===
		'#wpbody-content [data-ran-booster-release-workflow-result]'
			? {}
			: null;

	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();

	assert.deepEqual(state.replacedUrls, [
		{
			state: { htmx: true },
			title: '',
			url: 'https://example.test/wp-admin/admin.php?page=ran-booster&keep=present&unrelated=retained',
		},
	]);
});

test('an ordinary visit does not rewrite a provider release workflow result URL', () => {
	const state = fixture();
	state.window.location.href =
		'https://example.test/wp-admin/admin.php?ran_booster_release_workflow_result=workflow_inspected';

	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();

	assert.deepEqual(state.replacedUrls, []);
});

test('an enhanced read action retains an explicit success notice', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const successNotice = {
		textContent: 'Release eligibility checked; no changes found.',
		remove() {},
	};
	state.document.querySelectorAll = (selector) =>
		selector ===
		'#wpbody-content [data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
			? [successNotice]
			: [];
	state.document.dispatchEvent = (event) => {
		state.listeners.get(event.type)?.(event);
	};
	state.window.CustomEvent = class {
		constructor(type, options) {
			this.detail = options.detail;
			this.type = type;
		}
	};

	init();
	state.listeners.get('htmx:afterSwap')({
		detail: {
			requestConfig: { elt: state.sourceLink },
			xhr: { status: 200 },
		},
	});
	state.listeners.get('htmx:afterSettle')({
		detail: { requestConfig: { elt: state.sourceLink } },
	});
});

test('a package mutation retains an explicitly marked add-on success notice', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	let removed = false;
	const successNotice = {
		textContent: 'Release metadata refreshed.',
		remove() {
			removed = true;
		},
	};
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';
	state.document.querySelectorAll = (selector) => {
		return selector ===
			'#wpbody-content [data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
			? [successNotice]
			: [];
	};
	state.document.dispatchEvent = (event) => {
		state.listeners.get(event.type)?.(event);
	};
	state.window.CustomEvent = class {
		constructor(type, options) {
			this.detail = options.detail;
			this.type = type;
		}
	};

	init();
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: state.swapTarget,
			requestConfig: { elt: state.form },
			xhr: { status: 200 },
		},
	});
	state.listeners.get('htmx:afterSettle')({
		detail: { requestConfig: { elt: state.form } },
	});

	assert.equal(removed, false);
});

test('a package mutation leaves unrelated success notices in place', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	let removed = false;
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';
	const requestedSelectors = [];
	const unrelatedNotice = {
		remove() {
			removed = true;
		},
	};
	state.document.querySelectorAll = (selector) => {
		requestedSelectors.push(selector);
		return selector ===
			'#wpbody-content .notice.notice-success:not([data-ran-booster-update-summary]):not([data-ran-booster-feedback-toast])'
			? [unrelatedNotice]
			: [];
	};
	state.document.dispatchEvent = (event) => {
		state.listeners.get(event.type)?.(event);
	};
	state.window.CustomEvent = class {
		constructor(type, options) {
			this.detail = options.detail;
			this.type = type;
		}
	};
	init();
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: state.swapTarget,
			requestConfig: { elt: state.form },
			xhr: { status: 200 },
		},
	});

	assert.equal(removed, false);

	assert.doesNotMatch(
		requestedSelectors.join('\n'),
		/\.notice\.notice-success/
	);
});

test('a queued package summary remains persistent and never becomes a success toast', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const queueSummary = {
		textContent: 'Queued 2 plugins for sequential branch reinstall.',
		remove() {
			throw new Error('A queued summary must remain in the page.');
		},
	};
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';
	state.document.querySelectorAll = (selector) => {
		if (
			selector ===
			'#wpbody-content [data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
		) {
			return [];
		}

		return selector ===
			'#wpbody-content .notice.notice-success:not([data-ran-booster-update-summary]):not([data-ran-booster-feedback-toast])'
			? []
			: [queueSummary];
	};
	state.document.dispatchEvent = (event) => {
		state.listeners.get(event.type)?.(event);
	};
	state.window.CustomEvent = class {
		constructor(type, options) {
			this.detail = options.detail;
			this.type = type;
		}
	};

	init();
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: state.swapTarget,
			requestConfig: { elt: state.form },
			xhr: { status: 200 },
		},
	});
});

test('the feedback runtime has no legacy toast presenter', () => {
	assert.match(source, /onDomReady\(initEnhancedMutationFeedback\)/);
	assert.doesNotMatch(legacySource, /initEnhancedMutationFeedback/);
	assert.doesNotMatch(source, /\$\(|\$\.|\}\)\(jQuery\)/);
	assert.doesNotMatch(
		source,
		/data-ran-booster-feedback-toast|showToast|toastTimers|toastAnimations|positionToast/
	);
	assert.doesNotMatch(source, /setTimeout|\.animate\?\./);
	assert.doesNotMatch(source, /#wpbody-content \.notice\.notice-success/);
	assert.match(source, /window\.ranBoosterAnnounce\(/);
	assert.doesNotMatch(source, /wp\.a11y|setAttribute\('(role|aria-live)'/);
	assert.doesNotMatch(packageSource, /packageSourceScrollY/);
	assert.match(
		buttonCss,
		/ran-booster-enhanced-mutation__submitter--busy\.ran-booster-update-is-active::before[\s\S]*repeating-linear-gradient/
	);
	assert.match(css, /ran-booster-snackbar-host/);
});

test('General busy state keeps switches and their fieldsets usable while protecting submit controls', () => {
	const state = fixture();
	state.form.dataset.ranBoosterSettingsScope = 'self_update_discovery';
	const checkbox = {
		disabled: false,
		matches: (selector) => selector.includes('input[role="switch"]'),
	};
	const fieldset = {
		disabled: false,
		matches: (selector) => selector.includes('fieldset'),
	};
	state.form.elements = Object.assign([fieldset, checkbox, state.button], {
		namedItem: () => null,
	});
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	assert.equal(
		fieldset.disabled,
		false,
		'Disabling a fieldset would disable its switch descendants.'
	);
	assert.equal(checkbox.disabled, false);
	assert.equal(state.button.disabled, true);
	assert.equal(state.formAttributes.get('aria-busy'), 'true');
	assert.equal(
		state.form.classList.contains('ran-booster-enhanced-mutation-is-busy'),
		false
	);
	assert.equal(
		state.button.classList.contains(
			'ran-booster-enhanced-mutation__submitter--busy'
		),
		false
	);
	assert.equal(
		state.button.classList.contains('ran-booster-update-is-active'),
		false
	);
	state.listeners.get('htmx:afterRequest')({ detail: { elt: state.form } });
	assert.equal(state.button.disabled, false);
	assert.equal(state.formAttributes.has('aria-busy'), false);
});

test('General busy state leaves only the mounted switch subtree operable', () => {
	for (const scope of ['hide_github', 'self_update_discovery', 'numeric']) {
		const state = fixture();
		state.form.dataset.ranBoosterSettingsScope = scope;
		const control = (disabled, mounted) => ({
			disabled,
			matches: () => false,
			closest: (selector) =>
				selector.includes('[data-ran-booster-ui-mounted]') && mounted
					? {}
					: null,
			getAttribute: () => null,
			setAttribute() {},
			removeAttribute() {},
		});
		const button = control(false, true);
		const hiddenInput = control(false, true);
		const locked = control(true, true);
		const outside = control(false, false);
		state.form.elements = Object.assign(
			[button, hiddenInput, locked, outside, state.button],
			{ namedItem: () => null }
		);
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		state.listeners.get('htmx:beforeRequest')({
			detail: { elt: state.form },
		});
		assert.equal(button.disabled, scope === 'numeric');
		assert.equal(hiddenInput.disabled, scope === 'numeric');
		assert.equal(locked.disabled, true);
		assert.equal(outside.disabled, true);
		state.listeners.get('htmx:afterRequest')({
			detail: { elt: state.form },
		});
		assert.equal(locked.disabled, true);
		assert.equal(outside.disabled, false);
	}
});

test('package save feedback requires the matching authoritative edit response', () => {
	for (const scenario of [
		'plugin',
		'theme',
		'no-request',
		'no-load',
		'no-marker',
		'wrong-package',
		'wrong-type',
		'wrong-returned-action',
		'wrong-returned-identifier',
		'install',
		'already-managed',
		'partial-reinstall',
		'recovery',
		'failed-status',
		'stale',
		'independent-pending',
		'host-absent',
		'host-refused',
	]) {
		const state = fixture();
		const type = scenario === 'theme' ? 'theme' : 'plugin';
		const identifier =
			type === 'plugin' ? 'example/example.php' : 'example';
		const handlers = new Map();
		const xhr = {
			status: scenario === 'failed-status' ? 500 : 200,
			addEventListener() {},
			getResponseHeader: () => null,
		};
		const button = state.button;
		button.dataset = {};
		button.isConnected = true;
		button.matches = (selector) =>
			[
				'[data-ran-booster-enhanced-mutation]',
				'[data-ran-booster-package-settings-save]',
				'a, button',
			].includes(selector);
		button.hasAttribute = () => false;
		button.addEventListener = (name, handler) =>
			handlers.set(name, handler);
		button.removeEventListener = () => {};
		const action = [
			'install',
			'already-managed',
			'partial-reinstall',
		].includes(scenario)
			? scenario
			: `edit-${type}`;
		button.form = {
			elements: {
				namedItem: (name) => ({
					value: name === 'ran_booster[action]' ? action : identifier,
				}),
			},
		};
		const oldRegion = {
			id: 'wpbody-content',
			isConnected: true,
			contains: () => true,
		};
		let current = oldRegion;
		let removed = 0;
		let rendered = 0;
		const notice = {
			isConnected: true,
			textContent: 'Package settings saved.',
			dataset: {
				ranBoosterSavedPackage:
					scenario === 'wrong-package' ? 'other' : identifier,
				ranBoosterSavedType: scenario === 'wrong-type' ? 'other' : type,
			},
			remove() {
				removed++;
				this.isConnected = false;
			},
		};
		const returnedAction =
			scenario === 'wrong-returned-action'
				? 'install-plugin'
				: `edit-${type}`;
		const returnedIdentifier =
			scenario === 'wrong-returned-identifier' ? 'other' : identifier;
		const newRegion = {
			id: 'wpbody-content',
			querySelector(selector) {
				if (selector === '#ran-booster-package-edit-form') {
					return scenario === 'recovery'
						? null
						: {
								elements: {
									namedItem: (name) => ({
										value:
											name === 'ran_booster[action]'
												? returnedAction
												: returnedIdentifier,
									}),
								},
							};
				}
				return scenario === 'no-marker' ? null : notice;
			},
			getBoundingClientRect: () => ({ left: 0, width: 800 }),
			insertAdjacentElement() {},
		};
		state.document.getElementById = (id) =>
			id === 'wpbody-content' ? current : state.error;
		if (scenario !== 'host-absent') {
			state.window.ranBoosterFeedback = {
				show: (message) => {
					assert.equal(message, 'Package settings saved.');
					rendered++;
					return scenario !== 'host-refused';
				},
			};
		}
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = { elt: button, target: oldRegion, xhr };
		if (scenario !== 'no-request') {
			state.listeners.get('htmx:beforeRequest')({ detail });
		}
		if (scenario !== 'no-load' && handlers.has('htmx:beforeOnLoad')) {
			handlers.get('htmx:beforeOnLoad')({
				detail,
				preventDefault() {
					assert.fail('unexpected veto');
				},
			});
		}
		if (scenario === 'stale') {
			state.form.isConnected = true;
			state.form.addEventListener = () => {};
			state.form.removeEventListener = () => {};
			state.listeners.get('htmx:beforeRequest')({
				detail: {
					elt: state.form,
					target: oldRegion,
					xhr: { addEventListener() {} },
				},
			});
		}
		if (scenario === 'independent-pending') {
			state.form.isConnected = true;
			state.form.addEventListener = () => {};
			state.form.removeEventListener = () => {};
			state.listeners.get('htmx:beforeRequest')({
				detail: {
					elt: state.form,
					target: { id: 'other-target' },
					xhr: { addEventListener() {} },
				},
			});
		}
		current = newRegion;
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('htmx:afterSettle')({ detail });
		state.listeners.get('htmx:afterSettle')({ detail });
		const success = ['plugin', 'theme', 'independent-pending'].includes(
			scenario
		);
		const fallback = ['host-absent', 'host-refused'].includes(scenario);
		assert.equal(removed, success ? 1 : 0, scenario);
		assert.equal(
			rendered,
			success || scenario === 'host-refused' ? 1 : 0,
			scenario
		);
		assert.deepEqual(
			state.announcements,
			fallback
				? [{ message: 'Package settings saved.', type: 'polite' }]
				: [],
			scenario
		);
	}
});

test('retained completed results do not focus an unrelated static provider failure', () => {
	const state = fixture();
	const notice = { textContent: 'Plugin was successfully updated.' };
	const original = state.document.querySelectorAll;
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-package-mutation';
	state.document.querySelectorAll = (selector) =>
		selector.includes('[data-ran-booster-package-success]')
			? [notice]
			: original(selector);
	let focused = false;
	state.setRenderedErrors([
		{
			textContent: 'Provider unavailable.',
			hidden: false,
			querySelector: () => null,
			hasAttribute: () => false,
			setAttribute() {},
			focus() {
				focused = true;
			},
		},
	]);
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:afterSwap')({
		detail: { requestConfig: { elt: state.form }, xhr: { status: 200 } },
	});
	assert.equal(focused, false);
	assert.equal(state.error.focusOptions, null);
	assert.equal(notice.textContent, 'Plugin was successfully updated.');
});

test('an older same-target read cannot overtake or disrupt the newer package save', () => {
	for (const order of ['read-before-save', 'read-after-save']) {
		const state = fixture();
		const oldHandlers = new Map();
		const saveHandlers = new Map();
		const oldTarget = {
			id: 'wpbody-content',
			isConnected: true,
			contains: () => true,
		};
		let current = oldTarget;
		const oldXhr = {
			status: 200,
			addEventListener() {},
			getResponseHeader: () => null,
		};
		const saveXhr = {
			status: 200,
			addEventListener() {},
			getResponseHeader: () => null,
		};
		const old = state.sourceLink;
		old.isConnected = true;
		old.addEventListener = (name, handler) =>
			oldHandlers.set(name, handler);
		old.removeEventListener = () => {};
		const save = state.button;
		save.dataset = {};
		save.isConnected = true;
		save.matches = (selector) =>
			[
				'[data-ran-booster-enhanced-mutation]',
				'[data-ran-booster-package-settings-save]',
				'a, button',
			].includes(selector);
		save.hasAttribute = () => false;
		save.form = {
			elements: {
				namedItem: (name) => ({
					value:
						name === 'ran_booster[action]'
							? 'edit-plugin'
							: 'example/example.php',
				}),
			},
		};
		save.addEventListener = (name, handler) =>
			saveHandlers.set(name, handler);
		save.removeEventListener = () => {};
		state.document.getElementById = (id) =>
			id === 'wpbody-content' ? current : state.error;
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const oldDetail = { elt: old, target: oldTarget, xhr: oldXhr };
		const saveDetail = { elt: save, target: oldTarget, xhr: saveXhr };
		state.listeners.get('htmx:beforeRequest')({ detail: oldDetail });
		let veto = false;
		const readLoad = () =>
			oldHandlers.get('htmx:beforeOnLoad')({
				detail: oldDetail,
				preventDefault() {
					veto = true;
				},
			});
		if (order === 'read-before-save') {
			readLoad();
		}
		state.listeners.get('htmx:beforeRequest')({ detail: saveDetail });
		if (order === 'read-after-save') {
			readLoad();
		}
		assert.equal(veto, order === 'read-after-save');
		state.listeners.get('htmx:sendError')({ detail: oldDetail });
		state.listeners.get('htmx:afterRequest')({ detail: oldDetail });
		assert.equal(save.disabled, true, order);
		assert.equal(state.error.hidden, true, order);
		assert.deepEqual(state.announcements, [], order);
		saveHandlers.get('htmx:beforeOnLoad')({
			detail: saveDetail,
			preventDefault() {
				assert.fail('current save vetoed');
			},
		});
		const notice = {
			isConnected: true,
			textContent: 'Saved.',
			dataset: {
				ranBoosterSavedPackage: 'example/example.php',
				ranBoosterSavedType: 'plugin',
			},
		};
		current = {
			id: 'wpbody-content',
			querySelector: (selector) =>
				selector === '#ran-booster-package-edit-form'
					? save.form
					: notice,
		};
		state.listeners.get('htmx:afterSwap')({ detail: oldDetail });
		state.listeners.get('htmx:afterSwap')({ detail: saveDetail });
		state.listeners.get('htmx:afterSettle')({ detail: oldDetail });
		state.listeners.get('htmx:afterSettle')({ detail: saveDetail });
		assert.deepEqual(
			state.announcements,
			[{ message: 'Saved.', type: 'polite' }],
			order
		);
	}
});

test('a retained non-save result is announced once after its own settled response', () => {
	for (const scenario of [
		'plain',
		'header',
		'live',
		'hidden',
		'live-off',
		'failed',
	]) {
		const hasHeader = scenario === 'header';
		const state = fixture();
		const handlers = new Map();
		const target = {
			id: 'wpbody-content',
			isConnected: true,
			contains: () => true,
		};
		let current = target;
		const notice = {
			textContent: 'Metadata refreshed.',
			getClientRects: () => (scenario === 'hidden' ? [] : [{}]),
			closest: () =>
				['live', 'live-off'].includes(scenario)
					? {
							getAttribute: () =>
								scenario === 'live-off' ? 'off' : 'polite',
						}
					: null,
		};
		const xhr = {
			status: scenario === 'failed' ? 500 : 200,
			addEventListener() {},
			getResponseHeader: () =>
				hasHeader
					? JSON.stringify({
							'ran-booster:admin-mutation-success': {
								message: notice.textContent,
							},
						})
					: null,
		};
		state.form.isConnected = true;
		state.form.addEventListener = (name, handler) =>
			handlers.set(name, handler);
		state.form.removeEventListener = () => {};
		state.document.getElementById = (id) =>
			id === 'wpbody-content' ? current : state.error;
		state.document.querySelectorAll = (selector) =>
			selector.includes('data-ran-booster-package-success')
				? [notice]
				: [];
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = { elt: state.form, target, xhr };
		state.listeners.get('htmx:beforeRequest')({ detail });
		handlers.get('htmx:beforeOnLoad')({
			detail,
			preventDefault() {
				assert.fail('current response vetoed');
			},
		});
		current = {
			id: 'wpbody-content',
			querySelectorAll: () => [notice],
			append() {},
		};
		state.listeners.get('htmx:afterSwap')({ detail });
		if (hasHeader) {
			notice.getClientRects = () => [{}];
			notice.closest = () => null;
			state.listeners.get('ran-booster:admin-mutation-success')({
				detail: { message: notice.textContent },
			});
		} else {
			assert.deepEqual(
				state.announcements.filter((item) => item.type === 'polite'),
				[]
			);
		}
		state.listeners.get('htmx:afterSettle')({ detail });
		state.listeners.get('htmx:afterSettle')({ detail });
		assert.deepEqual(
			state.announcements.filter((item) => item.type === 'polite'),
			['live', 'hidden', 'failed'].includes(scenario)
				? []
				: [{ message: notice.textContent, type: 'polite' }],
			scenario
		);
	}
});

test('a region can anchor its hidden confirmation beside its actions', () => {
	for (const anchored of [true, false]) {
		const state = fixture();
		const handlers = new Map();
		const target = {
			id: 'ran-booster-debug-capture-region',
			isConnected: true,
			contains: () => true,
		};
		const message = 'Temporary logging capture started.';
		const xhr = {
			status: 200,
			addEventListener() {},
			getResponseHeader: () =>
				JSON.stringify({
					'ran-booster:admin-mutation-success': { message },
				}),
		};
		state.form.isConnected = true;
		state.form.addEventListener = (name, handler) =>
			handlers.set(name, handler);
		state.form.removeEventListener = () => {};
		let current = target;
		state.document.getElementById = (id) =>
			id === 'ran-booster-debug-capture-region' ? current : state.error;
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = { elt: state.form, target, xhr };
		state.listeners.get('htmx:beforeRequest')({ detail });
		handlers.get('htmx:beforeOnLoad')({
			detail,
			preventDefault() {
				assert.fail('current response vetoed');
			},
		});
		const anchorChildren = [];
		const regionChildren = [];
		current = {
			id: 'ran-booster-debug-capture-region',
			querySelectorAll: () => [],
			querySelector: (selector) =>
				anchored && selector === '[data-ran-booster-success-feedback]'
					? { append: (child) => anchorChildren.push(child) }
					: null,
			append: (child) => regionChildren.push(child),
		};
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message },
		});

		if (anchored) {
			assert.equal(anchorChildren.length, 1);
			assert.equal(anchorChildren[0].textContent, message);
			assert.equal(anchorChildren[0].className, 'screen-reader-text');
			assert.equal(regionChildren.length, 0);
		} else {
			assert.equal(regionChildren.length, 1);
			assert.equal(regionChildren[0].textContent, message);
			assert.notEqual(regionChildren[0].className, 'screen-reader-text');
			assert.equal(anchorChildren.length, 0);
		}
		assert.deepEqual(
			state.announcements.filter((item) => item.type === 'polite'),
			[{ message, type: 'polite' }]
		);
	}
});

test('stale source and current save failures release their own controls for retry', () => {
	const state = fixture();
	const target = {
		id: 'wpbody-content',
		isConnected: true,
		contains: () => true,
	};
	const makeXhr = () => ({ addEventListener() {} });
	const old = state.sourceLink;
	old.isConnected = true;
	old.addEventListener = () => {};
	old.removeEventListener = () => {};
	const save = state.button;
	save.dataset = {};
	save.isConnected = true;
	save.matches = (selector) =>
		[
			'[data-ran-booster-enhanced-mutation]',
			'[data-ran-booster-package-settings-save]',
			'a, button',
		].includes(selector);
	save.hasAttribute = () => false;
	save.form = {
		elements: {
			namedItem: (name) => ({
				value:
					name === 'ran_booster[action]'
						? 'edit-plugin'
						: 'example/example.php',
			}),
		},
	};
	save.addEventListener = () => {};
	save.removeEventListener = () => {};
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	const oldDetail = { elt: old, target, xhr: makeXhr() };
	const saveDetail = { elt: save, target, xhr: makeXhr() };
	state.listeners.get('htmx:beforeRequest')({ detail: oldDetail });
	state.listeners.get('htmx:beforeRequest')({ detail: saveDetail });
	state.listeners.get('htmx:sendError')({ detail: oldDetail });
	assert.equal(state.sourceLinkAttributes.has('aria-busy'), false);
	assert.equal(state.sourceLinkAttributes.has('aria-disabled'), false);
	assert.equal(save.disabled, true);
	assert.equal(state.error.hidden, true);
	state.listeners.get('htmx:sendError')({ detail: saveDetail });
	assert.equal(save.disabled, false);
	let prevented = false;
	state.listeners.get('htmx:beforeRequest')({
		detail: { elt: old, target, xhr: makeXhr() },
		preventDefault() {
			prevented = true;
		},
	});
	assert.equal(prevented, false);
	assert.equal(state.sourceLinkAttributes.get('aria-busy'), 'true');
});

test('a completed request cannot release a newer request on the same element', () => {
	const state = fixture();
	const target = {
		id: 'wpbody-content',
		isConnected: true,
		contains: () => true,
	};
	state.form.isConnected = true;
	state.form.addEventListener = () => {};
	state.form.removeEventListener = () => {};
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	const first = { elt: state.form, target, xhr: { addEventListener() {} } };
	const second = { elt: state.form, target, xhr: { addEventListener() {} } };
	state.listeners.get('htmx:beforeRequest')({ detail: first });
	state.listeners.get('htmx:afterRequest')({ detail: first });
	assert.equal(state.button.disabled, false);
	state.listeners.get('htmx:beforeRequest')({ detail: second });
	state.listeners.get('htmx:afterRequest')({ detail: first });
	state.listeners.get('htmx:sendError')({ detail: first });
	assert.equal(state.button.disabled, true);
	assert.equal(state.formAttributes.get('aria-busy'), 'true');
	assert.equal(state.error.hidden, true);
	state.listeners.get('htmx:afterRequest')({ detail: second });
	assert.equal(state.button.disabled, false);
	assert.equal(state.formAttributes.has('aria-busy'), false);
});

test('non-save success requires its current ready response and preserves native messages', async () => {
	for (const scenario of [
		'current',
		'existing',
		'live',
		'live-off',
		'hidden-existing',
		'unknown',
		'non-2xx',
		'malformed',
		'stale',
		'independent',
		'portal',
		'general-outside',
		'expired',
	]) {
		const state = fixture();
		const regionById = new Map();
		const handlers = new Map();
		const appended = [];
		const existing = {
			hidden: false,
			textContent: 'Confirmed safely.',
			getClientRects: () => (scenario === 'hidden-existing' ? [] : [{}]),
			closest: () =>
				['live', 'live-off'].includes(scenario)
					? {
							getAttribute: () =>
								scenario === 'live-off' ? 'off' : 'polite',
						}
					: null,
		};
		const old = {
			id: 'target-a',
			isConnected: true,
			contains: () => !['general-outside', 'portal'].includes(scenario),
		};
		if (scenario === 'general-outside') {
			old.id = 'ran-booster-general-region';
		}
		const next = {
			id: old.id,
			hasAttribute: () => true,
			querySelectorAll: () =>
				['existing', 'live', 'live-off', 'hidden-existing'].includes(
					scenario
				)
					? [existing]
					: [],
			append: (node) => appended.push(node),
		};
		regionById.set(old.id, old);
		state.document.getElementById = (id) =>
			regionById.get(id) || state.error;
		state.document.createElement = () => ({ textContent: '' });
		state.form.isConnected = true;
		state.form.matches = (selector) =>
			selector === '[data-ran-booster-enhanced-mutation]' ||
			(scenario === 'general-outside' &&
				selector === '[data-ran-booster-general-form]');
		state.form.addEventListener = (name, callback) =>
			handlers.set(name, callback);
		state.form.removeEventListener = () => {};
		const xhr = {
			status: scenario === 'non-2xx' ? 500 : 200,
			addEventListener() {},
			getResponseHeader: () =>
				scenario === 'malformed'
					? '{'
					: JSON.stringify({
							'ran-booster:admin-mutation-success': {
								message: 'Confirmed safely.',
							},
						}),
		};
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = { elt: state.form, target: old, xhr };
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message: 'Confirmed safely.' },
		});
		assert.deepEqual(
			appended,
			[],
			'A naked event must not create feedback.'
		);
		assert.deepEqual(state.announcements, []);
		state.listeners.get('htmx:beforeRequest')({ detail });
		if (['stale', 'independent'].includes(scenario)) {
			const target =
				scenario === 'stale'
					? old
					: { id: 'target-b', isConnected: true };
			regionById.set(target.id, target);
			const other = {
				...state.sourceLink,
				isConnected: true,
				addEventListener() {},
				removeEventListener() {},
			};
			state.listeners.get('htmx:beforeRequest')({
				detail: { elt: other, target, xhr: { addEventListener() {} } },
			});
		}
		let vetoed = false;
		handlers.get('htmx:beforeOnLoad')({
			detail,
			preventDefault() {
				vetoed = true;
			},
		});
		assert.equal(
			vetoed,
			['stale', 'general-outside'].includes(scenario),
			scenario
		);
		regionById.set(old.id, next);
		state.listeners.get('htmx:afterSwap')({ detail });
		if (scenario === 'expired') {
			await Promise.resolve();
		}
		const message =
			scenario === 'unknown' ? 'Different message.' : 'Confirmed safely.';
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message },
		});
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message },
		});
		const produced = [
			'current',
			'independent',
			'portal',
			'hidden-existing',
		].includes(scenario);
		const retained = ['existing', 'live', 'live-off'].includes(scenario);
		assert.equal(appended.length, produced ? 1 : 0, scenario);
		if (produced) {
			assert.equal(
				appended[0].textContent,
				'Confirmed safely.',
				scenario
			);
		}
		assert.deepEqual(
			state.announcements.filter((item) => item.type === 'polite'),
			produced || (retained && scenario !== 'live')
				? [{ message: 'Confirmed safely.', type: 'polite' }]
				: [],
			scenario
		);
	}
});

test('only the three authorized provider saves use the shared Snackbar', () => {
	for (const scenario of [
		'access',
		'access-button',
		'webhook',
		'public',
		'host-absent',
		'host-refused',
		'wrong-target',
		'wrong-operation',
		'wrong-header',
		'bare-header',
		'validation',
		'delete',
		'capture',
		'diagnostics',
	]) {
		const state = fixture();
		const handlers = new Map();
		const actions = {
			access: 'save-access-profile',
			'access-button': 'save-access-profile',
			webhook: 'save-webhook-profile',
			public: 'save-public-lookup-profile',
			'host-absent': 'save-access-profile',
			'host-refused': 'save-access-profile',
			'wrong-target': 'save-access-profile',
			'wrong-operation': 'save-other-profile',
			'wrong-header': 'save-access-profile',
			'bare-header': 'save-access-profile',
			validation: 'validate-access-profile',
			delete: 'delete-access-profile',
			capture: 'capture',
			diagnostics: 'diagnostics',
		};
		const operation = actions[scenario];
		let targetId = 'ran-booster-provider-profile-region';
		if (scenario === 'public') {
			targetId = 'ran-booster-public-lookup-profile-region';
		}
		if (scenario === 'wrong-target') {
			targetId = 'other-region';
		}
		const target = {
			id: targetId,
			isConnected: true,
			contains: () => true,
		};
		let region = target;
		let rendered = 0;
		const appended = [];
		state.form.isConnected = true;
		state.form.elements.namedItem = () => ({ value: operation });
		state.form.addEventListener = (name, callback) =>
			handlers.set(name, callback);
		state.form.removeEventListener = () => {};
		const requestElement =
			scenario === 'access-button' ? state.button : state.form;
		if (requestElement === state.button) {
			requestElement.dataset = {};
			requestElement.isConnected = true;
			requestElement.form = state.form;
			requestElement.matches = (selector) =>
				['[data-ran-booster-enhanced-mutation]', 'a, button'].includes(
					selector
				);
			requestElement.hasAttribute = () => false;
			requestElement.addEventListener = (name, callback) =>
				handlers.set(name, callback);
			requestElement.removeEventListener = () => {};
		}
		state.document.getElementById = (id) =>
			id === targetId ? region : state.error;
		if (scenario !== 'host-absent') {
			state.window.ranBoosterFeedback = {
				show: (message) => {
					assert.equal(message, 'Profile saved.');
					rendered++;
					return scenario !== 'host-refused';
				},
			};
		}
		const payload = { message: 'Profile saved.' };
		if (scenario !== 'public') {
			payload.operation = operation;
			if (scenario === 'wrong-header') {
				payload.operation = 'core:save-webhook-profile';
			} else if (
				scenario !== 'bare-header' &&
				['save-access-profile', 'save-webhook-profile'].includes(
					operation
				)
			) {
				payload.operation = `core:${operation}`;
			}
		}
		const xhr = {
			status: 200,
			addEventListener() {},
			getResponseHeader: () =>
				JSON.stringify({
					'ran-booster:admin-mutation-success': payload,
				}),
		};
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = { elt: requestElement, target, xhr };
		state.listeners.get('htmx:beforeRequest')({ detail });
		handlers.get('htmx:beforeOnLoad')({
			detail,
			preventDefault() {
				assert.fail('current response vetoed');
			},
		});
		region = {
			id: targetId,
			getBoundingClientRect: () => ({ left: 0, width: 800 }),
			insertAdjacentElement() {},
			querySelectorAll: () => [],
			querySelector: () => null,
			append: (node) => appended.push(node),
		};
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message: payload.message },
		});
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message: payload.message },
		});
		state.listeners.get('htmx:afterSettle')({ detail });
		state.listeners.get('htmx:afterSettle')({ detail });
		const expectedSnackbar = [
			'access',
			'access-button',
			'webhook',
			'public',
		].includes(scenario);
		assert.equal(
			rendered,
			expectedSnackbar || scenario === 'host-refused' ? 1 : 0,
			scenario
		);
		if (expectedSnackbar) {
			assert.deepEqual(appended, [], scenario);
			assert.deepEqual(state.announcements, [], scenario);
		} else if (
			['wrong-target', 'wrong-header', 'bare-header'].includes(scenario)
		) {
			assert.deepEqual(appended, [], scenario);
			assert.deepEqual(state.announcements, [], scenario);
		} else {
			assert.equal(appended.length, 1, scenario);
			assert.equal(appended[0].textContent, payload.message, scenario);
			assert.deepEqual(
				state.announcements,
				[{ message: payload.message, type: 'polite' }],
				scenario
			);
		}
	}
});

test('provider Snackbar hands the host the current visible returned save control', () => {
	for (const scenario of [
		'inside',
		'outside',
		'access-heading',
		'webhook-heading',
		'missing-heading',
		'offscreen-heading',
	]) {
		const state = fixture();
		const handlers = new Map();
		const isPublic = ['inside', 'outside'].includes(scenario);
		let operation = 'save-access-profile';
		if (isPublic) {
			operation = 'save-public-lookup-profile';
		}
		if (scenario === 'webhook-heading') {
			operation = 'save-webhook-profile';
		}
		const target = {
			id: isPublic
				? 'ran-booster-public-lookup-profile-region'
				: 'ran-booster-provider-profile-region',
			isConnected: true,
			contains: () => true,
		};
		let region = target;
		const makeControl = (visible, disabled = false) => ({
			isConnected: true,
			disabled,
			getClientRects: () => (visible ? [{}] : []),
			getBoundingClientRect: () => ({
				left: 10,
				top: 10,
				right: 80,
				bottom: 40,
			}),
			focus() {
				state.document.activeElement = this;
			},
		});
		const hidden = makeControl(false);
		const disabled = makeControl(true, true);
		const returned = makeControl(true);
		const heading = makeControl(true);
		if (scenario === 'offscreen-heading') {
			heading.getBoundingClientRect = () => ({
				left: 2100,
				top: 10,
				right: 2200,
				bottom: 40,
			});
		}
		const fallback = makeControl(true);
		state.document.querySelectorAll = (selector) =>
			selector === '#wpadminbar a[href]' ? [fallback] : [];
		const staleForm = {
			elements: { namedItem: () => ({ value: operation }) },
			querySelectorAll: () => [hidden, disabled],
		};
		const form = {
			elements: { namedItem: () => ({ value: operation }) },
			querySelectorAll: () =>
				isPublic ? [hidden, disabled, returned] : [hidden, disabled],
		};
		state.form.isConnected = true;
		state.form.elements.namedItem = () => ({ value: operation });
		state.form.addEventListener = (name, callback) =>
			handlers.set(name, callback);
		state.form.removeEventListener = () => {};
		state.button.isConnected = false;
		state.document.getElementById = (id) =>
			id === target.id ? region : state.error;
		const shown = [];
		state.window.ranBoosterFeedback = {
			show: (message, options) => {
				shown.push(options);
				return true;
			},
		};
		const xhr = {
			status: 200,
			addEventListener() {},
			getResponseHeader: () =>
				JSON.stringify({
					'ran-booster:admin-mutation-success': isPublic
						? { message: 'Saved.' }
						: { message: 'Saved.', operation: `core:${operation}` },
				}),
		};
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		state.listeners.get('submit')({
			target: state.form,
			submitter: state.button,
		});
		const detail = { elt: state.form, target, xhr };
		state.listeners.get('htmx:beforeRequest')({ detail });
		handlers.get('htmx:beforeOnLoad')({
			detail,
			preventDefault() {
				assert.fail('current response vetoed');
			},
		});
		region = {
			id: target.id,
			querySelectorAll: (selector) =>
				selector === 'form' ? [staleForm, form] : [],
			querySelector: () =>
				scenario === 'missing-heading' || isPublic ? null : heading,
			getBoundingClientRect: () => ({ left: 0, width: 800 }),
			insertAdjacentElement() {},
		};
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message: 'Saved.' },
		});
		assert.equal(shown.length, 1, scenario);
		let expected = heading;
		if (isPublic) {
			expected = returned;
		} else if (scenario === 'missing-heading') {
			expected = state.button;
		}
		assert.equal(shown[0].returnControl, expected, scenario);
	}
});

test('a correlated Transporter row result retains cells and places feedback inside its last cell', () => {
	for (const scenario of ['standard', 'existing', 'template', 'empty']) {
		const alreadyVisible = scenario === 'existing';
		const state = fixture();
		const handlers = new Map();
		const target = {
			id: 'migration-row',
			tagName: 'TR',
			isConnected: true,
			contains: () => true,
		};
		let current = target;
		const message = 'Migration request completed.';
		const link = { tagName: 'A' };
		const form = { tagName: 'FORM' };
		const existing = {
			textContent: message,
			getClientRects: () => [{}],
			closest: () => null,
		};
		const firstCell = { tagName: 'TD', children: [link] };
		const lastCell = {
			tagName: 'TD',
			children: alreadyVisible ? [form, existing] : [form],
			append(node) {
				this.children.push(node);
			},
		};
		const template = { tagName: 'TEMPLATE' };
		const cells = scenario === 'empty' ? [] : [firstCell, lastCell];
		const children = [
			...cells,
			...(scenario === 'template' ? [template] : []),
		];
		const row = {
			id: target.id,
			tagName: 'TR',
			children,
			cells,
			lastElementChild: children.at(-1) || null,
			insertCell() {
				const cell = {
					tagName: 'TD',
					children: [],
					append(node) {
						this.children.push(node);
					},
				};
				cells.push(cell);
				children.push(cell);
				return cell;
			},
			querySelectorAll: () => (alreadyVisible ? [existing] : []),
			append() {
				assert.fail(
					'A paragraph cannot be appended directly to a row.'
				);
			},
		};
		state.form.isConnected = true;
		state.form.addEventListener = (name, callback) =>
			handlers.set(name, callback);
		state.form.removeEventListener = () => {};
		state.document.getElementById = (id) =>
			id === target.id ? current : state.error;
		state.document.createElement = () => ({ textContent: '' });
		const xhr = {
			status: 200,
			addEventListener() {},
			getResponseHeader: () =>
				JSON.stringify({
					'ran-booster:admin-mutation-success': {
						message,
						operation: 'transporter:migration',
					},
				}),
		};
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = { elt: state.form, target, xhr };
		state.listeners.get('htmx:beforeRequest')({ detail });
		handlers.get('htmx:beforeOnLoad')({
			detail,
			preventDefault() {
				assert.fail('current response vetoed');
			},
		});
		current = row;
		state.listeners.get('htmx:afterSwap')({ detail });
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message },
		});
		state.listeners.get('ran-booster:admin-mutation-success')({
			detail: { message },
		});
		state.listeners.get('htmx:afterSettle')({ detail });
		state.listeners.get('htmx:afterSettle')({ detail });
		assert.equal(row.children, children);
		assert.equal(row.cells, cells);
		const resultCell = cells.at(-1);
		if (scenario === 'empty') {
			assert.equal(cells.length, 1);
			assert.equal(resultCell.children.length, 1);
		} else {
			assert.equal(cells.length, 2);
			assert.equal(firstCell.children[0], link);
			assert.equal(resultCell, lastCell);
			assert.equal(lastCell.children[0], form);
			assert.equal(lastCell.children.length, 2);
		}
		assert.equal(resultCell.children.at(-1).textContent, message);
		if (alreadyVisible) {
			assert.equal(resultCell.children.at(-1), existing);
		}
		if (scenario === 'template') {
			assert.equal(children.at(-1), template);
		}
		assert.deepEqual(state.announcements, [{ message, type: 'polite' }]);
	}
});

test('a fresh operation failure remains available beside recreated static notices', () => {
	const state = fixture();
	state.error.textContent = '';
	const untouched = [
		'data-ran-booster-provider-storage-notice',
		'data-ran-booster-assistance-site-notice',
	].map((marker) => ({
		textContent: marker,
		hidden: false,
		hasAttribute: (name) => name === marker,
		setAttribute() {
			assert.fail('Static notices retain their owner.');
		},
		focus() {
			assert.fail('An unrelated static notice cannot own this failure.');
		},
	}));
	const attributes = new Map();
	let focused = false;
	const operation = {
		textContent: 'The operation failed with its own recovery guidance.',
		hidden: false,
		closest: (selector) => (selector === '.ran-booster-admin' ? {} : null),
		hasAttribute: (name) => attributes.has(name),
		setAttribute: (name, value) => attributes.set(name, value),
		querySelector: () => ({
			textContent: 'The operation failed with its own recovery guidance.',
		}),
		focus() {
			focused = true;
		},
	};
	state.setRenderedErrors([...untouched, operation]);
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: state.swapTarget,
			requestConfig: { elt: state.form },
			xhr: { status: 422 },
		},
	});
	assert.equal(focused, true);
	assert.equal(attributes.has('role'), false);
	assert.deepEqual(state.announcements, [
		{ message: operation.textContent, type: 'assertive' },
	]);
	assert.deepEqual(
		untouched.map((node) => node.textContent),
		[
			'data-ran-booster-provider-storage-notice',
			'data-ran-booster-assistance-site-notice',
		]
	);
});

test('a swapped failure is selected from its returned subtree, including the root itself', async () => {
	for (const scenario of [
		'conflict',
		'unrelated',
		'self-warning',
		'unknown',
	]) {
		const state = fixture();
		const form = state.form;
		const target = state.error;
		const notices = [];
		const message = 'Settings changed. Review the current settings.';
		const prior = {
			textContent: 'Older unrelated error.',
			hidden: false,
			focus() {
				assert.fail('The older failure is outside the swap.');
			},
		};
		const attrs = new Map();
		let focused = 0;
		let updates = 0;
		const candidate = {
			hidden: false,
			textContent:
				scenario === 'self-warning'
					? 'Branch could not be checked.'
					: message,
			closest: (selector) =>
				selector === '.ran-booster-admin' && scenario === 'conflict'
					? {}
					: null,
			getAttribute: (name) => attrs.get(name) ?? null,
			hasAttribute: (name) => attrs.has(name),
			setAttribute: (name, value) => attrs.set(name, String(value)),
			matches: (selector) =>
				selector.includes('repository-branch-check')
					? scenario === 'self-warning'
					: scenario === 'conflict',
			querySelector: (selector) =>
				selector === 'p'
					? { textContent: candidate.textContent }
					: null,
			querySelectorAll: () => [],
			focus() {
				focused++;
			},
			dispatchEvent() {
				updates++;
			},
		};
		let swap = {
			matches: () => false,
			querySelectorAll: (selector) =>
				selector.includes('repository-branch-check') ? [] : notices,
		};
		form.hasAttribute = (name) =>
			name === 'data-ran-booster-relocate-rendered-error';
		form.dataset.ranBoosterErrorTarget =
			'#ran-booster-repository-branch-check-error';
		target.textContent = '';
		state.document.getElementById = () =>
			scenario === 'self-warning' ? candidate : target;
		state.document.querySelectorAll = (selector) =>
			selector.includes('.notice-error') ? [prior] : [];
		state.window.CustomEvent = class {
			constructor(type) {
				this.type = type;
			}
		};
		if (scenario === 'conflict') {
			notices.push(candidate);
		}
		if (scenario === 'self-warning') {
			attrs.set('data-ran-booster-repository-branch-check', '');
			attrs.set('data-ran-booster-ui-notice', '');
			attrs.set('role', 'alert');
			candidate.closest = () => candidate;
			swap = candidate;
		}
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		const detail = {
			elt: swap,
			requestConfig: { elt: form },
			xhr: {
				status: ['conflict', 'unrelated'].includes(scenario)
					? 409
					: 200,
			},
		};
		state.listeners.get('htmx:beforeRequest')({ detail: { elt: form } });
		state.listeners.get('htmx:afterSwap')({ detail });
		await Promise.resolve();
		await Promise.resolve();
		if (scenario === 'conflict') {
			assert.equal(target.hidden, true);
			assert.equal(candidate.textContent, message);
			assert.equal(focused, 1);
		} else if (scenario === 'unrelated') {
			assert.match(target.textContent, /could not complete/);
		} else if (scenario === 'self-warning') {
			assert.equal(focused, 1);
			// A rendered warning keeps its intent: nothing to redraw.
			assert.equal(updates, 0);
			assert.deepEqual(state.noticeIntents, []);
			assert.deepEqual(state.announcements, []);
		} else {
			assert.equal(target.hidden, true);
			assert.equal(focused, 0);
		}
	}
});

test('an empty authoritative Notice primary receives the failure, not retained detail text', async () => {
	const state = fixture();
	const primary = { textContent: '' };
	const detail = {
		hidden: false,
		textContent: 'Old diagnostic and action.',
		setAttribute() {},
	};
	const fields = [{ textContent: 'Previous validation field' }];
	let updates = 0;
	state.form.dataset.ranBoosterErrorTarget =
		'#ran-booster-repository-branch-check-error';
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-relocate-rendered-error';
	state.error.attributes.set('data-ran-booster-ui-notice', '');
	state.error.attributes.set('role', 'alert');
	state.error.closest = () => state.error;
	state.error.classList = classList();
	Object.defineProperty(state.error, 'textContent', {
		get: () =>
			`${primary.textContent} ${detail.hidden ? '' : detail.textContent}`,
	});
	state.error.querySelector = (selector) =>
		selector === '[data-ran-booster-ui-notice-primary]' ? primary : null;
	state.error.querySelectorAll = (selector) =>
		selector === '[data-ran-booster-ui-notice-detail]' ? [detail] : fields;
	state.error.dispatchEvent = () => {
		updates++;
	};
	state.window.CustomEvent = class {
		constructor(type) {
			this.type = type;
		}
	};
	state.document.getElementById = () => state.error;
	const unrelated = {
		textContent: 'Static unrelated failure',
		hasAttribute: () => true,
	};
	state.document.querySelectorAll = () => [unrelated];
	const swapped = { matches: () => false, querySelectorAll: () => [] };
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: swapped,
			requestConfig: { elt: state.form },
			xhr: { status: 422 },
		},
	});
	await Promise.resolve();
	await Promise.resolve();
	assert.match(primary.textContent, /could not complete/);
	assert.equal(detail.hidden, true);
	assert.equal(fields[0].textContent, '');
	// The static Notice redraws through the helper; nothing remounts it.
	assert.equal(updates, 0);
	assert.deepEqual(state.noticeIntents, [
		{ host: state.error, intent: 'error' },
	]);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, []);
	assert.equal(unrelated.textContent, 'Static unrelated failure');
});

test('relocated recovery nodes retain identity and are hidden on a later transport failure', async () => {
	const state = fixture();
	const rich = [
		{ id: 'first-details', isConnected: true },
		{ id: 'action', isConnected: true },
		{ id: 'second-details', isConnected: true },
	];
	const inline = { id: 'inline-link', isConnected: true };
	const oldAction = { id: 'old-action', isConnected: true };
	let oldActionCleaned = false;
	const text = { textContent: 'Branch failed. ' };
	const primary = {
		textContent: '',
		childNodes: [oldAction],
		children: [oldAction],
		replaceChildren(...nodes) {
			assert.equal(oldActionCleaned, true);
			this.children = [inline];
			this.childNodes = nodes;
			this.textContent = nodes
				.map((node) => node.textContent || '')
				.join('');
		},
	};
	const details = [];
	const content = {
		childNodes: [],
		append(node) {
			details.push(node);
		},
		querySelectorAll: () => details,
	};
	const sourcePrimary = {
		textContent: 'Branch failed.',
		childNodes: [text, inline],
		remove() {
			sourceContent.childNodes.shift();
		},
	};
	const sourceContent = {
		childNodes: [sourcePrimary, ...rich],
		querySelector: (selector) => (selector === 'p' ? sourcePrimary : null),
	};
	const attrs = new Map([
		['data-ran-booster-repository-branch-check', ''],
		['data-ran-booster-ui-notice-intent', 'warning'],
	]);
	let removed = false;
	let processed = false;
	const disabledProcessed = [];
	const notice = {
		hidden: false,
		isConnected: true,
		textContent: 'Branch failed. Recovery actions.',
		hasAttribute: (name) => attrs.has(name),
		getAttribute: (name) => attrs.get(name) ?? null,
		matches: (selector) => selector.includes('repository-branch-check'),
		querySelector(selector) {
			if (selector === '[data-ran-booster-ui-notice-content]') {
				return sourceContent;
			}
			return selector === 'p' ? sourcePrimary : null;
		},
		querySelectorAll: () => [],
		contains: () => false,
	};
	const swapped = {
		matches: () => false,
		querySelectorAll: (selector) =>
			selector.includes('repository-branch-check') ? [notice] : [],
	};
	state.form.dataset.ranBoosterErrorTarget =
		'#ran-booster-repository-branch-check-error';
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-relocate-rendered-error';
	state.error.attributes.set('data-ran-booster-ui-notice', '');
	state.error.attributes.set('role', 'alert');
	state.error.closest = () => state.error;
	state.error.classList = classList();
	Object.defineProperty(state.error, 'textContent', {
		get: () => primary.textContent,
	});
	state.error.querySelector = (selector) => {
		if (selector === '[data-ran-booster-ui-notice-primary]') {
			return primary;
		}
		if (selector === '[data-ran-booster-ui-notice-content]') {
			return content;
		}
		return null;
	};
	state.error.querySelectorAll = (selector) =>
		selector === '[data-ran-booster-ui-notice-detail]' ? details : [];
	state.error.dispatchEvent = () => {};
	state.window.CustomEvent = class {
		constructor(type) {
			this.type = type;
		}
	};
	state.window.htmx = {
		swap(node, value) {
			assert.equal(value, '');
			if (node === notice) {
				assert.equal(node.isConnected, true);
				assert.deepEqual(sourceContent.childNodes, [
					sourcePrimary,
					...rich,
				]);
				removed = true;
				node.isConnected = false;
			} else if (node === oldAction) {
				assert.equal(node.isConnected, true);
				oldActionCleaned = true;
				node.isConnected = false;
			}
		},
		process(node) {
			if (node === content) {
				processed = true;
				return;
			}
			assert.equal(node.hidden, true);
			assert.equal(node.attributes.get('hx-disable'), '');
			disabledProcessed.push(node);
			assert.ok(
				node.children.every((child) => child.isConnected !== false)
			);
		},
	};
	state.document.getElementById = () => state.error;
	state.document.createElement = () => ({
		hidden: false,
		children: [],
		attributes: new Map(),
		setAttribute(name, value) {
			this.attributes.set(name, value);
		},
		append(...nodes) {
			this.children.push(...nodes);
		},
	});
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:beforeRequest')({ detail: { elt: state.form } });
	state.listeners.get('htmx:afterSwap')({
		detail: {
			elt: swapped,
			requestConfig: { elt: state.form },
			xhr: { status: 200 },
		},
	});
	await Promise.resolve();
	await Promise.resolve();
	assert.equal(primary.textContent, 'Branch failed. ');
	assert.equal(primary.childNodes[1], inline);
	assert.equal(removed, true);
	assert.equal(processed, true);
	assert.deepEqual(details[0].children, rich);
	assert.equal(details[0].hidden, false);
	primary.childElementCount = 1;
	primary.parentElement = content;
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: {} },
	});
	await Promise.resolve();
	await Promise.resolve();
	assert.match(primary.textContent, /could not complete/);
	assert.equal(details[0].hidden, true);
	assert.deepEqual(details[0].children, rich);
	assert.equal(details[1].hidden, true);
	assert.equal(details[1].children[1], inline);
	assert.equal(inline.isConnected, true);
	assert.deepEqual(disabledProcessed, details);
});

test('a failure is spoken once through the shared helper without a live role', () => {
	const state = fixture();
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: {} },
	});
	assert.equal(state.error.hidden, false);
	assert.equal(state.errorAttributes.has('role'), false);
	assert.equal(state.errorAttributes.has('aria-live'), false);
	assert.equal(state.errorAttributes.get('tabindex'), '-1');
	assert.match(state.error.textContent, /could not complete/);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{
			message: 'We could not complete that request. Please try again.',
			type: 'assertive',
		},
	]);
});

test('a rendered alert host announces itself and is not spoken twice', () => {
	const state = fixture();
	state.errorAttributes.set('role', 'alert');
	state.error.closest = () => state.error;
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: {} },
	});
	assert.equal(state.errorAttributes.get('role'), 'alert');
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, []);
});

test('an aria-live off ancestor uses one manual failure announcement', () => {
	const state = fixture();
	state.error.closest = () => ({ getAttribute: () => 'off' });
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: {} },
	});
	assert.equal(state.error.hidden, false);
	assert.equal(state.errorAttributes.has('role'), false);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{
			message: 'We could not complete that request. Please try again.',
			type: 'assertive',
		},
	]);
});

test('a muted target is spoken once and keeps its muted live attribute', () => {
	const state = fixture();
	state.errorAttributes.set('aria-live', 'off');
	state.error.closest = () => state.error;
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: {} },
	});
	assert.equal(state.errorAttributes.has('role'), false);
	assert.equal(state.errorAttributes.get('aria-live'), 'off');
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{
			message: 'We could not complete that request. Please try again.',
			type: 'assertive',
		},
	]);
});

test('a whole-admin failure leaves unrelated WordPress notices and their actions untouched', () => {
	for (const scenario of ['core-wrapper', 'core-marker', 'no-core']) {
		const state = fixture();
		const wrapper = { className: 'ran-booster-admin' };
		const action = {
			href: 'https://example.test/details',
			isConnected: true,
		};
		const globalAttributes = new Map([['class', 'notice notice-error']]);
		let globalFocused = false;
		let globalRemoved = false;
		const globalNotice = {
			hidden: false,
			textContent: 'Third-party service unavailable.',
			children: [action],
			closest: () => null,
			hasAttribute: (name) => globalAttributes.has(name),
			setAttribute: (name, value) => globalAttributes.set(name, value),
			querySelector: () => ({
				textContent: 'Third-party service unavailable.',
			}),
			focus() {
				globalFocused = true;
			},
			remove() {
				globalRemoved = true;
			},
		};
		let coreFocused = false;
		const coreAttributes = new Map();
		if (scenario === 'core-marker') {
			coreAttributes.set('data-ran-booster-admin-mutation-error', '');
		}
		const coreNotice = {
			hidden: false,
			textContent: 'Settings changed. Review the current settings.',
			hasAttribute: (name) => coreAttributes.has(name),
			setAttribute: (name, value) => coreAttributes.set(name, value),
			closest: (selector) =>
				selector === '.ran-booster-admin' && scenario === 'core-wrapper'
					? wrapper
					: null,
			querySelector: () => ({
				textContent: 'Settings changed. Review the current settings.',
			}),
			focus() {
				coreFocused = true;
			},
		};
		const scope = {
			matches: () => false,
			querySelectorAll: (selector) =>
				selector.includes('repository-branch-check')
					? []
					: [
							globalNotice,
							...(scenario === 'no-core' ? [] : [coreNotice]),
						],
		};
		state.form.hasAttribute = (name) =>
			name === 'data-ran-booster-relocate-rendered-error';
		state.error.textContent = '';
		loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		})();
		state.listeners.get('htmx:beforeRequest')({
			detail: { elt: state.form },
		});
		state.listeners.get('htmx:afterSwap')({
			detail: {
				elt: scope,
				requestConfig: { elt: state.form },
				xhr: { status: 409 },
			},
		});
		assert.equal(globalFocused, false, scenario);
		assert.equal(globalRemoved, false, scenario);
		assert.equal(globalNotice.children[0], action, scenario);
		assert.equal(action.isConnected, true, scenario);
		assert.deepEqual(
			[...globalAttributes],
			[['class', 'notice notice-error']],
			scenario
		);
		if (scenario === 'no-core') {
			assert.equal(coreFocused, false);
			assert.match(state.error.textContent, /could not complete/);
			assert.deepEqual(state.error.focusOptions, { preventScroll: true });
		} else {
			assert.equal(coreFocused, true, scenario);
			assert.equal(
				coreNotice.textContent,
				'Settings changed. Review the current settings.',
				scenario
			);
			assert.equal(state.error.hidden, true, scenario);
		}
	}
});

test('credential transport errors update only the child Notice and retain their focus and announcement owner', () => {
	const state = fixture();
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-credential-validation';
	const result = { textContent: 'old success', hidden: false, className: '' };
	const attributes = new Map();
	const classes = new Set(['notice', 'notice-success', 'inline']);
	const updates = [];
	const notice = {
		hidden: true,
		setAttribute: (name, value) => attributes.set(name, value),
		classList: {
			remove: (name) => classes.delete(name),
			add: (name) => classes.add(name),
		},
		dispatchEvent: (event) =>
			updates.push({
				type: event.type,
				bubbles: event.bubbles,
				intent: attributes.get('data-ran-booster-ui-notice-intent'),
				text: result.textContent,
				hidden: notice.hidden,
			}),
	};
	result.closest = (selector) =>
		selector === '[data-ran-booster-ui-notice]' ? notice : null;
	state.window.CustomEvent = class {
		constructor(type, options) {
			this.type = type;
			this.bubbles = options.bubbles;
		}
	};
	state.error.textContent = 'Expiry unknown';
	state.error.querySelector = (selector) =>
		selector === '[data-ran-booster-credential-result]' ? result : null;
	loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	})();
	state.listeners.get('htmx:sendError')({
		detail: { elt: state.form, xhr: { status: 0 } },
	});
	assert.equal(state.error.textContent, 'Expiry unknown');
	assert.equal(classes.has('notice-success'), false);
	assert.equal(classes.has('notice-error'), true);
	assert.equal(attributes.get('data-ran-booster-ui-notice-intent'), 'error');
	assert.deepEqual(state.noticeIntents, [{ host: notice, intent: 'error' }]);
	assert.equal(
		result.textContent,
		'We could not complete that request. Please try again.'
	);
	assert.equal(notice.hidden, false);
	// The static Notice redraws through the helper; nothing remounts it.
	assert.deepEqual(updates, []);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{ message: result.textContent, type: 'assertive' },
	]);
});
