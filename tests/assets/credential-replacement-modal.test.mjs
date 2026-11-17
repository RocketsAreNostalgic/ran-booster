import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);
const editorSource = source.slice(
	source.indexOf('\tfunction initCredentialEditor()'),
	source.indexOf('\tfunction initCredentialSettings()')
);
const dialogSource = fs.readFileSync(
	new URL('../../assets/ran-booster-dialog.js', import.meta.url),
	'utf8'
);

test('confirmation adapter resolves once and returns focus on cancellation', async () => {
	let rendered;
	let unmounted = 0;
	let focused = 0;
	let accepted = 0;
	let cancelled = 0;
	const host = { remove() {} };
	const document = new EventTarget();
	document.body = { append() {} };
	document.createElement = () => host;
	const trigger = {
		ownerDocument: document,
		isConnected: true,
		focus: () => focused++,
	};
	const window = {
		ranBoosterUI: {
			version: '0.26.0',
			AlertDialog: { Root: 'root', Popup: 'popup' },
		},
		wp: {
			element: {
				createElement: (type, props, child) => ({ type, props, child }),
				createRoot: () => ({
					render: (value) => {
						rendered = value;
					},
					unmount: () => unmounted++,
				}),
				flushSync: (callback) => callback(),
			},
		},
	};
	vm.runInNewContext(dialogSource, { window, queueMicrotask });
	const open = () =>
		window.ranBoosterOpenDialog.confirm(
			trigger,
			'Unsaved changes',
			'Discard?',
			() => accepted++,
			() => cancelled++
		);
	assert.equal(open(), true);
	assert.equal(rendered.child.props['aria-modal'], true);
	assert.equal(rendered.child.props.title, 'Unsaved changes');
	assert.equal(rendered.child.props.description, 'Discard?');
	rendered.props.onOpenChange(false);
	rendered.props.onOpenChange(false);
	await Promise.resolve();
	assert.equal(cancelled, 1);
	assert.equal(focused, 1);
	assert.equal(unmounted, 1);
	assert.equal(open(), true);
	rendered.props.onConfirm();
	rendered.props.onOpenChange(false);
	await Promise.resolve();
	assert.equal(accepted, 1);
	assert.equal(cancelled, 1);
});

test('committed HTMX cleanup cancels the old prompt and settles overlapping callers', async () => {
	const document = new EventTarget();
	document.body = { append() {} };
	document.createElement = () => ({ remove() {} });
	const trigger = { ownerDocument: document, isConnected: false };
	let cancelCount = 0;
	let failRoot = true;
	const window = {
		ranBoosterUI: {
			version: '0.26.0',
			AlertDialog: { Root: 'root', Popup: 'popup' },
		},
		wp: {
			element: {
				createElement: (type, props, child) => ({ type, props, child }),
				createRoot: () => {
					if (failRoot) {
						throw new Error('unavailable');
					}
					return { render() {}, unmount() {} };
				},
				flushSync: (callback) => callback(),
			},
		},
	};
	vm.runInNewContext(dialogSource, { window, queueMicrotask });
	const open = () =>
		window.ranBoosterOpenDialog.confirm(
			trigger,
			'Title',
			'Question',
			() => {},
			() => cancelCount++
		);
	assert.equal(open(), false, 'construction failure allows native fallback');
	failRoot = false;
	assert.equal(open(), true, 'construction failure releases the guard');
	assert.equal(open(), true);
	assert.equal(cancelCount, 1, 'busy caller receives cancellation');
	const refused = event('htmx:beforeSwap', { elt: { contains: () => true } });
	document.dispatchEvent(refused);
	assert.equal(cancelCount, 1, 'a refused swap does not cancel');
	document.dispatchEvent(
		event('htmx:beforeCleanupElement', {
			elt: { contains: (node) => node === trigger },
		})
	);
	await Promise.resolve();
	assert.equal(cancelCount, 2, 'committed cleanup cancels the original');
	assert.equal(open(), true, 'later prompt is not stuck');
});
function event(type, detail) {
	const value = new Event(type, { cancelable: true });
	if (detail) {
		value.detail = detail;
	}
	return value;
}
function fixture(available = true, query = '') {
	const document = new EventTarget();
	const window = new EventTarget();
	const cancel = new EventTarget();
	const kind = new EventTarget();
	const removal = new EventTarget();
	const button = new EventTarget();
	button.isConnected = true;
	button.href = 'https://example.test/credential-b';
	button.getAttribute = () => 'saved';
	const form = new EventTarget();
	window.location = {
		href: 'https://example.test/wp-admin/admin.php' + query,
		assign: (url) => state.navigations.push(url),
	};
	form.elements = {
		'ran_booster[id]': { value: 'saved' },
		'ran_booster[label]': {},
		'ran_booster[secret]': {},
		'ran_booster[expires_on]': { value: '2027-01-01', dataset: {} },
	};
	form.querySelector = () => cancel;
	const editor = {
		isConnected: true,
		dataset: {},
		hidden: false,
		contains: () => !state.moved,
		querySelector(selector) {
			return {
				form,
				'[data-credential-cancel]': cancel,
				'.ran-booster-credential-kind': kind,
				'.ran-booster-credential-self-destruct': removal,
				'[data-credential-native-help]': {},
				'.ran-booster-dialog__title': {
					textContent: 'Edit credential',
				},
			}[selector];
		},
	};
	const state = {
		moved: false,
		opens: 0,
		populations: 0,
		updates: [],
		navigations: [],
		accept: false,
		form,
		editor,
		window,
		document,
		button,
		cancel,
		kind,
		removal,
	};
	window.confirm = () => state.accept;
	if (available) {
		window.ranBoosterOpenDialog = (trigger, content, options) => {
			assert.equal(trigger, button);
			assert.equal(content, form);
			state.focusTarget = options.initialFocus();
			assert.equal(options.disablePointerDismissal, true);
			state.moved = true;
			state.opens++;
			return {
				close() {
					state.moved = false;
				},
			};
		};
	}
	document.querySelector = () => editor;
	document.querySelectorAll = () => [button];
	state.init = Function(
		'document',
		'window',
		'__',
		'populateCredentialModal',
		'updateCredentialFields',
		'updateCredentialSelfDestructFields',
		`return (${editorSource});`
	)(
		document,
		window,
		(text) => text,
		() => {
			state.populations++;
			form.elements['ran_booster[id]'].value =
				button.getAttribute('data-id') || '';
			form.elements['ran_booster[secret]'].value = '';
		},
		(root) => state.updates.push(root),
		(root) => state.updates.push(root)
	);
	state.init();
	return state;
}

test('same native form opens with Label focus and retains draft across close/reopen', () => {
	const state = fixture();
	assert.equal(state.form.noValidate, false);
	state.form.value = 'synthetic unsaved draft';
	state.form.dispatchEvent(event('input'));
	state.init();
	const click = event('click');
	state.button.dispatchEvent(click);
	assert.equal(click.defaultPrevented, true);
	assert.equal(state.opens, 1, 'initialization must not duplicate handlers');
	assert.equal(state.focusTarget, state.form.elements['ran_booster[label]']);
	state.kind.dispatchEvent(event('change'));
	state.removal.dispatchEvent(event('change'));
	assert.deepEqual(
		state.updates.slice(-2),
		[state.form, state.form],
		'live field handlers must follow the moved form'
	);
	state.cancel.dispatchEvent(event('click'));
	assert.equal(state.moved, false);
	state.button.dispatchEvent(event('click'));
	assert.equal(state.populations, 0);
	assert.equal(state.form.value, 'synthetic unsaved draft');
});

test('missing or failing Dialog and modified clicks retain native link behavior', () => {
	const state = fixture(false);
	const click = event('click');
	state.button.dispatchEvent(click);
	assert.equal(click.defaultPrevented, false);
	state.window.ranBoosterOpenDialog = () => null;
	const failed = event('click');
	state.button.dispatchEvent(failed);
	assert.equal(failed.defaultPrevented, false);
	const modified = event('click');
	modified.ctrlKey = true;
	state.button.dispatchEvent(modified);
	assert.equal(modified.defaultPrevented, false);
});

test('missing dialog helper still confirms a dirty identity switch', () => {
	const state = fixture(false);
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'different';
	const declined = event('click');
	state.button.dispatchEvent(declined);
	assert.equal(declined.defaultPrevented, true);
	state.accept = true;
	const accepted = event('click');
	state.button.dispatchEvent(accepted);
	assert.equal(accepted.defaultPrevented, false);
});

test('switching credential identity cannot silently erase a dirty draft', () => {
	const state = fixture();
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'different';
	const click = event('click');
	state.button.dispatchEvent(click);
	assert.equal(click.defaultPrevented, true);
	assert.equal(state.populations, 0);
	assert.equal(state.opens, 0);
	state.accept = true;
	state.button.dispatchEvent(event('click'));
	assert.equal(state.populations, 1);
	assert.equal(state.opens, 1);
});

test('accepted native confirmation retains the link when the editor dialog fails', () => {
	const state = fixture();
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'different';
	state.accept = true;
	state.window.ranBoosterOpenDialog = () => null;
	const click = event('click');
	state.button.dispatchEvent(click);
	assert.equal(click.defaultPrevented, false);
});

test('asynchronous confirmation resumes the original switch once and cancel retains the draft', () => {
	const state = fixture();
	state.form.value = 'draft';
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'different';
	let accept;
	let cancel;
	let prompts = 0;
	state.window.ranBoosterOpenDialog.confirm = (
		button,
		title,
		message,
		yes,
		no
	) => {
		assert.equal(button, state.button);
		assert.equal(title, 'Unsaved credential changes');
		assert.equal(message, 'Discard unsaved credential changes?');
		prompts++;
		accept = yes;
		cancel = no;
		return true;
	};
	state.button.dispatchEvent(event('click'));
	state.button.dispatchEvent(event('click'));
	assert.equal(prompts, 1);
	cancel();
	assert.equal(state.form.value, 'draft');
	assert.equal(state.populations, 0);
	state.button.dispatchEvent(event('click'));
	accept();
	accept();
	assert.equal(state.populations, 1);
	assert.equal(state.opens, 1);
});

test('a stale identity switch does not replace the draft', () => {
	const state = fixture();
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'different';
	let accept;
	state.window.ranBoosterOpenDialog.confirm = (
		button,
		title,
		message,
		yes
	) => {
		accept = yes;
		return true;
	};
	state.button.dispatchEvent(event('click'));
	state.button.isConnected = false;
	accept();
	assert.equal(state.populations, 0);
});

for (const failure of ['missing', 'throws', 'null']) {
	test(`accepted deferred switch follows the original link once when editor ${failure}`, () => {
		const state = fixture();
		state.form.dispatchEvent(event('input'));
		state.button.getAttribute = () => 'different';
		let accept;
		state.window.ranBoosterOpenDialog.confirm = (
			button,
			title,
			message,
			yes
		) => {
			accept = yes;
			return true;
		};
		state.button.dispatchEvent(event('click'));
		if (failure === 'missing') {
			delete state.window.ranBoosterOpenDialog;
		} else if (failure === 'throws') {
			state.window.ranBoosterOpenDialog = () => {
				throw new Error('unavailable');
			};
		} else {
			state.window.ranBoosterOpenDialog = () => null;
		}
		accept();
		accept();
		assert.deepEqual(state.navigations, [
			'https://example.test/credential-b',
		]);
	});
}

test('accepted same-document fallback selects the credential and clears the draft secret', () => {
	const state = fixture();
	state.form.elements['ran_booster[id]'].value = '';
	state.form.elements['ran_booster[secret]'].value = 'synthetic draft';
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'B';
	let accept;
	state.window.ranBoosterOpenDialog.confirm = (
		button,
		title,
		message,
		yes
	) => {
		accept = yes;
		return true;
	};
	state.button.dispatchEvent(event('click'));
	delete state.window.ranBoosterOpenDialog;
	accept();
	accept();
	assert.equal(state.form.elements['ran_booster[id]'].value, 'B');
	assert.equal(state.form.elements['ran_booster[secret]'].value, '');
	assert.equal(state.populations, 1);
	assert.deepEqual(state.navigations, ['https://example.test/credential-b']);
});

test('changed destination makes an accepted switch stale', () => {
	const state = fixture();
	state.form.dispatchEvent(event('input'));
	state.button.getAttribute = () => 'different';
	let accept;
	state.window.ranBoosterOpenDialog.confirm = (
		button,
		title,
		message,
		yes
	) => {
		accept = yes;
		return true;
	};
	state.button.dispatchEvent(event('click'));
	state.button.href = 'https://example.test/other';
	accept();
	assert.equal(state.populations, 0);
	assert.deepEqual(state.navigations, []);
});

for (const [query, id, secret] of [
	['?replace_credential=saved', 'saved', true],
	['?replace_credential=other', 'saved', false],
	['?replace_credential=', '', false],
	['?add_credential=1', '', false],
	['', 'saved', false],
]) {
	test(`focus is keyed to exact replacement request: ${query || 'none'} / ${id || 'add'}`, () => {
		const state = fixture(true, query);
		assert.equal(
			state.opens,
			0,
			'native URL selections remain inline with JS'
		);
		state.button.getAttribute = () => id;
		state.form.elements['ran_booster[id]'].value = id;
		state.button.dispatchEvent(event('click'));
		assert.equal(
			state.focusTarget,
			state.form.elements[
				secret ? 'ran_booster[secret]' : 'ran_booster[label]'
			]
		);
	});
}
