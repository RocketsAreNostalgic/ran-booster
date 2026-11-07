(function () {
	'use strict';

	if (window.ranBoosterCardsInitialized) {
		return;
	}
	window.ranBoosterCardsInitialized = true;
	const selector = '.ran-booster-card[data-ran-booster-card]';
	const noticeSelector = '[data-ran-booster-ui-notice]';
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

	function restoreAll(hosts) {
		hosts
			.map((host) => {
				let depth = 0;
				for (
					let parent = host.parentElement;
					parent;
					parent = parent.parentElement
				) {
					depth++;
				}
				return { host, depth };
			})
			.sort((a, b) => b.depth - a.depth)
			.forEach(({ host }) => restore(host));
	}

	function cleanup(target) {
		const affected = Array.from(roots.keys()).filter(
			(host) =>
				target === host ||
				target.contains(host) ||
				host.contains(target)
		);
		restoreAll(affected);
	}

	function mountSurface(card) {
		const notice = card.getAttribute('data-ran-booster-ui-notice') !== null;
		const ownSelector = notice ? noticeSelector : selector;
		const nativeContent = notice
			? card.querySelector('[data-ran-booster-ui-notice-content]')
			: null;
		if (
			roots.has(card) ||
			!card.isConnected ||
			!card.closest('.ran-booster-admin') ||
			(!notice &&
				!['extensions', 'overview', 'plugins', 'settings'].includes(
					card.getAttribute('data-ran-booster-card')
				)) ||
			card.parentElement?.closest(ownSelector) ||
			card.querySelector(ownSelector) ||
			!['DIV', 'SECTION'].includes(card.tagName) ||
			(notice &&
				(card.tagName !== 'DIV' ||
					nativeContent?.parentElement !== card))
		) {
			return;
		}
		const element = window.wp?.element;
		const Surface = notice
			? window.ranBoosterUI?.NoticeRoot
			: window.ranBoosterUI?.CardRoot;
		const Description = notice
			? window.ranBoosterUI?.NoticeDescription
			: 'div';
		if (
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync ||
			!Surface ||
			!Description
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
			const contentSelector = notice
				? '[data-ran-booster-ui-notice-slot]'
				: '[data-ran-booster-card-content]';
			const surfaceProps = notice
				? { intent: 'error', className: 'ran-booster-ui-notice' }
				: { className: 'ran-booster-card__surface' };
			const contentProps = notice
				? {
						render: h('div'),
						className: 'ran-booster-ui-notice__description',
						'data-ran-booster-ui-notice-slot': '',
					}
				: {
						className: 'ran-booster-card__content',
						'data-ran-booster-card-content': '',
					};
			root = element.createRoot(host);
			element.flushSync(() =>
				root.render(
					h(Surface, surfaceProps, h(Description, contentProps))
				)
			);
			const content = host.querySelector(contentSelector);
			if (!content) {
				throw new Error('Missing component content');
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
			!['none', 'stable', 'medium', 'high', 'informational'].includes(
				intent
			) ||
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
			[selector, noticeSelector].forEach((surfaceSelector) => {
				const closest = scope.closest?.(surfaceSelector);
				if (closest) {
					mountSurface(closest);
				}
				scope.querySelectorAll?.(surfaceSelector).forEach(mountSurface);
			});
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
		restoreAll(Array.from(roots.keys()));
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
