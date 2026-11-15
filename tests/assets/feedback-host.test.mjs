import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-feedback.js', import.meta.url),
	'utf8'
);
const DURATION = 10000;

function resolve(href) {
	try {
		const url = new URL(href, 'https://example.test/wp-admin/admin.php');
		return { href: url.href, origin: url.origin, protocol: url.protocol };
	} catch {
		return { href: '', origin: 'null', protocol: '' };
	}
}

function element(extra = {}) {
	const listeners = new Map();
	return {
		className: '',
		style: {},
		removed: false,
		isConnected: true,
		attributes: new Map(),
		listeners,
		append() {},
		remove() {
			this.removed = true;
			this.isConnected = false;
		},
		addEventListener(name, callback) {
			listeners.set(name, callback);
		},
		setAttribute(name, value) {
			this.attributes.set(name, value);
		},
		hasAttribute(name) {
			return this.attributes.has(name);
		},
		matches: () => false,
		focus() {
			this.ownerDocument.activeElement = this;
		},
		...extra,
	};
}

/**
 * Run the real host source against a small fake DOM, WordPress runtime and a
 * virtual clock. Nothing here renders markup: the SnackbarList props the host
 * passes are the observable contract.
 *
 * @param {Object} options
 */
function build(options = {}) {
	const {
		components = true,
		cssReady = true,
		renderThrows = false,
		noRender = false,
		readyState = 'complete',
	} = options;
	let clock = 0;
	let nextTimer = 1;
	const timers = new Map();
	const documentListeners = new Map();
	const windowListeners = new Map();
	const roots = [];
	const created = [];
	const targets = new Map();
	const containers = [];
	const document = {
		readyState,
		activeElement: null,
		querySelectorAll(selector) {
			if (selector === '[data-ran-booster-feedback]') {
				return containers.filter((container) => !container.removed);
			}
			return options.candidates?.(selector) ?? [];
		},
		getElementById: (id) => targets.get(id) ?? null,
		querySelector: (selector) => targets.get(selector) ?? null,
		createElement() {
			// Every created node knows which nodes it contains and whether
			// the component rendered into it.
			const node = element({ ownerDocument: document });
			node.inside = new Set();
			node.contains = (candidate) => node.inside.has(candidate);
			node.querySelector = () => (node.rendered ? {} : null);
			created.push(node);
			return node;
		},
		addEventListener(name, callback) {
			documentListeners.set(name, callback);
		},
		body: element(),
	};
	const wpcontent = element();
	targets.set('wpcontent', wpcontent);
	const window = {
		innerWidth: 1000,
		innerHeight: 800,
		location: {
			href: 'https://example.test/wp-admin/admin.php?page=ran-booster',
			origin: 'https://example.test',
		},
		getComputedStyle: () => ({
			paddingLeft: cssReady ? '20px' : '0px',
			backgroundColor: cssReady ? 'rgba(0, 0, 0, 0.85)' : 'transparent',
		}),
		addEventListener(name, callback) {
			windowListeners.set(name, callback);
		},
		setTimeout(callback, delay) {
			const id = nextTimer++;
			timers.set(id, { callback, at: clock + delay });
			return id;
		},
		clearTimeout(id) {
			timers.delete(id);
		},
		wp: {
			i18n: { __: (text) => text },
			element: {
				createElement: (component, props) => ({ component, props }),
				flushSync: (callback) => callback(),
				createRoot() {
					const root = {
						notices: [],
						unmounted: false,
						renders: 0,
						render(tree) {
							if (renderThrows) {
								throw new Error('render');
							}
							root.renders++;
							root.notices = tree.props.notices;
							root.onRemove = tree.props.onRemove;
							const host = created.findLast(
								(node) =>
									node.className ===
									'ran-booster-snackbar-host'
							);
							host.rendered =
								!noRender && root.notices.length > 0;
						},
						unmount() {
							root.unmounted = true;
						},
					};
					roots.push(root);
					return root;
				},
			},
			components: components ? { SnackbarList() {} } : {},
		},
	};
	const env = {
		run: () =>
			Function(
				'window',
				'document',
				'Date',
				'URL',
				source
			)(window, document, { now: () => clock }, URL),
		window,
		document,
		roots,
		created,
		targets,
		documentListeners,
		windowListeners,
		get feedback() {
			return window.ranBoosterFeedback;
		},
		get host() {
			return created.findLast(
				(node) => node.className === 'ran-booster-snackbar-host'
			);
		},
		get root() {
			return roots.at(-1);
		},
		get notices() {
			return this.root && !this.root.unmounted ? this.root.notices : [];
		},
		tick(milliseconds) {
			const end = clock + milliseconds;
			for (;;) {
				const due = [...timers.entries()]
					.filter(([, timer]) => timer.at <= end)
					.sort((a, b) => a[1].at - b[1].at)[0];
				if (!due) {
					break;
				}
				timers.delete(due[0]);
				clock = due[1].at;
				due[1].callback();
			}
			clock = end;
		},
		pendingTimers: () => timers.size,
		target(id = 'inline-failure', extra = {}) {
			const node = element({ ownerDocument: document, ...extra });
			targets.set(id, node);
			return node;
		},
		// A node inside the rendered snackbar with the given uid.
		inside(notice, extra = {}) {
			return {
				...extra,
				closest: (selector) =>
					selector === '.components-snackbar'
						? { className: notice.className }
						: null,
			};
		},
		staticContainer(items) {
			const marks = new Set();
			const container = {
				removed: false,
				setAttribute: (name) => marks.add(name),
				hasAttribute: (name) => marks.has(name),
				items: [],
				querySelectorAll: () => [...container.items],
				querySelector: () => container.items[0] ?? null,
				remove() {
					container.removed = true;
				},
			};
			for (const spec of items) {
				const text = { textContent: spec.text };
				const link = spec.link
					? {
							textContent: spec.link.text,
							// Anchors resolve their own href and origin.
							...resolve(spec.link.href),
						}
					: null;
				const itemMarks = new Set();
				const item = {
					removed: false,
					setAttribute: (name) => itemMarks.add(name),
					hasAttribute: (name) => itemMarks.has(name),
					childNodes: link ? [text, link] : [text],
					getAttribute: (name) =>
						({
							'data-feedback-key': spec.key,
							'data-intent': spec.intent,
							'data-feedback-target': spec.target,
						})[name] ?? null,
					querySelector: (selector) =>
						selector === 'a.ran-booster-feedback__activity'
							? link
							: null,
					remove() {
						item.removed = true;
						container.items = container.items.filter(
							(candidate) => candidate !== item
						);
					},
				};
				container.items.push(item);
			}
			containers.push(container);
			return container;
		},
	};
	return env;
}

function boot(options) {
	const env = build(options);
	env.run();
	return env;
}

test('a success shows once with the success intent, explicit dismiss and a polite announcement', () => {
	const env = boot();
	assert.equal(env.feedback.show('Saved.'), true);
	assert.equal(env.notices.length, 1);
	const [notice] = env.notices;
	assert.equal(notice.content, 'Saved.');
	assert.equal(notice.explicitDismiss, true);
	assert.match(notice.className, /ran-booster-snackbar--success/);
	assert.equal(
		'spokenMessage' in notice,
		false,
		'The component announces success itself, politely.'
	);
	assert.deepEqual(notice.actions, []);
	assert.equal(env.host.className, 'ran-booster-snackbar-host');
});

test('a success dismisses itself after exactly the one universal duration', () => {
	const env = boot();
	env.feedback.show('Saved.');
	env.tick(DURATION - 1);
	assert.equal(env.notices.length, 1);
	env.tick(1);
	assert.equal(env.notices.length, 0);
	assert.equal(env.root.unmounted, true);
	assert.equal(env.host.removed, true);
});

test('the duration is one constant owned by the host, never set per call', () => {
	assert.equal(source.match(/\b10000\b/g).length, 1);
	assert.equal(source.match(/const DURATION = 10000;/g).length, 1);
	assert.doesNotMatch(source, /options\.(duration|timeout)|duration:/);
	assert.doesNotMatch(source, /setTimeout\([^)]*\b\d{3,}\b/);
});

test('hover pauses the timer and resumes with the remaining time', () => {
	const env = boot();
	env.feedback.show('Saved.');
	const [notice] = env.notices;
	env.tick(4000);
	env.host.listeners.get('pointerover')({ target: env.inside(notice) });
	assert.equal(env.pendingTimers(), 0);
	env.tick(60000);
	assert.equal(env.notices.length, 1);
	env.host.listeners.get('pointerout')({
		target: env.inside(notice),
		relatedTarget: null,
	});
	env.tick(5999);
	assert.equal(env.notices.length, 1);
	env.tick(1);
	assert.equal(env.notices.length, 0);
});

test('focus pauses the timer and hover plus focus must both release it', () => {
	const env = boot();
	env.feedback.show('Saved.');
	const [notice] = env.notices;
	const node = env.inside(notice);
	env.tick(2000);
	env.host.listeners.get('focusin')({ target: node });
	env.host.listeners.get('pointerover')({ target: node });
	env.tick(30000);
	env.host.listeners.get('pointerout')({
		target: node,
		relatedTarget: null,
	});
	env.tick(30000);
	assert.equal(env.notices.length, 1, 'Focus still holds the item.');
	env.host.listeners.get('focusout')({
		target: node,
		relatedTarget: null,
	});
	env.tick(7999);
	assert.equal(env.notices.length, 1);
	env.tick(1);
	assert.equal(env.notices.length, 0);
});

test('moving between controls inside one item does not release the pause', () => {
	const env = boot();
	env.feedback.show('Saved.');
	const [notice] = env.notices;
	const first = env.inside(notice);
	const second = env.inside(notice);
	env.host.listeners.get('focusin')({ target: first });
	env.host.listeners.get('focusout')({
		target: first,
		relatedTarget: second,
	});
	env.tick(DURATION * 3);
	assert.equal(env.notices.length, 1);
});

test('pausing one item leaves the others on their own timers', () => {
	const env = boot();
	env.feedback.show('One.');
	env.tick(1000);
	env.feedback.show('Two.');
	const one = env.notices[0];
	env.host.listeners.get('pointerover')({ target: env.inside(one) });
	env.tick(DURATION);
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['One.']
	);
});

test('Escape inside an item dismisses only that item', () => {
	const env = boot();
	env.feedback.show('One.');
	env.feedback.show('Two.');
	const [one] = env.notices;
	const keydown = env.host.listeners.get('keydown');
	keydown({ key: 'Enter', target: env.inside(one) });
	keydown({ key: 'Escape', target: { closest: () => null } });
	assert.equal(env.notices.length, 2);
	keydown({ key: 'Escape', target: env.inside(one) });
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Two.']
	);
});

test('the dismiss control removes one item and a late call for it is ignored', () => {
	const env = boot();
	env.feedback.show('One.');
	env.feedback.show('Two.');
	const [one, two] = env.notices;
	const { onRemove } = env.root;
	onRemove(one.id);
	assert.deepEqual(
		env.notices.map((notice) => notice.id),
		[two.id]
	);
	assert.doesNotThrow(() => onRemove(one.id));
	assert.equal(env.notices.length, 1);
});

test('at most three show and the oldest success is evicted first', () => {
	const env = boot();
	for (const text of ['One.', 'Two.', 'Three.', 'Four.']) {
		env.feedback.show(text);
	}
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Two.', 'Three.', 'Four.']
	);
	env.feedback.show('Five.');
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Three.', 'Four.', 'Five.']
	);
	env.tick(DURATION);
	assert.equal(env.notices.length, 0, 'Evicted items left no stray timer.');
});

test('a pointer to a persistent error is never evicted while a success can be', () => {
	const env = boot();
	const target = env.target();
	env.feedback.show('Release check failed.', {
		intent: 'error',
		id: 'pointer',
		target,
	});
	env.feedback.show('One.');
	env.feedback.show('Two.');
	env.feedback.show('Three.');
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Release check failed.', 'Two.', 'Three.']
	);
	env.feedback.show('Four.');
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Release check failed.', 'Three.', 'Four.']
	);
});

test('three pointers are never evicted: a new success is spoken, not shown, and a new pointer is omitted', () => {
	const env = boot();
	const spoken = [];
	env.window.wp.a11y = {
		speak: (message, level) => spoken.push([message, level]),
	};
	for (const id of ['a', 'b', 'c']) {
		env.feedback.show(`Pointer ${id}.`, {
			intent: id === 'b' ? 'warning' : 'error',
			id,
			target: env.target(`target-${id}`),
		});
	}
	const before = env.notices.map((notice) => notice.content);
	assert.equal(env.feedback.show('Saved.'), false);
	assert.equal(
		env.feedback.show('Pointer d.', {
			intent: 'error',
			id: 'd',
			target: env.target('target-d'),
		}),
		false
	);
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		before
	);
	const container = env.staticContainer([
		{ key: 's', intent: 'success', text: 'Settings saved.' },
	]);
	env.feedback.consume();
	assert.equal(container.items.length, 1, 'The static result stays.');
	assert.deepEqual(spoken, [['Settings saved.', 'polite']]);
});

test('two successes and a pointer: a new success evicts the oldest success', () => {
	const env = boot();
	env.feedback.show('One.');
	env.feedback.show('Failed.', {
		intent: 'error',
		id: 'p',
		target: env.target(),
	});
	env.feedback.show('Two.');
	env.feedback.show('Three.');
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Failed.', 'Two.', 'Three.']
	);
	env.feedback.show('Four.');
	env.feedback.show('Five.');
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Failed.', 'Four.', 'Five.']
	);
});

test('a pointer renders silent, untimed and dismissible, and only with a connected inline target', () => {
	const env = boot();
	assert.equal(
		env.feedback.show('Failed.', { intent: 'error', id: 'x' }),
		false,
		'An error never shows without the inline element it points to.'
	);
	const gone = env.target('gone');
	gone.isConnected = false;
	assert.equal(
		env.feedback.show('Failed.', {
			intent: 'error',
			id: 'x',
			target: gone,
		}),
		false
	);
	assert.equal(env.notices.length, 0);
	assert.equal(env.host, undefined, 'No host is mounted for a refusal.');

	const target = env.target();
	assert.equal(
		env.feedback.show('Failed.', { intent: 'error', id: 'x', target }),
		true
	);
	const [notice] = env.notices;
	assert.match(notice.className, /ran-booster-snackbar--error/);
	assert.equal(notice.explicitDismiss, true);
	assert.equal(notice.spokenMessage, '');
	assert.equal(notice.actions[0].label, 'Show details');
	assert.equal(env.pendingTimers(), 0);
	env.tick(DURATION * 100);
	assert.equal(env.notices.length, 1, 'A pointer never auto-dismisses.');
	env.root.onRemove(notice.id);
	assert.equal(env.notices.length, 0);
});

test('a warning is a pointer too, with its own intent class and no timer', () => {
	const env = boot();
	env.feedback.show('Saved, with a warning.', {
		intent: 'warning',
		id: 'w',
		target: env.target(),
	});
	assert.match(env.notices[0].className, /ran-booster-snackbar--warning/);
	assert.equal(env.notices[0].spokenMessage, '');
	assert.equal(env.pendingTimers(), 0);
});

test('the pointer action focuses the inline failure, making a plain element focusable', () => {
	const env = boot();
	const plain = env.target('plain');
	env.feedback.show('Failed.', { intent: 'error', id: 'p', target: plain });
	env.notices[0].actions[0].onClick();
	assert.equal(plain.attributes.get('tabindex'), '-1');
	assert.equal(env.document.activeElement, plain);

	const field = env.target('field', { matches: (s) => s.includes('input') });
	env.feedback.show('Failed too.', {
		intent: 'error',
		id: 'f',
		target: field,
	});
	env.notices[1].actions[0].onClick();
	assert.equal(field.attributes.has('tabindex'), false);
	assert.equal(env.document.activeElement, field);
});

test('an info stands alone: polite, timed like a success, with no pointer and no target needed', () => {
	const env = boot();
	assert.equal(
		env.feedback.show('Not run.', { intent: 'info', id: 'i' }),
		true
	);
	const [notice] = env.notices;
	assert.match(notice.className, /ran-booster-snackbar--info/);
	assert.equal('spokenMessage' in notice, false);
	assert.deepEqual(notice.actions, []);
	env.tick(DURATION - 1);
	assert.equal(env.notices.length, 1);
	env.tick(1);
	assert.equal(env.notices.length, 0);
});

test('a static info is converted without a target and does not qualify a success', () => {
	const env = boot();
	const container = env.staticContainer([
		{ key: 'i', intent: 'info', text: 'Check not run.' },
		{ key: 's', intent: 'success', text: 'Saved.' },
	]);
	env.feedback.consume();
	assert.equal(container.items.length, 0);
	assert.deepEqual(
		env.notices.map((notice) => notice.className.match(/--(\w+)/)[1]),
		['info', 'success']
	);
});

test('an unknown intent or empty or non-string message shows nothing', () => {
	const env = boot();
	const target = env.target();
	assert.equal(env.feedback.show('Hi.', { intent: 'notice', target }), false);
	assert.equal(env.feedback.show('   '), false);
	assert.equal(env.feedback.show(undefined), false);
	assert.equal(env.feedback.show({ toString: () => 'Object text' }), false);
	assert.equal(env.notices.length, 0);
	env.feedback.show('  Trimmed.  ');
	assert.equal(env.notices[0].content, 'Trimmed.');
});

test('the same id shows once and restarts the timer instead of stacking', () => {
	const env = boot();
	env.feedback.show('Saved.');
	env.tick(6000);
	assert.equal(env.feedback.show('Saved.'), true);
	assert.equal(env.notices.length, 1);
	assert.equal(env.root.renders, 1, 'No second snackbar was rendered.');
	env.tick(9999);
	assert.equal(env.notices.length, 1);
	env.tick(1);
	assert.equal(env.notices.length, 0);
	env.feedback.show('Other copy.', { id: 'same' });
	env.feedback.show('Different copy.', { id: 'same' });
	assert.equal(env.notices.length, 1);
	// One item per key; a later result with different fixed copy replaces the text.
	assert.equal(env.notices[0].content, 'Different copy.');
});

test('a success may carry one link action built by the caller', () => {
	const env = boot();
	env.feedback.show('Queued 3 updates.', {
		action: { label: 'View activity', url: '/activity' },
	});
	assert.deepEqual(env.notices[0].actions, [
		{ label: 'View activity', url: '/activity' },
	]);
});

test('an unavailable host shows nothing so the caller keeps its own result', () => {
	assert.equal(
		boot({ components: false }).feedback.show('Saved.'),
		false,
		'SnackbarList missing'
	);
	const noStyle = boot({ cssReady: false });
	assert.equal(noStyle.feedback.show('Saved.'), false, 'styles missing');
	assert.equal(noStyle.host, undefined);
	const noRoot = boot();
	noRoot.window.wp.element.createRoot = undefined;
	assert.equal(noRoot.feedback.show('Saved.'), false, 'createRoot missing');
	const broken = boot({ renderThrows: true });
	assert.equal(broken.feedback.show('Saved.'), false, 'render failure');
	assert.equal(broken.host.removed, true, 'A failed host is removed.');
	assert.equal(broken.root.unmounted, true);
});

test('the host mounts once inside the Booster frame, not the content column', () => {
	const env = boot();
	const mounted = [];
	const frame = element();
	frame.append = (child) => {
		child.parentNode = frame;
		mounted.push(['frame', child]);
	};
	env.targets.set('.wrap.ran-booster-admin', frame);
	env.targets.get('wpcontent').append = (child) =>
		mounted.push(['wpcontent', child]);
	env.feedback.show('One.');
	env.feedback.show('Two.');
	assert.deepEqual(mounted, [['frame', env.host]]);
	assert.equal(env.roots.length, 1);
});

test('a swap that replaces the frame re-parents the host into the new frame', () => {
	const env = boot();
	const first = element();
	const second = element();
	first.append = (child) => (child.parentNode = first);
	second.append = (child) => (child.parentNode = second);
	env.targets.set('.wrap.ran-booster-admin', first);
	env.feedback.show('One.');
	assert.equal(env.host.parentNode, first);
	env.targets.set('.wrap.ran-booster-admin', second);
	env.documentListeners.get('htmx:afterSwap')?.();
	assert.equal(env.host.parentNode, second);
	assert.equal(env.roots.length, 1);
});

test('without a Booster frame the host falls back to the content column', () => {
	const env = boot();
	let appendedTo = null;
	env.targets.get('wpcontent').append = (child) => {
		appendedTo = child;
	};
	env.feedback.show('One.');
	assert.equal(appendedTo, env.host);
});

test('a server-rendered success becomes a snackbar and leaves no duplicate', () => {
	const env = boot();
	const container = env.staticContainer([
		{
			key: 'settings_saved',
			intent: 'success',
			text: 'Settings saved. ',
			link: {
				text: 'View activity',
				href: '/wp-admin/admin.php?page=activity',
			},
		},
	]);
	env.feedback.consume();
	assert.equal(env.notices.length, 1);
	assert.equal(
		env.notices[0].content,
		'Settings saved.',
		'The link text is not part of the message.'
	);
	assert.deepEqual(env.notices[0].actions, [
		{
			label: 'View activity',
			url: 'https://example.test/wp-admin/admin.php?page=activity',
		},
	]);
	assert.equal(container.items.length, 0);
	assert.equal(container.removed, true);
	env.feedback.consume();
	assert.equal(env.notices.length, 1, 'A second pass adds nothing.');
	assert.equal(env.root.renders, 1);
});

test('a link to another origin or a script URL is dropped, never made an action', () => {
	for (const href of [
		'https://evil.test/x',
		'javascript:alert(1)',
		'http://[bad',
	]) {
		const env = boot();
		env.staticContainer([
			{
				key: 'k',
				intent: 'success',
				text: 'Done.',
				link: { text: 'Open', href },
			},
		]);
		env.feedback.consume();
		assert.deepEqual(env.notices[0].actions, [], href);
	}
});

test('the message is only the fixed item text, not the URL, storage or any other source', () => {
	assert.doesNotMatch(
		source,
		/location\.(search|hash)|URLSearchParams|document\.cookie|localStorage|sessionStorage|innerHTML|insertAdjacentHTML|fetch\(|XMLHttpRequest/
	);
	const env = boot();
	env.window.location.search = '?message=Injected';
	env.staticContainer([{ key: 'k', intent: 'success', text: 'Fixed copy.' }]);
	env.feedback.consume();
	assert.equal(env.notices[0].content, 'Fixed copy.');
});

test('a server-rendered failure becomes only a pointer to its inline element', () => {
	const env = boot();
	env.target('inline-failure');
	const container = env.staticContainer([
		{
			key: 'check_failed',
			intent: 'error',
			text: 'The release check failed.',
			target: 'inline-failure',
		},
	]);
	env.feedback.consume();
	assert.equal(env.notices.length, 1);
	assert.match(env.notices[0].className, /--error/);
	assert.equal(env.notices[0].actions[0].label, 'Show details');
	assert.equal(container.removed, true);
});

test('a failure without a present inline target stays static and is never snackbar-only', () => {
	const env = boot();
	env.target('elsewhere');
	const container = env.staticContainer([
		{ key: 'a', intent: 'error', text: 'No target.' },
		{
			key: 'b',
			intent: 'warning',
			text: 'Missing target.',
			target: 'not-on-the-page',
		},
	]);
	env.feedback.consume();
	assert.equal(env.notices.length, 0);
	assert.equal(container.items.length, 2);
	assert.equal(container.removed, false);
});

test('a warning in the result keeps the success static and durable', () => {
	const env = boot();
	env.target('inline-failure');
	const container = env.staticContainer([
		{ key: 's', intent: 'success', text: 'Saved.' },
		{
			key: 'w',
			intent: 'warning',
			text: 'Saved, with a warning.',
			target: 'inline-failure',
		},
	]);
	env.feedback.consume();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Saved, with a warning.']
	);
	assert.deepEqual(
		container.items.map((item) => item.getAttribute('data-feedback-key')),
		['s']
	);
	assert.equal(container.removed, false);
});

test('when the host cannot render, the confirmed static result stays visible', () => {
	for (const options of [
		{ components: false },
		{ cssReady: false },
		{ renderThrows: true },
	]) {
		const env = boot(options);
		env.target('inline-failure');
		const container = env.staticContainer([
			{ key: 's', intent: 'success', text: 'Saved.' },
		]);
		env.feedback.consume();
		assert.equal(container.items.length, 1);
		assert.equal(container.removed, false);
	}
});

test('unknown static intents are left alone', () => {
	const env = boot();
	const container = env.staticContainer([
		{ key: 'x', intent: 'notice', text: 'Something.' },
	]);
	env.feedback.consume();
	assert.equal(container.items.length, 1);
	assert.equal(env.notices.length, 0);
});

test('static items convert on load and after each HTMX swap', () => {
	const loading = boot({ readyState: 'loading' });
	loading.staticContainer([{ key: 'a', intent: 'success', text: 'First.' }]);
	assert.equal(loading.notices.length, 0, 'Waits for the document.');
	loading.documentListeners.get('DOMContentLoaded')();
	assert.equal(loading.notices.length, 1);

	const ready = boot();
	ready.staticContainer([{ key: 'b', intent: 'success', text: 'Second.' }]);
	ready.documentListeners.get('htmx:afterSwap')();
	assert.equal(ready.notices.length, 1);
	assert.equal(ready.notices[0].content, 'Second.');
});

test('pagehide and htmx history save tear the host down so a restored page never replays', () => {
	for (const event of ['pagehide', 'htmx:beforeHistorySave']) {
		const env = boot();
		env.feedback.show('Saved.');
		env.feedback.show('Failed.', {
			intent: 'error',
			id: 'e',
			target: env.target(),
		});
		const listeners =
			event === 'pagehide' ? env.windowListeners : env.documentListeners;
		listeners.get(event)();
		assert.equal(env.root.unmounted, true, event);
		assert.equal(env.host.removed, true, event);
		assert.equal(env.pendingTimers(), 0, event);
		assert.doesNotThrow(() => env.tick(DURATION * 2));
		listeners.get(event)();
		env.feedback.show('After.');
		assert.equal(
			env.roots.length,
			2,
			'A later result mounts a fresh host.'
		);
		assert.equal(env.notices[0].content, 'After.');
	}
});

function fakeControl(options = {}) {
	return {
		isConnected: options.connected !== false,
		disabled: Boolean(options.disabled),
		getClientRects: () => (options.hidden ? [] : [{}]),
		getBoundingClientRect: () =>
			options.offscreen
				? { left: 0, top: -50, right: 50, bottom: -10 }
				: { left: 10, top: 10, right: 50, bottom: 50 },
		focus(settings) {
			assert.deepEqual(settings, { preventScroll: true });
			options.attempts?.push(options.name);
			if (!options.fail) {
				this.ownerDocument.activeElement = this;
			}
		},
	};
}

test('dismissing the item that holds focus returns focus to the first eligible control in the viewport', () => {
	const attempts = [];
	const make = (name, extra) =>
		Object.assign(fakeControl({ name, attempts, ...extra }), {
			ownerDocument: null,
		});
	const hidden = make('hidden', { hidden: true });
	const disabled = make('disabled', { disabled: true });
	const disconnected = make('disconnected', { connected: false });
	const failed = make('failed', { fail: true });
	const visible = make('visible');
	const offscreen = make('offscreen', { offscreen: true });
	const returnControl = make('return-control');
	const env = boot({
		candidates: (selector) =>
			selector.includes('save-numeric-bottom')
				? [offscreen]
				: [hidden, disabled, disconnected, failed, visible],
	});
	for (const node of [
		hidden,
		disabled,
		disconnected,
		failed,
		visible,
		offscreen,
		returnControl,
	]) {
		node.ownerDocument = env.document;
	}
	env.feedback.show('Saved.', { returnControl });
	const [notice] = env.notices;
	const inner = env.inside(notice);
	env.host.inside.add(inner);
	env.document.activeElement = inner;
	env.host.listeners.get('keydown')({ key: 'Escape', target: inner });
	assert.deepEqual(attempts, ['return-control']);
	assert.equal(env.document.activeElement, returnControl);
});

test('without a return control the next eligible candidate takes focus', () => {
	const attempts = [];
	const failed = fakeControl({ name: 'failed', fail: true, attempts });
	const visible = fakeControl({ name: 'visible', attempts });
	const offscreen = fakeControl({
		name: 'offscreen',
		offscreen: true,
		attempts,
	});
	const env = boot({
		candidates: (selector) =>
			selector.includes('save-numeric-bottom')
				? [offscreen]
				: [failed, visible],
	});
	for (const node of [failed, visible, offscreen]) {
		node.ownerDocument = env.document;
	}
	env.feedback.show('Saved.');
	const inner = env.inside(env.notices[0]);
	env.host.inside.add(inner);
	env.document.activeElement = inner;
	env.root.onRemove(env.notices[0].id);
	assert.deepEqual(attempts, ['failed', 'visible']);
	assert.equal(env.document.activeElement, visible);
});

test('focus is left alone when no candidate is visible or focus is elsewhere', () => {
	const env = boot({
		candidates: () => [
			Object.assign(fakeControl({ offscreen: true }), {
				ownerDocument: null,
				focus: () => assert.fail('Off-screen control was focused.'),
			}),
		],
	});
	env.feedback.show('One.');
	env.feedback.show('Two.');
	const [one, two] = env.notices;
	const inner = env.inside(one);
	env.host.inside.add(inner);
	env.document.activeElement = inner;
	env.root.onRemove(one.id);
	assert.equal(env.document.activeElement, inner);

	const outside = {};
	env.document.activeElement = outside;
	env.root.onRemove(two.id);
	assert.equal(env.document.activeElement, outside);
});

test('an item expiring or evicted never takes focus from a different item', () => {
	const env = boot({
		candidates: () => [
			Object.assign(fakeControl({}), {
				ownerDocument: null,
				focus: () => assert.fail('Focus was stolen.'),
			}),
		],
	});
	env.feedback.show('One.');
	env.feedback.show('Two.');
	const two = env.notices[1];
	const inner = env.inside(two);
	env.host.inside.add(inner);
	env.document.activeElement = inner;
	env.host.listeners.get('focusin')({ target: inner });
	env.tick(DURATION);
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Two.']
	);
	env.feedback.show('Three.');
	env.feedback.show('Four.');
	assert.equal(env.notices.length, 3);
	assert.equal(env.document.activeElement, inner);
});

test('a qualified result keeps its success static on every later pass', () => {
	const env = boot();
	env.target('inline-failure');
	const container = env.staticContainer([
		{ key: 's', intent: 'success', text: 'Saved.' },
		{
			key: 'w',
			intent: 'warning',
			text: 'Saved, with a warning.',
			target: 'inline-failure',
		},
	]);
	env.feedback.consume();
	env.feedback.consume();
	env.documentListeners.get('htmx:afterSwap')();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Saved, with a warning.']
	);
	assert.equal(container.items.length, 1);
});

test('a repeated result refreshes the control focus returns to', () => {
	const env = boot({
		candidates: () => [],
	});
	const first = { ...env.target('first'), isConnected: false };
	const second = env.target('second');
	env.feedback.show('Saved.', { returnControl: first });
	env.feedback.show('Saved.', { returnControl: second });
	const inner = env.inside(env.notices[0]);
	env.host.inside.add(inner);
	env.document.activeElement = inner;
	second.getClientRects = () => [{}];
	second.getBoundingClientRect = () => ({
		left: 1,
		top: 1,
		right: 2,
		bottom: 2,
	});
	second.disabled = false;
	second.focus = () => {
		env.document.activeElement = second;
	};
	env.root.onRemove(env.notices[0].id);
	assert.equal(env.document.activeElement, second);
});

function pointers(env, ids = ['a', 'b', 'c']) {
	const targets = {};
	for (const id of ids) {
		targets[id] = env.target(`target-${id}`);
		env.feedback.show(`Pointer ${id}.`, {
			intent: 'error',
			id,
			target: targets[id],
		});
	}
	return targets;
}

test('a pointer whose inline target was swapped away is dropped and frees its slot', () => {
	const env = boot();
	const targets = pointers(env);
	targets.a.isConnected = false;
	assert.equal(env.feedback.show('Saved.'), true);
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Pointer b.', 'Pointer c.', 'Saved.']
	);
});

test('consume drops stale pointers and unmounts when none is left', () => {
	const env = boot();
	const targets = pointers(env, ['a', 'b']);
	targets.a.isConnected = false;
	env.feedback.consume();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Pointer b.']
	);
	targets.b.isConnected = false;
	env.documentListeners.get('htmx:afterSwap')();
	assert.equal(env.root.unmounted, true);
	assert.equal(env.host.removed, true);
});

test('a repeated pointer refreshes its target and Show details follows it', () => {
	const env = boot();
	const first = env.target('first');
	const second = env.target('second');
	env.feedback.show('Failed.', { intent: 'error', id: 'p', target: first });
	const [{ actions }] = env.notices;
	env.feedback.show('Failed.', { intent: 'error', id: 'p', target: second });
	assert.equal(env.notices.length, 1);
	actions[0].onClick();
	assert.equal(env.document.activeElement, second);
	assert.equal(first.attributes.has('tabindex'), false);
});

test('a repeated success is spoken again, a first one is announced by the component', () => {
	const env = boot();
	const spoken = [];
	env.window.wp.a11y = { speak: (...args) => spoken.push(args) };
	env.feedback.show('Saved.');
	assert.deepEqual(spoken, []);
	env.feedback.show('Saved.');
	env.feedback.show('Saved.');
	assert.deepEqual(spoken, [
		['Saved.', 'polite'],
		['Saved.', 'polite'],
	]);
});

test('an unshown static success is spoken once however many passes follow', () => {
	for (const options of [{}, { components: false }]) {
		const env = boot(options);
		const spoken = [];
		env.window.wp.a11y = { speak: (...args) => spoken.push(args) };
		pointers(env);
		env.staticContainer([
			{ key: 's', intent: 'success', text: 'Settings saved.' },
		]);
		env.feedback.consume();
		env.feedback.consume();
		env.documentListeners.get('htmx:afterSwap')();
		env.documentListeners.get('htmx:afterSwap')();
		assert.deepEqual(spoken, [['Settings saved.', 'polite']]);
	}
});

test('the Activity action needs an http or https link on this origin', () => {
	for (const href of [
		'blob:https://example.test/abc',
		'ftp://example.test/x',
	]) {
		const env = boot();
		env.staticContainer([
			{
				key: 'k',
				intent: 'success',
				text: 'Done.',
				link: { text: 'Open', href },
			},
		]);
		env.feedback.consume();
		assert.deepEqual(env.notices[0].actions, [], href);
	}
});

test('the public entry cannot be replaced or changed', () => {
	const env = boot();
	const descriptor = Object.getOwnPropertyDescriptor(
		env.window,
		'ranBoosterFeedback'
	);
	assert.equal(descriptor.writable, false);
	assert.equal(descriptor.configurable, false);
	assert.equal(Object.isFrozen(descriptor.value), true);
	assert.throws(() => {
		'use strict';
		env.window.ranBoosterFeedback = {};
	});
});

test('evicting the item that holds focus returns focus to a control', () => {
	const returnControl = Object.assign(fakeControl({}), {
		ownerDocument: null,
	});
	const env = boot();
	returnControl.ownerDocument = env.document;
	env.feedback.show('One.', { returnControl });
	env.feedback.show('Two.');
	env.feedback.show('Three.');
	const inner = env.inside(env.notices[0]);
	env.host.inside.add(inner);
	env.document.activeElement = inner;
	env.feedback.show('Four.');
	assert.equal(env.document.activeElement, returnControl);
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Two.', 'Three.', 'Four.']
	);
});

test('a snackbar that did not render is refused and torn down', () => {
	const env = boot({ noRender: true });
	assert.equal(env.feedback.show('Saved.'), false);
	assert.equal(env.host.removed, true);
	assert.equal(env.root.unmounted, true);
});

test('a pointer to an inline notice is hidden until the host takes it', () => {
	const css = fs.readFileSync(
		new URL(
			'../../assets/ran-booster/25-admin-primitives.css',
			import.meta.url
		),
		'utf8'
	);
	assert.match(
		css,
		/\.ran-booster-feedback__item\[data-feedback-target\]\[data-intent="error"\],\s*\.ran-booster-feedback__item\[data-feedback-target\]\[data-intent="warning"\]\s*\{\s*display: none;/
	);
});

test('a full page load strips the result arguments once the host has taken an item', () => {
	const env = build();
	const replaced = [];
	env.window.history = {
		state: null,
		replaceState: (state, title, url) => replaced.push(String(url)),
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=a&ran_booster_result=install&ran_booster_package=a&_ran_booster_notice_nonce=n&ran_booster_bulk_changed=2&ran_booster_extension_result=installed';
	env.staticContainer([
		{ key: 'package_install', intent: 'success', text: 'Installed.' },
	]);
	env.run();
	assert.equal(env.notices.length, 1);
	assert.deepEqual(replaced, [
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=a',
	]);
});

test('the address is kept when the host takes nothing or on a later swap', () => {
	const env = build({ components: false });
	const replaced = [];
	env.window.history = {
		state: null,
		replaceState: (state, title, url) => replaced.push(String(url)),
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?ran_booster_result=install&_ran_booster_notice_nonce=n';
	env.staticContainer([
		{ key: 'package_install', intent: 'success', text: 'Installed.' },
	]);
	env.run();
	assert.deepEqual(replaced, [], 'host unavailable: result stays static');
	env.feedback.consume();
	assert.deepEqual(replaced, [], 'a swap never strips the address');
});

test('taking only a warning or error pointer keeps the address and the inline record replayable', () => {
	const env = build();
	const replaced = [];
	env.window.history = {
		state: null,
		replaceState: (state, title, url) => replaced.push(String(url)),
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?ran_booster_bulk_error=stale&_ran_booster_bulk_notice_nonce=n';
	env.target('ran-booster-feedback-bulk-error');
	env.staticContainer([
		{
			key: 'bulk_error',
			intent: 'error',
			text: 'Stale.',
			target: 'ran-booster-feedback-bulk-error',
		},
	]);
	env.run();
	assert.equal(env.notices.length, 1);
	assert.deepEqual(replaced, []);
});

test('only result arguments are stripped and foreign arguments survive', () => {
	const env = build();
	const replaced = [];
	env.window.history = {
		state: { keep: true },
		replaceState: (state, title, url) =>
			replaced.push([state, String(url)]),
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?page=ran-booster-extensions&s=term&ran_booster_bulk_error=stale&ran_booster_extension_id=x&ran_booster_extension_result=installed&utm=1';
	env.staticContainer([
		{ key: 'extension_installed', intent: 'success', text: 'Done.' },
	]);
	env.run();
	assert.deepEqual(replaced, [
		[
			{ keep: true },
			'https://example.test/wp-admin/admin.php?page=ran-booster-extensions&s=term&utm=1',
		],
	]);
});

test('a failing replaceState never throws out of the host', () => {
	const env = build();
	env.window.history = {
		state: null,
		replaceState() {
			throw new Error('blocked');
		},
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?ran_booster_result=install&_ran_booster_notice_nonce=n';
	env.staticContainer([
		{ key: 'package_install', intent: 'success', text: 'Installed.' },
	]);
	assert.doesNotThrow(() => env.run());
	assert.equal(env.notices.length, 1);
});

test('a success beside a failure pointer stays static, the pointer is taken and the address is kept', () => {
	const env = build();
	const replaced = [];
	env.window.history = {
		state: null,
		replaceState: (state, title, url) => replaced.push(String(url)),
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?ran_booster_result=edit-unverified&_ran_booster_notice_nonce=n';
	env.target('ran-booster-feedback-package-edit-unverified');
	const container = env.staticContainer([
		{ key: 'package_install', intent: 'success', text: 'Installed.' },
		{
			key: 'package_edit_unverified',
			intent: 'warning',
			text: 'Check skipped.',
			target: 'ran-booster-feedback-package-edit-unverified',
		},
	]);
	env.run();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Check skipped.']
	);
	assert.deepEqual(
		container.items.map((item) => item.getAttribute('data-feedback-key')),
		['package_install']
	);
	assert.deepEqual(replaced, []);
});

test('a pointer whose target is missing stays unshown and the address is kept', () => {
	const env = build();
	const replaced = [];
	env.window.history = {
		state: null,
		replaceState: (state, title, url) => replaced.push(String(url)),
	};
	env.window.location.href =
		'https://example.test/wp-admin/admin.php?ran_booster_bulk_error=stale&_ran_booster_bulk_notice_nonce=n';
	const container = env.staticContainer([
		{
			key: 'bulk_error',
			intent: 'error',
			text: 'Stale.',
			target: 'no-such-element',
		},
	]);
	env.run();
	assert.equal(env.notices.length, 0);
	assert.equal(container.items.length, 1);
	assert.deepEqual(replaced, []);
});

// A replay is a reload or Back to an address that still carries a signed
// result: it concerns the earlier action, never a new or current condition.
function replayEnv(type, href, items) {
	const env = build();
	const replaced = [];
	env.window.history = {
		state: null,
		replaceState: (state, title, url) => replaced.push(String(url)),
	};
	env.window.performance = {
		getEntriesByType: () => [{ type }],
	};
	env.window.location.href = href;
	const label = {
		hidden: true,
		removeAttribute(name) {
			if (name === 'hidden') {
				label.hidden = false;
			}
		},
	};
	env.target('ran-booster-feedback-bulk-error', {
		querySelector: (selector) =>
			selector === '[data-ran-booster-earlier]' ? label : null,
	});
	const container = env.staticContainer(items);
	return { env, replaced, label, container };
}

const FAILURE =
	'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&ran_booster_bulk_error=stale&_ran_booster_bulk_notice_nonce=n';
const POINTER = {
	key: 'bulk_error',
	intent: 'error',
	text: 'Stale.',
	target: 'ran-booster-feedback-bulk-error',
};

test('a fresh redirect to a failure still shows its pointer and keeps the label hidden', () => {
	const { env, label, replaced } = replayEnv('navigate', FAILURE, [POINTER]);
	env.run();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Stale.']
	);
	assert.equal(label.hidden, true);
	assert.deepEqual(replaced, []);
});

for (const type of ['reload', 'back_forward']) {
	test(`a ${type} of a failure address labels the inline record and shows no fresh pointer`, () => {
		const { env, label, replaced, container } = replayEnv(type, FAILURE, [
			POINTER,
		]);
		env.run();
		assert.equal(env.notices.length, 0);
		assert.equal(label.hidden, false);
		assert.equal(container.removed, true);
		assert.deepEqual(
			replaced,
			[],
			'the persistent record keeps its address'
		);
	});
}

test('an earlier failure, a later success, then Back and reload each read as the earlier result', () => {
	// Back to the failure address after a later success: labelled, no snackbar.
	const back = replayEnv('back_forward', FAILURE, [POINTER]);
	back.env.run();
	assert.equal(back.env.notices.length, 0);
	assert.equal(back.label.hidden, false);

	// The later success was fresh: shown once, address stripped.
	const fresh = replayEnv(
		'navigate',
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&ran_booster_result=install&_ran_booster_notice_nonce=n',
		[{ key: 'package_install', intent: 'success', text: 'Installed.' }]
	);
	fresh.env.run();
	assert.deepEqual(
		fresh.env.notices.map((notice) => notice.content),
		['Installed.']
	);
	assert.equal(fresh.replaced.length, 1);

	// Reloading the stripped success address has no result and no item.
	const reload = replayEnv(
		'reload',
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
		[]
	);
	reload.env.run();
	assert.equal(reload.env.notices.length, 0);
	assert.deepEqual(reload.replaced, []);
});

test('a replayed success is not announced again and its address is stripped', () => {
	const { env, replaced, container } = replayEnv(
		'reload',
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&ran_booster_result=install&_ran_booster_notice_nonce=n',
		[{ key: 'package_install', intent: 'success', text: 'Installed.' }]
	);
	env.run();
	assert.equal(env.notices.length, 0);
	assert.equal(container.removed, true);
	assert.deepEqual(replaced, [
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
	]);
});

test('a reload without a result address still shows a flash item', () => {
	const { env } = replayEnv(
		'reload',
		'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
		[{ key: 'release_check_current', intent: 'success', text: 'Checked.' }]
	);
	env.run();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Checked.']
	);
});

test('the earlier-result label is hidden by default and its display is not overridden', () => {
	const css = fs.readFileSync(
		new URL(
			'../../assets/ran-booster/25-admin-primitives.css',
			import.meta.url
		),
		'utf8'
	);
	assert.match(
		css,
		/\[data-ran-booster-earlier\]\[hidden\]\s*\{\s*display: none;/
	);
});

test('a replay with a success and a failure pointer keeps the failure address on reload, Back and reload again', () => {
	for (const type of ['reload', 'back_forward', 'reload']) {
		const { env, label, replaced } = replayEnv(
			type,
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&ran_booster_bulk_error=stale&_ran_booster_bulk_notice_nonce=n&ran_booster_result=install&_ran_booster_notice_nonce=m',
			[
				{
					key: 'package_install',
					intent: 'success',
					text: 'Installed.',
				},
				POINTER,
			]
		);
		env.run();
		assert.equal(env.notices.length, 0, type);
		assert.equal(label.hidden, false, type);
		assert.deepEqual(replaced, [], `${type} keeps the failure address`);
	}
});

test('a replayed info item is dropped without a label and keeps the address', () => {
	// Info is a flash result and never carries a result argument; if one does,
	// it is not a success to strip for, so the address is left alone.
	const { env, label, replaced, container } = replayEnv('reload', FAILURE, [
		{ key: 'release_check_current', intent: 'info', text: 'Checked.' },
	]);
	env.run();
	assert.equal(env.notices.length, 0);
	assert.equal(label.hidden, true);
	assert.equal(container.removed, true);
	assert.deepEqual(replaced, []);
});

test('a replayed pointer whose target is missing is dropped without a label', () => {
	const { env, label, container } = replayEnv('back_forward', FAILURE, [
		{ ...POINTER, target: 'no-such-element' },
	]);
	env.run();
	assert.equal(env.notices.length, 0);
	assert.equal(label.hidden, true);
	assert.equal(container.removed, true);
});

test('a missing or empty navigation entry list is treated as a fresh load', () => {
	for (const performance of [
		undefined,
		{},
		{ getEntriesByType: () => [] },
		{ getEntriesByType: () => [{}] },
	]) {
		const { env, label } = replayEnv('reload', FAILURE, [POINTER]);
		env.window.performance = performance;
		env.run();
		assert.deepEqual(
			env.notices.map((notice) => notice.content),
			['Stale.']
		);
		assert.equal(label.hidden, true);
	}
});

test('a real PerformanceNavigationTiming entry marks a reload as a replay', () => {
	const entry = Object.assign(Object.create({ entryType: 'navigation' }), {
		name: FAILURE,
		type: 'reload',
		redirectCount: 0,
	});
	const { env, label } = replayEnv('navigate', FAILURE, [POINTER]);
	env.window.performance = {
		getEntriesByType: (kind) => (kind === 'navigation' ? [entry] : []),
	};
	env.run();
	assert.equal(env.notices.length, 0);
	assert.equal(label.hidden, false);
});

test('a later result with the same key shows its own text in the one item, and an identical repeat only resets the timer', () => {
	const env = boot();
	env.feedback.show('Changed 2 plugins to Disabled.', { id: 'bulk_policy' });
	env.tick(4000);
	assert.equal(
		env.feedback.show('Changed 2 plugins to Automatic.', {
			id: 'bulk_policy',
		}),
		true
	);
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Changed 2 plugins to Automatic.']
	);
	// The timer restarted from the later result.
	env.tick(DURATION - 1);
	assert.equal(env.notices.length, 1);
	env.feedback.show('Changed 2 plugins to Automatic.', { id: 'bulk_policy' });
	assert.equal(env.notices.length, 1);
	env.tick(DURATION - 1);
	assert.equal(env.notices.length, 1);
	env.tick(1);
	assert.equal(env.notices.length, 0);
});

test('a static item with the same key and different copy updates the visible item', () => {
	const env = boot();
	env.feedback.show('Changed 2 plugins to Disabled.', { id: 'bulk_policy' });
	env.staticContainer([
		{
			key: 'bulk_policy',
			intent: 'success',
			text: 'Changed 2 plugins to Automatic.',
		},
	]);
	env.feedback.consume();
	assert.deepEqual(
		env.notices.map((notice) => notice.content),
		['Changed 2 plugins to Automatic.']
	);
});

function pointerTarget(env) {
	return env.target('plugins-result', {
		matches: () => true,
		setAttribute() {},
	});
}

test('a warning replaces a success with the same key: one item with the warning intent, a Show details action and no timer', () => {
	const env = boot();
	const target = pointerTarget(env);
	env.feedback.show('Changed 2 plugins to Enabled.', {
		id: 'bulk_plugin_state',
	});
	env.tick(3000);
	assert.equal(
		env.feedback.show('Changed 1 plugin; skipped 1.', {
			id: 'bulk_plugin_state',
			intent: 'warning',
			target,
		}),
		true
	);
	assert.equal(env.notices.length, 1);
	const [notice] = env.notices;
	assert.match(notice.className, /ran-booster-snackbar--warning/);
	assert.equal(notice.content, 'Changed 1 plugin; skipped 1.');
	assert.equal(notice.actions.length, 1);
	assert.equal(notice.actions[0].label, 'Show details');
	assert.equal(env.pendingTimers(), 0);
	env.tick(DURATION * 3);
	assert.equal(env.notices.length, 1, 'A pointer never dismisses itself.');
});

test('a success replaces a warning with the same key: one item with the success intent, no action and a timer that dismisses it', () => {
	const env = boot();
	const target = pointerTarget(env);
	env.feedback.show('Changed 1 plugin; skipped 1.', {
		id: 'bulk_plugin_state',
		intent: 'warning',
		target,
	});
	assert.equal(env.pendingTimers(), 0);
	assert.equal(
		env.feedback.show('Changed 2 plugins to Enabled.', {
			id: 'bulk_plugin_state',
		}),
		true
	);
	assert.equal(env.notices.length, 1);
	const [notice] = env.notices;
	assert.match(notice.className, /ran-booster-snackbar--success/);
	assert.equal(notice.content, 'Changed 2 plugins to Enabled.');
	assert.deepEqual(notice.actions, []);
	assert.equal(env.pendingTimers(), 1);
	env.tick(DURATION);
	assert.equal(env.notices.length, 0);
});

test('a changed text is not also spoken by hand, an unchanged repeat is', () => {
	const env = boot();
	const spoken = [];
	env.window.wp.a11y = { speak: (text) => spoken.push(text) };
	env.feedback.show('Changed 2 plugins to Disabled.', { id: 'bulk_policy' });
	env.feedback.show('Changed 2 plugins to Automatic.', {
		id: 'bulk_policy',
	});
	assert.deepEqual(spoken, [], 'The component speaks a changed message.');
	env.feedback.show('Changed 2 plugins to Automatic.', {
		id: 'bulk_policy',
	});
	assert.deepEqual(spoken, ['Changed 2 plugins to Automatic.']);
});
