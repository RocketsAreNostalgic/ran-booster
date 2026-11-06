(function () {
	'use strict';

	if (window.ranBoosterCardsInitialized) {
		return;
	}
	window.ranBoosterCardsInitialized = true;
	const selector = '.ran-booster-card[data-ran-booster-card]';
	const badgeSelector = '[data-ran-booster-ui-badge]';
	const mounted = 'data-ran-booster-ui-mounted';
	const roots = new Map();

	function focusAfterMove(host, active) {
		if (
			active &&
			active.isConnected &&
			host.contains(active) &&
			(host.ownerDocument.activeElement === document.body ||
				!host.ownerDocument.activeElement)
		) {
			active.focus({ preventScroll: true });
		}
	}

	function restore(host) {
		const entry = roots.get(host);
		if (!entry) {
			return;
		}
		const active = host.contains(host.ownerDocument.activeElement)
			? host.ownerDocument.activeElement
			: null;
		if (entry.content) {
			while (entry.content.firstChild) {
				host.insertBefore(entry.content.firstChild, entry.mount);
			}
		}
		try {
			entry.root.unmount();
		} catch {
			// Native content remains authoritative if React cleanup fails.
		}
		if (entry.content) {
			entry.mount.remove();
		} else {
			entry.mount.replaceChildren();
			entry.fallback.hidden = false;
		}
		host.removeAttribute(mounted);
		roots.delete(host);
		focusAfterMove(host, active);
	}

	function cleanup(target) {
		const affected = Array.from(roots.keys()).filter(
			(host) =>
				target === host ||
				target.contains(host) ||
				host.contains(target)
		);
		affected.forEach(restore);
	}

	function mountCard(card) {
		if (
			roots.has(card) ||
			!card.isConnected ||
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
		const element = window.wp?.element;
		const CardRoot = window.ranBoosterUI?.CardRoot;
		if (
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync ||
			!CardRoot
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
						CardRoot,
						{ className: 'ran-booster-card__surface' },
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
			card.setAttribute(mounted, '');
		} catch {
			original.forEach((node) => card.insertBefore(node, host));
			try {
				root?.unmount();
			} catch {
				// Keep all original native children on failed enhancement.
			}
			host.remove();
		}
		focusAfterMove(card, active);
	}

	function mountBadge(host) {
		if (
			!host.isConnected ||
			host.tagName !== 'SPAN' ||
			!host.closest('.ran-booster-admin') ||
			host.parentElement?.closest(badgeSelector)
		) {
			return;
		}
		const fallback = host.querySelector(
			'[data-ran-booster-ui-badge-fallback]'
		);
		const mount = host.querySelector('[data-ran-booster-ui-badge-mount]');
		if (
			!fallback ||
			!mount ||
			fallback.parentElement !== host ||
			mount.parentElement !== host
		) {
			return;
		}
		const label = host.getAttribute('data-ran-booster-ui-badge-label');
		const intent = host.getAttribute('data-ran-booster-ui-badge-intent');
		const element = window.wp?.element;
		const Badge = window.ranBoosterUI?.Badge;
		if (
			!label ||
			!['none', 'stable'].includes(intent) ||
			!Badge ||
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync
		) {
			restore(host);
			fallback.hidden = false;
			return;
		}
		const current = roots.get(host);
		if (current?.label === label && current.intent === intent) {
			return;
		}
		let root;
		try {
			root = current?.root || element.createRoot(mount);
			roots.set(host, { root, mount, fallback });
			element.flushSync(() =>
				root.render(
					element.createElement(
						Badge,
						{ intent, className: 'ran-booster-ui-badge' },
						label
					)
				)
			);
			roots.set(host, { root, mount, fallback, label, intent });
			fallback.hidden = true;
			host.setAttribute(mounted, '');
		} catch {
			restore(host);
			fallback.hidden = false;
		}
	}

	function initialize(scope) {
		queueMicrotask(() => {
			roots.forEach((entry, host) => {
				if (!host.isConnected) {
					restore(host);
				}
			});
			const closest = scope.closest?.(selector);
			if (closest) {
				mountCard(closest);
			}
			scope.querySelectorAll?.(selector).forEach(mountCard);
			const badge = scope.closest?.(badgeSelector);
			if (badge) {
				mountBadge(badge);
			}
			scope.querySelectorAll?.(badgeSelector).forEach(mountBadge);
		});
	}

	document.addEventListener('ran-booster:ui-badge-update', (event) => {
		const host = event.target.closest?.(badgeSelector);
		if (host) {
			mountBadge(host);
		}
	});
	document.addEventListener('htmx:beforeSwap', (event) => {
		if (event.detail.shouldSwap) {
			cleanup(event.detail.target);
		}
	});
	document.addEventListener('htmx:beforeCleanupElement', (event) =>
		cleanup(event.detail.elt)
	);
	document.addEventListener('htmx:beforeHistorySave', () => {
		Array.from(roots.keys()).forEach(restore);
		initialize(document);
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
