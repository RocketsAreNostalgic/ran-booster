import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-rate-limit.js', import.meta.url),
	'utf8'
);

const load = (elements = []) => {
	const listeners = {};
	const document = {
		readyState: 'complete',
		documentElement: { lang: 'en-US' },
		querySelectorAll: (selector) =>
			selector === 'time[data-ran-booster-rate-limit]' ? elements : [],
		addEventListener: (type, listener) => {
			listeners[type] = listener;
		},
	};
	const wp = {
		i18n: {
			__: (text) => text,
			sprintf: (template, ...values) => {
				let index = 0;
				return template.replace(/%(?:\d+\$)?[ds]/g, () =>
					String(values[index++])
				);
			},
		},
	};
	const window = { document, wp };
	vm.runInNewContext(source, { window, Date, Array, Number, Math });
	return { window, listeners };
};

const timeElement = (datetime, provider) => ({
	textContent:
		'GitHub is limiting lookups from this site. Try again in about 59 minutes.',
	getAttribute: (name) =>
		({ datetime, 'data-ran-booster-rate-limit': provider })[name] ?? null,
});

const withZone = async (zone, run) => {
	const previous = process.env.TZ;
	process.env.TZ = zone;
	try {
		await run();
	} finally {
		if (previous === undefined) {
			delete process.env.TZ;
		} else {
			process.env.TZ = previous;
		}
	}
};

test('the retry time is shown on the browser clock, never the server or site zone', async () => {
	await withZone('Pacific/Auckland', () => {
		const { window } = load();
		const retryAt = Math.floor(Date.now() / 1000) + 3540;
		const shown = new Date(Math.ceil(retryAt / 60) * 60000);
		const options = { hour: 'numeric', minute: '2-digit' };
		const local = shown.toLocaleTimeString('en-US', options);
		assert.notEqual(
			local,
			shown.toLocaleTimeString('en-US', { ...options, timeZone: 'UTC' })
		);
		assert.equal(
			window.ranBoosterRateLimit.message({
				provider: 'Bitbucket',
				retry_at: retryAt,
			}),
			`Bitbucket is limiting lookups from this site. Try again after ${local}.`
		);
	});
});

test('no, passed, distant or malformed times fall back to try again later', () => {
	const { window } = load();
	const now = Math.floor(Date.now() / 1000);
	for (const rateLimit of [
		{ provider: 'GitHub', retry_at: null },
		{ provider: 'GitHub', retry_at: now - 60 },
		{ provider: 'GitHub', retry_at: now + 86401 },
		{ provider: 'GitHub', retry_at: String(now + 60) },
	]) {
		assert.equal(
			window.ranBoosterRateLimit.message(rateLimit),
			'GitHub is limiting lookups from this site. Try again later.'
		);
	}
	assert.equal(
		window.ranBoosterRateLimit.message(null),
		'The Git provider is limiting lookups from this site. Try again later.'
	);
});

test('a server time element is rewritten on load and after an htmx swap', async () => {
	await withZone('America/Los_Angeles', () => {
		const retryAt = Math.floor(Date.now() / 1000) + 3540;
		const iso = new Date(retryAt * 1000).toISOString();
		const element = timeElement(iso, 'GitHub');
		const unusable = timeElement('not a date', 'GitHub');
		const { listeners } = load([element, unusable]);
		const local = new Date(
			Math.ceil(retryAt / 60) * 60000
		).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
		assert.equal(
			element.textContent,
			`GitHub is limiting lookups from this site. Try again after ${local}.`
		);
		// Without a usable time the server's minutes sentence stays.
		assert.match(unusable.textContent, /about 59 minutes/);

		const swapped = timeElement(iso, '');
		listeners['htmx:afterSwap']({
			detail: { target: { querySelectorAll: () => [swapped] } },
		});
		assert.equal(
			swapped.textContent,
			`The Git provider is limiting lookups from this site. Try again after ${local}.`
		);
	});
});
