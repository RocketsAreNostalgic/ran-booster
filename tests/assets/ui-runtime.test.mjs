import assert from 'node:assert/strict';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { createRequire } from 'node:module';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { gzipSync } from 'node:zlib';

const require = createRequire(import.meta.url);
const root = new URL('../../', import.meta.url);
const manifest = JSON.parse(
	fs.readFileSync(new URL('package.json', root), 'utf8')
);
const assets = new URL('assets/lib/', root);
const entries = JSON.parse(
	fs.readFileSync(new URL('ran-booster-ui-manifest.json', assets), 'utf8')
);
const foundation = fs.readFileSync(new URL(entries.foundation, assets));
const overlay = fs.readFileSync(new URL(entries.overlay, assets));
const adapter = fs.readFileSync(new URL('assets/ran-booster-cards.js', root));
function packageVersion(name) {
	let dir = path.dirname(require.resolve(name));
	while (!fs.existsSync(path.join(dir, 'package.json'))) {
		dir = path.dirname(dir);
	}
	return JSON.parse(fs.readFileSync(path.join(dir, 'package.json'), 'utf8'))
		.version;
}

test('UI build dependencies are pinned to installed versions', () => {
	for (const [name, expected] of [
		['@wordpress/ui', '0.26.0'],
		['esbuild', '0.25.12'],
		['rollup', '4.68.0'],
		['@rollup/plugin-node-resolve', '16.0.3'],
		['@rollup/plugin-commonjs', '29.0.3'],
	]) {
		assert.equal(manifest.devDependencies[name], expected);
		assert.equal(packageVersion(name), expected);
	}
});

test('manifest identifies two canonical assets and separate page budgets', () => {
	assert.deepEqual(Object.keys(entries).sort(), ['foundation', 'overlay']);
	assert.match(entries.foundation, /^ran-booster-ui-foundation-[\w-]+\.js$/);
	assert.match(entries.overlay, /^ran-booster-ui-overlay-[\w-]+\.js$/);
	assert.ok(
		foundation.length + adapter.length <= 139980,
		'foundation and adapter raw'
	);
	assert.ok(
		gzipSync(foundation).length + gzipSync(adapter).length <= 29780,
		'foundation and adapter gzip sum'
	);
	assert.ok(overlay.length <= 320000, 'overlay raw');
	assert.ok(gzipSync(overlay).length <= 90000, 'overlay gzip');
	assert.ok(
		overlay.toString('utf8').includes(`./${entries.foundation}`),
		'overlay imports canonical foundation'
	);
});

test('host React loads the foundation once and overlay extends the same object', async () => {
	const React = require('react');
	globalThis.window = {};
	globalThis.React = React;
	globalThis.ReactDOM = require('react-dom');
	globalThis.ReactJSXRuntime = require('react/jsx-runtime');
	const translated = [];
	globalThis.wp = {
		i18n: {
			__: (message) => {
				translated.push(message);
				return message === 'Close' ? 'Fermer' : message;
			},
			isRTL: () => false,
		},
		a11y: { speak: () => {} },
	};
	try {
		await import(
			pathToFileURL(fileURLToPath(new URL(entries.foundation, assets)))
				.href
		);
		const ui = globalThis.window.ranBoosterUI;
		assert.deepEqual(Object.keys(ui).sort(), [
			'Button',
			'CollapsibleCard',
			'NoticeDescription',
			'NoticeRoot',
			'Switch',
			'createRepositoryNavigationBridge',
			'createSectionNavigation',
			'version',
		]);
		assert.equal(ui.version, '0.26.0');
		assert.ok(ui.Switch);
		assert.deepEqual(Object.keys(ui.CollapsibleCard).sort(), [
			'Content',
			'Header',
			'Root',
		]);
		await import(
			pathToFileURL(fileURLToPath(new URL(entries.overlay, assets))).href
		);
		assert.equal(globalThis.window.ranBoosterUI, ui);
		assert.equal(ui.CardRoot, undefined);
		assert.deepEqual(Object.keys(ui.Dialog).sort(), [
			'CloseIconButton',
			'Content',
			'Description',
			'Header',
			'Popup',
			'Portal',
			'Root',
			'Title',
		]);
		assert.ok(ui.Input);
		const render = require('react-dom/server').renderToStaticMarkup;
		const switchMarkup = render(
			React.createElement(ui.Switch, { checked: true })
		);
		assert.match(switchMarkup, /role="switch"/);
		assert.match(switchMarkup, /aria-checked="true"/);
		const button = render(
			React.createElement(
				ui.Button,
				{
					type: 'submit',
					name: 'mode',
					value: 'compact',
					disabled: true,
					focusableWhenDisabled: false,
				},
				'Compact'
			)
		);
		assert.match(button, /<button[^>]*type="submit"/);
		assert.match(button, /name="mode"/);
		assert.match(button, /value="compact"/);
		assert.match(button, /disabled=""/);
		const content = React.createElement(
			ui.NoticeDescription,
			{ render: React.createElement('div') },
			React.createElement('p', null, 'Error details')
		);
		const html = render(
			React.createElement(ui.NoticeRoot, { intent: 'error' }, content)
		);
		assert.match(html, /<div[^>]*><p>Error details<\/p><\/div>/);
		assert.doesNotMatch(html, /role="alert"/);
		const close = render(
			React.createElement(
				ui.Dialog.Root,
				null,
				React.createElement(ui.Dialog.CloseIconButton)
			)
		);
		assert.ok(translated.includes('Close'));
		assert.match(close, /aria-label="Fermer"/);
	} finally {
		delete globalThis.window;
		delete globalThis.React;
		delete globalThis.ReactDOM;
		delete globalThis.ReactJSXRuntime;
		delete globalThis.wp;
	}
});

test('foundation fails before publishing controls when either WordPress host service is missing', () => {
	const script = `
		import React from 'react';
		import * as ReactDOM from 'react-dom';
		import * as JSX from 'react/jsx-runtime';
		globalThis.window = {}; globalThis.React = React; globalThis.ReactDOM = ReactDOM; globalThis.ReactJSXRuntime = JSX;
		globalThis.wp = { i18n: {__: x => x}, a11y: {speak() {}} };
		if (process.argv[2] === 'i18n') delete globalThis.wp.i18n.__;
		if (process.argv[2] === 'a11y') delete globalThis.wp.a11y.speak;
		try { await import(process.argv[1]); process.exitCode = 2; }
		catch (error) {
			if (!error.message.includes('Missing WordPress host service') || window.ranBoosterUI !== undefined) process.exitCode = 3;
		}
	`;
	for (const missing of ['i18n', 'a11y']) {
		const run = spawnSync(
			process.execPath,
			[
				'--input-type=module',
				'-e',
				script,
				new URL(entries.foundation, assets).href,
				missing,
				new URL(entries.overlay, assets).href,
			],
			{ cwd: fileURLToPath(root), encoding: 'utf8' }
		);
		assert.equal(run.status, 0, `${missing}: ${run.stderr}`);
	}
});
