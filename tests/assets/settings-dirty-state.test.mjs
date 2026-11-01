import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-settings.js', import.meta.url),
	'utf8'
);

test('dirty tracking survives failed swaps and resets after repeated successful saves', () => {
	const bodyEvents = {};
	const windowEvents = {};
	let current;
	let notice;
	let saved = false;
	let focused = false;
	const makeForm = (value) => ({
		value,
		events: {},
		addEventListener(name, fn) {
			this.events[name] = fn;
		},
	});
	const swap = (value, success) => {
		current = makeForm(value);
		current.token = 'refreshed';
		notice = { hidden: true };
		saved = success;
		bodyEvents['htmx:afterSwap']({
			detail: { target: { id: 'ran-booster-general-region' } },
		});
	};
	const document = {
		body: {
			addEventListener(name, fn) {
				bodyEvents[name] = fn;
			},
		},
		querySelector(selector) {
			if (selector === '[data-ran-booster-general-form]') {
				return current;
			}
			if (selector === '[data-ran-booster-unsaved]') {
				return notice;
			}
			return {
				hasAttribute: () => saved,
				querySelector: () => ({
					focus() {
						focused = true;
					},
				}),
			};
		},
	};
	const window = {
		addEventListener(name, fn) {
			windowEvents[name] = fn;
		},
	};
	const FormData = class {
		constructor(form) {
			return [
				['ran_booster[preferences][value]', form.value],
				['ran_booster[token]', form.token || 'old'],
			];
		}
	};
	current = makeForm('original');
	notice = { hidden: true };
	vm.runInNewContext(source, { document, window, FormData, JSON });
	current.events.submit();
	swap('original', false);
	assert.equal(notice.hidden, true);
	assert.equal(focused, true);
	focused = false;
	current.value = 'edited';
	current.events.input();
	assert.equal(notice.hidden, false);
	current.events.submit();
	swap('edited', false);
	assert.equal(notice.hidden, false);
	let prevented = false;
	windowEvents.beforeunload({
		preventDefault() {
			prevented = true;
		},
	});
	assert.equal(prevented, true);
	current.events.submit();
	focused = false;
	swap('edited', true);
	assert.equal(notice.hidden, true);
	assert.equal(focused, false);
	current.value = 'again';
	current.events.change();
	assert.equal(notice.hidden, false);
	current.events.submit();
	swap('again', true);
	assert.equal(notice.hidden, true);
});
