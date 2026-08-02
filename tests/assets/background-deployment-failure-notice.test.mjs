import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL(
		'../../assets/background-deployment-failure-notice.js',
		import.meta.url
	),
	'utf8'
);

test('background failure dismissal posts only the server action and nonce', async () => {
	let listener;
	const requests = [];
	globalThis.document = {
		addEventListener(type, callback) {
			assert.equal(type, 'click');
			listener = callback;
		},
	};
	globalThis.window = {
		ranBoosterBackgroundFailureNotice: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			action: 'ran_booster_dismiss_background_failure_notice',
			nonce: 'failure-notice-nonce',
		},
		fetch(url, options) {
			requests.push({ options, url });
			return Promise.resolve();
		},
	};

	try {
		Function(source)();
		listener({
			target: {
				closest(selector) {
					return selector.includes(
						'data-ran-booster-background-failure-notice'
					)
						? {}
						: null;
				},
			},
		});
		await Promise.resolve();

		assert.equal(requests.length, 1);
		assert.equal(requests[0].url, '/wp-admin/admin-ajax.php');
		assert.equal(requests[0].options.method, 'POST');
		assert.equal(
			requests[0].options.body,
			'action=ran_booster_dismiss_background_failure_notice&nonce=failure-notice-nonce'
		);
		assert.doesNotMatch(requests[0].options.body, /fingerprint/i);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});
