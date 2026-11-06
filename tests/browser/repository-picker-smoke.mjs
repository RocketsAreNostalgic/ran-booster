/* eslint-disable no-nested-ternary, @wordpress/no-global-active-element -- Fixture routing and focus assertions run in a browser. */
// Set RAN_BOOSTER_UI_MANIFEST, RAN_PLAYWRIGHT_MODULE, RAN_WORDPRESS_70_PATH and RAN_WORDPRESS_714_PATH, then run node tests/browser/repository-picker-smoke.mjs [output-directory].
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
const uiManifestPath = process.env.RAN_BOOSTER_UI_MANIFEST;
if (
	!modulePath ||
	!uiManifestPath ||
	!process.env.RAN_WORDPRESS_70_PATH ||
	!process.env.RAN_WORDPRESS_714_PATH
) {
	throw new Error(
		'Set RAN_BOOSTER_UI_MANIFEST, RAN_PLAYWRIGHT_MODULE, RAN_WORDPRESS_70_PATH and RAN_WORDPRESS_714_PATH to local absolute paths.'
	);
}
const { chromium } = await import(pathToFileURL(modulePath));
const uiManifest = JSON.parse(await readFile(uiManifestPath, 'utf8'));
const uiDirectory = path.dirname(uiManifestPath);
for (const name of ['foundation', 'overlay']) {
	assert.equal(
		path.basename(uiManifest[name] || ''),
		uiManifest[name],
		`Invalid UI manifest ${name}`
	);
}
const uiHashes = {};
for (const name of ['foundation', 'overlay']) {
	uiHashes[name] = createHash('sha256')
		.update(await readFile(path.join(uiDirectory, uiManifest[name])))
		.digest('hex');
}
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

function scripts(version) {
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
	visit('wp-element');
	visit('wp-i18n');
	visit('react-jsx-runtime');
	return ordered;
}
const browser = await chromium.launch({ channel: 'msedge', headless: true });
for (const version of ['7.0', '7.1.4']) {
	for (const scenario of ['ready', 'delayed', 'failed', 'missing']) {
		const available = scenario === 'ready' || scenario === 'delayed';
		const label = `${version}-${scenario}`;
		const context = await browser.newContext({
			serviceWorkers: 'block',
			viewport: { width: 1200, height: 850 },
		});
		const page = await context.newPage();
		const errors = [];
		const stylesheetResponses = [];
		const unexpectedRequests = {};
		let visualMetrics = null;
		page.on('response', (response) => {
			if (response.request().resourceType() === 'stylesheet') {
				stylesheetResponses.push({
					url: response.url(),
					status: response.status(),
				});
			}
		});
		page.on('pageerror', (error) =>
			errors.push(
				error.message.replace(/https?:\/\/[^\s)]+/g, '[redacted URL]')
			)
		);
		const pending = [];
		const uiRequests = [];
		const wpScripts = scripts(version);
		const bootstrap =
			wpScripts
				.map(
					(script) => `<script src="/wp-includes/${script}"></script>`
				)
				.join('') +
			`<script type="module" src="/ui/${uiManifest.foundation}"></script><script type="module" src="/ui/${uiManifest.overlay}"></script>` +
			(scenario === 'missing'
				? '<script type="module">if(window.ranBoosterUI) delete window.ranBoosterUI.Input;</script>'
				: '') +
			`<script type="module">const a = await import('/ui/${uiManifest.foundation}'); const b = await import('/ui/${uiManifest.foundation}'); window.foundationIdentity = a === b;</script>` +
			'<script src="/picker.js"></script>';
		await page.route('**/*', async (route) => {
			const url = new URL(route.request().url());
			if (url.origin !== 'http://picker.test') {
				const category = route.request().resourceType();
				unexpectedRequests[category] =
					(unexpectedRequests[category] || 0) + 1;
				await route.abort();
				return;
			}
			if (url.pathname.startsWith('/ui/')) {
				uiRequests.push(url.pathname + url.search);
				const name = path.basename(url.pathname);
				assert.ok(
					[uiManifest.foundation, uiManifest.overlay].includes(name)
				);
				if (name === uiManifest.overlay && scenario === 'failed') {
					await route.fulfill({ status: 404, body: '' });
					return;
				}
				if (name === uiManifest.overlay && scenario === 'delayed') {
					await new Promise((resolve) => setTimeout(resolve, 250));
				}
				await route.fulfill({
					body: await readFile(path.join(uiDirectory, name)),
					contentType: 'application/javascript',
				});
				return;
			}
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
				let body = await readFile(filename);
				if (url.pathname === '/') {
					body = body
						.toString()
						.replace('</body>', `${bootstrap}</body>`);
				}
				await route.fulfill({
					body,
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
			assert.ok(stylesheetResponses.length > 0);
			assert.ok(
				stylesheetResponses.every(
					(response) => response.status === 200
				),
				JSON.stringify(stylesheetResponses)
			);
			const exports = await page.evaluate(() => ({
				version: window.ranBoosterUI?.version,
				Dialog: typeof window.ranBoosterUI?.Dialog,
				Input: typeof window.ranBoosterUI?.Input,
				reactVersion: window.React?.version || null,
				foundationIdentity: window.foundationIdentity,
			}));
			Object.assign(runtimeFiles[version], { [scenario]: exports });
			assert.equal(exports.foundationIdentity, true);
			assert.deepEqual(
				uiRequests.sort(),
				[
					`/ui/${uiManifest.foundation}`,
					`/ui/${uiManifest.overlay}`,
				].sort()
			);
			if (available) {
				assert.equal(exports.version, '0.26.0');
				assert.equal(exports.Dialog, 'object');
				assert.ok(['function', 'object'].includes(exports.Input));
			}
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
				await page
					.getByRole('dialog', {
						name: 'Pick a plugin repository from GitHub',
					})
					.waitFor();
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
				visualMetrics = await page
					.locator('.ran-booster-repository-picker__public-search')
					.evaluate((form) => {
						const searchButton = form.querySelector('button');
						const ownerField = form.querySelector(
							'.ran-booster-repository-picker__owner'
						);
						const select = form.querySelector('select');
						const b = window.getComputedStyle(searchButton),
							s = window.getComputedStyle(select);
						const br = searchButton.getBoundingClientRect(),
							or = ownerField.getBoundingClientRect();
						return {
							button: {
								background: b.backgroundColor,
								minHeight: b.minHeight,
								height: br.height,
								paddingInline: b.paddingInline,
								centerDifference: Math.abs(
									(br.top + br.bottom - or.top - or.bottom) /
										2
								),
							},
							select: {
								width: select.getBoundingClientRect().width,
								availableWidth:
									select.parentElement.getBoundingClientRect()
										.width,
								appearance: s.appearance,
								backgroundImage: s.backgroundImage,
								backgroundPosition: s.backgroundPosition,
								paddingInlineEnd: s.paddingInlineEnd,
							},
						};
					});
				assert.notEqual(
					visualMetrics.button.background,
					'rgba(0, 0, 0, 0)',
					JSON.stringify(visualMetrics)
				);
				assert.ok(
					visualMetrics.button.height >= 30 &&
						parseFloat(visualMetrics.button.paddingInline) >= 8 &&
						visualMetrics.button.centerDifference <= 2,
					JSON.stringify(visualMetrics)
				);
				assert.equal(
					visualMetrics.select.appearance,
					'none',
					JSON.stringify(visualMetrics)
				);
				assert.notEqual(
					visualMetrics.select.backgroundImage,
					'none',
					JSON.stringify(visualMetrics)
				);
				assert.ok(
					parseFloat(visualMetrics.select.paddingInlineEnd) >= 24 &&
						visualMetrics.select.backgroundPosition !== '0% 0%',
					JSON.stringify(visualMetrics)
				);
				assert.ok(
					Math.abs(
						visualMetrics.select.width -
							visualMetrics.select.availableWidth
					) <= 2,
					JSON.stringify(visualMetrics.select)
				);
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
				await page.waitForFunction(
					() =>
						document.activeElement ===
						document.querySelector('.ran-booster-repository-input'),
					null,
					{ timeout: 2000 }
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
				await page.keyboard.press('Escape');
				await button.evaluate((node) => node.click());
				await page
					.getByRole('dialog', {
						name: 'Pick a plugin repository from GitHub',
					})
					.waitFor();
				await page.waitForTimeout(350);
				assert.equal(
					await page.evaluate(
						() =>
							!!document.activeElement.closest('[role="dialog"]')
					),
					true
				);
				await page
					.getByRole('button', { name: 'Close repository picker' })
					.click();
				await page.waitForFunction(
					() =>
						document.activeElement ===
						document.querySelector(
							'.ran-booster-open-repository-picker'
						)
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
				assert.equal(await filter.isDisabled(), true);
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
				await page.setViewportSize({ width: 320, height: 844 });
				const bounds = await page.getByRole('dialog').boundingBox();
				assert.ok(
					bounds &&
						bounds.x >= 0 &&
						bounds.x + bounds.width <= 320 &&
						bounds.y >= 0 &&
						bounds.y + bounds.height <= 844,
					JSON.stringify(bounds)
				);
				assert.equal(
					await page
						.getByRole('button', {
							name: 'Close repository picker',
						})
						.isVisible(),
					true
				);
				const closeHit = await page
					.getByRole('button', { name: 'Close repository picker' })
					.evaluate((node) => {
						const rect = node.getBoundingClientRect();
						return node.contains(
							document.elementFromPoint(
								rect.x + rect.width / 2,
								rect.y + rect.height / 2
							)
						);
					});
				assert.equal(closeHit, true);
				const canScroll = await page
					.locator('.ran-booster-repository-picker__body')
					.evaluate((node) => {
						let current = node;
						while (
							current &&
							current.scrollHeight <= current.clientHeight
						) {
							current = current.parentElement;
						}
						if (!current) {
							return false;
						}
						current.scrollTop = 25;
						return current.scrollTop > 0;
					});
				assert.equal(canScroll, true);
				await page.setViewportSize({ width: 1200, height: 850 });
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
			assert.deepEqual(unexpectedRequests, {});
			assert.deepEqual(errors, []);
			evidence.push({
				label,
				passed: true,
				unexpectedRequests,
				visualMetrics,
				stylesheetResponses,
			});
		} catch (error) {
			failed = true;
			evidence.push({
				label,
				passed: false,
				error: error.stack,
				pageErrors: errors,
				unexpectedRequests,
				visualMetrics,
				stylesheetResponses,
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
	JSON.stringify(
		{ uiManifest, uiHashes, runtimeFiles, cases: evidence },
		null,
		2
	)
);
process.stdout.write(
	`${JSON.stringify({ uiManifest, uiHashes, runtimeFiles, cases: evidence }, null, 2)}\n`
);
if (failed) {
	process.exitCode = 1;
}
