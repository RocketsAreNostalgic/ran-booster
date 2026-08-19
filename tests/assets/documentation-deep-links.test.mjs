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

function createFixture({ hash = '#first', observer = true } = {}) {
	const first = { id: 'first', open: false };
	const second = { id: 'second', open: false };
	const child = {};
	const links = [createLink('#first'), createLink('#second')];
	const targets = new Map([
		['first', first],
		['second', second],
		['child', child],
	]);
	const observers = [];
	const windowListeners = new Map();
	const root = {
		contains(section) {
			return section === first || section === second;
		},
		dataset: {},
		ownerDocument: null,
		querySelectorAll(selector) {
			if (selector === '.ran-booster-documentation__index a[href^="#"]') {
				return links;
			}
			if (selector === '.ran-booster-documentation__section[id]') {
				return [first, second];
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
			return selector === '.ran-booster-documentation' ? root : null;
		},
		querySelectorAll() {
			return [];
		},
		readyState: 'complete',
	};
	root.ownerDocument = document;

	function IntersectionObserver(callback, options) {
		this.callback = callback;
		this.options = options;
		this.observed = [];
		this.observe = (section) => this.observed.push(section);
		observers.push(this);
	}

	const window = {
		addEventListener(name, callback) {
			windowListeners.set(name, callback);
		},
		location: { hash },
		IntersectionObserver: observer ? IntersectionObserver : undefined,
	};
	Function(
		'document',
		'window',
		'IntersectionObserver',
		source
	)(document, window, observer ? IntersectionObserver : undefined);

	return { first, links, observers, second, window, windowListeners };
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

test('malformed and unknown hashes preserve the current active link safely', () => {
	const fixture = createFixture();

	fixture.window.location.hash = '#%E0%A4%A';
	fixture.windowListeners.get('hashchange')();
	fixture.window.location.hash = '#missing';
	fixture.windowListeners.get('hashchange')();

	assert.equal(fixture.links[0].getAttribute('aria-current'), 'location');
	assert.equal(fixture.links[1].hasAttribute('aria-current'), false);
});

test('the observer picks the section nearest the activation line without rewriting the URL', () => {
	const fixture = createFixture({ hash: '#first' });
	const observer = fixture.observers[0];

	assert.deepEqual(observer.observed, [fixture.first, fixture.second]);
	assert.deepEqual(observer.options, {
		rootMargin: '-70px 0px -65% 0px',
		threshold: 0,
	});
	observer.callback([
		{
			boundingClientRect: { top: 20 },
			isIntersecting: true,
			target: fixture.first,
		},
		{
			boundingClientRect: { top: 80 },
			isIntersecting: true,
			target: fixture.second,
		},
	]);

	assert.equal(fixture.links[0].hasAttribute('aria-current'), false);
	assert.equal(fixture.links[1].getAttribute('aria-current'), 'location');
	assert.equal(fixture.window.location.hash, '#first');
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

	fixture.windowListeners.get('afterprint')();

	assert.equal(fixture.first.open, false);
	assert.equal(fixture.second.open, true);
});
