import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-enhanced-mutations.js', import.meta.url),
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
const footerCss = fs.readFileSync(
	new URL('../../assets/ran-booster/30-provider-cards.css', import.meta.url),
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
	const animationFrames = [];
	const replacedUrls = [];
	const scrollCalls = [];
	const timeouts = [];
	let packageDisclosures = [
		{ id: 'ran-booster-advanced-source-settings', open: false },
		{ id: 'ran-booster-package-danger-zone', open: false },
	];
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
	const form = {
		classList: classList(),
		dataset: { ranBoosterErrorTarget: '#ran-booster-local-error' },
		elements: [button, secondary],
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
	const sourceLinkAttributes = new Map();
	const sourceLink = {
		classList: classList(),
		dataset: { ranBoosterErrorTarget: '#ran-booster-local-error' },
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
		closest() {
			return null;
		},
		matches() {
			return false;
		},
	};
	const toast = {
		animations: [],
		classList: classList(),
		dataset: { ranBoosterFeedbackTimeout: '6000' },
		hidden: true,
		style: {
			properties: new Map(),
			setProperty(name, value) {
				this.properties.set(name, value);
			},
		},
		animate(keyframes, options) {
			const animation = {
				cancelled: false,
				currentTime: null,
				paused: false,
				played: false,
				cancel() {
					this.cancelled = true;
				},
				onfinish: null,
				pause() {
					this.paused = true;
				},
				play() {
					this.played = true;
				},
			};
			this.animations.push({
				animation,
				hidden: this.hidden,
				isVisible: this.classList.contains('is-visible'),
				keyframes,
				message: toastMessage.textContent,
				options,
				visibility: this.style.visibility,
				zIndex: this.style.zIndex,
			});
			return animation;
		},
		getBoundingClientRect() {
			return { width: 360 };
		},
		closest(selector) {
			return selector === '.ran-booster-admin' ? admin : null;
		},
		matches(selector) {
			return selector === '[data-ran-booster-feedback-toast]';
		},
		textContent: '',
		querySelector(selector) {
			return selector === '[data-ran-booster-feedback-toast-message]'
				? toastMessage
				: null;
		},
	};
	const admin = {
		getBoundingClientRect() {
			return { left: 160, width: 1100 };
		},
	};
	const toastMessage = { textContent: '' };
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
			if (selector === '[data-ran-booster-feedback-toast]') {
				return toast;
			}

			return null;
		},
		querySelectorAll(selector) {
			if (selector === '[data-ran-booster-package-disclosure]') {
				return packageDisclosures;
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
		setTimeout(callback, timeout) {
			timeouts.push({ callback, timeout });
			return timeouts.length;
		},
		wp: {
			a11y: {
				speak(message, type) {
					announcements.push({ message, type });
				},
			},
		},
	};

	return {
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
		swapTarget,
		sourceLink,
		sourceLinkAttributes,
		timeouts,
		toast,
		toastMessage,
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
		[false, false],
		[true, true],
		[true, false],
		[false, true],
	]) {
		const state = fixture();
		const init = loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
		});
		const ids = [
			'ran-booster-advanced-source-settings',
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

test('enhanced disclosure restoration is keyed by stable IDs and ignores absent replacements', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	const reversedReplacement = [
		{ id: 'ran-booster-package-danger-zone', open: false },
		{ id: 'ran-booster-advanced-source-settings', open: true },
	];

	state.setPackageDisclosures([
		{ id: 'ran-booster-advanced-source-settings', open: false },
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
	assert.equal(state.errorAttributes.get('role'), 'alert');
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{ message: 'The setting could not be saved.', type: 'assertive' },
	]);
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
	assert.equal(renderedNotice.attributes.get('role'), 'alert');
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

test('an opted-in branch check moves its rendered provider failure beside the action', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});
	state.error.textContent = '';
	state.form.hasAttribute = (name) =>
		name === 'data-ran-booster-relocate-rendered-error';
	let removed = false;
	const renderedNotice = {
		hidden: false,
		textContent:
			'The repository provider rate limit has been reached. Try again later.',
		remove() {
			removed = true;
		},
		querySelector(selector) {
			return selector === 'p'
				? {
						textContent:
							'The repository provider rate limit has been reached. Try again later.',
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

	assert.equal(removed, true);
	assert.equal(state.error.hidden, false);
	assert.equal(
		state.error.textContent,
		'The repository provider rate limit has been reached. Try again later.'
	);
	assert.deepEqual(state.error.focusOptions, { preventScroll: true });
	assert.deepEqual(state.announcements, [
		{
			message:
				'The repository provider rate limit has been reached. Try again later.',
			type: 'assertive',
		},
	]);
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

test('enhanced mutation transport failures reset busy state and keep an error local', () => {
	for (const eventName of [
		'htmx:responseError',
		'htmx:sendError',
		'htmx:swapError',
		'htmx:targetError',
		'htmx:timeout',
	]) {
		const state = fixture();
		const init = loadFunction('initEnhancedMutationFeedback', {
			document: state.document,
			window: state.window,
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
		assert.equal(state.button.disabled, false, eventName);
		assert.equal(state.error.hidden, false, eventName);
		assert.equal(
			state.error.textContent,
			'We could not complete that request. Please try again.',
			eventName
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
			eventName
		);
		assert.equal(state.toast.hidden, true, eventName);
	}
});

test('enhanced mutation feedback exposes one transient success toast', () => {
	const state = fixture();
	const init = loadFunction('initEnhancedMutationFeedback', {
		document: state.document,
		window: state.window,
	});

	init();
	state.listeners.get('ran-booster:admin-mutation-success')({
		detail: { message: 'Settings saved.' },
	});

	assert.equal(state.toast.hidden, false);
	assert.equal(state.toast.classList.contains('is-visible'), false);
	assert.equal(state.toastMessage.textContent, 'Settings saved.');
	assert.deepEqual(state.announcements, []);
	assert.equal(state.toast.animations[0].options.duration, 160);
	assert.equal(state.toast.animations[0].hidden, false);
	assert.equal(state.toast.animations[0].isVisible, false);
	assert.equal(state.toast.animations[0].visibility, 'hidden');
	assert.equal(state.toast.animations[0].message, 'Settings saved.');
	assert.equal(state.toast.animations[0].zIndex, '100');
	assert.equal(
		state.toast.animations[0].keyframes[0].transform,
		'translateY(calc(100% + var(--ran-booster-space-20)))'
	);
	assert.equal(
		state.toast.animations[0].keyframes[1].transform,
		'translateY(0)'
	);
	assert.equal(state.toast.animations[0].animation.paused, true);
	assert.equal(state.toast.animations[0].animation.currentTime, 0);
	assert.equal(state.toast.style.visibility, 'hidden');

	state.animationFrames.shift()();

	assert.equal(state.toast.classList.contains('is-visible'), true);
	assert.equal(state.toast.style.visibility, '');
	assert.equal(state.toast.animations[0].animation.played, true);
	assert.deepEqual(state.announcements, [
		{ message: 'Settings saved.', type: 'polite' },
	]);
	assert.equal(state.timeouts[0].timeout, 6000);
	state.timeouts[0].callback();

	assert.equal(
		state.toast.animations[1].keyframes[0].transform,
		'translateY(0)'
	);
	assert.equal(
		state.toast.animations[1].keyframes[1].transform,
		'translateY(calc(100% + var(--ran-booster-space-20)))'
	);
});

test('a package mutation converts its swapped success notice into the shared toast', () => {
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

	assert.equal(removed, true);
	assert.equal(
		state.toastMessage.textContent,
		'Plugin was successfully updated.'
	);
	assert.equal(state.toast.hidden, false);
	assert.equal(state.animationFrames.length, 1);
	assert.deepEqual(state.replacedUrls, [
		{
			state: { htmx: true },
			title: '',
			url: 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php',
		},
	]);
});

test('an enhanced read action converts an explicit success notice into the shared toast', () => {
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

	assert.equal(
		state.toastMessage.textContent,
		'Release eligibility checked; no changes found.'
	);
	assert.equal(state.toast.hidden, false);
});

test('a package mutation consumes only an explicitly marked add-on success notice', () => {
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

	assert.equal(removed, true);
	assert.equal(state.toastMessage.textContent, 'Release metadata refreshed.');
	assert.equal(state.toast.hidden, false);
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
	assert.equal(state.toast.hidden, true);
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

	assert.equal(state.toast.hidden, true);
	assert.equal(state.toastMessage.textContent, '');
});

test('the feedback runtime is vanilla and its motion is optional', () => {
	assert.match(source, /onDomReady\(initEnhancedMutationFeedback\)/);
	assert.doesNotMatch(legacySource, /initEnhancedMutationFeedback/);
	assert.doesNotMatch(source, /\$\(|\$\.|\}\)\(jQuery\)/);
	assert.match(source, /data-ran-booster-enhanced-mutation/);
	assert.match(source, /data-ran-booster-feedback-toast/);
	assert.doesNotMatch(source, /#wpbody-content \.notice\.notice-success/);
	assert.match(
		buttonCss,
		/ran-booster-enhanced-mutation__submitter--busy\.ran-booster-update-is-active::before[\s\S]*repeating-linear-gradient/
	);
	assert.doesNotMatch(css, /repeating-linear-gradient/);
	assert.doesNotMatch(source, /htmx:oobAfterSwap/);
	assert.doesNotMatch(source, /spinner is-active/);
	assert.doesNotMatch(source, /positionToast/);
	assert.doesNotMatch(packageSource, /packageSourceScrollY/);
	assert.match(source, /wp\.a11y\.speak/);
	assert.match(source, /getBoundingClientRect/);
	assert.match(source, /\.animate\?\./);
	assert.match(source, /prefers-reduced-motion: reduce/);
	assert.match(source, /prefersReducedMotion\(\)/);
	assert.match(buttonCss, /prefers-reduced-motion: reduce/);
	assert.match(css, /position: fixed/);
	assert.doesNotMatch(css, /position: absolute/);
	assert.doesNotMatch(css, /feedback-toast-center-x/);
	assert.match(css, /box-sizing: border-box/);
	assert.match(css, /border-inline-start: 0/);
	assert.match(css, /inset-block-end:\s*var\(--ran-booster-space-20\)/);
	assert.match(css, /left: 50%/);
	assert.match(
		footerCss,
		/\.ran-booster-admin \.ran-booster-footer\s*\{[^}]*position: relative/s
	);
	assert.match(css, /z-index: 100/);
	assert.match(css, /translate:\s*-50% 0/);
	assert.match(
		css,
		/translateY\(\s*calc\(100% \+ var\(--ran-booster-space-20\)\)\s*\)/
	);
});
