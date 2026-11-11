(function () {
	'use strict';

	if (window.ranBoosterCardsInitialized) {
		return;
	}
	window.ranBoosterCardsInitialized = true;

	// Page Cards and Badges are static markup drawn with the public
	// components' own CSS (ran-booster-wp-ui-static.css) and are never mounted.
	// Scripts that change a Badge status in place go through this one helper.
	const badgeIntents = ['none', 'stable', 'medium', 'high', 'informational'];
	window.ranBoosterBadge = {
		update(badge, label, intent) {
			if (!badge || !badgeIntents.includes(intent)) {
				return;
			}
			badgeIntents.forEach((name) =>
				badge.classList.toggle(`is-${name}-intent`, name === intent)
			);
			badge.setAttribute('data-ran-booster-badge-intent', intent);
			if (typeof label === 'string' && badge.textContent !== label) {
				badge.textContent = label;
			}
		},
	};
	// A static Notice (ran-wp-ui-notice) is drawn with the public Notice's CSS
	// and is never mounted; its intent icon is server-rendered markup.
	const staticNotice = '.ran-wp-ui-notice';
	const noticeSelector = '[data-ran-booster-ui-notice]';
	const disclosureSelector = '[data-ran-booster-ui-disclosure]';
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
			[entry.heading, entry.note, entry.meta].forEach(
				(part) => part && entry.summary.appendChild(part)
			);
			entry.details.appendChild(entry.body);
			if (entry.documentation) {
				entry.details.hidden = false;
			}
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

	function mountNotice(notice) {
		if (notice.matches(staticNotice)) {
			return;
		}
		const intent =
			notice.getAttribute('data-ran-booster-ui-notice-intent') ?? 'error';
		const validIntent = ['error', 'warning', 'info', 'success'].includes(
			intent
		);
		const current = roots.get(notice);
		if (current && (!validIntent || current.intent !== intent)) {
			restoreAll(
				Array.from(roots.keys()).filter((host) => notice.contains(host))
			);
			initialize(notice);
			return;
		}
		if (!validIntent) {
			return;
		}
		const nativeContent = notice.querySelector(
			'[data-ran-booster-ui-notice-content]'
		);
		if (
			roots.has(notice) ||
			!notice.isConnected ||
			(!notice.closest('.ran-booster-admin') &&
				notice.getAttribute('data-ran-booster-ui-notice-scope') !==
					'global') ||
			notice.parentElement?.closest(noticeSelector) ||
			notice.querySelector(noticeSelector) ||
			notice.tagName !== 'DIV' ||
			nativeContent?.parentElement !== notice
		) {
			return;
		}
		const element = window.wp?.element;
		const Surface = window.ranBoosterUI?.NoticeRoot;
		const Description = window.ranBoosterUI?.NoticeDescription;
		if (
			!element?.createRoot ||
			!element.createElement ||
			!element.flushSync ||
			!Surface ||
			!Description
		) {
			return;
		}
		const original = Array.from(notice.childNodes);
		const active = notice.contains(notice.ownerDocument.activeElement)
			? notice.ownerDocument.activeElement
			: null;
		const host = document.createElement('div');
		host.className = 'ran-booster-card__mount';
		notice.appendChild(host);
		let root;
		try {
			const h = element.createElement;
			root = element.createRoot(host);
			element.flushSync(() =>
				root.render(
					h(
						Surface,
						{ intent, className: 'ran-booster-ui-notice' },
						h(Description, {
							render: h('div'),
							className: 'ran-booster-ui-notice__description',
							'data-ran-booster-ui-notice-slot': '',
						})
					)
				)
			);
			const content = host.querySelector(
				'[data-ran-booster-ui-notice-slot]'
			);
			if (!content) {
				throw new Error('Missing component content');
			}
			original.forEach((node) => content.appendChild(node));
			roots.set(notice, { root, mount: host, content, intent });
			notice.setAttribute(mounted, '');
		} catch {
			original.forEach((node) => notice.insertBefore(node, host));
			try {
				root?.unmount();
			} catch {
				// Keep all original native children on failed enhancement.
			}
			host.remove();
		}
		focusAfterMove(notice, active);
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

	function navigationLinks(host, provider) {
		return Array.from(
			host.querySelectorAll(provider ? ':scope > li > a' : ':scope > a')
		);
	}

	function restoreNavigation(host, entry) {
		entry.sectionNavigation?.destroy();
		roots.delete(host);
	}

	// The server renders the links; this only adds the sliding indicator.
	function mountNavigation(host, provider) {
		const UI = window.ranBoosterUI;
		if (!host || !host.isConnected || roots.has(host)) {
			return;
		}
		const sectionNavigation = UI?.createSectionNavigation?.(host);
		if (sectionNavigation) {
			roots.set(host, {
				navigation: true,
				provider,
				sectionNavigation,
			});
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
		const links = navigationLinks(host, true);
		links.forEach((link) => {
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
		roots
			.get(host)
			.sectionNavigation.update({ animate: true, afterPaint: true });
		return (
			links.find(
				(link) =>
					link.getAttribute('data-ran-booster-provider-task') === task
			) || null
		);
	}

	function mountDisclosure(host, restoreFocus = false) {
		if (
			roots.has(host) ||
			!host.isConnected ||
			!host.closest('.ran-booster-admin')
		) {
			return;
		}
		const documentation =
			host.getAttribute('data-ran-booster-ui-disclosure') ===
			'documentation';
		const documentationRoot = documentation
			? host.closest('.ran-booster-documentation')
			: null;
		const details = host.querySelector(
			':scope > details[data-ran-booster-ui-disclosure-fallback]'
		);
		const summary = details?.querySelector(':scope > summary');
		const body = details?.querySelector(
			':scope > div[data-ran-booster-ui-disclosure-content]'
		);
		const remembered = details?.id
			? window.ranBoosterUI?.rememberedDisclosureStates?.[details.id]
			: undefined;
		if (typeof remembered === 'boolean') {
			details.open = remembered;
		}
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
		const meta = summary?.querySelector(
			':scope > [data-ran-booster-ui-disclosure-meta]'
		);
		const heading = summary?.querySelector(
			':scope > [data-ran-booster-ui-disclosure-heading]'
		);
		const note = summary?.querySelector(
			'[data-ran-booster-ui-disclosure-warning]'
		);
		let title = summary?.textContent.trim() ?? '';
		if (meta) {
			const titleSource = summary.cloneNode(true);
			titleSource
				.querySelector('[data-ran-booster-ui-disclosure-meta]')
				.remove();
			title = titleSource.textContent.trim();
		}
		const label =
			summary?.getAttribute('aria-label') ||
			(host.getAttribute('data-ran-booster-ui-disclosure') !==
			'installation-component'
				? title
				: '');
		if (
			(documentation && !documentationRoot) ||
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
			const headerProps = {
				className: 'ran-booster-ui-disclosure__header',
			};
			if (heading) {
				headerProps.render = h(
					heading.getAttribute(
						'data-ran-booster-ui-disclosure-heading'
					) || 'h4'
				);
			}
			let updateOpen;
			function Disclosure() {
				const [open, setOpen] = window.React.useState(details.open);
				updateOpen = setOpen;
				return h(
					ui.Root,
					{
						className: 'ran-booster-ui-disclosure',
						open,
						onOpenChange: (next) => {
							details.open = next;
							if (documentation) {
								documentationRoot.dispatchEvent(
									new window.CustomEvent(
										'ran-booster:documentation-state',
										{
											bubbles: true,
										}
									)
								);
							} else {
								setOpen(next);
							}
						},
					},
					h(
						ui.Header,
						headerProps,
						h(
							'span',
							{
								'aria-hidden': 'true',
								ref:
									heading && ((n) => n?.appendChild(heading)),
							},
							heading ? null : title
						),
						h('span', { className: 'screen-reader-text' }, label),
						meta
							? h('span', {
									className:
										'ran-booster-ui-disclosure__meta',
									ref: (node) => node?.appendChild(meta),
								})
							: null
					),
					note && h('div', { ref: (n) => n?.appendChild(note) }),
					h(ui.Content, {
						className: documentation
							? 'ran-booster-documentation__ui-panel'
							: undefined,
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
			if (documentation) {
				details.hidden = true;
				host.appendChild(mount);
			} else {
				details.replaceWith(mount);
			}
			const entry = {
				root,
				mount,
				disclosure: true,
				documentation,
				heading,
				note,
				meta,
				synchronize: () => updateOpen(details.open),
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
			if (documentation) {
				details.hidden = false;
			}
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
			const UI = window.ranBoosterUI;
			if (UI) {
				UI.setNavigationCurrent = setNavigationCurrent;
				UI.disclosureOpenStates = () =>
					Object.fromEntries(
						[...roots.values()]
							.filter(
								(entry) => entry.disclosure && entry.details.id
							)
							.map((entry) => [
								entry.details.id,
								entry.details.open,
							])
					);
				UI.repositoryNavigation ??=
					UI.createRepositoryNavigationBridge?.(roots);
			}
			roots.forEach((entry, host) => {
				if (!host.isConnected) {
					restore(host);
				}
			});
			const notice = scope.closest?.(noticeSelector);
			if (notice) {
				mountNotice(notice);
			}
			scope.querySelectorAll?.(noticeSelector).forEach(mountNotice);
			const disclosure = scope.closest?.(disclosureSelector);
			if (disclosure) {
				mountDisclosure(disclosure, disclosure === restoredDisclosure);
			}
			scope
				.querySelectorAll?.(disclosureSelector)
				.forEach((host) =>
					mountDisclosure(host, host === restoredDisclosure)
				);
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
			const repositoryNavigation = UI?.repositoryNavigation;
			if (repositoryNavigation) {
				scope
					.querySelectorAll?.(repositoryNavigation.selector)
					.forEach(repositoryNavigation.mount);
			}
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

	document.addEventListener('ran-booster:ui-disclosure-update', (event) => {
		const host = event.target.closest?.(disclosureSelector);
		if (host) {
			mountDisclosure(host);
		}
	});
	document.addEventListener('ran-booster:documentation-state', (event) => {
		const scope = event.target;
		if (!scope.matches?.('.ran-booster-documentation')) {
			return;
		}
		window.wp?.element?.flushSync?.(() => {
			roots.forEach((entry, host) => {
				if (entry.documentation && scope.contains(host)) {
					entry.synchronize();
				}
			});
		});
		if (scope.dataset.ranBoosterDocumentationDeepLinksBound !== 'true') {
			initialize(scope);
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
