import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadInitializer() {
	const signature = '\tfunction initDevelopmentSafetyNoticeDismissal() {';
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, 'The notice dismissal initializer must exist.');

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

	assert.notEqual(
		end,
		-1,
		'The notice dismissal initializer must be complete.'
	);

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

test('dismissal posts the scoped action and nonce when WordPress dismisses the notice', async () => {
	const listeners = new Map();
	const requests = [];
	const notice = {
		addEventListener(type, listener) {
			listeners.set(type, listener);
		},
	};
	globalThis.document = {
		querySelector(selector) {
			return selector === '[data-ran-booster-development-safety]'
				? notice
				: null;
		},
	};
	globalThis.window = {
		ranBoosterDevelopmentSafetyNotice: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			action: 'ran_booster_dismiss_development_safety_notice',
			nonce: 'notice-nonce',
		},
		URLSearchParams,
		fetch(url, options) {
			requests.push({ options, url });
			return Promise.resolve();
		},
	};

	try {
		loadInitializer()();
		listeners.get('click')({
			target: {
				closest(selector) {
					return selector === '.notice-dismiss' ? {} : null;
				},
			},
		});
		await Promise.resolve();

		assert.equal(requests.length, 1);
		assert.equal(requests[0].url, '/wp-admin/admin-ajax.php');
		assert.equal(requests[0].options.method, 'POST');
		assert.equal(requests[0].options.credentials, 'same-origin');
		assert.equal(
			requests[0].options.body,
			'action=ran_booster_dismiss_development_safety_notice&nonce=notice-nonce'
		);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('ordinary notice clicks do not persist dismissal', () => {
	const listeners = new Map();
	let requestCount = 0;
	globalThis.document = {
		querySelector() {
			return {
				addEventListener(type, listener) {
					listeners.set(type, listener);
				},
			};
		},
	};
	globalThis.window = {
		ranBoosterDevelopmentSafetyNotice: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			action: 'dismiss',
			nonce: 'nonce',
		},
		URLSearchParams,
		fetch() {
			requestCount += 1;
			return Promise.resolve();
		},
	};

	try {
		loadInitializer()();
		listeners.get('click')({
			target: {
				closest() {
					return null;
				},
			},
		});

		assert.equal(requestCount, 0);
	} finally {
		delete globalThis.document;
		delete globalThis.window;
	}
});

test('the common ready path initializes persistent dismissal', () => {
	assert.match(
		source,
		/initDevelopmentSafetyNoticeDismissal\(\);/,
		'The common admin ready callback must initialize notice persistence.'
	);
});
