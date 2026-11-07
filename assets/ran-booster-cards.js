(function () {
	'use strict';

	if (window.ranBoosterCardsInitialized) {
		return;
	}
	window.ranBoosterCardsInitialized = true;
	const selector = '.ran-booster-card[data-ran-booster-card]';
	const noticeSelector = '[data-ran-booster-ui-notice]';
	const disclosureSelector = [
		'installation-component',
		'settings-configuration',
		'settings-storage',
	]
		.map((kind) => `[data-ran-booster-ui-disclosure="${kind}"]`)
		.join(',');
	const badgeSelector = '[data-ran-booster-ui-badge]';
	const buttonSelector = '[data-ran-booster-ui-button]';
	const switchSelector = '[data-ran-booster-ui-switch="general-preference"]';
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
		if (entry.switchInput) {
			const focused =
				entry.mount.contains(host.ownerDocument.activeElement) ||
				(entry.recoveryPending && entry.recoveryFocus);
			try {
				entry.root.unmount();
			} catch {
				// The native checkbox remains authoritative.
			}
			entry.mount.replaceChildren();
			entry.fallback.hidden = false;
			entry.label.htmlFor = entry.switchInput.id;
			host.removeAttribute(mounted);
			roots.delete(host);
			if (focused) {
				focusAfterMove(host, entry.switchInput);
			}
			return;
		}
		let active = host.contains(host.ownerDocument.activeElement)
			? host.ownerDocument.activeElement
			: null;
		if (entry.disclosure) {
			const trigger = entry.mount.querySelector('[role=button]');
			if (trigger && active === trigger) {
				active = entry.summary;
			}
			if (
				!active &&
				host.ownerDocument.activeElement === host.ownerDocument.body
			) {
				if (entry.body.contains(entry.lastFocus)) {
					active = entry.lastFocus;
				} else if (entry.lastFocusWasTrigger) {
					active = entry.summary;
				}
			}
			entry.details.appendChild(entry.body);
			entry.mount.removeEventListener('focusin', entry.trackFocus);
			entry.mount.removeEventListener('focusout', entry.clearFocus);
			entry.mount.replaceWith(entry.details);
		}
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
			if (entry.disclosure) {
				entry.mount.remove();
			} else if (entry.button) {
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
		const intent = notice
			? (card.getAttribute('data-ran-booster-ui-notice-intent') ??
				'error')
			: null;
		const validIntent =
			!notice || ['error', 'warning', 'info', 'success'].includes(intent);
		const current = roots.get(card);
		if (notice && current && (!validIntent || current.intent !== intent)) {
			restoreAll(
				Array.from(roots.keys()).filter((host) => card.contains(host))
			);
			initialize(card);
			return;
		}
		if (!validIntent) {
			return;
		}
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
					'installation-component',
					'overview',
					'plugins',
					'provider',
					'settings',
					'themes',
					'transporter',
					'package-edit',
					'activity',
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
		if (
			!notice &&
			card.getAttribute('data-ran-booster-card') === 'settings' &&
			Array.from(card.querySelectorAll(switchSelector)).some((host) => {
				const switchEntry = roots.get(host);
				const parts = switchParts(host, switchEntry);
				return parts && switchEngaged(parts, switchEntry);
			})
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
				? { intent, className: 'ran-booster-ui-notice' }
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
			roots.set(card, { root, mount: host, content, intent });
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

	function switchParts(host, current) {
		if (
			!host.isConnected ||
			host.tagName !== 'SPAN' ||
			!host.closest('.ran-booster-admin')
		) {
			return null;
		}
		const fallback = host.querySelector(
			':scope > [data-ran-booster-ui-switch-fallback]'
		);
		const mount = host.querySelector(
			':scope > [data-ran-booster-ui-switch-mount]'
		);
		const input = fallback?.querySelector('input[role="switch"]');
		const scope = input?.form?.dataset.ranBoosterSettingsScope;
		const label = input?.ownerDocument.getElementById(
			input.getAttribute('aria-labelledby')
		);
		if (
			host.children.length !== 2 ||
			fallback?.tagName !== 'LABEL' ||
			mount?.tagName !== 'SPAN' ||
			fallback.children.length !== 2 ||
			input?.parentElement !== fallback ||
			!fallback.querySelector(
				':scope > .ran-booster-settings__switch-control[aria-hidden="true"]'
			) ||
			input?.type !== 'checkbox' ||
			!input.id ||
			!['hide_github', 'self_update_discovery'].includes(scope) ||
			input.name !== `ran_booster[preferences][${scope}]` ||
			label?.tagName !== 'LABEL' ||
			host.contains(label) ||
			label.closest('form') !== input.form ||
			(!current &&
				input.ownerDocument.getElementById(`${input.id}-ui`)) ||
			fallback.htmlFor !== input.id ||
			label.htmlFor !== (current ? `${input.id}-ui` : input.id)
		) {
			return null;
		}
		return { fallback, mount, input, label };
	}

	function switchEngaged({ fallback, mount, input, label }, current) {
		const active = input.ownerDocument.activeElement;
		return (
			label.matches(':active') ||
			(!fallback.hidden &&
				(fallback.contains(active) ||
					fallback.matches(':active') ||
					input.matches(':active'))) ||
			(current?.switchInput === input &&
				(mount.contains(active) || mount.matches(':active')))
		);
	}

	function mountSwitch(host) {
		const current = roots.get(host);
		if (current?.recoveryPending) {
			return;
		}
		const parts = switchParts(host, current);
		const element = window.wp?.element;
		const Switch = window.ranBoosterUI?.Switch;
		if (
			!parts ||
			!Switch ||
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync ||
			!element.Component
		) {
			restore(host);
			return;
		}
		const { fallback, mount, input, label } = parts;
		if (
			!current &&
			(fallback.hidden ||
				switchEngaged(parts) ||
				mount.firstChild ||
				pointerActive ||
				activeKeys.size)
		) {
			return;
		}
		const focusedInput = input.ownerDocument.activeElement === input;
		const focusedUI = mount.contains(input.ownerDocument.activeElement);
		let entry = current;
		try {
			if (!entry) {
				entry = {
					root: element.createRoot(mount),
					mount,
					fallback,
					switchInput: input,
					label,
				};
				const owner = entry;
				class SwitchBoundary extends element.Component {
					constructor(props) {
						super(props);
						this.state = { failed: false };
					}
					static getDerivedStateFromError() {
						return { failed: true };
					}
					getSnapshotBeforeUpdate() {
						owner.focusOwned = mount.contains(
							input.ownerDocument.activeElement
						);
						return null;
					}
					componentDidUpdate() {}
					componentDidCatch() {
						owner.recoveryPending = true;
						owner.recoveryFocus = Boolean(owner.focusOwned);
						queueMicrotask(() => {
							if (
								roots.get(host) === owner &&
								owner.mount === mount
							) {
								restore(host);
							}
						});
					}
					render() {
						return this.state.failed ? null : this.props.children;
					}
				}
				entry.boundary = SwitchBoundary;
				roots.set(host, entry);
			}
			const props = {
				id: `${input.id}-ui`,
				checked: input.checked,
				disabled: input.disabled,
				nativeButton: true,
				render: element.createElement('button', { type: 'button' }),
				onCheckedChange: (checked) => {
					if (input.disabled) {
						return;
					}
					input.checked = checked;
					input.dispatchEvent(new Event('change', { bubbles: true }));
				},
			};
			['aria-labelledby', 'aria-describedby', 'aria-invalid'].forEach(
				(name) => {
					if (input.hasAttribute(name)) {
						props[name] = input.getAttribute(name);
					}
				}
			);
			element.flushSync(() =>
				entry.root.render(
					element.createElement(
						entry.boundary,
						null,
						element.createElement(Switch, props)
					)
				)
			);
			const button = mount.querySelector('button[role="switch"]');
			if (
				!button ||
				button.id !== props.id ||
				button.type !== 'button' ||
				button.hasAttribute('name') ||
				button.hasAttribute('form') ||
				mount.querySelector('input[name]')
			) {
				throw new Error('Missing UI switch');
			}
			fallback.hidden = true;
			label.htmlFor = props.id;
			host.setAttribute(mounted, '');
			if (
				focusedInput &&
				(input.ownerDocument.activeElement === input ||
					input.ownerDocument.activeElement ===
						input.ownerDocument.body ||
					!input.ownerDocument.activeElement)
			) {
				button.focus({ preventScroll: true });
			}
		} catch {
			restore(host);
			if (focusedUI) {
				focusAfterMove(host, input);
			}
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

	function updateNavigationIndicator(host, entry, animate = false) {
		if (
			!host.isConnected ||
			roots.get(host) !== entry ||
			!host.matches('.ran-section-navigation')
		) {
			return;
		}
		const current = navigationLinks(host, entry.provider).find(
			(link) => link.getAttribute('aria-current') === 'page'
		);
		if (
			!current ||
			!host.querySelector('.ran-section-navigation__indicator')
		) {
			host.classList.remove('ran-section-navigation--ready');
			return;
		}
		const track = host.getBoundingClientRect();
		const link = current.getBoundingClientRect();
		const size =
			parseFloat(
				window
					.getComputedStyle(host)
					.getPropertyValue('--ran-section-indicator-size')
			) || 2;
		host.classList.toggle('ran-section-navigation--moving', animate);
		host.style.setProperty(
			'--ran-section-indicator-x',
			`${link.left - track.left + host.scrollLeft - host.clientLeft}px`
		);
		host.style.setProperty(
			'--ran-section-indicator-top',
			`${link.bottom - track.top + host.scrollTop - host.clientTop - size}px`
		);
		host.style.setProperty(
			'--ran-section-indicator-width',
			`${link.width}px`
		);
		host.classList.add('ran-section-navigation--ready');
	}

	function observeNavigation(host, entry) {
		if (window.ResizeObserver) {
			entry.navigationResize = new window.ResizeObserver(() =>
				updateNavigationIndicator(host, entry)
			);
			entry.navigationResize.observe(host);
			navigationLinks(host, entry.provider).forEach((link) =>
				entry.navigationResize.observe(link)
			);
		}
		entry.navigationFocus = (event) => {
			if (navigationLinks(host, entry.provider).includes(event.target)) {
				event.target.scrollIntoView({
					block: 'nearest',
					inline: 'nearest',
				});
			}
		};
		host.addEventListener('focusin', entry.navigationFocus);
	}

	function restoreNavigation(host, entry) {
		entry.navigationResize?.disconnect();
		host.removeEventListener('focusin', entry.navigationFocus);
		host.classList.remove(
			'ran-section-navigation--ready',
			'ran-section-navigation--moving'
		);
		for (const property of ['x', 'top', 'width']) {
			host.style.removeProperty(`--ran-section-indicator-${property}`);
		}

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

	function renderNavigation(host, entry, animate = false) {
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
		updateNavigationIndicator(host, entry, animate);
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
				entry.scroll ??= host.scrollLeft;
				entry.focusIndex ??= navigationLinks(host, provider).indexOf(
					host.ownerDocument.activeElement
				);
				return { failed: true };
			}
			componentDidCatch() {
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
			observeNavigation(host, entry);
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
			renderNavigation(host, entry, true);
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

	function mountDisclosure(host, restoreFocus = false) {
		if (
			roots.has(host) ||
			!host.isConnected ||
			!host.closest('.ran-booster-admin')
		) {
			return;
		}
		const details = host.querySelector(
			':scope > details[data-ran-booster-ui-disclosure-fallback]'
		);
		const summary = details?.querySelector(':scope > summary');
		const body = details?.querySelector(
			':scope > div[data-ran-booster-ui-disclosure-content]'
		);
		const supportedChildren =
			details &&
			[...details.childNodes].every(
				(node) =>
					node === summary ||
					node === body ||
					(node.nodeType === 3 && !node.textContent.trim())
			) &&
			details.children.length === 2 &&
			details.children[0] === summary &&
			details.children[1] === body;
		const ui = window.ranBoosterUI?.CollapsibleCard,
			e = window.wp?.element;
		const label =
			summary?.getAttribute('aria-label') ||
			(host.getAttribute('data-ran-booster-ui-disclosure') !==
			'installation-component'
				? summary?.textContent.trim()
				: '');
		if (
			!details ||
			!summary ||
			!body ||
			!supportedChildren ||
			!body.children.length ||
			!label ||
			(details.contains(host.ownerDocument.activeElement) &&
				!(
					restoreFocus && host.ownerDocument.activeElement === summary
				)) ||
			!ui?.Root ||
			!ui?.Header ||
			!ui?.Content ||
			!e?.createRoot ||
			!e?.flushSync ||
			!window.React?.Component
		) {
			return;
		}
		const mount = document.createElement('div');
		let root;
		try {
			const h = e.createElement;
			const panelRef = window.React.createRef();
			let recovering = false;
			class Boundary extends window.React.Component {
				constructor(props) {
					super(props);
					this.state = { failed: false };
				}
				static getDerivedStateFromError() {
					recovering = true;
					return { failed: true };
				}
				componentDidCatch() {
					const failed = roots.get(host);
					if (!failed || failed.mount !== mount) {
						return;
					}
					queueMicrotask(() => {
						if (
							roots.get(host) === failed &&
							failed.mount === mount
						) {
							restore(host);
						}
					});
				}
				render() {
					return this.state.failed ? null : this.props.children;
				}
			}
			function Disclosure() {
				const [open, setOpen] = window.React.useState(details.open);
				return h(
					ui.Root,
					{
						className: 'ran-booster-ui-disclosure',
						open,
						onOpenChange: (next) => {
							details.open = next;
							setOpen(next);
						},
					},
					h(
						ui.Header,
						{ className: 'ran-booster-ui-disclosure__header' },
						h(
							'span',
							{ 'aria-hidden': 'true' },
							summary.textContent
						),
						h('span', { className: 'screen-reader-text' }, label)
					),
					h(ui.Content, {
						ref: panelRef,
						keepMounted: true,
						render: h('div', {
							'data-disclosure-slot': '',
							style: { padding: 0 },
						}),
					})
				);
			}
			root = e.createRoot(mount);
			e.flushSync(() => root.render(h(Boundary, null, h(Disclosure))));
			const slots = mount.querySelectorAll('[data-disclosure-slot]');
			const slot = slots[0];
			const triggers = mount.querySelectorAll('[role="button"]');
			const trigger = triggers[0];
			const panel = panelRef.current;
			const controls = trigger?.getAttribute('aria-controls');
			if (
				slots.length !== 1 ||
				triggers.length !== 1 ||
				trigger.tabIndex !== 0 ||
				trigger.closest(
					'[hidden], [inert], :disabled, [aria-hidden="true"], [aria-disabled="true"]'
				) ||
				trigger.getAttribute('aria-expanded') !==
					String(details.open) ||
				!mount.contains(panel) ||
				!panel?.contains(slot) ||
				panel.contains(trigger) ||
				trigger.contains(panel)
			) {
				throw Error('Invalid disclosure trigger or panel');
			}
			const panelId = panel.id;
			if (
				panelId &&
				(host.ownerDocument.getElementById(panelId) ||
					mount.querySelectorAll('#' + window.CSS.escape(panelId))
						.length !== 1)
			) {
				throw Error('Duplicate disclosure panel ID');
			}
			// Base UI omits aria-controls on a closed trigger.
			if (
				(details.open || controls !== null) &&
				(!controls || /\s/.test(controls) || controls !== panelId)
			) {
				throw Error('Invalid disclosure panel association');
			}
			slot.appendChild(body);
			details.replaceWith(mount);
			const entry = {
				root,
				mount,
				disclosure: true,
				details,
				summary,
				body,
				lastFocus: null,
			};
			entry.trackFocus = (event) => {
				entry.lastFocus = event.target;
				entry.lastFocusWasTrigger =
					event.target === mount.querySelector('[role=button]');
			};
			entry.clearFocus = (event) => {
				if (
					!recovering &&
					(!event.relatedTarget ||
						!mount.contains(event.relatedTarget))
				) {
					entry.lastFocus = null;
					entry.lastFocusWasTrigger = false;
				}
			};
			mount.addEventListener('focusin', entry.trackFocus);
			mount.addEventListener('focusout', entry.clearFocus);
			roots.set(host, entry);
			host.setAttribute(mounted, '');
			if (restoreFocus) {
				mount
					.querySelector('[role=button]')
					?.focus({ preventScroll: true });
			}
		} catch {
			if (body.parentElement !== details) {
				details.appendChild(body);
			}
			try {
				root?.unmount();
			} catch {}
			mount.remove();
		}
	}

	function initialize(scope, restoredDisclosure = null) {
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
			const disclosure = scope.closest?.(disclosureSelector);
			if (disclosure) {
				mountDisclosure(disclosure, disclosure === restoredDisclosure);
			}
			scope
				.querySelectorAll?.(disclosureSelector)
				.forEach((host) =>
					mountDisclosure(host, host === restoredDisclosure)
				);
			const badge = scope.closest?.(badgeSelector);
			if (badge) {
				mountBadge(badge);
			}
			scope.querySelectorAll?.(badgeSelector).forEach(mountBadge);
			const switchHost = scope.closest?.(switchSelector);
			if (switchHost) {
				mountSwitch(switchHost);
			}
			scope.querySelectorAll?.(switchSelector).forEach(mountSwitch);
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
		() => {
			pointerActive = true;
			pointerEpoch++;
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
			if (event.key === 'Enter' || event.key === ' ') {
				activeKeys.set(event.key, (activeKeys.get(event.key) || 0) + 1);
			}
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
	document.addEventListener('focusout', retryNavigation, true);

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
	document.addEventListener('ran-booster:ui-notice-update', (event) => {
		const host = event.target.closest?.(noticeSelector);
		if (host) {
			initialize(host);
		}
	});
	document.addEventListener('ran-booster:ui-switch-update', (event) => {
		const host = event.target.closest?.(switchSelector);
		if (host) {
			mountSwitch(host);
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
		const restoredDisclosure =
			Array.from(roots.entries()).find(
				([host, entry]) =>
					entry.disclosure &&
					host.contains(host.ownerDocument.activeElement)
			)?.[0] || null;
		restoreAll(Array.from(roots.keys()));
		initialize(document, restoredDisclosure);
	});
	document.addEventListener('htmx:load', (event) =>
		initialize(event.detail.elt || event.target)
	);
	document.addEventListener('htmx:afterProcessNode', (event) =>
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
