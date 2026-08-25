import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-packages.js', import.meta.url),
	'utf8'
);

function loadFunction(name, dependencies = {}) {
	const signature = `\tfunction ${name}(`;
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `The ${name} function must exist.`);

	let depth = 0;
	let end = -1;
	for (
		let index = source.indexOf('{', start);
		index < source.length;
		index++
	) {
		if (source[index] === '{') {
			depth += 1;
		} else if (source[index] === '}') {
			depth -= 1;
			if (depth === 0) {
				end = index + 1;
				break;
			}
		}
	}

	assert.notEqual(end, -1, `The ${name} function must be complete.`);

	return Function(
		...Object.keys(dependencies),
		`"use strict"; return (${source.slice(start, end)});`
	)(...Object.values(dependencies));
}

function form({ action = '', canonical = true, enhanced = false } = {}) {
	const attributes = new Map([
		['action', action],
		['method', 'post'],
	]);
	if (enhanced) {
		attributes.set('data-ran-booster-enhanced-mutation', '');
		attributes.set('data-ran-booster-package-mutation', '');
		attributes.set('hx-post', action);
		attributes.set('hx-select', '#wpbody-content');
		attributes.set('hx-swap', 'outerHTML show:none');
		attributes.set('hx-sync', 'this:drop');
		attributes.set('hx-target', '#wpbody-content');
		attributes.set(
			'data-ran-booster-error-target',
			'#ran-booster-package-mutation-error'
		);
	} else if (canonical) {
		attributes.set('data-ran-booster-package-mutation', '');
	}

	return {
		attributes,
		getAttribute(name) {
			return attributes.get(name) ?? null;
		},
		hasAttribute(name) {
			return attributes.has(name);
		},
		querySelector(selector) {
			return canonical && selector === 'input[name="ran_booster[action]"]'
				? {}
				: null;
		},
		setAttribute(name, value) {
			attributes.set(name, String(value));
		},
	};
}

test('all canonical package operation forms inherit the shared HTMX contract', () => {
	const editForm = form();
	const adminPostForm = form({ action: '/wp-admin/admin-post.php' });
	const unrelatedForm = form({ canonical: false });
	const processed = [];
	const init = loadFunction('initPackageMutationForms', {
		document: {
			querySelectorAll(selector) {
				assert.equal(
					selector,
					'.ran-booster-admin form[method="post"][data-ran-booster-package-mutation]'
				);
				return [editForm, adminPostForm];
			},
		},
		window: {
			htmx: {
				process(candidate) {
					processed.push(candidate);
				},
			},
		},
	});

	init();

	for (const candidate of [editForm, adminPostForm]) {
		assert.equal(
			candidate.attributes.get('data-ran-booster-enhanced-mutation'),
			''
		);
		assert.equal(
			candidate.attributes.get('data-ran-booster-package-mutation'),
			''
		);
		assert.equal(candidate.attributes.get('hx-target'), '#wpbody-content');
		assert.equal(candidate.attributes.get('hx-select'), '#wpbody-content');
		assert.equal(
			candidate.attributes.get('hx-swap'),
			'outerHTML show:none'
		);
		assert.equal(candidate.attributes.get('hx-sync'), 'this:drop');
		assert.equal(
			candidate.attributes.get('data-ran-booster-error-target'),
			'#ran-booster-package-mutation-error'
		);
	}
	assert.equal(editForm.attributes.get('hx-post'), '');
	assert.equal(
		adminPostForm.attributes.get('hx-post'),
		'/wp-admin/admin-post.php'
	);
	assert.equal(
		unrelatedForm.attributes.has('data-ran-booster-enhanced-mutation'),
		false
	);
	assert.deepEqual(processed, [editForm, adminPostForm]);
});

test('an add-on package form keeps its anchored swap while Core derives the rest', () => {
	const addOnForm = form({ action: '/wp-admin/admin-post.php' });
	addOnForm.setAttribute(
		'hx-swap',
		'outerHTML show:#ran-booster-advanced-source-settings:top'
	);
	const processed = [];
	const init = loadFunction('initPackageMutationForms', {
		document: {
			querySelectorAll() {
				return [addOnForm];
			},
		},
		window: {
			htmx: {
				process(candidate) {
					processed.push(candidate);
				},
			},
		},
	});

	init();

	assert.equal(
		addOnForm.getAttribute('hx-swap'),
		'outerHTML show:#ran-booster-advanced-source-settings:top'
	);
	assert.equal(addOnForm.getAttribute('hx-post'), '/wp-admin/admin-post.php');
	assert.equal(addOnForm.getAttribute('hx-target'), '#wpbody-content');
	assert.equal(addOnForm.getAttribute('hx-select'), '#wpbody-content');
	assert.equal(addOnForm.getAttribute('hx-sync'), 'this:drop');
	assert.deepEqual(processed, [addOnForm]);
});

test('an absolute native action becomes an origin-relative HTMX post target', () => {
	const absoluteAction = 'http://localhost:10008/wp-admin/admin-post.php';
	const mutationForm = form({ action: absoluteAction });
	const init = loadFunction('initPackageMutationForms', {
		document: {
			querySelectorAll() {
				return [mutationForm];
			},
		},
		window: {
			htmx: { process() {} },
		},
	});

	init();

	assert.equal(mutationForm.getAttribute('action'), absoluteAction);
	assert.equal(
		mutationForm.getAttribute('hx-post'),
		'/wp-admin/admin-post.php'
	);
});

test('an existing package mutation contract is not reprocessed', () => {
	const enhancedForm = form({ enhanced: true });
	let processCalls = 0;
	const init = loadFunction('initPackageMutationForms', {
		document: {
			querySelectorAll() {
				return [enhancedForm];
			},
		},
		window: {
			htmx: {
				process() {
					processCalls += 1;
				},
			},
		},
	});

	init();

	assert.equal(processCalls, 0);
});

test('a package operation can explicitly retain native submission', () => {
	const nativeForm = form();
	nativeForm.attributes.set('data-ran-booster-native-submit', '');
	let processCalls = 0;
	const init = loadFunction('initPackageMutationForms', {
		document: {
			querySelectorAll() {
				return [nativeForm];
			},
		},
		window: {
			htmx: {
				process() {
					processCalls += 1;
				},
			},
		},
	});

	init();

	assert.equal(
		nativeForm.attributes.has('data-ran-booster-enhanced-mutation'),
		false
	);
	assert.equal(processCalls, 0);
});
