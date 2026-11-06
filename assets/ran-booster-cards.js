(function () {
	'use strict';

	if (window.ranBoosterCardsInitialized) {
		return;
	}
	window.ranBoosterCardsInitialized = true;
	const selector = '.ran-booster-card[data-ran-booster-card]';
	const roots = new Map();

	function focusAfterMove(card, active) {
		if (
			active &&
			active.isConnected &&
			card.contains(active) &&
			(card.ownerDocument.activeElement === document.body ||
				!card.ownerDocument.activeElement)
		) {
			active.focus({ preventScroll: true });
		}
	}

	function restore(card) {
		const entry = roots.get(card);
		if (!entry) {
			return;
		}
		const active = card.contains(card.ownerDocument.activeElement)
			? card.ownerDocument.activeElement
			: null;
		while (entry.content.firstChild) {
			card.insertBefore(entry.content.firstChild, entry.mount);
		}
		entry.root.unmount();
		entry.mount.remove();
		roots.delete(card);
		focusAfterMove(card, active);
	}

	function mount(card) {
		if (
			roots.has(card) ||
			!card.closest('.ran-booster-admin') ||
			!['extensions', 'overview', 'plugins'].includes(
				card.getAttribute('data-ran-booster-card')
			) ||
			card.parentElement?.closest(selector) ||
			card.querySelector(selector) ||
			!['DIV', 'SECTION'].includes(card.tagName)
		) {
			return;
		}
		const css = document.getElementById('wp-components-css');
		const element = window.wp?.element;
		const Card = window.wp?.components?.Card;
		let hasStyles = false;
		try {
			hasStyles =
				Boolean(css?.sheet?.cssRules.length) &&
				!css.disabled &&
				!css.sheet.disabled;
		} catch {
			// Unreadable or failed styles retain the native card.
		}
		if (
			!hasStyles ||
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync ||
			!Card
		) {
			return;
		}

		const original = Array.from(card.childNodes);
		const active = card.contains(card.ownerDocument.activeElement)
			? card.ownerDocument.activeElement
			: null;
		const host = document.createElement('div');
		host.className = 'ran-booster-card__mount';
		card.appendChild(host);
		let root;
		try {
			const h = element.createElement;
			root = element.createRoot(host);
			element.flushSync(() =>
				root.render(
					h(
						Card,
						{
							isBorderless: true,
							isRounded: false,
							className: 'ran-booster-card__surface',
						},
						h('div', {
							className: 'ran-booster-card__content',
							'data-ran-booster-card-content': '',
						})
					)
				)
			);
			const content = host.querySelector(
				'[data-ran-booster-card-content]'
			);
			if (!content) {
				throw new Error('Missing card content');
			}
			original.forEach((node) => content.appendChild(node));
			roots.set(card, { root, mount: host, content });
		} catch {
			original.forEach((node) => card.insertBefore(node, host));
			if (root) {
				root.unmount();
			}
			host.remove();
		}
		focusAfterMove(card, active);
	}

	function initialize(scope) {
		roots.forEach((entry, card) => {
			if (!card.isConnected) {
				restore(card);
			}
		});
		const closest = scope.closest?.(selector);
		if (closest) {
			mount(closest);
		}
		scope.querySelectorAll?.(selector).forEach(mount);
	}

	document.addEventListener('htmx:beforeSwap', (event) => {
		if (event.detail.shouldSwap) {
			restore(event.detail.target);
		}
	});
	document.addEventListener('htmx:beforeCleanupElement', (event) => {
		const target = event.detail.elt;
		roots.forEach((entry, card) => {
			if (
				target === card ||
				target.contains(card) ||
				card.contains(target)
			) {
				restore(card);
			}
		});
	});
	document.addEventListener('htmx:beforeHistorySave', () => {
		roots.forEach((entry, card) => restore(card));
		queueMicrotask(() => initialize(document));
	});
	document.addEventListener('htmx:load', (event) =>
		initialize(event.detail.elt || event.target)
	);
	document.addEventListener('htmx:historyRestore', () =>
		initialize(document)
	);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () =>
			initialize(document)
		);
	} else {
		initialize(document);
	}
})();
