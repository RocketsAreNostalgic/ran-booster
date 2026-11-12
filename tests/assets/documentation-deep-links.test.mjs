import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);

function createLink(href) {
	const attributes = new Map([['href', href]]);
	const listeners = new Map();

	return {
		addEventListener(name, callback) {
			listeners.set(name, callback);
		},
		click() {
			listeners.get('click')?.();
		},
		getAttribute(name) {
			return attributes.get(name) || null;
		},
		hasAttribute(name) {
			return attributes.has(name);
		},
		removeAttribute(name) {
			attributes.delete(name);
		},
		setAttribute(name, value) {
			attributes.set(name, value);
		},
	};
}

function createFixture({
	hash = '#first',
	observer = true,
	margin = 192,
	childMargin = 0,
	padding = 32,
	shellBottom = 0,
	optIn = false,
} = {}) {
	const sections = [];
	const wrappers = [];
	const stateEvents = [];
	function createSection(id) {
		let isOpen = false;
		let name = 'ran-booster-documentation';
		const section = {
			id,
			tagName: 'DETAILS',
			rect: {
				top: id === 'first' ? 166 : 224,
				bottom: id === 'first' ? 200 : 400,
			},
			getBoundingClientRect() {
				return this.rect;
			},
			get open() {
				return isOpen;
			},
			set open(value) {
				isOpen = value;
				if (value && name) {
					sections.forEach((other) => {
						if (
							other !== section &&
							other.getAttribute('name') === name
						) {
							other.open = false;
						}
					});
				}
			},
			getAttribute(attribute) {
				return attribute === 'name' ? name : null;
			},
			removeAttribute(attribute) {
				if (attribute === 'id') {
					this.id = '';
				}
				if (attribute === 'name') {
					name = null;
				}
			},
			setAttribute(attribute, value) {
				if (attribute === 'name') {
					name = value;
				}
			},
		};
		sections.push(section);
		return section;
	}
	const first = createSection('first');
	const second = createSection('second');
	const child = {};
	const shell = { getBoundingClientRect: () => ({ bottom: shellBottom }) };
	const links = [createLink('#first'), createLink('#second')];
	const targets = new Map([
		['first', first],
		['second', second],
		['child', child],
	]);
	const observers = [];
	const windowListeners = new Map();
	const root = {
		hasAttribute(name) {
			return optIn && name === 'data-ran-booster-ui-documentation';
		},
		dispatchEvent(event) {
			stateEvents.push([event.type, first.open, second.open]);
		},
		contains(section) {
			return (
				section === first ||
				section === second ||
				wrappers.includes(section)
			);
		},
		dataset: {},
		isConnected: true,
		addEventListener(name, callback) {
			windowListeners.set('root:' + name, callback);
		},
		querySelector(selector) {
			return selector === ':target'
				? targets.get(window.location.hash.slice(1)) || null
				: null;
		},
		ownerDocument: null,
		querySelectorAll(selector) {
			if (selector === '.ran-booster-documentation__index a[href^="#"]') {
				return links;
			}
			if (
				selector ===
				'details[data-ran-booster-documentation-section][id]'
			) {
				return optIn
					? [first, second].filter((section) => section.id)
					: [];
			}
			if (selector === '.ran-booster-documentation__section[id]') {
				return optIn ? wrappers : [first, second];
			}
			if (selector === 'details') {
				return [first, second];
			}
			return [];
		},
	};
	[first, second].forEach(function (section) {
		section.closest = function (selector) {
			return selector === '.ran-booster-documentation__section'
				? section
				: null;
		};
	});
	child.closest = function (selector) {
		return selector === '.ran-booster-documentation__section'
			? first
			: null;
	};

	const document = {
		getElementById(id) {
			return targets.get(id) || null;
		},
		querySelector(selector) {
			if (selector === '.ran-booster-documentation') {
				return root;
			}
			return selector === '.ran-admin-shell' ? shell : null;
		},
		querySelectorAll() {
			return [];
		},
		readyState: 'complete',
		scrollingElement: { clientHeight: 500 },
	};
	root.ownerDocument = document;
	if (optIn) {
		[first, second].forEach((section) => {
			const summary = { setAttribute() {} },
				body = { setAttribute() {} };
			section.querySelector = (selector) =>
				selector === ':scope > summary' ? summary : body;
			section.parentNode = {
				insertBefore(wrapper) {
					targets.set(wrapper.id, wrapper);
				},
			};
		});
		document.createElement = () => ({
			tagName: 'DIV',
			attributes: new Map(),
			setAttribute(name, value) {
				this.attributes.set(name, value);
			},
			appendChild(details) {
				this.details = details;
				details.wrapper = this;
				wrappers.push(this);
			},
			querySelector() {
				return this.details;
			},
			closest(selector) {
				return selector.includes('documentation') ? this : null;
			},
			getBoundingClientRect() {
				return this.details.getBoundingClientRect();
			},
		});
		[first, second].forEach((section) => {
			section.closest = (selector) =>
				selector.includes('data-ran-booster-ui-disclosure')
					? section.wrapper
					: section;
		});
		child.closest = (selector) =>
			selector.includes('data-ran-booster-ui-disclosure')
				? first.wrapper
				: first;
	}

	function IntersectionObserver(callback, options) {
		this.callback = callback;
		this.options = options;
		this.observed = [];
		this.observe = (section) => this.observed.push(section);
		observers.push(this);
	}

	const listenerSets = new Map();
	const frames = [];
	const window = {
		innerHeight: 500,
		CustomEvent: class {
			constructor(type, options) {
				this.type = type;
				this.bubbles = options.bubbles;
			}
		},
		requestAnimationFrame(callback) {
			frames.push(callback);
		},
		getComputedStyle(element) {
			return element === document.scrollingElement
				? { scrollPaddingTop: String(padding) }
				: {
						scrollMarginBlockStart: String(
							element === child ? childMargin : margin
						),
					};
		},
		addEventListener(name, callback) {
			if (!listenerSets.has(name)) {
				listenerSets.set(name, new Set());
				windowListeners.set(name, (event) =>
					listenerSets.get(name).forEach((fn) => fn(event))
				);
			}
			listenerSets.get(name).add(callback);
		},
		removeEventListener(name, callback) {
			listenerSets.get(name)?.delete(callback);
		},
		location: { hash },
		IntersectionObserver: observer ? IntersectionObserver : undefined,
	};
	function initialize() {
		Function(
			'document',
			'window',
			'IntersectionObserver',
			source
		)(document, window, observer ? IntersectionObserver : undefined);
	}
	initialize();

	return {
		first,
		root,
		wrappers,
		stateEvents,
		listenerSets,
		initialize,
		links,
		observers,
		second,
		window,
		windowListeners,
		pending() {
			return frames.length;
		},
		flush() {
			while (frames.length) {
				frames.shift()();
			}
		},
	};
}

test('initial and changed hashes open their top-level sections and update the index', () => {
	const fixture = createFixture();

	assert.equal(fixture.first.open, true);
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');

	fixture.window.location.hash = '#second';
	fixture.windowListeners.get('hashchange')();

	assert.equal(fixture.second.open, true);
	assert.equal(fixture.links[0].hasAttribute('aria-current'), false);
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
});

test('clicking the current hash reopens its section without changing the URL', () => {
	const fixture = createFixture();
	fixture.first.open = false;

	fixture.links[0].click();

	assert.equal(fixture.first.open, true);
	assert.equal(fixture.window.location.hash, '#first');
});

test('child anchors open and activate their containing indexed section', () => {
	const fixture = createFixture({ hash: '#child' });

	assert.equal(fixture.first.open, true);
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
});

test('history opens the child article before native scroll restoration and hashchange', () => {
	const fixture = createFixture({ hash: '#second' });
	fixture.window.location.hash = '#child';
	fixture.windowListeners.get('popstate')();

	assert.equal(fixture.first.open, true);
	assert.equal(fixture.second.open, false);
	assert.equal(fixture.window.location.hash, '#child');
	fixture.windowListeners.get('hashchange')();
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
});

test('history with an invalid fragment leaves the open article and current link intact', () => {
	for (const hash of ['#missing', '#%E0%A4%A']) {
		const fixture = createFixture({ hash: '#second' });
		fixture.window.location.hash = hash;
		fixture.windowListeners.get('popstate')();

		assert.equal(fixture.second.open, true);
		assert.equal(fixture.first.open, false);
		assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
		assert.equal(fixture.window.location.hash, hash);
	}
});

test('malformed and unknown hashes preserve the current active link safely', () => {
	const fixture = createFixture();

	fixture.window.location.hash = '#%E0%A4%A';
	fixture.windowListeners.get('hashchange')();
	fixture.window.location.hash = '#missing';
	fixture.windowListeners.get('hashchange')();

	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
	assert.equal(fixture.links[1].hasAttribute('aria-current'), false);
});

test('invalid hash changes preserve the settled section until the reader scrolls', () => {
	for (const hash of ['#missing', '#%E0%A4%A']) {
		const fixture = createFixture({ hash: '#second' });
		fixture.flush();
		fixture.window.location.hash = hash;
		fixture.windowListeners.get('hashchange')({ type: 'hashchange' });
		fixture.flush();
		assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
		fixture.windowListeners.get('scroll')({ type: 'scroll' });
		fixture.flush();
		assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
		assert.equal(fixture.window.location.hash, hash);
	}
});

test('without IntersectionObserver, deep links and click activation still work', () => {
	const fixture = createFixture({ hash: '#child', observer: false });

	assert.equal(fixture.first.open, true);
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
	fixture.second.open = false;
	fixture.links[1].click();
	assert.equal(fixture.second.open, true);
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
});

test('printing opens every documentation disclosure and restores its exact prior state', () => {
	const fixture = createFixture();
	fixture.first.open = false;
	fixture.second.open = true;

	fixture.windowListeners.get('beforeprint')();
	fixture.windowListeners.get('beforeprint')();

	assert.equal(fixture.first.open, true);
	assert.equal(fixture.second.open, true);
	assert.equal(fixture.first.getAttribute('name'), null);
	assert.equal(fixture.second.getAttribute('name'), null);

	fixture.windowListeners.get('afterprint')();

	assert.equal(fixture.first.open, false);
	assert.equal(fixture.second.open, true);
	assert.equal(
		fixture.first.getAttribute('name'),
		'ran-booster-documentation'
	);
	assert.equal(
		fixture.second.getAttribute('name'),
		'ran-booster-documentation'
	);
	fixture.first.open = true;
	assert.equal(fixture.second.open, false);
});

test('scroll tracking updates from fresh geometry without changing the hash', () => {
	const fixture = createFixture({ hash: '#second' });
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
	fixture.first.rect = { top: 201, bottom: 234 };
	fixture.second.rect = { top: 259, bottom: 400 };
	fixture.windowListeners.get('scroll')?.();
	fixture.flush();
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
	assert.equal(fixture.window.location.hash, '#second');
	fixture.first.rect = { top: 166, bottom: 200 };
	fixture.second.rect = { top: 224, bottom: 400 };
	fixture.windowListeners.get('scroll')?.();
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
});

test('child target margin and root padding determine the reading line', () => {
	const fixture = createFixture({
		hash: '#child',
		childMargin: 100,
		padding: 20,
	});
	fixture.first.rect = { top: 30, bottom: 80 };
	fixture.second.rect = { top: 110, bottom: 200 };
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
	assert.equal(fixture.window.location.hash, '#child');
});

test('a visible shell guides a no-hash page and resize refreshes geometry', () => {
	const fixture = createFixture({ hash: '', shellBottom: 144, padding: 0 });
	fixture.first.rect = { top: 30, bottom: 70 };
	fixture.second.rect = { top: 130, bottom: 180 };
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
	fixture.first.rect = { top: 140, bottom: 180 };
	fixture.second.rect = { top: 200, bottom: 240 };
	fixture.windowListeners.get('resize')?.();
	fixture.flush();
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
});

test('an open article spanning the reading line stays current', () => {
	const fixture = createFixture({ hash: '#second' });
	fixture.first.rect = { top: 10, bottom: 250 };
	fixture.second.rect = { top: 230, bottom: 260 };
	fixture.flush();
	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
});

test('scroll events coalesce into one frame and ignore content behind the shell', () => {
	const fixture = createFixture({ hash: '', shellBottom: 144, padding: 0 });
	fixture.first.rect = { top: 140, bottom: 143 };
	fixture.second.rect = { top: 200, bottom: 250 };
	fixture.windowListeners.get('scroll')?.();
	fixture.windowListeners.get('scroll')?.();
	assert.equal(fixture.pending(), 1);
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
	assert.equal(fixture.window.location.hash, '');
});

test('percentage scroll padding resolves against the scrolling viewport', () => {
	const fixture = createFixture({ hash: '', shellBottom: 0, padding: '20%' });
	fixture.first.rect = { top: 10, bottom: 50 };
	fixture.second.rect = { top: 90, bottom: 120 };
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
});

test('detached documentation releases only its tracking listeners', () => {
	const fixture = createFixture({ hash: '#second' });
	fixture.flush();
	assert.equal(fixture.listenerSets.get('hashchange').size, 2);
	fixture.windowListeners.get('root:htmx:beforeCleanupElement')?.();
	fixture.root.isConnected = false;
	fixture.first.rect = { top: 224, bottom: 300 };
	fixture.second.rect = { top: 350, bottom: 400 };
	fixture.flush();
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
	assert.equal(fixture.listenerSets.get('scroll').size, 0);
	assert.equal(fixture.listenerSets.get('resize').size, 0);
	assert.equal(fixture.listenerSets.get('hashchange').size, 1);
});

test('reinitializing the same connected documentation does not duplicate tracking', () => {
	const fixture = createFixture();
	fixture.initialize();
	assert.equal(fixture.listenerSets.get('scroll').size, 1);
	assert.equal(fixture.listenerSets.get('resize').size, 1);
	assert.equal(fixture.listenerSets.get('hashchange').size, 2);
});

test('a scroll after native removal cleans the tracking listeners', () => {
	const fixture = createFixture();
	fixture.flush();
	fixture.root.isConnected = false;
	fixture.windowListeners.get('scroll')();
	fixture.flush();
	assert.equal(fixture.listenerSets.get('scroll').size, 0);
	assert.equal(fixture.listenerSets.get('hashchange').size, 1);
});

test('prepared documentation synchronizes native state immediately for navigation and print', () => {
	const f = createFixture({ optIn: true, hash: '#first' });
	assert.equal(f.wrappers.length, 2);
	assert.equal(f.wrappers[0].id, 'first');
	assert.equal(f.first.id, '');
	assert.equal(
		f.wrappers[0].attributes.get('data-ran-booster-ui-disclosure'),
		'documentation'
	);
	assert.equal(
		f.wrappers[0].attributes.get('data-ran-booster-ui-disclosure-density'),
		'compact'
	);
	assert.deepEqual(f.stateEvents.at(-1), [
		'ran-booster:documentation-state',
		true,
		false,
	]);
	f.links[1].click();
	assert.deepEqual(f.stateEvents.at(-1), [
		'ran-booster:documentation-state',
		false,
		true,
	]);
	f.window.location.hash = '#child';
	f.windowListeners.get('popstate')();
	assert.deepEqual(f.stateEvents.at(-1), [
		'ran-booster:documentation-state',
		true,
		false,
	]);
	f.windowListeners.get('beforeprint')();
	assert.deepEqual(f.stateEvents.at(-1), [
		'ran-booster:documentation-state',
		true,
		true,
	]);
	f.windowListeners.get('afterprint')();
	assert.deepEqual(f.stateEvents.at(-1), [
		'ran-booster:documentation-state',
		true,
		false,
	]);
	assert.equal(f.first.getAttribute('name'), 'ran-booster-documentation');
	f.windowListeners.get('root:toggle')({ type: 'toggle' });
	assert.equal(f.stateEvents.at(-1)[0], 'ran-booster:documentation-state');
});

test('opening an article re-seats its heading only when it ended under the fixed header', () => {
	const fixture = createFixture({ hash: '', shellBottom: 144 });
	for (const top of [-179, 300]) {
		const calls = [];
		const section = {
			getBoundingClientRect: () => ({ top }),
			scrollIntoView: (options) => calls.push(JSON.stringify(options)),
		};
		const trigger = { closest: () => section };
		fixture.windowListeners.get('root:click')({
			target: { closest: () => trigger },
		});
		fixture.flush();
		assert.deepEqual(
			calls,
			top < 144 ? ['{"block":"start","behavior":"instant"}'] : []
		);
	}
	const none = { closest: () => null };
	fixture.windowListeners.get('root:click')({ target: none });
	assert.equal(fixture.pending(), 0);
});

test('the re-seat also covers the native summary and a shell scrolled off a short viewport', () => {
	const fixture = createFixture({ hash: '', shellBottom: -500 });
	const selectors = [];
	const calls = [];
	const section = {
		getBoundingClientRect: () => ({ top: -179 }),
		scrollIntoView: (options) => calls.push(JSON.stringify(options)),
	};
	const trigger = { closest: () => section };
	fixture.windowListeners.get('root:click')({
		target: {
			closest: (selector) => {
				selectors.push(selector);
				return trigger;
			},
		},
	});
	fixture.flush();
	assert.match(selectors[0], /\[role="button"\]\[aria-expanded="false"\]/);
	assert.match(
		selectors[0],
		/details\.ran-booster-documentation__section:not\(\[open\]\) > summary/
	);
	assert.deepEqual(calls, ['{"block":"start","behavior":"instant"}']);
});
