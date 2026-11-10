import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(
	new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
	'utf8'
);

function loadHandler() {
	const signature = '\tfunction confirmDelegatedSubmit(';
	const start = source.indexOf(signature);
	assert.notEqual(start, -1, 'The delegated confirm handler must exist.');

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
	assert.notEqual(end, -1);

	return Function(
		'window',
		`"use strict"; return (${source.slice(start, end)});`
	);
}

function button(message, { bound = false, disabled = false } = {}) {
	const node = {
		disabled,
		dataset: bound ? { ranBoosterConfirmBound: 'true' } : {},
		getAttribute: (name) => (name === 'data-confirm' ? message : null),
	};
	node.closest = (selector) =>
		selector === 'button[type="submit"][data-confirm]' ? node : null;
	return node;
}

function click(handler, target) {
	const event = {
		target,
		prevented: false,
		preventDefault() {
			this.prevented = true;
		},
	};
	handler(event);
	return event;
}

test('the document listener is registered for clicks', () => {
	assert.match(
		source,
		/document\.addEventListener\('click', confirmDelegatedSubmit\)/
	);
});

test('cancelling the confirmation prevents the submission', () => {
	const asked = [];
	const handler = loadHandler()({
		confirm: (message) => {
			asked.push(message);
			return false;
		},
	});

	const event = click(handler, button('Remove this webhook?'));
	assert.equal(event.prevented, true);
	assert.deepEqual(asked, ['Remove this webhook?']);
});

test('accepting the confirmation lets the submission proceed', () => {
	const handler = loadHandler()({ confirm: () => true });

	assert.equal(click(handler, button('Remove?')).prevented, false);
});

test('a button created by a later DOM swap is still confirmed', () => {
	const handler = loadHandler()({ confirm: () => false });
	const root = { children: [button('first')] };

	assert.equal(click(handler, root.children[0]).prevented, true);
	root.children = [button('second')];
	assert.equal(click(handler, root.children[0]).prevented, true);
});

test('buttons the initial binding owns or that are disabled or unrelated are skipped', () => {
	let asked = 0;
	const handler = loadHandler()({
		confirm: () => {
			asked += 1;
			return false;
		},
	});

	assert.equal(click(handler, button('x', { bound: true })).prevented, false);
	assert.equal(
		click(handler, button('x', { disabled: true })).prevented,
		false
	);
	assert.equal(click(handler, { closest: () => null }).prevented, false);
	assert.equal(asked, 0);
});
