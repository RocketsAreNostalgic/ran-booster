import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/credential-expiry-notice.js', import.meta.url),
	'utf8'
);

test('expiry notice dismissal posts only the server action and nonce', async () => {
	let listener;
	const requests = [];
	globalThis.document = {
		addEventListener(type, callback) {
			assert.equal(type, 'click');
			listener = callback;
		},
	};
	globalThis.window = {
		ranBoosterCredentialExpiryNotice: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			action: 'ran_booster_dismiss_credential_expiry_notice',
			nonce: 'expiry-notice-nonce',
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
						'data-ran-booster-credential-expiry-notice'
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
			'action=ran_booster_dismiss_credential_expiry_notice&nonce=expiry-notice-nonce'
		);
		assert.doesNotMatch(requests[0].options.body, /fingerprint/i);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('unrelated clicks do not request dismissal', () => {
	let listener;
	let requests = 0;
	globalThis.document = {
		addEventListener(type, callback) {
			listener = callback;
		},
	};
	globalThis.window = {
		ranBoosterCredentialExpiryNotice: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			action: 'dismiss',
			nonce: 'nonce',
		},
		fetch() {
			requests += 1;
			return Promise.resolve();
		},
	};

	try {
		Function(source)();
		listener({
			target: {
				closest() {
					return null;
				},
			},
		});
		assert.equal(requests, 0);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});
