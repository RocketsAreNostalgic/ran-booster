import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const icons = path.join(
	fs.realpathSync('node_modules/@wordpress/ui'),
	'../icons/build-module/library'
);

test('the Settings error host renders the static public Notice', () => {
	const view = fs.readFileSync('views/settings-general.php', 'utf8');
	const host = view.match(
		/<div id="ran-booster-general-<\?php echo esc_attr\( \$scope \); \?>-error"[\s\S]*?NoticeIcon::print_intent\( 'error' \); \?><\/div>/
	);
	assert.ok(host, 'scope error host');
	assert.match(
		host[0],
		/^<div id="[^"]*" class="notice notice-error inline ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-admin-mutation-error data-ran-booster-ui-notice/
	);
	assert.match(
		host[0],
		/><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary>/
	);
	// The icon follows the content, as Notice.Root draws it after children.
	assert.match(
		host[0],
		/<\/div><\?php \\RAN\\Admin\\Component\\NoticeIcon::print_intent\( 'error' \); \?><\/div>$/
	);
	assert.doesNotMatch(host[0], /politeness|notice-list|aria-live/);
});

test('NoticeIcon draws the glyphs the installed Notice.Root uses', () => {
	const php = fs.readFileSync('RAN/Admin/Component/NoticeIcon.php', 'utf8');
	for (const [intent, glyph] of [
		['info', 'info'],
		['warning', 'caution'],
		['success', 'published'],
		['error', 'error'],
	]) {
		const source = fs.readFileSync(`${icons}/${glyph}.mjs`, 'utf8');
		const expected = Array.from(
			source.matchAll(
				/jsx\(Path, \{\s*d: "([^"]+)"(,\s*strokeLinejoin: "round")?/g
			),
			(found) => `array( '${found[1]}', ${found[2] ? 'true' : 'false'} )`
		);
		assert.ok(expected.length > 0, glyph);
		assert.match(source, /viewBox: "0 0 24 24"/);
		assert.match(source, /stroke: "currentColor",\s*strokeWidth: "1.5"/);
		const entry = php.match(
			new RegExp(`'${intent}'\\s*=> array\\(([\\s\\S]*?)\\n\\t\\t\\),`)
		);
		assert.ok(entry, intent);
		assert.deepEqual(
			Array.from(
				entry[1].matchAll(/array\( '[^']+', (?:true|false) \)/g),
				(found) => found[0]
			),
			expected,
			intent
		);
	}
});

test('static notices hand their frame to the layer and are never a fallback', () => {
	const primitives = fs.readFileSync(
		'assets/ran-booster/25-admin-primitives.css',
		'utf8'
	);
	// Inside .ran-booster-admin, and for a global notice wherever it renders.
	const frame = primitives.match(
		/\.ran-booster-admin \.notice\.ran-wp-ui-notice,\n\.notice\.ran-wp-ui-notice\[data-ran-booster-ui-notice-scope="global"\] \{([^}]*)\}/
	);
	assert.ok(frame, 'core .notice reset');
	assert.match(frame[1], /\n\tbox-sizing: border-box;/);
	for (const property of [
		'padding',
		'border',
		'border-radius',
		'box-shadow',
		'background',
	]) {
		assert.match(frame[1], new RegExp(`\\n\\t${property}: revert-layer;`));
	}
	assert.match(
		primitives,
		/\.ran-booster-admin \.ran-wp-ui-notice\[hidden\],\n\.ran-wp-ui-notice\[data-ran-booster-ui-notice-scope="global"\]\[hidden\] \{\n\tdisplay: none;\n\}/
	);
	// Core's dismiss button sits in the Notice's close-icon grid slot.
	const dismiss = primitives.match(
		/\.ran-booster-admin \.notice\.ran-wp-ui-notice > \.notice-dismiss,\n\.notice\.ran-wp-ui-notice\[data-ran-booster-ui-notice-scope="global"\]\n\t> \.notice-dismiss \{([^}]*)\}/
	);
	assert.ok(dismiss, 'dismiss button placement');
	for (const declaration of [
		'position: static;',
		'grid-column: 3;',
		'grid-row: 1;',
		'margin-inline-start: var(--wpds-dimension-gap-xs);',
	]) {
		assert.ok(dismiss[1].includes(`\n\t${declaration}`), declaration);
	}
	for (const sheet of [
		'assets/ran-booster/25-admin-primitives.css',
		'assets/ran-booster-notice-compat.css',
	]) {
		const css = fs.readFileSync(sheet, 'utf8');
		assert.doesNotMatch(
			css,
			/:not\(\[data-ran-booster-ui-mounted\]\)/,
			`${sheet}: a fallback rule would style a static notice`
		);
		assert.match(
			css,
			/:not\(\[data-ran-booster-ui-mounted\], \.ran-wp-ui-notice\)/
		);
	}
});

/*
 * PHP-rendered notice hosts that stay mounted by ran-booster-cards.js, each
 * found by a marker inside its opening tag. Hosts whose intent changes in
 * place wait for the shared per-page icon template; the others render where
 * the static Notice CSS is not guaranteed to load.
 */
const leftMounted = [
	{
		file: 'views/portability.php',
		marker: 'data-portability-export-message',
		reason: 'ran-booster-portability.js changes its intent in place',
	},
	{
		file: 'views/portability.php',
		marker: 'data-ran-booster-ui-notice-intent="info" hidden',
		reason: 'ran-booster-portability.js changes the preview notice intent in place',
	},
	{
		file: 'views/packages/branch-readiness.php',
		marker: 'id="ran-booster-repository-branch-check-error"',
		reason: 'ran-booster-enhanced-mutations.js switches warning and error in place',
	},
	{
		file: 'views/provider-credential-health.php',
		marker: 'class="notice notice-',
		reason: 'ran-booster-enhanced-mutations.js switches success to error in place',
	},
	{
		file: 'RAN/Runtime/UnsupportedMultisiteBootstrap.php',
		marker: 'data-ran-booster-unsupported-multisite-notice',
		reason: 'the Multisite safety bootstrap loads no Booster styles or scripts',
	},
	{
		file: 'RAN/Admin/ManagedPluginFailureRows.php',
		marker: 'class="update-message notice inline notice-error notice-alt"',
		reason: 'Plugins rows load the Notice styles only while the failure notice is unacknowledged, and native-update-warning.js rewrites them',
	},
];

function phpFiles(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const file = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			return phpFiles(file);
		}
		return entry.name.endsWith('.php') ? [file] : [];
	});
}

function noticeHosts() {
	const hosts = [];
	for (const file of [...phpFiles('views'), ...phpFiles('RAN')]) {
		const source = fs.readFileSync(file, 'utf8');
		const markers = Array.from(
			source.matchAll(/data-ran-booster-ui-notice(?![-\w])/g),
			(found) => found.index
		);
		markers.forEach((at, index) => {
			const open = source.lastIndexOf('<div', at);
			const content = source.indexOf(
				'data-ran-booster-ui-notice-content',
				at
			);
			const next = markers[index + 1] ?? source.length;
			hosts.push({
				file,
				tag: source.slice(open, at),
				attributes: source.slice(at, content),
				content: source.slice(Math.max(at, content - 160), content),
				body: source.slice(content, next),
			});
		});
	}
	return hosts;
}

test('every PHP-rendered notice host is a static Notice unless left mounted', () => {
	const hosts = noticeHosts();
	assert.ok(hosts.length > 60, `found ${hosts.length} hosts`);
	const allowed = new Set();
	for (const host of hosts) {
		const entry = leftMounted.find(
			(item) =>
				item.file === host.file &&
				(host.tag + host.attributes).includes(item.marker)
		);
		const label = `${host.file}: ${host.tag.slice(0, 120)}`;
		if (entry) {
			allowed.add(entry);
			assert.doesNotMatch(host.tag, /ran-wp-ui-notice/, label);
			continue;
		}
		assert.match(
			host.tag,
			/ran-wp-ui-notice ran-wp-ui-box-sizing (?:is-|<\?php)/,
			label
		);
		assert.match(
			host.content,
			/class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description"/,
			label
		);
		assert.match(
			host.body,
			/NoticeIcon::(?:print_intent|markup)\(/,
			`${label}: intent icon`
		);
		assert.doesNotMatch(
			host.tag + host.body,
			/data-wp-ui-notice-(?:politeness|list)/,
			label
		);
	}
	// Every allowlisted host still exists, so the list cannot go stale.
	assert.deepEqual(
		leftMounted.filter((item) => !allowed.has(item)),
		[]
	);
});
