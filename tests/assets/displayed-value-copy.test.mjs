import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function fixture(clipboard, text = '/private/proposed path') {
	const listeners = new Map();
	const timers = new Map();
	const selected = [];
	const value = { textContent: text, isConnected: true };
	const status = {
		textContent: '',
		dataset: {
			copiedMessage: 'Copied',
			copyFailedMessage: 'Select and copy',
		},
	};
	const classes = new Set();
	const button = {
		disabled: false,
		classList: {
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
		},
		closest: () => root,
	};
	const root = {
		querySelector: (selector) =>
			selector === '[data-copy-value]' ? value : status,
	};
	const document = {
		readyState: 'loading',
		addEventListener: (name, fn) => listeners.set(name, fn),
		createRange: () => ({
			selectNodeContents: (node) => selected.push(node),
		}),
	};
	let changed;
	const window = {
		MutationObserver: class {
			constructor(callback) {
				changed = callback;
			}
			observe() {}
			disconnect() {}
		},
		navigator: { clipboard },
		getSelection: () => ({ removeAllRanges() {}, addRange() {} }),
		clearTimeout: (id) => timers.delete(id),
		setTimeout: (fn) => {
			const id = timers.size + 1;
			timers.set(id, fn);
			return id;
		},
	};
	value.ownerDocument = { ...document, defaultView: window };
	vm.runInNewContext(source, { document, window, wp: { i18n: {} } });
	const event = {
		target: {
			closest: (selector) =>
				selector === '[data-copy-value-button]' ? button : null,
		},
	};
	return {
		click: () => listeners.get('click')(event),
		value,
		status,
		button,
		classes,
		selected,
		timers,
		changed: () => changed(),
	};
}

test('copies the current displayed value and resets confirmed feedback', async () => {
	const copied = [];
	const f = fixture({ writeText: async (value) => copied.push(value) });
	f.value.textContent = '/actual & current';
	await f.click();
	assert.deepEqual(copied, ['/actual & current']);
	assert.equal(f.status.textContent, 'Copied');
	assert.equal(f.classes.has('is-copied'), true);
	[...f.timers.values()][0]();
	assert.equal(f.status.textContent, '');
});

test('denied and unavailable clipboard select the displayed value and report failure', async () => {
	for (const clipboard of [
		undefined,
		{
			writeText: async () => {
				throw new Error('denied');
			},
		},
	]) {
		const f = fixture(clipboard);
		await f.click();
		assert.deepEqual(f.selected, [f.value]);
		assert.equal(f.status.textContent, 'Select and copy');
		assert.equal(f.button.disabled, false);
	}
});

test('does not announce stale success after a refreshed value or copy empty text', async () => {
	let resolve;
	const f = fixture({
		writeText: () =>
			new Promise((done) => {
				resolve = done;
			}),
	});
	const pending = f.click();
	f.value.textContent = '/new';
	resolve();
	await pending;
	assert.equal(f.status.textContent, '');
	f.value.textContent = '';
	await f.click();
	assert.equal(f.button.disabled, false);
});

test('changing a displayed value clears prior confirmed feedback', async () => {
	const f = fixture({ writeText: async () => {} });
	await f.click();
	f.value.textContent = '/updated';
	f.changed();
	assert.equal(f.status.textContent, '');
	assert.equal(f.classes.has('is-copied'), false);
	assert.equal(f.timers.size, 0);
});
