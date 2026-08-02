import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const css = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/20-repository-picker.css',
		import.meta.url
	),
	'utf8'
);
const source = fs.readFileSync(
	new URL('../../assets/ran-booster-repository-picker.js', import.meta.url),
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

test('repository results fill a stable bounded viewport before scrolling', () => {
	assert.match(
		css,
		/\.ran-booster-repository-picker__dialog\s*\{[^}]*block-size:\s*min\(720px,\s*calc\(100vh - 40px\)\);/s
	);
	assert.match(
		css,
		/\.ran-booster-repository-picker__body\s*\{[^}]*overflow-y:\s*auto;[^}]*min-block-size:\s*0;[^}]*flex:\s*1 1 auto;/s
	);
	assert.match(
		css,
		/\.ran-booster-repository-picker__list\s*\{[^}]*min-block-size:\s*min\(184px,\s*30vh\);[^}]*max-block-size:\s*min\(368px,\s*42vh\);[^}]*flex:\s*1 1 368px;/s
	);
	assert.match(
		css,
		/\.ran-booster-repository-picker__list\s*\{[^}]*overflow-y:\s*auto;/s
	);
	assert.match(
		css,
		/\.ran-booster-repository-picker__repository\s*\{[^}]*min-block-size:\s*46px;/s
	);
	assert.doesNotMatch(
		css,
		/\.ran-booster-repository-picker__list:empty\s*\{[^}]*display:\s*none;/s
	);
});

test('repository filter stays in flow and disables while results are unavailable', () => {
	assert.doesNotMatch(
		source,
		/filter\.setAttribute\(['"]hidden['"],\s*['"]hidden['"]\)/
	);
	assert.doesNotMatch(source, /filter\.removeAttribute\(['"]hidden['"]\)/);
	assert.match(source, /search\.disabled\s*=\s*true/);
	assert.match(source, /search\.disabled\s*=\s*false/);
});

test('repository picker keeps its striped loading indicator in the layout', () => {
	assert.match(
		css,
		/\.ran-booster-repository-picker__list::before\s*\{[^}]*block-size:\s*3px;[^}]*background-image:\s*repeating-linear-gradient\([^}]*content:\s*"";[^}]*opacity:\s*0;/s
	);
	assert.match(
		css,
		/\.ran-booster-repository-picker__list\.is-checking::before\s*\{[^}]*animation:\s*ran-booster-update-stripes 0\.8s linear infinite;[^}]*opacity:\s*1;/s
	);
	assert.match(
		css,
		/@media \(prefers-reduced-motion:\s*reduce\)\s*\{[^}]*\.ran-booster-repository-picker__list::before\s*\{[^}]*animation:\s*none;[^}]*transition:\s*none;/s
	);
	assert.doesNotMatch(
		css,
		/\.ran-booster-repository-picker__dialog(?:::before|\.is-checking)/
	);
	assert.doesNotMatch(
		source,
		/createElement\(['"](?:div|span)['"]\).*loading/i
	);
});

test('repository picker loading state toggles the stable host and busy state', () => {
	const setLoading = loadFunction('setRepositoryPickerLoading');
	const classes = new Set();
	const attributes = new Map();
	const list = {
		classList: {
			toggle(name, enabled) {
				if (enabled) {
					classes.add(name);
				} else {
					classes.delete(name);
				}
			},
		},
		setAttribute(name, value) {
			attributes.set(name, value);
		},
	};

	setLoading(list, true);

	assert.equal(classes.has('is-checking'), true);
	assert.equal(attributes.get('aria-busy'), 'true');

	setLoading(list, false);

	assert.equal(classes.has('is-checking'), false);
	assert.equal(attributes.get('aria-busy'), 'false');
	assert.match(
		source,
		/setRepositoryPickerLoading\(list,\s*true\);[\s\S]*requestRepositoryList\(/
	);
});
