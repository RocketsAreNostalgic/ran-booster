/* eslint-disable no-nested-ternary, @wordpress/no-global-active-element -- Fixture routing and focus assertions run in a browser. */
// Set RAN_PLAYWRIGHT_MODULE, RAN_WORDPRESS_70_PATH and RAN_WORDPRESS_714_PATH, then run node tests/browser/repository-picker-smoke.mjs [output-directory].
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { pathToFileURL, fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import os from 'node:os';

const root = path.resolve(
	path.dirname(fileURLToPath(import.meta.url)),
	'../..'
);
const output =
	process.argv[2] || path.join(os.tmpdir(), 'booster-632-evidence');
const modulePath = process.env.RAN_PLAYWRIGHT_MODULE;
if (
	!modulePath ||
	!process.env.RAN_WORDPRESS_70_PATH ||
	!process.env.RAN_WORDPRESS_714_PATH
) {
	throw new Error(
		'Set RAN_PLAYWRIGHT_MODULE, RAN_WORDPRESS_70_PATH and RAN_WORDPRESS_714_PATH to local absolute paths.'
	);
}
const { chromium } = await import(pathToFileURL(modulePath));
const distributions = {
	'7.0': process.env.RAN_WORDPRESS_70_PATH,
	'7.1.4': process.env.RAN_WORDPRESS_714_PATH,
};
await mkdir(output, { recursive: true });
const evidence = [];
const runtimeFiles = {};
for (const [version, directory] of Object.entries(distributions)) {
	const versionFile = await readFile(
		path.join(directory, 'wp-includes/version.php'),
		'utf8'
	);
	const match = versionFile.match(/\$wp_version\s*=\s*['"]([^'"]+)['"]/);
	assert.ok(match, `No WordPress version in ${directory}`);
	assert.equal(
		match[1],
		version,
		`Unexpected WordPress version in ${directory}`
	);
	const files = [
		'js/dist/components.js',
		'js/dist/components.min.js',
		'js/dist/element.js',
		'js/dist/element.min.js',
		'css/dist/components/style.min.css',
	];
	const sha256 = {};
	for (const file of files) {
		sha256[file] = createHash('sha256')
			.update(await readFile(path.join(directory, 'wp-includes', file)))
			.digest('hex');
	}
	runtimeFiles[version] = { actualVersion: match[1], sha256 };
}
let failed = false;

function scripts(version, includeComponents = true) {
	const base = `${distributions[version]}/wp-includes`;
	const registry = JSON.parse(
		execFileSync(
			'php',
			[
				'-r',
				'echo json_encode(require $argv[1]);',
				`${base}/assets/script-loader-packages.php`,
			],
			{ encoding: 'utf8' }
		)
	);
	const ordered = [],
		seen = new Set();
	function visit(name) {
		if (seen.has(name)) {
			return;
		}
		seen.add(name);
		if (
			[
				'react',
				'react-dom',
				'react-jsx-runtime',
				'moment',
				'lodash',
				'regenerator-runtime',
			].includes(name)
		) {
			ordered.push(`js/dist/vendor/${name}.min.js`);
			return;
		}
		assert.ok(name.startsWith('wp-'), `Unexpected dependency ${name}`);
		const file = `${name.slice(3)}.js`,
			entry = registry[file];
		assert.ok(entry, `Missing dependency ${file}`);
		for (const dep of entry.dependencies) {
			visit(dep);
		}
		ordered.push(`js/dist/${name.slice(3)}.min.js`);
	}
	visit(includeComponents ? 'wp-components' : 'wp-i18n');
	return ordered;
}
const browser = await chromium.launch({ channel: 'msedge', headless: true });
for (const version of ['7.0', '7.1.4']) {
	for (const available of [true, false]) {
		const label = `${version}-${available ? 'components' : 'fallback'}`;
		const context = await browser.newContext({
			viewport: { width: 1200, height: 850 },
		});
		const page = await context.newPage();
		const errors = [];
		page.on('pageerror', (error) => errors.push(error.message));
		const pending = [];
		await page.route('http://picker.test/**', async (route) => {
			const url = new URL(route.request().url());
			if (url.pathname === '/ajax') {
				const params = new URLSearchParams(route.request().postData());
				const owner = params.get('owner') || 'accessible';
				const provider = params.get('provider');
				const response = {
					success: true,
					data: {
						repositories: [
							{
								provider,
								locator: `${owner}/alpha`,
								provider_repository_id: '123',
								default_branch: 'main',
								credential_id: 'profile',
							},
							{
								provider,
								locator: `${owner}/beta`,
								provider_repository_id: '456',
								default_branch: 'trunk',
								credential_id: 'profile',
							},
						],
						public_lookup_profile_id: 'profile',
					},
				};
				if (owner.startsWith('delay')) {
					pending.push({ route, response });
					return;
				}
				await route.fulfill({ json: response });
				return;
			}
			const relative =
				url.pathname === '/'
					? 'tests/fixtures/repository-picker.html'
					: url.pathname === '/picker.js'
						? 'assets/ran-booster-repository-picker.js'
						: url.pathname.startsWith('/css/')
							? `assets/ran-booster/${path.basename(url.pathname)}`
							: null;
			const filename = relative
				? path.join(root, relative)
				: path.join(
						distributions[version],
						url.pathname.replace(/^\//, '')
					);
			try {
				await route.fulfill({
					body: await readFile(filename),
					contentType: filename.endsWith('.js')
						? 'application/javascript'
						: filename.endsWith('.css')
							? 'text/css'
							: 'text/html',
				});
			} catch {
				await route.fulfill({ status: 404, body: '' });
			}
		});
		try {
			await page.goto('http://picker.test/');
			for (const script of scripts(version, available)) {
				await page.addScriptTag({ url: `/wp-includes/${script}` });
			}
			const exports = await page.evaluate(() => ({
				Modal: typeof window.wp?.components?.Modal,
				SearchControl: typeof window.wp?.components?.SearchControl,
				createRoot: typeof window.wp?.element?.createRoot,
				createPortal: typeof window.wp?.element?.createPortal,
				flushSync: typeof window.wp?.element?.flushSync,
				reactVersion: window.React?.version || null,
			}));
			Object.assign(runtimeFiles[version], {
				[available ? 'componentsExports' : 'fallbackExports']: exports,
			});
			if (available) {
				for (const name of ['Modal', 'SearchControl']) {
					assert.ok(
						['function', 'object'].includes(exports[name]),
						`Missing React component ${name}`
					);
				}
				for (const name of [
					'createRoot',
					'createPortal',
					'flushSync',
				]) {
					assert.equal(
						exports[name],
						'function',
						`Missing runtime export ${name}`
					);
				}
			} else {
				assert.equal(exports.Modal, 'undefined');
			}
			await page.addScriptTag({ url: '/picker.js' });
			const button = page.locator('.ran-booster-open-repository-picker');
			if (!available) {
				assert.equal(await button.isHidden(), true);
				assert.equal(await button.isDisabled(), true);
				await page
					.locator('.ran-booster-repository-input')
					.fill('manual/repo');
				assert.equal(
					await page
						.locator('.ran-booster-repository-input')
						.inputValue(),
					'manual/repo'
				);
			} else {
				await button.click();
				const owner = page.locator(
					'.ran-booster-repository-picker__owner'
				);
				await owner.waitFor({ state: 'visible' });
				assert.equal(
					await owner.evaluate(
						(node) => node === document.activeElement
					),
					true
				);
				const filter = page.getByRole('searchbox', {
					name: 'Filter repository results',
				});
				assert.equal(await filter.isDisabled(), true);
				await owner.fill('org');
				await page
					.locator(
						'.ran-booster-repository-picker__public-search button'
					)
					.click();
				await page
					.getByRole('button', { name: /org\/alpha/ })
					.waitFor();
				await page.waitForTimeout(300);
				await page
					.locator('.ran-booster-repository-picker__body')
					.evaluate((node) => {
						for (
							let current = node;
							current;
							current = current.parentElement
						) {
							current.scrollTop = 0;
						}
					});
				await page.screenshot({
					path: path.join(output, `${label}-open.png`),
				});
				await filter.fill('beta');
				assert.equal(
					await page
						.getByRole('button', { name: /org\/alpha/ })
						.count(),
					0
				);
				await filter.fill('');
				await page.getByRole('button', { name: /org\/alpha/ }).click();
				assert.equal(
					await page
						.locator('.ran-booster-repository-input')
						.inputValue(),
					'org/alpha'
				);
				assert.equal(
					await page
						.locator('.ran-booster-provider-repository-id-input')
						.inputValue(),
					'123'
				);
				assert.equal(
					await page
						.locator(
							'.ran-booster-provider-repository-identity-source-input'
						)
						.inputValue(),
					'picker'
				);
				assert.equal(
					await page
						.locator('.ran-booster-branch-input')
						.inputValue(),
					'main'
				);
				assert.equal(
					await page
						.locator('.ran-booster-repository-input')
						.evaluate((node) => node === document.activeElement),
					true
				);
				assert.ok(
					(await page.evaluate(() => window.contextChanges)).includes(
						'picker'
					)
				);
				await button.click();
				await page.keyboard.press('Escape');
				await page.waitForFunction(
					() =>
						document.activeElement ===
						document.querySelector(
							'.ran-booster-open-repository-picker'
						),
					null,
					{ timeout: 2000 }
				);
				await button.click();
				await page
					.getByRole('button', { name: /Accessible repositories/ })
					.click();
				await page
					.getByRole('button', { name: /accessible\/alpha/ })
					.click();
				assert.equal(
					await page
						.locator('.ran-booster-credential-input')
						.inputValue(),
					'profile'
				);
				await button.click();
				await page
					.getByRole('button', { name: /Public repositories/ })
					.click();
				await owner.fill('delay-old');
				await page
					.locator(
						'.ran-booster-repository-picker__public-search button'
					)
					.click();
				await page.waitForFunction(
					() =>
						document
							.querySelector(
								'.ran-booster-repository-picker__list'
							)
							.getAttribute('aria-busy') === 'true'
				);
				await owner.fill('new');
				await page
					.locator(
						'.ran-booster-repository-picker__public-search button'
					)
					.click();
				await page
					.getByRole('button', { name: /new\/alpha/ })
					.waitFor();
				assert.equal(pending.length, 1);
				await pending.shift().route.fulfill({
					json: {
						success: true,
						data: {
							repositories: [
								{
									provider: 'gh',
									locator: 'delay-old/alpha',
								},
							],
						},
					},
				});
				await page.waitForTimeout(100);
				assert.equal(
					await page
						.getByRole('button', { name: /delay-old\/alpha/ })
						.count(),
					0
				);
				await owner.fill('delay-reset');
				await page
					.locator(
						'.ran-booster-repository-picker__public-search button'
					)
					.click();
				await page.waitForFunction(
					() =>
						document
							.querySelector(
								'.ran-booster-repository-picker__list'
							)
							.getAttribute('aria-busy') === 'true'
				);
				await page
					.locator('.ran-booster-repository-picker__public-profile')
					.selectOption('profile');
				assert.equal(pending.length, 1);
				await pending.shift().route.fulfill({
					json: {
						success: true,
						data: {
							repositories: [
								{
									provider: 'gh',
									locator: 'delay-reset/alpha',
								},
							],
						},
					},
				});
				await page.waitForTimeout(100);
				assert.equal(
					await page
						.getByRole('button', { name: /delay-reset\/alpha/ })
						.count(),
					0
				);
				await owner.fill('delay-close');
				await page
					.locator(
						'.ran-booster-repository-picker__public-search button'
					)
					.click();
				await page.waitForFunction(
					() =>
						document
							.querySelector(
								'.ran-booster-repository-picker__list'
							)
							.getAttribute('aria-busy') === 'true'
				);
				await page.keyboard.press('Escape');
				assert.equal(pending.length, 1);
				await pending.shift().route.fulfill({
					json: {
						success: true,
						data: {
							repositories: [
								{
									provider: 'gh',
									locator: 'delay-close/alpha',
								},
							],
						},
					},
				});
				await button.click();
				await page.waitForTimeout(100);
				assert.equal(
					await page
						.getByRole('button', { name: /delay-close\/alpha/ })
						.count(),
					0
				);
				await page.keyboard.press('Tab');
				assert.equal(
					await page.evaluate(
						() =>
							!!document.activeElement.closest('[role="dialog"]')
					),
					true
				);
				await page.screenshot({
					path: path.join(output, `${label}.png`),
				});
				await page.evaluate(() => {
					const target = document.getElementById('wpbody-content');
					document.dispatchEvent(
						new CustomEvent('htmx:beforeSwap', {
							detail: { target },
						})
					);
					target.innerHTML = target.innerHTML;
					document.dispatchEvent(
						new CustomEvent('htmx:afterSwap', {
							detail: { target },
						})
					);
				});
				assert.equal(await page.getByRole('dialog').count(), 0);
				for (const item of pending.splice(0)) {
					await item.route.fulfill({ json: item.response });
				}
				await page
					.locator('.ran-booster-open-repository-picker')
					.click();
				assert.equal(await page.getByRole('dialog').count(), 1);
				await page.waitForTimeout(100);
				assert.equal(
					await page
						.getByRole('button', { name: /delay-close\/alpha/ })
						.count(),
					0
				);
			}
			assert.deepEqual(errors, []);
			evidence.push({ label, passed: true });
		} catch (error) {
			failed = true;
			evidence.push({
				label,
				passed: false,
				error: error.stack,
				pageErrors: errors,
			});
			await page
				.screenshot({ path: path.join(output, `${label}-failure.png`) })
				.catch(() => {});
		} finally {
			for (const item of pending) {
				await item.route.abort().catch(() => {});
			}
			await context.close();
		}
	}
}
await browser.close();
await writeFile(
	path.join(output, 'repository-picker-smoke.json'),
	JSON.stringify({ runtimeFiles, cases: evidence }, null, 2)
);
process.stdout.write(
	`${JSON.stringify({ runtimeFiles, cases: evidence }, null, 2)}\n`
);
if (failed) {
	process.exitCode = 1;
}
