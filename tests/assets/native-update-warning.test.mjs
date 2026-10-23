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
	const calls = [];
	const rowSelectors = [];
	const row = {
		addClass(value) {
			calls.push(['row.addClass', value]);
			return this;
		},
		find() {
			return message;
		},
		removeClass(value) {
			calls.push(['row.removeClass', value]);
			return this;
		},
	};
	const message = {
		addClass(value) {
			calls.push(['message.addClass', value]);
			return this;
		},
		attr(name, value) {
			calls.push(['message.attr', name, value]);
			return this;
		},
		find() {
			return this;
		},
		html(value) {
			calls.push(['message.html', value]);
			return this;
		},
		removeAttr(name) {
			calls.push(['message.removeAttr', name]);
			return this;
		},
		removeClass(value) {
			calls.push(['message.removeClass', value]);
			return this;
		},
	};
	const $ = (target) => ({
		on(event, callback) {
			handlers.set(event, callback);
			return this;
		},
		prop(name, value) {
			assert.equal(target, '[data-ran-booster-native-update-warning]');
			assert.equal(name, 'hidden');
			notices.push(value);
			return this;
		},
		removeClass(value) {
			calls.push([target + '.removeClass', value]);
			return this;
		},
		trigger(event, value) {
			calls.push(['trigger', event, value]);
			return this;
		},
	});
	const original$ = $;
	const wrapped$ = (target) => {
		if ('string' === typeof target && target.startsWith('tr[data-')) {
			rowSelectors.push(target);
			return row;
		}
		return original$(target);
	};

	return { $: wrapped$, calls, handlers, notices, rowSelectors };
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

test('native Plugin-table failure callback replaces only the WordPress default and keeps WordPress cleanup', () => {
	const harness = jqueryHarness();
	const speaks = [];
	const defaultError = () => {};
	globalThis.window = {
		pagenow: 'plugins',
		wp: {
			a11y: { speak: (...args) => speaks.push(args) },
			i18n: {
				__: (text) => text,
				_x: (text, context) => {
					assert.equal(context, 'plugin');
					return text;
				},
				sprintf: (text, value) => text.replace('%s', value),
			},
			updates: {
				isValidResponse: () => true,
				maybeHandleCredentialError: () => false,
				updatePluginError: defaultError,
			},
		},
	};
	globalThis.document = {};
	globalThis.jQuery = harness.$;

	try {
		vm.runInNewContext(source, {
			document: globalThis.document,
			jQuery: globalThis.jQuery,
			window: globalThis.window,
		});
		const args = { error: defaultError };
		harness.handlers.get('wp-plugin-updating')({}, args);
		assert.notEqual(args.error, defaultError);
		const response = {
			errorMessage: 'The update failed.',
			plugin: 'example/example.php',
			pluginName: 'Example',
		};
		args.error(response);
		assert.deepEqual(speaks, [
			['Update failed: The update failed.', 'assertive'],
		]);
		assert.deepEqual(harness.calls, [
			['row.removeClass', 'is-enqueued'],
			['message.removeClass', 'updating-message notice-warning'],
			['message.addClass', 'notice-error'],
			['message.html', 'Update failed: The update failed.'],
			['message.attr', 'aria-label', 'Example update failed.'],
			['#wp-admin-bar-updates.removeClass', 'spin'],
			['trigger', 'wp-plugin-update-error', response],
		]);
	} finally {
		delete globalThis.document;
		delete globalThis.jQuery;
		delete globalThis.window;
	}
});

test('native failure callback preserves custom callbacks and routes only Plugin tables', () => {
	const harness = jqueryHarness();
	const customError = () => {};
	globalThis.window = {
		pagenow: 'plugin-install',
		wp: { updates: { updatePluginError: () => {} } },
	};
	globalThis.document = {};
	globalThis.jQuery = harness.$;

	try {
		vm.runInNewContext(source, {
			document: globalThis.document,
			jQuery: globalThis.jQuery,
			window: globalThis.window,
		});
		const customArgs = { error: customError };
		harness.handlers.get('wp-plugin-updating')({}, customArgs);
		assert.equal(customArgs.error, customError);
		globalThis.window.pagenow = 'plugins-network';
		const defaultArgs = {
			error: globalThis.window.wp.updates.updatePluginError,
		};
		harness.handlers.get('wp-plugin-updating')({}, defaultArgs);
		assert.notEqual(
			defaultArgs.error,
			globalThis.window.wp.updates.updatePluginError
		);
	} finally {
		delete globalThis.document;
		delete globalThis.jQuery;
		delete globalThis.window;
	}
});

test('native failure callback leaves malformed and credential responses to WordPress', () => {
	const harness = jqueryHarness();
	let validates = 0;
	let credentials = 0;
	const defaultError = () => {};
	globalThis.window = {
		pagenow: 'plugins',
		wp: {
			updates: {
				isValidResponse: () => {
					validates++;
					return 1 !== validates;
				},
				maybeHandleCredentialError: () => {
					credentials++;
					return true;
				},
				updatePluginError: defaultError,
			},
		},
	};
	globalThis.document = {};
	globalThis.jQuery = harness.$;

	try {
		vm.runInNewContext(source, {
			document: globalThis.document,
			jQuery: globalThis.jQuery,
			window: globalThis.window,
		});
		const args = { error: defaultError };
		harness.handlers.get('wp-plugin-updating')({}, args);
		args.error({ errorMessage: 'Credentials required.' });
		args.error('malformed response');
		assert.equal(credentials, 1);
		assert.equal(validates, 2);
		assert.deepEqual(harness.calls, []);
	} finally {
		delete globalThis.document;
		delete globalThis.jQuery;
		delete globalThis.window;
	}
});

test('native failure callback uses the WordPress slug fallback and ignores an absent default callback', () => {
	const harness = jqueryHarness();
	const defaultError = () => {};
	globalThis.window = {
		pagenow: 'plugins',
		wp: {
			a11y: { speak() {} },
			i18n: {
				__: (text) => text,
				sprintf: (text, value) => text.replace('%s', value),
			},
			updates: {
				isValidResponse: () => true,
				maybeHandleCredentialError: () => false,
				updatePluginError: defaultError,
			},
		},
	};
	globalThis.document = {};
	globalThis.jQuery = harness.$;

	try {
		vm.runInNewContext(source, {
			document: globalThis.document,
			jQuery: globalThis.jQuery,
			window: globalThis.window,
		});
		const args = { error: defaultError };
		harness.handlers.get('wp-plugin-updating')({}, args);
		args.error({ errorMessage: 'Failed.', slug: 'example' });
		assert.deepEqual(harness.rowSelectors, ['tr[data-slug="example"]']);
		globalThis.window.wp.updates.updatePluginError = undefined;
		const missingDefault = {};
		harness.handlers.get('wp-plugin-updating')({}, missingDefault);
		assert.equal(missingDefault.error, undefined);
	} finally {
		delete globalThis.document;
		delete globalThis.jQuery;
		delete globalThis.window;
	}
});
