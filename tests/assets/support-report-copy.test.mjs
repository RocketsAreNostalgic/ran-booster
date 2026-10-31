import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function fixture(clipboard) {
	const listeners = new Map();
	const timers = new Map();
	let nextTimer = 0;
	vm.runInNewContext(source, {
		wp: { i18n: {} },
		document: {
			readyState: 'loading',
			addEventListener(name, callback) {
				listeners.set(name, callback);
			},
		},
		window: {
			navigator: { clipboard },
			setTimeout(callback, delay) {
				assert.equal(delay, 2000);
				nextTimer += 1;
				timers.set(nextTimer, callback);
				return nextTimer;
			},
			clearTimeout(id) {
				timers.delete(id);
			},
		},
	});
	const click = listeners.get('click');
	click.timers = timers;
	return click;
}

function report(value) {
	const input = {
		value,
		isConnected: true,
		focused: false,
		selected: false,
		focus() {
			this.focused = true;
		},
		select() {
			this.selected = true;
		},
	};
	const status = {
		textContent: '',
		dataset: {
			copiedMessage: 'Support report copied to the clipboard.',
			copyFailedMessage:
				'Clipboard access failed. Use your browser copy command.',
		},
	};
	const root = {
		querySelector(selector) {
			return selector === 'textarea' ? input : status;
		},
	};
	const classes = new Set(['dashicons', 'dashicons-clipboard']);
	const icon = {
		classList: {
			replace(before, after) {
				if (classes.delete(before)) {
					classes.add(after);
				}
			},
		},
	};
	const copy = {
		disabled: false,
		closest: () => root,
		querySelector: () => icon,
	};
	return {
		input,
		status,
		copy,
		classes,
		event: {
			target: {
				closest: (selector) =>
					selector === '[data-support-report-copy]' ? copy : null,
			},
		},
	};
}

test('copy scopes each control to its current report field and message', async () => {
	const copied = [];
	const click = fixture({ writeText: async (value) => copied.push(value) });
	const diagnostic = report('diagnostic\nreport');
	const capture = report('captured\nevents');
	capture.status.dataset.copiedMessage =
		'Captured events copied to the clipboard.';
	await click(capture.event);
	await click(diagnostic.event);
	assert.deepEqual(copied, ['captured\nevents', 'diagnostic\nreport']);
	assert.equal(
		capture.status.textContent,
		'Captured events copied to the clipboard.'
	);
	assert.equal(
		diagnostic.status.textContent,
		diagnostic.status.dataset.copiedMessage
	);
});

test('a denied captured-events copy selects only its own field', async () => {
	const click = fixture({
		writeText: async () => {
			throw new Error('denied');
		},
	});
	const diagnostic = report('diagnostic report');
	const capture = report('captured events');
	capture.status.dataset.copyFailedMessage =
		'Clipboard access failed. The captured events are selected; use your browser copy command.';
	await click(capture.event);
	assert.equal(capture.input.selected, true);
	assert.equal(
		capture.status.textContent,
		capture.status.dataset.copyFailedMessage
	);
	assert.equal(diagnostic.input.selected, false);
	assert.equal(diagnostic.status.textContent, '');
});

test('only an explicit copy action writes the current multiline report after repeated replacements', async () => {
	const copied = [];
	const click = fixture({ writeText: async (value) => copied.push(value) });
	assert.deepEqual(copied, []);
	await click({ target: { closest: () => null } });
	assert.deepEqual(copied, []);
	for (let run = 0; run < 4; run += 1) {
		const current = report(
			`GitHub provider\nsource: bundled\n\nBooster Core updates\nmode: off\nresults: ${run}\n`
		);
		await click(current.event);
		assert.equal(copied[run], current.input.value);
		assert.equal(
			current.status.textContent,
			current.status.dataset.copiedMessage
		);
		assert.equal(current.input.focused, false);
		assert.equal(current.copy.disabled, false);
	}
});

test('copy reads the textarea at activation and announces success only after clipboard completion', async () => {
	let finish;
	let copied;
	const click = fixture({
		writeText(value) {
			copied = value;
			return new Promise((resolve) => {
				finish = resolve;
			});
		},
	});
	const current = report('old');
	current.input.value = 'current\nreport';
	current.status.textContent = current.status.dataset.copiedMessage;
	const pending = click(current.event);
	assert.equal(copied, 'current\nreport');
	assert.equal(current.status.textContent, '');
	assert.equal(current.copy.disabled, true);
	assert.equal(current.classes.has('dashicons-yes'), false);
	assert.equal(click.timers.size, 0);
	finish();
	await pending;
	assert.equal(
		current.status.textContent,
		current.status.dataset.copiedMessage
	);
	assert.equal(current.classes.has('dashicons-yes'), true);
	assert.equal(current.classes.has('dashicons-clipboard'), false);
	const reset = [...click.timers.values()][0];
	reset();
	assert.equal(current.classes.has('dashicons-clipboard'), true);
	assert.equal(current.classes.has('dashicons-yes'), false);
});

test('a repeat copy cancels the prior checkmark reset and resets before a denied retry', async () => {
	let denied = false;
	const click = fixture({
		writeText: async () => {
			if (denied) {
				throw new Error('denied');
			}
		},
	});
	const current = report('current\nreport');
	await click(current.event);
	assert.equal(current.classes.has('dashicons-yes'), true);
	await click(current.event);
	assert.equal(click.timers.size, 1);
	denied = true;
	await click(current.event);
	assert.equal(click.timers.size, 0);
	assert.equal(current.classes.has('dashicons-clipboard'), true);
	assert.equal(current.classes.has('dashicons-yes'), false);
	assert.equal(
		current.status.textContent,
		current.status.dataset.copyFailedMessage
	);
});

test('unavailable, denied and synchronously failing clipboard select the unchanged report for manual copy', async () => {
	for (const clipboard of [
		undefined,
		{},
		{
			writeText: async () => {
				throw new Error('denied');
			},
		},
		{
			writeText: () => {
				throw new Error('unavailable');
			},
		},
	]) {
		const click = fixture(clipboard);
		const current = report('GitHub provider\nBooster Core updates\n');
		await click(current.event);
		assert.equal(current.input.focused, true);
		assert.equal(current.input.selected, true);
		assert.equal(
			current.input.value,
			'GitHub provider\nBooster Core updates\n'
		);
		assert.equal(
			current.status.textContent,
			current.status.dataset.copyFailedMessage
		);
		assert.equal(current.copy.disabled, false);
		assert.equal(current.classes.has('dashicons-yes'), false);
		assert.equal(click.timers.size, 0);
	}
});

test('completion for a replaced report cannot announce success or move focus in the new region', async () => {
	for (const fails of [false, true]) {
		let finish;
		const click = fixture({
			writeText: () =>
				new Promise((resolve, reject) => {
					finish = fails ? reject : resolve;
				}),
		});
		const old = report('old report');
		const pending = click(old.event);
		old.input.isConnected = false;
		const current = report('new report');
		finish();
		await pending;
		assert.equal(current.status.textContent, '');
		assert.equal(old.status.textContent, '');
		assert.equal(old.classes.has('dashicons-yes'), false);
		assert.equal(click.timers.size, 0);
		assert.equal(old.input.focused, false);
		assert.equal(current.input.focused, false);
	}
});

test('download uses the current UTF-8 report, validates its filename and cleans up', async () => {
	for (const filename of [
		'ran-booster-support-20261031-123456.txt',
		'../private.txt',
	]) {
		const listeners = new Map();
		const blobs = [];
		const revoked = [];
		const links = [];
		const current = report('old');
		current.input.value = 'current café\n報告';
		const button = {
			dataset: { supportReportFilename: filename },
			closest: () => ({
				querySelector: (selector) =>
					selector === 'textarea' ? current.input : current.status,
			}),
		};
		vm.runInNewContext(source, {
			wp: { i18n: { __: (value) => value } },
			Blob,
			document: {
				readyState: 'loading',
				addEventListener: (name, callback) =>
					listeners.set(name, callback),
				body: { appendChild: (link) => links.push(link) },
				createElement: () => ({
					click() {
						this.clicked = true;
					},
					remove() {
						this.removed = true;
					},
				}),
			},
			window: {
				URL: {
					createObjectURL: (blob) => {
						blobs.push(blob);
						return 'blob:report';
					},
					revokeObjectURL: (url) => revoked.push(url),
				},
			},
		});
		await listeners.get('click')({
			target: {
				closest: (selector) =>
					selector === '[data-support-report-download]'
						? button
						: null,
			},
		});
		assert.equal(await blobs[0].text(), current.input.value);
		assert.equal(blobs[0].type, 'text/plain;charset=utf-8');
		assert.equal(
			links[0].download,
			filename.startsWith('ran-') ? filename : 'ran-booster-support.txt'
		);
		assert.equal(links[0].clicked, true);
		assert.equal(links[0].removed, true);
		assert.deepEqual(revoked, ['blob:report']);
	}
});

test('download failure selects the report and announces manual save', async () => {
	const listeners = new Map();
	const current = report('unchanged');
	const button = {
		dataset: {},
		closest: () => ({
			querySelector: (selector) =>
				selector === 'textarea' ? current.input : current.status,
		}),
	};
	vm.runInNewContext(source, {
		wp: { i18n: { __: (value) => value } },
		Blob,
		document: {
			readyState: 'loading',
			addEventListener: (name, callback) => listeners.set(name, callback),
		},
		window: {
			URL: {
				createObjectURL() {
					throw new Error();
				},
			},
		},
	});
	await listeners.get('click')({
		target: {
			closest: (selector) =>
				selector === '[data-support-report-download]' ? button : null,
		},
	});
	assert.equal(current.input.value, 'unchanged');
	assert.equal(current.input.focused, true);
	assert.equal(current.input.selected, true);
	assert.equal(
		current.status.textContent,
		'Download failed. Select the report and save it manually.'
	);
});
