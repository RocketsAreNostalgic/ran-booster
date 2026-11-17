import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);
const editorSource = source.slice(
	source.indexOf('\tfunction initCredentialEditor()'),
	source.indexOf('\tfunction initCredentialSettings()')
);
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
	button.getAttribute = () => 'saved';
	const form = new EventTarget();
	window.location = {
		href: 'https://example.test/wp-admin/admin.php' + query,
	};
	form.elements = {
		'ran_booster[id]': { value: 'saved' },
		'ran_booster[label]': {},
		'ran_booster[secret]': {},
		'ran_booster[expires_on]': { value: '2027-01-01', dataset: {} },
	};
	form.querySelector = () => cancel;
	const editor = {
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
