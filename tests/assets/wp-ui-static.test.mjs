import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { extract } from '../../tools/wp-ui-static/extract.mjs';

const uiRoot = 'node_modules/@wordpress/ui';
const css = fs.readFileSync('assets/lib/ran-booster-wp-ui-static.css', 'utf8');
const manifest = JSON.parse(
	fs.readFileSync('assets/lib/ran-booster-wp-ui-static.json', 'utf8')
);

test('committed static styles match a fresh extraction', async () => {
	const fresh = await extract({ uiRoot, prefix: 'ran-wp-ui' });
	assert.equal(css, fresh.css);
	assert.deepEqual(manifest, fresh.manifest);
});

test('the manifest records the installed @wordpress/ui version', () => {
	const { version } = JSON.parse(
		fs.readFileSync(`${uiRoot}/package.json`, 'utf8')
	);
	assert.equal(manifest.source.version, version);
	assert.match(css, new RegExp(`from @wordpress/ui ${version} `));
});

test('class names follow the Gutenberg component convention', () => {
	assert.doesNotMatch(css, /[0-9a-f]{16}__/, 'a hashed class leaked');
	const classes = new Set(css.match(/\.[a-z_][\w-]*/g) ?? []);
	for (const selector of classes) {
		const name = selector.slice(1);
		const owned = name.startsWith(`${manifest.prefix}-`);
		const state = /^(?:is|has)-[a-z0-9-]+$/.test(name);
		assert.ok(owned || state, `Unexpected class ${selector}`);
		assert.match(
			name,
			/^[a-z][a-z0-9-]*(?:__[a-z0-9-]+)?$/,
			`${selector} is not lowercase-hyphen block__element`
		);
	}
	for (const found of css.matchAll(/(.)\.((?:is|has)-[a-z0-9-]+)/g)) {
		assert.ok(
			/[a-z0-9]/.test(found[1]),
			`State .${found[2]} must compound onto a block class`
		);
	}
});

test('every manifest class exists in the stylesheet', () => {
	for (const entry of Object.values(manifest.components)) {
		assert.ok(css.includes(`.${entry.block}`), entry.block);
		for (const element of entry.elements) {
			const expected =
				entry.block.endsWith(element) &&
				entry.elements.length === 1 &&
				entry.block === `${manifest.prefix}-${element}`
					? `.${entry.block}`
					: `.${entry.block}__${element}`;
			assert.ok(css.includes(expected), expected);
		}
		for (const state of entry.states) {
			assert.ok(css.includes(`.${entry.block}.${state}`), state);
		}
	}
});

test('the upstream prefix produces the same stylesheet apart from the namespace', async () => {
	const upstream = await extract({ uiRoot, prefix: 'wp-ui' });
	assert.equal(upstream.css.replaceAll('.wp-ui-', '.ran-wp-ui-'), css);
});
