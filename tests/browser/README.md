# Package-settings browser checks

These are private CUA-runner helpers, not CI tests or a full WCAG claim. Run
them only against an externally marked disposable site after authentication and
test-fixture setup are complete.

```js
const checks =
	await import('file:///absolute/path/to/checkout/tests/browser/package-settings.mjs');
await checks.assertPackageSettingsContainment(tab);
await checks.assertSourceRouteKeyboardRegression(tab, {
	source: 'release_asset',
	expectedPreviousSelector: 'a[data-ran-booster-source-choice="branch"]',
});
```

Start from a saved fixture package on Branch. Current disposable fixture IDs
are plugin `a11y-branch-disabled-plugin/a11y-branch-disabled-plugin.php` and
theme `a11y-branch-disabled-theme`; injected user fixtures are also valid. The
source assertion activates the route with Enter, awaits the exact incoming
current-view marker, then checks the expected previous Shift+Tab destination.
It does not create, install, delete, or mutate a package.
