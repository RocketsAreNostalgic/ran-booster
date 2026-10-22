import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/native-update-warning.js', import.meta.url),
	'utf8'
);

function jqueryHarness() {
	const handlers = new Map();
	const notices = [];
	const $ = (target) => ({
		on(event, callback) {
			handlers.set(event, callback);
		},
		prop(name, value) {
			assert.equal(target, '[data-ran-booster-native-update-warning]');
			assert.equal(name, 'hidden');
			notices.push(value);
		},
	});

	return { $, handlers, notices };
}

test('native update warning sends a fixed heartbeat request and preserves state for malformed responses', () => {
	const harness = jqueryHarness();
	globalThis.window = { wp: { heartbeat: { connectNow() {} } } };
	globalThis.document = {};
	globalThis.jQuery = harness.$;

	try {
		vm.runInNewContext(source, {
			document: globalThis.document,
			jQuery: globalThis.jQuery,
			window: globalThis.window,
		});
		const request = {};
		harness.handlers.get('heartbeat-send')({}, request);
		assert.deepEqual(request, { ran_booster_native_update_warning: true });
		harness.handlers.get('heartbeat-tick')({}, {});
		harness.handlers.get('heartbeat-tick')(
			{},
			{ ran_booster_native_update_warning: 'true' }
		);
		assert.deepEqual(harness.notices, []);
		harness.handlers.get('heartbeat-tick')(
			{},
			{ ran_booster_native_update_warning: true }
		);
		harness.handlers.get('heartbeat-tick')(
			{},
			{ ran_booster_native_update_warning: false }
		);
		assert.deepEqual(harness.notices, [false, true]);
	} finally {
		delete globalThis.document;
		delete globalThis.jQuery;
		delete globalThis.window;
	}
});

test('native plugin-update success asks WordPress for an immediate standard heartbeat', () => {
	const harness = jqueryHarness();
	let connects = 0;
	globalThis.window = { wp: { heartbeat: { connectNow: () => connects++ } } };
	globalThis.document = {};
	globalThis.jQuery = harness.$;

	try {
		vm.runInNewContext(source, {
			document: globalThis.document,
			jQuery: globalThis.jQuery,
			window: globalThis.window,
		});
		harness.handlers.get('wp-plugin-update-success')();
		assert.equal(connects, 1);
	} finally {
		delete globalThis.document;
		delete globalThis.jQuery;
		delete globalThis.window;
	}
});
