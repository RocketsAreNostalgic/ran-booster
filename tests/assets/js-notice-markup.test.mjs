import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (name) =>
	fs.readFileSync(new URL(`../../assets/${name}`, import.meta.url), 'utf8');

// A script-made notice is the static Notice: it comes from
// ranBoosterNotice.create, never from hand-set classes, and nothing asks the
// retired React mount to redraw it.
const handBuilt =
	/'notice (?:notice-|inline)[^']*'|data-ran-booster-ui-notice(?:-intent)?[=',]|setAttribute\(\s*'data-ran-booster-ui-notice(?:-intent|-scope|-content)?'/;

test('portability apply results are created as static Notices', () => {
	const source = read('ran-booster-portability.js');
	assert.match(
		source,
		/window\.ranBoosterNotice\.create\(resultType, \{\s*className: 'ran-booster-portability__apply-result',\s*\}\)/
	);
	assert.doesNotMatch(source, handBuilt);
	assert.doesNotMatch(source, /ui-notice-update/);
	// A result redrawn for a new status changes intent in place.
	assert.match(
		source,
		/if \(rendered\) \{\s*window\.ranBoosterNotice\.setIntent\(item, resultType\);/
	);
});

test('the release candidate warning is created as a static Notice', () => {
	const source = read('ran-booster-release-management.js');
	assert.match(
		source,
		/window\.ranBoosterNotice\.create\('warning', \{\s*className: 'ran-booster-release-candidate__warning',\s*scope: 'global',\s*\}\)/
	);
	assert.doesNotMatch(source, handBuilt);
	assert.doesNotMatch(source, /ui-notice-update/);
});
