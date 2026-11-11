/**
 * CUA DOM-tab adapter for focused package-settings browser regressions.
 * These assertions inspect rendered geometry and focus, never CSS source.
 */
export async function assertNavigationProgress(tab, selector, busy = false) {
	return tab.playwright.evaluate(
		({ selector, busy }) => {
			const progress = document.querySelector(selector);
			const spinner = progress?.querySelector('.spinner.is-active');
			if (!progress || !spinner)
				throw new Error('Progress spinner is missing.');
			const rect = progress.getBoundingClientRect();
			const spinnerStyle = getComputedStyle(spinner);
			const progressStyle = getComputedStyle(progress);
			if (
				rect.width <= 0 ||
				rect.height <= 0 ||
				progressStyle.display === 'none'
			) {
				throw new Error('Progress slot is not reserved.');
			}
			if ((spinnerStyle.visibility === 'visible') !== busy) {
				throw new Error(
					`Spinner visibility is ${spinnerStyle.visibility}; busy is ${busy}.`
				);
			}
			return {
				width: rect.width,
				height: rect.height,
				visibility: spinnerStyle.visibility,
			};
		},
		{ selector, busy }
	);
}

export async function assertSourceRouteKeyboardRegression(
	tab,
	{
		source = 'release_asset',
		expectedCurrentId = `ran-booster-source-tab-${source}`,
		expectedNextSelector,
		expectedPreviousSelector,
	} = {}
) {
	await tab.playwright.waitForLoadState({ state: 'load' });
	await tab.playwright
		.locator(
			'[data-ran-booster-source-controls][data-ran-booster-source-tabs-bound="true"]'
		)
		.waitFor({ state: 'visible' });
	const sourceControl = tab.playwright.locator(
		`[data-ran-booster-source-choice="${source}"]`
	);
	const currentView = tab.playwright.locator(
		`span#${expectedCurrentId}[aria-current="page"]`
	);

	await sourceControl.press('Enter');
	await currentView.waitFor({ state: 'visible' });

	const afterSwap = await activeElementState(tab);
	assertEqual(afterSwap.id, expectedCurrentId, 'HTMX did not restore focus.');
	assertEqual(
		afterSwap.tagName,
		'SPAN',
		'Current source view is not a span.'
	);

	await tab.pressKey(null, 'Tab');
	const afterTab = await activeElementState(tab, expectedNextSelector);
	assert(
		afterTab.id !== expectedCurrentId &&
			afterTab.inWpBody &&
			(!expectedNextSelector || afterTab.matchesExpectedSelector),
		'Tab left the replaced settings region or reached an unexpected control.'
	);

	// tabindex=-1 supports HTMX restoration but is excluded from Tab order.
	await currentView.press('Shift+Tab');
	const afterShiftTab = await activeElementState(
		tab,
		expectedPreviousSelector
	);
	assert(
		afterShiftTab.inWpBody &&
			(!expectedPreviousSelector ||
				afterShiftTab.matchesExpectedSelector),
		'Shift+Tab did not reach the expected preceding control.'
	);

	return { afterShiftTab, afterSwap, afterTab };
}

export async function assertPackageSettingsContainment(tab) {
	await tab.playwright.waitForLoadState({ state: 'load' });
	return tab.playwright.evaluate(() => {
		const assert = (condition, message) => {
			if (!condition) throw new Error(message);
		};
		const main = document.querySelector(
			'.ran-booster-package-settings__main'
		);
		assert(main, 'Package settings main region is missing.');
		assert(
			document.documentElement.scrollWidth <= window.innerWidth + 1,
			'Package page causes horizontal viewport overflow.'
		);

		const mainRect = main.getBoundingClientRect();
		const visible = (element) => {
			const style = getComputedStyle(element);
			const rect = element.getBoundingClientRect();
			return (
				style.display !== 'none' &&
				style.visibility !== 'hidden' &&
				rect.width > 0 &&
				rect.height > 0
			);
		};
		const assertInside = (element, label) => {
			const rect = element.getBoundingClientRect();
			const parentRect =
				element.parentElement?.getBoundingClientRect() || mainRect;
			assert(
				rect.left >= parentRect.left - 1 &&
					rect.right <= parentRect.right + 1,
				`${label} escapes its parent region.`
			);
			assert(
				element.scrollWidth <= element.clientWidth + 2,
				`${label} has horizontal overflow.`
			);
		};

		main.querySelectorAll('button, .button').forEach((button) => {
			if (visible(button))
				assertInside(button, `Button ${button.textContent.trim()}`);
		});
		main.querySelectorAll(
			'.ran-booster-package-danger-zone__actions label'
		).forEach((label) => {
			if (visible(label))
				assertInside(label, `Danger label ${label.textContent.trim()}`);
		});

		const reinstall = main.querySelector(
			'[data-ran-booster-settings-reinstall]'
		);
		if (reinstall && reinstall.getAttribute('aria-disabled') === 'true') {
			const reason = document.getElementById(
				reinstall.getAttribute('aria-describedby') || ''
			);
			assert(
				reason?.getAttribute('role') === 'tooltip',
				'A disabled Reinstall must describe itself with a reason tooltip.'
			);
		}
		assert(
			!main.querySelector('[data-ran-booster-reinstall-guidance]'),
			'The inline Reinstall guidance line is removed.'
		);

		return { mainWidth: mainRect.width, viewportWidth: window.innerWidth };
	});
}

async function activeElementState(tab, expectedSelector = '') {
	return tab.playwright.evaluate((selector) => {
		const active = document.activeElement;
		return {
			id: active?.id || '',
			inWpBody: Boolean(active?.closest?.('#wpbody-content')),
			matchesExpectedSelector: Boolean(
				selector && active?.matches?.(selector)
			),
			tagName: active?.tagName || '',
		};
	}, expectedSelector);
}

function assert(condition, message) {
	if (!condition) throw new Error(message);
}

function assertEqual(actual, expected, message) {
	assert(
		actual === expected,
		`${message} Expected ${expected}; received ${actual}.`
	);
}
