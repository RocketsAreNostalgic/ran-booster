import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function loadFunction(name, dependencies = {}) {
	const signature = `\tfunction ${name}(`;
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `The ${name} function must exist.`);

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

	assert.notEqual(end, -1, `The ${name} function must be complete.`);

	return Function(
		...Object.keys(dependencies),
		`"use strict"; return (${source.slice(start, end)});`
	)(...Object.values(dependencies));
}

test('authoritative provider profile swaps rebind only the replaced controls', () => {
	const calls = [];
	const handleProviderProfileSwap = loadFunction(
		'handleProviderProfileSwap',
		{
			activeCredentialButton: { id: 'detached-trigger' },
			document: {
				body: {
					classList: {
						remove(className) {
							calls.push(['body-class-remove', className]);
						},
					},
				},
			},
			initCredentialSettings() {
				calls.push(['credentials']);
			},
			initWebhookSecretTools() {
				calls.push(['secret-tools']);
			},
			initWebhookUrlCopy(target) {
				calls.push(['webhook-copy', target.id]);
			},
		}
	);

	handleProviderProfileSwap({
		detail: { target: { id: 'unrelated-region' } },
	});
	assert.deepEqual(calls, []);

	handleProviderProfileSwap({
		detail: { target: { id: 'ran-booster-provider-profile-region' } },
	});
	assert.deepEqual(calls, [
		['body-class-remove', 'ran-booster-repository-picker-open'],
		['secret-tools'],
		['webhook-copy', 'ran-booster-provider-profile-region'],
		['credentials'],
	]);
});

test('only verified provider profile success operations restore focus', () => {
	let focusCount = 0;
	globalThis.document = {
		querySelector(selector) {
			assert.equal(
				selector,
				'#ran-booster-provider-profile-region [data-ran-booster-provider-profile-focus]'
			);

			return {
				focus(options) {
					assert.deepEqual(options, { preventScroll: true });
					focusCount += 1;
				},
			};
		},
	};

	try {
		const handleProviderProfileSuccess = loadFunction(
			'handleProviderProfileSuccess'
		);

		handleProviderProfileSuccess({
			detail: { operation: 'assisted-hooks:manage-webhook' },
		});
		handleProviderProfileSuccess({ detail: {} });
		assert.equal(focusCount, 0);

		for (const operation of [
			'core:save-access-profile',
			'core:delete-access-profile',
			'core:save-webhook-profile',
		]) {
			handleProviderProfileSuccess({ detail: { operation } });
		}
		handleProviderProfileSuccess({
			detail: {
				value: { operation: 'core:delete-webhook-profile' },
			},
		});
		assert.equal(focusCount, 4);
	} finally {
		delete globalThis.document;
	}
});

test('the lifecycle handlers are registered on the post-swap and safe success events', () => {
	assert.match(
		source,
		/document\.addEventListener\('htmx:afterSwap', handleProviderProfileSwap\)/
	);
	assert.match(
		source,
		/'ran-booster:admin-mutation-success',\s+handleProviderProfileSuccess/
	);
});
