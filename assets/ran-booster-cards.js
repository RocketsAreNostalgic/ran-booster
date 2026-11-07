(function () {
	'use strict';

	if (window.ranBoosterCardsInitialized) {
		return;
	}
	window.ranBoosterCardsInitialized = true;
	const selector = '.ran-booster-card[data-ran-booster-card]';
	const noticeSelector = '[data-ran-booster-ui-notice]';
	const badgeSelector = '[data-ran-booster-ui-badge]';
	const buttonSelector = '[data-ran-booster-ui-button]';
	const mounted = 'data-ran-booster-ui-mounted';
	const roots = new Map();
	const navigationOwner =
		'.ran-booster-admin[data-ran-booster-shell-navigation]';
	const providerSelector =
		'nav.ran-booster-provider-task-tabs[data-ran-booster-ui-navigation="thin"]';
	let pointerActive = false;
	let pointerEpoch = 0;
	const activeKeys = new Map();

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
		if (entry.navigation) {
			restoreNavigation(host, entry);
			return;
		}
		let active = host.contains(host.ownerDocument.activeElement)
			? host.ownerDocument.activeElement
			: null;
		if (entry.button) {
			const button = entry.mount.querySelector('button');
			if (button) {
				entry.button.disabled = button.disabled;
				if (active === button) {
					active = entry.button;
				}
			}
			entry.mount.replaceWith(entry.button);
		}
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
			if (entry.button) {
				host.appendChild(entry.mount);
			} else {
				entry.fallback.hidden = false;
			}
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
				(!roots.get(host).navigation && host.contains(target))
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
				![
					'extensions',
					'overview',
					'plugins',
					'settings',
					'themes',
				].includes(card.getAttribute('data-ran-booster-card'))) ||
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

	function mountButton(host) {
		if (
			roots.has(host) ||
			!host.isConnected ||
			host.tagName !== 'SPAN' ||
			!host.closest('.ran-booster-admin') ||
			host.getAttribute('data-ran-booster-ui-button') !==
				'overview-presentation'
		) {
			return;
		}
		const button = host.querySelector(
			'[data-ran-booster-ui-button-fallback]'
		);
		const mount = host.querySelector('[data-ran-booster-ui-button-mount]');
		const element = window.wp?.element,
			Button = window.ranBoosterUI?.Button;
		if (
			!button ||
			button.tagName !== 'BUTTON' ||
			button.getAttribute('type') !== 'submit' ||
			button.parentElement !== host ||
			!mount ||
			mount.tagName !== 'SPAN' ||
			mount.parentElement !== host ||
			mount.firstChild ||
			!Button ||
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync
		) {
			return;
		}
		const props = {
			type: 'submit',
			tone: 'neutral',
			variant: 'outline',
			disabled: button.disabled,
			focusableWhenDisabled: false,
		};
		[
			'name',
			'value',
			'form',
			'aria-label',
			'aria-describedby',
			'id',
			'title',
		].forEach((name) => {
			if (button.hasAttribute(name)) {
				props[name] = button.getAttribute(name);
			}
		});
		const focused = host.ownerDocument.activeElement === button;
		mount.remove();
		let root;
		try {
			root = element.createRoot(mount);
			element.flushSync(() =>
				root.render(
					element.createElement(Button, props, button.textContent)
				)
			);
			const replacement = mount.querySelector('button');
			if (!replacement) {
				throw new Error('Missing UI button');
			}
			button.replaceWith(mount);
			roots.set(host, { root, mount, button });
			host.setAttribute(mounted, '');
			if (focused) {
				focusAfterMove(host, replacement);
			}
		} catch {
			try {
				root?.unmount();
			} catch {
				/* Keep the native action available. */
			}
			mount.replaceChildren();
			host.appendChild(mount);
		}
	}

	const navigationAttributes = [
		'class',
		'href',
		'title',
		'rel',
		'target',
		'download',
		'aria-current',
		'aria-controls',
		'hx-get',
		'data-ran-booster-provider-task',
	];

	function navigationLinks(host, provider) {
		return Array.from(
			host.querySelectorAll(provider ? ':scope > li > a' : ':scope > a')
		);
	}

	function navigationItems(links) {
		return links.map((link) => ({
			label: link.textContent,
			href: link.getAttribute('href'),
			current: link.getAttribute('aria-current') === 'page',
			className: link.getAttribute('class') || '',
			title: link.getAttribute('title') ?? undefined,
			target: link.getAttribute('target') ?? undefined,
			rel: link.getAttribute('rel') ?? undefined,
			download: link.getAttribute('download') ?? undefined,
			task:
				link.getAttribute('data-ran-booster-provider-task') ??
				undefined,
			requestUrl: link.getAttribute('hx-get') ?? undefined,
			controls: link.getAttribute('aria-controls') ?? undefined,
		}));
	}

	function restoreNavigation(host, entry) {
		const visible = navigationLinks(host, entry.provider);
		const visibleIndex = visible.indexOf(host.ownerDocument.activeElement);
		const index = entry.focusIndex ?? visibleIndex;
		const scroll = entry.scroll ?? host.scrollLeft;
		roots.delete(host);
		try {
			entry.root?.unmount();
		} catch {
			// Restore retained native nodes even if React cleanup fails.
		}
		host.replaceChildren(...entry.original);
		host.scrollLeft = scroll;
		const active = host.ownerDocument.activeElement;
		if (
			index >= 0 &&
			(!active ||
				active === host.ownerDocument.body ||
				!active.isConnected)
		) {
			entry.links[index]?.focus({ preventScroll: true });
		}
	}

	function renderNavigation(host, entry) {
		const element = window.wp.element;
		const UI = window.ranBoosterUI;
		element.flushSync(() =>
			entry.root.render(
				element.createElement(
					entry.boundary,
					null,
					element.createElement(UI.NavigationLinks, {
						items: navigationItems(entry.links),
						provider: entry.provider,
					})
				)
			)
		);
		const visible = navigationLinks(host, entry.provider);
		if (
			visible.length !== entry.links.length ||
			visible.some(
				(link, index) =>
					link.tagName !== 'A' ||
					link.getAttribute('href') !==
						entry.links[index].getAttribute('href')
			)
		) {
			throw new Error('Navigation did not render its destinations');
		}
	}

	function hasNavigationText(node) {
		return Array.from(node.childNodes).some(
			(child) => child.nodeType === 3 && child.textContent.trim()
		);
	}

	function mountNavigation(host, provider) {
		const element = window.wp?.element;
		const UI = window.ranBoosterUI;
		if (
			!host ||
			!host.isConnected ||
			roots.has(host) ||
			pointerActive ||
			activeKeys.size > 0 ||
			host.contains(host.ownerDocument.activeElement) ||
			host.closest('[aria-busy="true"]') ||
			!UI?.NavigationLinks ||
			!element?.createRoot ||
			!element?.createElement ||
			!element?.flushSync ||
			!element?.Component
		) {
			return;
		}
		const links = navigationLinks(host, provider);
		if (
			!links.length ||
			hasNavigationText(host) ||
			host.children.length !== links.length ||
			links.some(
				(link) =>
					!link.getAttribute('href') ||
					(!provider && link.hasAttribute('hx-get')) ||
					link.childElementCount ||
					![null, 'page'].includes(
						link.getAttribute('aria-current')
					) ||
					/^_blank$/i.test(link.getAttribute('target') || '') ||
					Array.from(link.attributes).some(
						(attribute) =>
							!navigationAttributes.includes(attribute.name)
					)
			) ||
			(provider &&
				links.some(
					(link) =>
						link.parentElement.tagName !== 'LI' ||
						hasNavigationText(link.parentElement) ||
						link.parentElement.children.length !== 1 ||
						link.parentElement.attributes.length
				))
		) {
			return;
		}
		if (provider) {
			const region = host.closest('#ran-booster-provider-tasks');
			const nav = host.parentElement;
			const panel = region?.querySelector(
				'#ran-booster-provider-task-panel'
			);
			const overrides = [
				'hx-target',
				'hx-select',
				'hx-swap',
				'hx-push-url',
				'hx-history',
				'hx-sync',
			];
			if (
				!window.htmx?.process ||
				window.htmx.config?.disableInheritance ||
				[
					region,
					nav,
					host,
					...links.map((link) => link.parentElement),
				].some(
					(node) =>
						node?.hasAttribute('hx-disinherit') ||
						node?.hasAttribute('data-hx-disinherit')
				) ||
				nav?.getAttribute('hx-boost') !== 'true' ||
				nav.hasAttribute('data-hx-boost') ||
				nav.parentElement !== region ||
				overrides.some(
					(attribute) =>
						region?.hasAttribute(`data-${attribute}`) ||
						[
							nav,
							host,
							...links.map((link) => link.parentElement),
						].some(
							(node) =>
								node.hasAttribute(attribute) ||
								node.hasAttribute(`data-${attribute}`)
						)
				) ||
				!region ||
				region.getAttribute('hx-target') !==
					'#ran-booster-provider-task-panel' ||
				region.getAttribute('hx-select') !==
					'#ran-booster-provider-task-panel' ||
				region.getAttribute('hx-swap') !==
					'outerHTML transition:false show:none' ||
				region.getAttribute('hx-push-url') !== 'true' ||
				region.getAttribute('hx-history') !== 'false' ||
				region.getAttribute('hx-sync') !== 'this:replace' ||
				!panel ||
				host.contains(panel) ||
				host.ownerDocument.getElementById(
					'ran-booster-provider-task-panel'
				) !== panel ||
				links.some(
					(link) =>
						!link.getAttribute('hx-get') ||
						!link.getAttribute('data-ran-booster-provider-task')
				)
			) {
				return;
			}
		}
		const entry = {
			navigation: true,
			provider,
			original: Array.from(host.childNodes),
			links,
			scroll: host.scrollLeft,
		};
		const scroll = host.scrollLeft;
		class Boundary extends element.Component {
			constructor(props) {
				super(props);
				this.state = { failed: false };
			}
			static getDerivedStateFromError() {
				return { failed: true };
			}
			componentDidCatch() {
				entry.focusIndex ??= entry.lastFocus ?? -1;
				queueMicrotask(() => {
					if (roots.get(host) === entry) {
						restore(host);
					}
				});
			}
			render() {
				return this.state.failed ? null : this.props.children;
			}
		}
		entry.boundary = Boundary;
		try {
			entry.root = element.createRoot(host);
			roots.set(host, entry);
			renderNavigation(host, entry);
			host.scrollLeft = scroll;
			if (provider) {
				navigationLinks(host, true).forEach((link) =>
					window.htmx.process(link)
				);
			}
			delete entry.scroll;
		} catch {
			if (roots.get(host) === entry) {
				restore(host);
			}
		}
	}

	function setNavigationCurrent(region, task) {
		const host = Array.from(roots.keys()).find(
			(track) =>
				roots.get(track).navigation &&
				roots.get(track).provider &&
				track.isConnected &&
				region.contains(track)
		);
		if (!host) {
			return undefined;
		}
		const entry = roots.get(host);
		const visible = navigationLinks(host, true);
		const index = visible.indexOf(host.ownerDocument.activeElement);
		const scroll = host.scrollLeft;
		entry.focusIndex = index;
		entry.scroll = scroll;
		entry.links.forEach((link) => {
			const selected =
				typeof task === 'string' &&
				task !== '' &&
				link.getAttribute('data-ran-booster-provider-task') === task;
			link.classList.toggle('current', selected);
			if (selected) {
				link.setAttribute('aria-current', 'page');
			} else {
				link.removeAttribute('aria-current');
			}
		});
		try {
			renderNavigation(host, entry);
			host.scrollLeft = scroll;
			delete entry.focusIndex;
			delete entry.scroll;
			const updated = navigationLinks(host, true);
			const active = host.ownerDocument.activeElement;
			if (
				index >= 0 &&
				(!active ||
					active === host.ownerDocument.body ||
					!active.isConnected)
			) {
				updated[index]?.focus({ preventScroll: true });
			}
			return (
				updated.find(
					(link) =>
						link.getAttribute('data-ran-booster-provider-task') ===
						task
				) || null
			);
		} catch {
			restore(host);
			return undefined;
		}
	}

	function initialize(scope) {
		queueMicrotask(() => {
			if (window.ranBoosterUI) {
				window.ranBoosterUI.setNavigationCurrent = setNavigationCurrent;
			}
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
			const button = scope.closest?.(buttonSelector);
			if (button) {
				mountButton(button);
			}
			scope.querySelectorAll?.(buttonSelector).forEach(mountButton);
			const owners = Array.from(
				scope.querySelectorAll?.(navigationOwner) || []
			);
			const owner = scope.closest?.(navigationOwner);
			if (owner) {
				owners.push(owner);
			}
			owners.forEach((item) => {
				const header = item.previousElementSibling;
				if (header?.matches('header.ran-admin-shell')) {
					mountNavigation(
						header.querySelector(
							':scope > nav.ran-admin-shell__navigation:not(.ran-admin-shell__navigation--breadcrumbs)'
						),
						false
					);
				}
			});
			const providers = Array.from(
				scope.querySelectorAll?.(providerSelector) || []
			);
			const provider = scope.closest?.(providerSelector);
			if (provider) {
				providers.push(provider);
			}
			providers.forEach((nav) => {
				if (nav.closest('.ran-booster-admin')) {
					mountNavigation(
						nav.querySelector(':scope > ul.filter-links'),
						true
					);
				}
			});
		});
	}

	function retryNavigation() {
		setTimeout(() => initialize(document), 0);
	}
	document.addEventListener(
		'pointerdown',
		(event) => {
			pointerActive = true;
			pointerEpoch++;
			roots.forEach((entry, host) => {
				if (entry.navigation && !host.contains(event.target)) {
					entry.lastFocus = -1;
				}
			});
		},
		true
	);
	function releasePointer() {
		const epoch = pointerEpoch;
		setTimeout(() => {
			if (epoch === pointerEpoch) {
				pointerActive = false;
			}
			initialize(document);
		}, 0);
	}
	document.addEventListener('pointerup', releasePointer, true);
	document.addEventListener('pointercancel', releasePointer, true);
	document.addEventListener(
		'keydown',
		(event) => {
			if (event.key === 'Tab') {
				roots.forEach((entry) => {
					if (entry.navigation) {
						entry.lastFocus = -1;
					}
				});
			}
			if (event.key === 'Enter' || event.key === ' ') {
				activeKeys.set(event.key, (activeKeys.get(event.key) || 0) + 1);
			}
		},
		true
	);
	document.addEventListener(
		'focusin',
		(event) => {
			roots.forEach((entry, host) => {
				if (entry.navigation) {
					entry.lastFocus = navigationLinks(
						host,
						entry.provider
					).indexOf(event.target);
				}
			});
		},
		true
	);
	document.addEventListener(
		'keyup',
		(event) => {
			if (event.key === 'Enter' || event.key === ' ') {
				const epoch = activeKeys.get(event.key);
				setTimeout(() => {
					if (activeKeys.get(event.key) === epoch) {
						activeKeys.delete(event.key);
					}
					initialize(document);
				}, 0);
			}
		},
		true
	);
	document.addEventListener(
		'focusout',
		(event) => {
			roots.forEach((entry, host) => {
				if (entry.navigation && !host.contains(event.relatedTarget)) {
					entry.lastFocus = -1;
				}
			});
			retryNavigation();
		},
		true
	);
	document.addEventListener('htmx:afterRequest', retryNavigation);
	document.addEventListener('ran-booster:ui-ready', () =>
		initialize(document)
	);

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
