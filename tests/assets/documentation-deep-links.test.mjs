import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster.js', import.meta.url),
	'utf8'
);

function loadFunction(name) {
	const signature = `\tfunction ${name}(`;
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `The shared ${name} function must exist.`);

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

	assert.notEqual(end, -1, `The shared ${name} function must be complete.`);

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

function detailsFixture() {
	const details = {
		open: false,
		tagName: 'DETAILS',
		closest(selector) {
			return selector === '.ran-booster-documentation details'
				? details
				: null;
		},
	};
	const nested = {
		tagName: 'H3',
		closest(selector) {
			return selector === '.ran-booster-documentation details'
				? details
				: null;
		},
	};
	const elements = new Map([
		['ran-booster-push-to-deploy', details],
		['provider setup', nested],
	]);
	const requested = [];

	return {
		details,
		requested,
		root: {
			getElementById(id) {
				requested.push(id);
				return elements.get(id) || null;
			},
		},
	};
}

test('documentation hash opens the targeted details element', () => {
	const openDetailsForHash = loadFunction('openDetailsForHash');
	const { details, root } = detailsFixture();

	assert.equal(openDetailsForHash(root, '#ran-booster-push-to-deploy'), true);
	assert.equal(details.open, true);
});

test('encoded child anchors open their containing details element', () => {
	const openDetailsForHash = loadFunction('openDetailsForHash');
	const { details, requested, root } = detailsFixture();

	assert.equal(openDetailsForHash(root, '#provider%20setup'), true);
	assert.deepEqual(requested, ['provider setup']);
	assert.equal(details.open, true);
});

test('invalid, missing and overlong hashes are ignored safely', () => {
	const openDetailsForHash = loadFunction('openDetailsForHash');
	const { requested, root } = detailsFixture();

	assert.equal(openDetailsForHash(root, '#%E0%A4%A'), false);
	assert.equal(openDetailsForHash(root, '#'), false);
	assert.equal(openDetailsForHash(root, `#${'a'.repeat(513)}`), false);
	assert.equal(openDetailsForHash(root, 'ran-booster-push-to-deploy'), false);
	assert.deepEqual(requested, []);
});

test('documentation initialization handles initial and changed hashes', () => {
	assert.match(
		source,
		/openDetailsForHash\(document, window\.location\.hash\);\s*window\.addEventListener\('hashchange'/
	);
});
