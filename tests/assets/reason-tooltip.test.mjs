import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-enhanced-mutations.js', import.meta.url),
	'utf8'
);
const css = fs.readFileSync(
	new URL(
		'../../assets/ran-booster/15-enhanced-mutations.css',
		import.meta.url
	),
	'utf8'
);

function setup() {
	const listeners = {};
	const attrs = new Map([
		['aria-disabled', 'true'],
		['aria-describedby', 'reason'],
	]);
	const outside = { closest: () => null };
	const bubble = {
		hidden: true,
		style: {},
		offsetWidth: 200,
		offsetHeight: 30,
	};
	const control = {
		getAttribute: (name) => attrs.get(name) ?? null,
	};
	const wrapper = {
		contains: (node) => node === control || node === bubble,
		querySelector: (selector) =>
			selector === '[role="tooltip"]' ? bubble : null,
		getBoundingClientRect: () => ({ left: 300, top: 100, bottom: 130 }),
	};
	const closestTargets = {
		'[data-ran-booster-reason-tooltip]': wrapper,
		'[aria-disabled="true"]': control,
	};
	control.closest = (selector) => closestTargets[selector] ?? null;
	const document = {
		readyState: 'loading',
		documentElement: { clientWidth: 375 },
		addEventListener: (type, handler) => {
			listeners[type] = handler;
		},
		querySelectorAll: () => [],
	};
	const window = { innerHeight: 800, addEventListener() {} };
	Function('window', 'document', source)(window, document);
	return { listeners, bubble, control, outside };
}

test('the reason shows on hover and keyboard focus and hides on leave, blur and Escape', () => {
	const { listeners, bubble, control, outside } = setup();

	listeners.mouseover({ target: control });
	assert.equal(bubble.hidden, false);
	listeners.mouseout({ target: control, relatedTarget: outside });
	assert.equal(bubble.hidden, true);

	listeners.focusin({ target: control });
	assert.equal(bubble.hidden, false);
	listeners.focusout({ target: control, relatedTarget: outside });
	assert.equal(bubble.hidden, true);

	listeners.focusin({ target: control });
	listeners.keydown({ key: 'Escape' });
	assert.equal(bubble.hidden, true);
});

test('the bubble stays inside a 375px viewport and does not leave the control', () => {
	const { listeners, bubble, control, outside } = setup();

	listeners.mouseover({ target: control });
	assert.equal(bubble.style.left, '167px', 'Clamped to 375 - 200 - 8.');
	assert.equal(bubble.style.top, '136px');
	listeners.mouseout({ target: control, relatedTarget: control });
	assert.equal(bubble.hidden, false, 'Moving within the wrapper keeps it.');
	listeners.mouseout({ target: control, relatedTarget: outside });
	assert.equal(bubble.hidden, true);
});

test('activation is ignored while aria-disabled and explains itself', () => {
	const { listeners, bubble, control } = setup();
	const event = {
		target: control,
		prevented: false,
		stopped: false,
		preventDefault() {
			this.prevented = true;
		},
		stopPropagation() {
			this.stopped = true;
		},
	};

	listeners.click(event);
	assert.equal(event.prevented, true);
	assert.equal(event.stopped, true);
	assert.equal(bubble.hidden, false);
});

test('an active control is not blocked', () => {
	const { listeners, control } = setup();
	control.closest = (selector) =>
		selector === '[data-ran-booster-reason-tooltip]' ? {} : null;
	const event = {
		target: control,
		preventDefault() {
			assert.fail('Active controls must submit.');
		},
		stopPropagation() {},
	};

	listeners.click(event);
});

test('the bubble is fixed-positioned so a card overflow cannot clip it', () => {
	assert.match(
		css,
		/\.ran-booster-reason-tooltip__bubble \{[^}]*position: fixed;/
	);
	assert.match(css, /\.ran-booster-reason-tooltip__bubble\[hidden\]/);
});
