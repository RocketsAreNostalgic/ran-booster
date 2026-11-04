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
	click.input = listeners.get('input');
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
	const statusClasses = new Set();
	const status = {
		textContent: '',
		classList: {
			add: (name) => statusClasses.add(name),
			remove: (name) => statusClasses.delete(name),
		},
		dataset: {
			copiedMessage: 'Support report copied to the clipboard.',
			copyFailedMessage:
				'Clipboard access failed. Use your browser copy command.',
		},
	};
	const root = {
		querySelector(selector) {
			if (selector === 'textarea') {
				return input;
			}
			if (selector === '[data-support-report-copy]') {
				return copy;
			}
			return status;
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
		getAttribute: () => 'Copy report',
		setAttribute() {},
		closest: () => root,
		querySelector: () => icon,
	};
	return {
		input,
		status,
		statusClasses,
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
	assert.equal(current.copy.disabled, false);
	assert.equal(current.classes.has('dashicons-yes'), false);
	assert.equal(click.timers.size, 0);
	finish();
	await pending;
	assert.equal(
		current.status.textContent,
		current.status.dataset.copiedMessage
	);
	assert.equal(current.classes.has('dashicons-yes'), true);
	assert.equal(current.statusClasses.has('screen-reader-text'), true);
	assert.equal(current.classes.has('dashicons-clipboard'), false);
	const reset = [...click.timers.values()][0];
	reset();
	assert.equal(current.classes.has('dashicons-clipboard'), true);
	assert.equal(current.classes.has('dashicons-yes'), false);
	assert.equal(current.status.textContent, '');
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
		assert.equal(current.statusClasses.has('screen-reader-text'), false);
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
		'ran-booster-support-20261031-123456.json',
		'../private.json',
		'ran-booster-support-20261031-123456.txt',
	]) {
		const listeners = new Map();
		const blobs = [];
		const revoked = [];
		const links = [];
		const current = report('old');
		current.input.value = '{\n  "message": "café 報告"\n}';
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
		assert.equal(JSON.parse(await blobs[0].text()).message, 'café 報告');
		assert.equal(blobs[0].type, 'application/json;charset=utf-8');
		assert.equal(
			links[0].download,
			filename.endsWith('.json') && filename.startsWith('ran-')
				? filename
				: 'ran-booster-support.json'
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

test('changed report while copy is pending receives no stale outcome', async () => {
	for (const fails of [false, true]) {
		let finish;
		const click = fixture({
			writeText: () =>
				new Promise((resolve, reject) => {
					finish = fails ? reject : resolve;
				}),
		});
		const current = report('old');
		const pending = click(current.event);
		current.input.value = 'new';
		finish();
		await pending;
		assert.equal(current.status.textContent, '');
		assert.equal(current.input.selected, false);
	}
});

test('download failure replaces pending or confirmed copy feedback and remains visible', async () => {
	for (const pendingCopy of [false, true]) {
		let finish;
		const current = report('frozen report');
		const classes = new Set();
		current.status.classList = {
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
		};
		const listeners = new Map();
		const timers = new Map();
		let nextTimer = 0;
		const download = { dataset: {}, closest: () => root };
		const root = {
			querySelector: (selector) =>
				({
					textarea: current.input,
					'[data-support-report-status]': current.status,
					'[data-support-report-copy]': current.copy,
				})[selector],
		};
		current.copy.closest = () => root;
		vm.runInNewContext(source, {
			wp: { i18n: { __: (value) => value } },
			Blob,
			document: {
				readyState: 'loading',
				addEventListener: (name, callback) =>
					listeners.set(name, callback),
			},
			window: {
				navigator: {
					clipboard: {
						writeText: () =>
							pendingCopy
								? new Promise((resolve) => {
										finish = resolve;
									})
								: Promise.resolve(),
					},
				},
				setTimeout: (fn) => {
					timers.set(++nextTimer, fn);
					return nextTimer;
				},
				clearTimeout: (id) => timers.delete(id),
				URL: {
					createObjectURL() {
						throw new Error();
					},
				},
			},
		});
		const copy = listeners.get('click')(current.event);
		if (!pendingCopy) {
			await copy;
			assert.equal(classes.has('screen-reader-text'), true);
		}
		listeners.get('click')({
			target: {
				closest: (selector) =>
					selector === '[data-support-report-download]'
						? download
						: null,
			},
		});
		if (pendingCopy) {
			finish();
			await copy;
		}
		assert.equal(
			current.status.textContent,
			'Download failed. Select the report and save it manually.'
		);
		assert.equal(classes.has('screen-reader-text'), false);
		assert.equal(timers.size, 0);
		assert.equal(current.input.selected, true);
	}
});

test('report input A-B-A invalidates pending copy and a duplicate click writes only once', async () => {
	let finish;
	let writes = 0;
	const click = fixture({
		writeText: () => {
			writes += 1;
			return new Promise((resolve) => {
				finish = resolve;
			});
		},
	});
	const current = report('A');
	const first = click(current.event);
	await click(current.event);
	assert.equal(writes, 1);
	const target = {
		closest: (selector) =>
			selector === '[data-support-report-tools] textarea'
				? { closest: () => ({ querySelector: () => current.copy }) }
				: null,
	};
	current.input.value = 'B';
	click.input({ target });
	current.input.value = 'A';
	click.input({ target });
	finish();
	await first;
	assert.equal(current.status.textContent, '');
	assert.equal(current.classes.has('dashicons-yes'), false);
});

test('detached button or status cannot announce a delayed result', async () => {
	for (const detached of ['copy', 'status']) {
		let finish;
		const click = fixture({
			writeText: () =>
				new Promise((resolve) => {
					finish = resolve;
				}),
		});
		const current = report('frozen');
		const pending = click(current.event);
		current[detached].isConnected = false;
		finish();
		await pending;
		assert.equal(current.status.textContent, '');
		assert.equal(current.classes.has('dashicons-yes'), false);
	}
});
