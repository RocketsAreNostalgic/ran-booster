import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const sources = {
	credential: fs.readFileSync(
		new URL('../../assets/ran-booster-secure-inputs.js', import.meta.url),
		'utf8'
	),
	repositoryPicker: fs.readFileSync(
		new URL(
			'../../assets/ran-booster-repository-picker.js',
			import.meta.url
		),
		'utf8'
	),
};

function loadTrapFocus(source, sourceName) {
	const signature = '\tfunction trapFocus(event, container) {';
	const start = source.indexOf(signature);

	assert.notEqual(start, -1, `${sourceName} trapFocus function must exist.`);

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

	assert.notEqual(
		end,
		-1,
		`${sourceName} trapFocus function must be complete.`
	);

	return Function(`"use strict"; return (${source.slice(start, end)});`)();
}

function fixture() {
	const document = {
		activeElement: null,
		defaultView: {
			getComputedStyle(candidate) {
				return candidate.style;
			},
		},
	};
	const container = element('container', document);
	const first = element('first', document, container);
	const lastVisible = element('last-visible', document, container);
	const hiddenInput = element('hidden-input', document, container, {
		tagName: 'INPUT',
		type: 'hidden',
	});
	const hiddenElement = element('hidden-element', document, container, {
		hidden: true,
	});
	const ariaHiddenParent = element(
		'aria-hidden-parent',
		document,
		container,
		{
			ariaHidden: 'true',
		}
	);
	const ariaHiddenChild = element(
		'aria-hidden-child',
		document,
		ariaHiddenParent
	);
	const displayHiddenParent = element(
		'display-hidden-parent',
		document,
		container,
		{ display: 'none' }
	);
	const displayHiddenChild = element(
		'display-hidden-child',
		document,
		displayHiddenParent
	);
	const visibilityHidden = element('visibility-hidden', document, container, {
		visibility: 'hidden',
	});
	const disabled = element('disabled', document, container, {
		disabled: true,
	});
	const removedFromTabOrder = element(
		'negative-tab-index',
		document,
		container,
		{
			tabIndex: -1,
		}
	);
	const noRect = element('no-rect', document, container, { rendered: false });

	container.querySelectorAll = () => [
		first,
		lastVisible,
		hiddenInput,
		ariaHiddenChild,
		displayHiddenChild,
		visibilityHidden,
		disabled,
		removedFromTabOrder,
		noRect,
		hiddenElement,
	];

	return { container, document, first, lastVisible };
}

function element(name, document, parentElement = null, options = {}) {
	return {
		name,
		ownerDocument: document,
		parentElement,
		tagName: options.tagName || 'BUTTON',
		type: options.type || 'button',
		disabled: options.disabled || false,
		tabIndex: options.tabIndex ?? 0,
		hidden: options.hidden || false,
		style: {
			display: options.display || 'block',
			visibility: options.visibility || 'visible',
		},
		getAttribute(attribute) {
			return attribute === 'aria-hidden'
				? options.ariaHidden || null
				: null;
		},
		getClientRects() {
			return options.rendered === false ? [] : [{}];
		},
		focus() {
			this.ownerDocument.activeElement = this;
		},
	};
}

function tabEvent(shiftKey = false) {
	return {
		key: 'Tab',
		shiftKey,
		defaultPrevented: false,
		preventDefault() {
			this.defaultPrevented = true;
		},
	};
}

test('forward Tab wraps from the last visible candidate to the first', () => {
	Object.entries(sources).forEach(([sourceName, source]) => {
		const trapFocus = loadTrapFocus(source, sourceName);
		const { container, first, lastVisible } = fixture();
		const event = tabEvent();

		container.ownerDocument.activeElement = lastVisible;
		trapFocus(event, container);

		assert.equal(event.defaultPrevented, true);
		assert.equal(container.ownerDocument.activeElement, first);
	});
});

test('Shift+Tab wraps from the first to the last visible candidate', () => {
	Object.entries(sources).forEach(([sourceName, source]) => {
		const trapFocus = loadTrapFocus(source, sourceName);
		const { container, first, lastVisible } = fixture();
		const event = tabEvent(true);

		container.ownerDocument.activeElement = first;
		trapFocus(event, container);

		assert.equal(event.defaultPrevented, true);
		assert.equal(container.ownerDocument.activeElement, lastVisible);
	});
});
