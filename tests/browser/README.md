# Package-settings browser checks

These are private CUA-runner helpers, not CI tests or a full WCAG claim. Run
them only against an externally marked disposable site after authentication and
test-fixture setup are complete.

```js
const checks =
	await import('file:///absolute/path/to/checkout/tests/browser/package-settings.mjs');
await checks.assertPackageSettingsContainment(tab);
await checks.assertNavigationProgress(
	tab,
	'[data-ran-booster-source-progress]'
);
await checks.assertSourceRouteKeyboardRegression(tab, {
	source: 'release_asset',
	expectedPreviousSelector: 'a[data-ran-booster-source-choice="branch"]',
});
await checks.assertNavigationProgress(
	tab,
	'[data-ran-booster-source-progress]'
);
```

Use `assertNavigationProgress` before and after real navigation for source and
provider progress (`[data-ran-booster-provider-task-progress]`). Pass `true`
as its third argument while a request is pending to check the visible busy
state. The assertion reads the actual spinner's computed visibility and checks
that the progress slot retains geometry.

Start from a saved fixture package on Branch. Current disposable fixture IDs
are plugin `a11y-branch-disabled-plugin/a11y-branch-disabled-plugin.php` and
theme `a11y-branch-disabled-theme`; injected user fixtures are also valid. The
source assertion activates the route with Enter, awaits the exact incoming
current-view marker, then checks the expected previous Shift+Tab destination.
It does not create, install, delete, or mutate a package.

## Overview Activity

After opening Overview on the prepared site, measure the rendered rows before a
change or assert their spacing, containment and badge readability afterward:

```js
const activity =
	await import('file:///absolute/path/to/checkout/tests/browser/overview-activity.mjs');
const measurements = await activity.measureOverviewActivity(tab);
await activity.assertOverviewActivity(tab, { expectedRows: 5 });
```

Use `expectedRows: 0` for an empty or unavailable Activity card. Set
`compact: false` to omit the one-line badge height check. The helper reads the
page only; include narrow layouts and long translated labels in fixture checks.
When the operation and badge share a row, the operation must retain six times
its computed font size, capped by the available row width, for readable text.
The badge can instead wrap below it.

## Page frame

`page-frame.mjs` measures the shared page frame defined in "Page frame
geometry" in `docs/admin-composition-contract.md`.

```js
const frame =
	await import('file:///absolute/path/to/checkout/tests/browser/page-frame.mjs');
await frame.assertPageFrame(tab);
```

`assertPageFrame` checks the shell gap, left edges, Card gaps and heading insets.
`measurePageFrame` returns the raw measurements for review.
