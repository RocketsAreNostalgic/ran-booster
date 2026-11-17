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
	form.action = window.location.href;
	form.isConnected = true;
	form.elements = {
		'ran_booster[id]': { value: 'saved' },
		'ran_booster[label]': {},
		'ran_booster[secret]': {},
		'ran_booster[provider]': { value: 'fixture' },
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

test('dirty interlocks reject unconfirmed form replacement and preserve scoped errors', async () => {
	const state = fixture();
	state.form.dispatchEvent(event('input'));
	const leave = event('beforeunload');
	state.window.dispatchEvent(leave);
	assert.equal(leave.defaultPrevented, true);
	const response = {
		shouldSwap: true,
		target: { contains: () => true },
		requestConfig: { elt: {} },
	};
	state.document.dispatchEvent(event('htmx:beforeSwap', response));
	assert.equal(response.shouldSwap, false);
	response.shouldSwap = true;
	response.requestConfig.elt = state.form;
	state.document.dispatchEvent(event('htmx:beforeSwap', response));
	assert.equal(
		response.shouldSwap,
		false,
		'form origin alone never confirms a destructive response'
	);
	const formReplacement = event('htmx:beforeSwap', {
		shouldSwap: true,
		target: { contains: (node) => node === state.form },
		requestConfig: { elt: state.form },
	});
	state.document.dispatchEvent(formReplacement);
	assert.equal(formReplacement.defaultPrevented, true);
	assert.equal(formReplacement.detail.shouldSwap, false);
	const scopedError = {
		shouldSwap: true,
		target: { contains: () => false },
		requestConfig: { elt: state.form },
	};
	state.document.dispatchEvent(event('htmx:beforeSwap', scopedError));
	assert.equal(scopedError.shouldSwap, true);
	const submit = event('submit');
	submit.submitter = { name: 'original-submitter' };
	state.form.dispatchEvent(submit);
	assert.equal(submit.defaultPrevented, false);
	await Promise.resolve();
	const nativeLeave = event('beforeunload');
	state.window.dispatchEvent(nativeLeave);
	assert.equal(nativeLeave.defaultPrevented, false);
	const enhancedSubmit = event('submit');
	state.form.dispatchEvent(enhancedSubmit);
	enhancedSubmit.preventDefault();
	await Promise.resolve();
	const refusedLeave = event('beforeunload');
	state.window.dispatchEvent(refusedLeave);
	assert.equal(refusedLeave.defaultPrevented, true);
	state.document.dispatchEvent(
		event('htmx:beforeCleanupElement', { elt: { contains: () => true } })
	);
	const afterRemoval = event('beforeunload');
	state.window.dispatchEvent(afterRemoval);
	assert.equal(afterRemoval.defaultPrevented, false);
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

const successRedirect =
	'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture&ran_booster_interaction_operation=core%3Asave-access-profile&ran_booster_interaction_target=core_provider_profiles&ran_booster_interaction_outcome=success';
function warnsOnLeave(state) {
	const leaving = event('beforeunload');
	state.window.dispatchEvent(leaving);
	return leaving.defaultPrevented;
}
function redirectResponse(
	state,
	redirect = successRedirect,
	status = 200,
	elt = state.form
) {
	return event('htmx:beforeOnLoad', {
		requestConfig: { elt },
		xhr: {
			status,
			getResponseHeader: (name) =>
				name === 'HX-Redirect' ? redirect : null,
		},
	});
}
test('only matching confirmed credential redirect temporarily permits leaving', async () => {
	const state = fixture();
	state.form.dispatchEvent(event('input'));
	for (const res of [
		redirectResponse(state, null),
		redirectResponse(state, successRedirect, 422),
		redirectResponse(state, successRedirect, 200, {}),
		redirectResponse(
			state,
			successRedirect.replace('tab=fixture', 'tab=other')
		),
		redirectResponse(
			state,
			successRedirect.replace(
				'outcome=success',
				'outcome=validation_failure'
			)
		),
		redirectResponse(
			state,
			successRedirect.replace('example.test', 'other.test')
		),
	]) {
		state.document.dispatchEvent(res);
		assert.equal(warnsOnLeave(state), true);
	}
	state.document.dispatchEvent(redirectResponse(state));
	assert.equal(warnsOnLeave(state), false);
	await new Promise((resolve) => setTimeout(resolve, 0));
	assert.equal(
		warnsOnLeave(state),
		true,
		'grant expires if navigation does not complete'
	);
	const vetoed = redirectResponse(state);
	state.document.dispatchEvent(vetoed);
	vetoed.preventDefault();
	assert.equal(
		warnsOnLeave(state),
		true,
		'later beforeOnLoad veto retains draft protection'
	);
	state.document.dispatchEvent(redirectResponse(state));
	state.form.dispatchEvent(event('input'));
	assert.equal(
		warnsOnLeave(state),
		true,
		'further edits revoke the navigation grant'
	);
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

for (const reset of ['input', 'change', 'pageshow']) {
	test(`native submit bypass expires on ${reset}`, async () => {
		const state = fixture();
		state.form.dispatchEvent(event('input'));
		const original = event('submit');
		original.submitter = { name: 'original' };
		state.form.dispatchEvent(original);
		await Promise.resolve();
		assert.equal(
			warnsOnLeave(state),
			false,
			'uncancelled native navigation is allowed'
		);
		(reset === 'pageshow' ? state.window : state.form).dispatchEvent(
			event(reset)
		);
		assert.equal(
			warnsOnLeave(state),
			true,
			'restored document or fresh editing must be guarded'
		);
		state.form.dispatchEvent(event('submit'));
		state.form.dispatchEvent(event('input'));
		await Promise.resolve();
		assert.equal(
			warnsOnLeave(state),
			true,
			'a queued observation cannot rearm bypass after an edit'
		);
		const cancelled = event('submit');
		state.form.dispatchEvent(cancelled);
		cancelled.preventDefault();
		await Promise.resolve();
		assert.equal(
			warnsOnLeave(state),
			true,
			'cancelled native submit never permits leaving'
		);
	});
}
