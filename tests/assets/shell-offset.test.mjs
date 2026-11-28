import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);

function loadInitShellOffset(dependencies) {
	const signature = '\tfunction initShellOffset(';
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, 'The initShellOffset function must exist.');

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

	assert.notEqual(end, -1, 'The initShellOffset function must be complete.');

	return Function(
		...Object.keys(dependencies),
		`"use strict"; return (${source.slice(start, end)});`
	)(...Object.values(dependencies));
}

function harness({ shell, bar }) {
	const writes = [];
	const observers = [];
	const resizeListeners = [];
	const shellElement = {
		getBoundingClientRect: () => ({ height: shell.height }),
	};
	const barElement = bar
		? { getBoundingClientRect: () => ({ bottom: bar.bottom }) }
		: null;
	const document = {
		querySelector: (selector) =>
			selector === 'header.ran-admin-shell' ? shellElement : null,
		getElementById: (id) => (id === 'wpadminbar' ? barElement : null),
		documentElement: {
			style: {
				setProperty(name, value) {
					writes.push([name, value]);
				},
			},
		},
	};
	const window = {
		ResizeObserver: class {
			constructor(callback) {
				this.callback = callback;
				observers.push(this);
			}
			observe(target, options) {
				this.target = target;
				this.options = options;
			}
		},
		getComputedStyle: (element) =>
			element === shellElement
				? { position: shell.position, top: shell.top }
				: { position: bar.position },
		addEventListener(type, listener) {
			if (type === 'resize') {
				resizeListeners.push(listener);
			}
		},
	};

	loadInitShellOffset({ document, window })();

	return { writes, observers, resizeListeners, shell, bar };
}

test('a sticky shell publishes its sticky top plus its height at once', () => {
	const run = harness({
		shell: { position: 'sticky', top: '32px', height: 111.4 },
		bar: { position: 'fixed', bottom: 32 },
	});

	assert.deepEqual(run.writes, [['--ran-booster-shell-offset', '144px']]);
	assert.equal(run.observers.length, 1);
	assert.deepEqual(run.observers[0].options, { box: 'border-box' });
	assert.equal(run.resizeListeners.length, 1);
});

test('a shell that stops sticking still clears a fixed admin bar', () => {
	const run = harness({
		shell: { position: 'relative', top: 'auto', height: 120 },
		bar: { position: 'fixed', bottom: 46 },
	});

	assert.deepEqual(run.writes, [['--ran-booster-shell-offset', '46px']]);
});

test('without a fixed admin bar a non-sticky shell publishes zero', () => {
	const scrolling = harness({
		shell: { position: 'relative', top: 'auto', height: 120 },
		bar: { position: 'absolute', bottom: 46 },
	});
	const absent = harness({
		shell: { position: 'relative', top: 'auto', height: 120 },
		bar: null,
	});

	assert.deepEqual(scrolling.writes, [['--ran-booster-shell-offset', '0px']]);
	assert.deepEqual(absent.writes, [['--ran-booster-shell-offset', '0px']]);
});

test('re-measuring writes only when the offset changes', () => {
	const run = harness({
		shell: { position: 'sticky', top: '32px', height: 112 },
		bar: { position: 'fixed', bottom: 32 },
	});

	run.observers[0].callback();
	run.resizeListeners[0]();
	assert.equal(run.writes.length, 1);

	run.shell.height = 160;
	run.observers[0].callback();
	assert.deepEqual(run.writes.at(-1), [
		'--ran-booster-shell-offset',
		'192px',
	]);

	run.shell.position = 'relative';
	run.resizeListeners[0]();
	assert.deepEqual(run.writes.at(-1), ['--ran-booster-shell-offset', '32px']);
	assert.equal(run.writes.length, 3);
});
