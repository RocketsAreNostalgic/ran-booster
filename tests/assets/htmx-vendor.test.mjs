import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const packageRuntime = fs.readFileSync(
	new URL('../../node_modules/htmx.org/dist/htmx.min.js', import.meta.url)
);
const packageLicense = fs.readFileSync(
	new URL('../../node_modules/htmx.org/LICENSE', import.meta.url)
);
const bundledRuntime = fs.readFileSync(
	new URL('../../assets/lib/htmx/htmx.min.js', import.meta.url)
);
const bundledLicense = fs.readFileSync(
	new URL('../../assets/lib/htmx/LICENSE', import.meta.url)
);

test('bundled HTMX runtime and license match the pinned package', () => {
	assert.deepEqual(bundledRuntime, packageRuntime);
	assert.deepEqual(bundledLicense, packageLicense);
});
