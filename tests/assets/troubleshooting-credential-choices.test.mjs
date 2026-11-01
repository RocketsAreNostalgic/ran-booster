import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);

test('diagnostic region replacements bind credential choices once per new form', () => {
	const listeners = new Map();
	let region;
	const document = {
		readyState: 'loading',
		addEventListener(name, callback) {
			listeners.set(name, callback);
		},
		querySelector(selector) {
			return selector ===
				'#ran-booster-troubleshooting-diagnostics-region'
				? region
				: null;
		},
	};
	vm.runInNewContext(source, { document });
	const swap = listeners.get('htmx:afterSwap');
	for (let i = 0; i < 3; i += 1) {
		let change;
		let binds = 0;
		const provider = {
			value: 'gh',
			addEventListener(name, callback) {
				assert.equal(name, 'change');
				binds += 1;
				change = callback;
			},
		};
		const options = [
			{ dataset: { provider: 'gh' } },
			{ dataset: { provider: 'bb' } },
		];
		const credential = {
			options,
			selectedIndex: 0,
			value: 'gh-secret',
			querySelectorAll: () => options,
		};
		const form = {
			dataset: {},
			querySelector: (selector) =>
				selector.endsWith('__provider') ? provider : credential,
		};
		region = { querySelector: () => form };
		const event = {
			detail: {
				target: {
					id: 'ran-booster-troubleshooting-diagnostics-region',
				},
			},
		};
		swap(event);
		swap(event);
		assert.equal(binds, 1);
		assert.equal(options[0].disabled, false);
		assert.equal(options[1].hidden, true);
		provider.value = 'bb';
		change();
		assert.equal(options[0].disabled, true);
		assert.equal(options[1].hidden, false);
		assert.equal(credential.value, '');
	}
});
