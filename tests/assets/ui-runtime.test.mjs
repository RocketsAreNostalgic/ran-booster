import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { gzipSync } from 'node:zlib';

const require = createRequire(import.meta.url);
const root = new URL('../../', import.meta.url);
const manifest = JSON.parse(
	fs.readFileSync(new URL('package.json', root), 'utf8')
);
const bundle = fs.readFileSync(
	new URL('assets/lib/ran-booster-ui.min.js', root)
);
const adapter = fs.readFileSync(new URL('assets/ran-booster-cards.js', root));

test('UI build dependencies are pinned to the installed versions', () => {
	for (const [name, expected] of [
		['@wordpress/ui', '0.26.0'],
		['esbuild', '0.25.12'],
	]) {
		assert.equal(manifest.devDependencies[name], expected);
		assert.equal(require(`${name}/package.json`).version, expected);
	}
});

test('shipped UI runtime and adapter stay within the combined budget', () => {
	assert.ok(bundle.length + adapter.length <= 80000, 'combined raw bytes');
	assert.ok(
		gzipSync(bundle).length + gzipSync(adapter).length <= 20000,
		'sum of independently compressed bytes'
	);
});

test('runtime uses host React and exposes the fixed public UI components', () => {
	const React = require('react');
	const window = {};
	const code = bundle.toString('utf8');
	assert.throws(() => vm.runInNewContext(code, { window: {} }));
	vm.runInNewContext(code, {
		window,
		React,
		ReactJSXRuntime: require('react/jsx-runtime'),
	});
	assert.deepEqual(Object.keys(window.ranBoosterUI).sort(), [
		'Badge',
		'CardRoot',
		'version',
	]);
	assert.equal(window.ranBoosterUI.version, '0.26.0');
	const render = require('react-dom/server').renderToStaticMarkup;
	assert.match(
		render(
			React.createElement(
				window.ranBoosterUI.CardRoot,
				null,
				'Card content'
			)
		),
		/Card content/
	);
	assert.match(
		render(
			React.createElement(
				window.ranBoosterUI.Badge,
				{ intent: 'stable' },
				'Ready'
			)
		),
		/Ready/
	);
});
