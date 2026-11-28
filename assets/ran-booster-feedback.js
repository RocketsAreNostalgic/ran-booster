(function () {
	'use strict';

	if (window.ranBoosterFeedback) {
		return;
	}

	// The one success duration (#755 R5). WordPress's own Snackbar hard-codes
	// its timeout and cannot be paused, so every item is presented with
	// explicitDismiss and this host runs the timer.
	const DURATION = 10000;
	const LIMIT = 3;
	const INTENTS = ['success', 'info', 'error', 'warning'];
	// Success and info stand alone and dismiss themselves; an error or
	// warning is only a pointer to its inline message.
	const timed = (intent) => intent === 'success' || intent === 'info';
	const TAKEN = 'data-ran-booster-feedback-taken';
	const SPOKEN = 'data-ran-booster-feedback-spoken';

	let state = null;
	let sequence = 0;

	function idOf(node) {
		const match = node
			?.closest?.('.components-snackbar')
			?.className.match(/ran-booster-snackbar-(\d+)/);
		return match ? Number(match[1]) : null;
	}

	function find(uid) {
		return state?.items.find((item) => item.uid === uid);
	}

	function render() {
		const { createElement, flushSync } = window.wp.element;
		flushSync(() =>
			state.root.render(
				createElement(window.wp.components.SnackbarList, {
					notices: state.items.map((item) => ({
						id: item.uid,
						className: `ran-booster-snackbar--${item.intent} ran-booster-snackbar-${item.uid}`,
						content: item.message,
						icon: createElement('span', {
							ref: (node) => {
								if (!node) {
									return;
								}
								const source = document
									.getElementById('ran-booster-notice-icons')
									?.content.querySelector(
										`[data-ran-booster-notice-icon="${item.intent}"]`
									);
								node.replaceChildren(
									...(source ? [source.cloneNode(true)] : [])
								);
							},
						}),
						explicitDismiss: true,
						// The inline failure a pointer leads to owns the
						// announcement, so the pointer stays silent.
						...(timed(item.intent) ? {} : { spokenMessage: '' }),
						actions: item.action ? [item.action] : [],
					})),
					onRemove: remove,
				})
			)
		);
	}

	function arm(item) {
		window.clearTimeout(item.timer);
		item.timer = null;
		if (timed(item.intent) && !item.hover && !item.focus) {
			item.started = Date.now();
			item.timer = window.setTimeout(
				() => remove(item.uid),
				item.remaining
			);
		}
	}

	function hold(uid, kind, on) {
		const item = find(uid);
		if (!item || !timed(item.intent) || item[kind] === on) {
			return;
		}
		if (item.timer) {
			item.remaining -= Date.now() - item.started;
		}
		item[kind] = on;
		arm(item);
	}

	function restoreFocus(uid = null) {
		const active = state.host.ownerDocument.activeElement;
		const owner = idOf(active);
		if (
			!state.host.contains(active) ||
			(owner !== null && owner !== uid && uid !== null)
		) {
			return;
		}
		const candidates = [
			...(state.returnControl ? [state.returnControl] : []),
			...document.querySelectorAll(
				'[data-ran-booster-package-settings-save]'
			),
			...document.querySelectorAll('#ran-booster-save-numeric-bottom'),
			...document.querySelectorAll(
				'#ran-booster-general-region [data-ran-booster-ui-switch-mount] button[role="switch"], #ran-booster-general-region input[type="number"], #ran-booster-general-region input[role="switch"]'
			),
			...document.querySelectorAll('#wpadminbar a[href]'),
		];
		for (const candidate of candidates) {
			if (
				!candidate.isConnected ||
				candidate.disabled ||
				!candidate.getClientRects().length
			) {
				continue;
			}
			const rect = candidate.getBoundingClientRect();
			if (
				rect.left < 0 ||
				rect.top < 0 ||
				rect.right > window.innerWidth ||
				rect.bottom > window.innerHeight
			) {
				continue;
			}
			candidate.focus({ preventScroll: true });
			if (state.host.ownerDocument.activeElement === candidate) {
				break;
			}
		}
	}

	function dispose() {
		if (!state) {
			return;
		}
		restoreFocus();
		const { host, root, items } = state;
		state = null;
		items.forEach((item) => window.clearTimeout(item.timer));
		root.unmount();
		host.remove();
	}

	function remove(uid) {
		const item = find(uid);
		if (!item) {
			return;
		}
		restoreFocus(uid);
		window.clearTimeout(item.timer);
		state.items = state.items.filter((candidate) => candidate !== item);
		if (state.items.length) {
			render();
		} else {
			dispose();
		}
	}

	// The Booster frame is the one box every Booster screen shares; the
	// toast is centred in it, wherever its width comes from.
	function parent() {
		return (
			document.querySelector('.wrap.ran-booster-admin') ||
			document.getElementById('wpcontent') ||
			document.body
		);
	}

	// Some swaps replace the whole frame; the host follows the current one.
	function place() {
		const target = state && parent();
		if (target && state.host.parentNode !== target) {
			target.append(state.host);
		}
	}

	// Mount the host once. It measures the Booster frame as a size
	// container, so CSS alone centres the list.
	function mount() {
		if (state) {
			place();
			return true;
		}
		const element = window.wp?.element;
		if (
			!element?.createRoot ||
			!element.flushSync ||
			!window.wp?.components?.SnackbarList
		) {
			return false;
		}
		// WordPress's component styles are not a guaranteed API.
		const probe = document.createElement('div');
		probe.className = 'components-snackbar';
		probe.style.cssText = 'position:fixed;visibility:hidden';
		document.body.append(probe);
		const style = window.getComputedStyle(probe);
		const ready =
			parseFloat(style.paddingLeft) > 0 &&
			style.backgroundColor !== 'rgba(0, 0, 0, 0)' &&
			style.backgroundColor !== 'transparent';
		probe.remove();
		if (!ready) {
			return false;
		}
		const host = document.createElement('div');
		host.className = 'ran-booster-snackbar-host';
		parent().append(host);
		try {
			state = { host, root: element.createRoot(host), items: [] };
		} catch {
			host.remove();
			return false;
		}
		const track = (kind, on) => (event) => {
			const uid = idOf(event.target);
			if (uid !== null && (on || idOf(event.relatedTarget) !== uid)) {
				hold(uid, kind, on);
			}
		};
		host.addEventListener('pointerover', track('hover', true));
		host.addEventListener('pointerout', track('hover', false));
		host.addEventListener('focusin', track('focus', true));
		host.addEventListener('focusout', track('focus', false));
		host.addEventListener('keydown', (event) => {
			const uid = idOf(event.target);
			if (event.key === 'Escape' && uid !== null) {
				remove(uid);
			}
		});
		return true;
	}

	// A pointer whose inline message was swapped away leads nowhere, so it
	// goes instead of holding one of the slots. So does a pointer to a coded
	// notice that is hidden or was released for a new result (its taken mark
	// cleared), as that notice no longer says what the pointer does.
	function prune() {
		state?.items
			.filter(
				({ target, notice }) =>
					target &&
					(!target.isConnected ||
						(notice &&
							(target.hidden || !target.hasAttribute(TAKEN))))
			)
			.forEach((item) => remove(item.uid));
	}

	// The inline record can contain only the sentence already in the snackbar.
	// Offer navigation only when it leads to additional content or an action.
	function detail(target, message) {
		const available = (node) =>
			!node.closest(
				'[hidden], [inert], :disabled, [aria-hidden="true"], [aria-disabled="true"]'
			) && node.getClientRects().length > 0;
		// Enhancement replaces the native details with a live trigger. Prefer it
		// when both exist, as a retained fallback can be hidden.
		const triggers = Array.from(
			target.querySelectorAll?.(
				'[data-ran-booster-ui-disclosure] [role="button"][aria-expanded], [data-ran-booster-ui-disclosure] button[aria-expanded]'
			) || []
		);
		const native = Array.from(
			target.querySelectorAll?.('details > summary') || []
		);
		const trigger = [...triggers, ...native].find(available);
		if (trigger) {
			return { node: trigger, disclosure: true };
		}
		const normalize = (text) => text.replace(/\s+/g, ' ').trim();
		const content =
			target.querySelector?.('[data-ran-booster-ui-notice-content]') ||
			target;
		const control = Array.from(
			content.querySelectorAll?.(
				'a[href], button, input:not([type="hidden"]), select, textarea'
			) || []
		).find(available);
		if (control) {
			return { node: control };
		}
		const paragraph = Array.from(
			content.querySelectorAll?.('p') || []
		).find(
			(node) =>
				available(node) &&
				normalize(node.textContent) &&
				normalize(node.textContent) !== normalize(message)
		);
		return paragraph ? { node: paragraph } : null;
	}

	function pointerAction(item, explicitAction) {
		const found = detail(item.target, item.message);
		if (!found) {
			return explicitAction;
		}
		return {
			label: window.wp.i18n.__('Show details', 'ran-booster'),
			onClick: () => {
				const current = detail(item.target, item.message);
				if (!current) {
					return;
				}
				const { node, disclosure } = current;
				if (disclosure) {
					const native =
						node.matches('summary') && node.parentElement;
					if (native) {
						native.open = true;
					} else if (node.getAttribute('aria-expanded') === 'false') {
						node.click();
					}
				}
				if (
					!node.matches(
						'a[href], button, input, select, textarea, summary, [role="button"]'
					)
				) {
					node.setAttribute('tabindex', '-1');
				}
				node.focus({ preventScroll: true });
				node.scrollIntoView?.({ block: 'nearest' });
			},
		};
	}

	// Show one confirmed result (fixed Core copy). Success items dismiss
	// themselves after DURATION. An error or warning is only a pointer to its
	// persistent inline message (options.target), never timed or shown alone.
	// Returns false when nothing was shown so the caller keeps its own result.
	function show(message, options = {}) {
		const { intent = 'success', target = null, action = null } = options;
		const text = typeof message === 'string' ? message.trim() : '';
		if (
			!text ||
			!INTENTS.includes(intent) ||
			(!timed(intent) && !target?.isConnected)
		) {
			return false;
		}
		prune();
		// A repeat is the same result only when key, intent and copy all match;
		// any other message is its own item.
		const id = [options.id || '', intent, text].join('\u0000');
		const duplicate = state?.items.find((item) => item.id === id);
		if (duplicate) {
			state.returnControl = options.returnControl || state.returnControl;
			duplicate.remaining = DURATION;
			if (timed(intent)) {
				// The component speaks only on mount; a repeat is spoken here.
				window.wp?.a11y?.speak?.(text, 'polite');
			} else {
				duplicate.target = target;
				duplicate.notice = false;
				duplicate.action = pointerAction(duplicate, action);
				render();
			}
			if (duplicate.timer) {
				arm(duplicate);
			}
			return true;
		}
		if (!mount()) {
			return false;
		}
		const item = {
			id,
			uid: ++sequence,
			intent,
			message: text,
			remaining: DURATION,
			hover: false,
			focus: false,
			action,
			target,
		};
		if (!timed(intent)) {
			item.action = pointerAction(item, action);
		}

		if (state.items.length >= LIMIT) {
			// The oldest success goes. A pointer to a persistent error is never
			// dropped: with only pointers visible the new item is not shown (a
			// success keeps its static form, a pointer's inline message is the
			// record). To be revisited after use.
			const evicted = state.items.find((i) => timed(i.intent));
			if (!evicted) {
				return false;
			}
			restoreFocus(evicted.uid);
			window.clearTimeout(evicted.timer);
			state.items = state.items.filter((i) => i !== evicted);
		}
		state.returnControl = options.returnControl || state.returnControl;
		state.items.push(item);
		try {
			render();
			if (!state.host.querySelector('.components-snackbar')) {
				throw new Error('not rendered');
			}
		} catch {
			dispose();
			return false;
		}
		arm(item);
		return true;
	}

	// Convert the server-rendered results into snackbars. A success item is
	// the static form of its snackbar and goes once shown. A coded notice is
	// its own record: the host reads its key, intent and first paragraph, and
	// shows an error or warning only as a pointer to it. The notice stays, so
	// there is one element per result with or without JavaScript. Whatever
	// cannot be shown (no host, no room) stays as the visible static result.
	function present(node) {
		const notice = node.hasAttribute('data-ran-booster-ui-notice');
		const intent = node.getAttribute(
			notice ? 'data-ran-booster-ui-notice-intent' : 'data-intent'
		);
		const link = node.querySelector('a.ran-booster-feedback__activity');
		const text =
			(notice
				? node.querySelector('[data-ran-booster-ui-notice-content] > p')
						?.textContent
				: Array.from(node.childNodes)
						.filter((child) => child !== link)
						.map((child) => child.textContent)
						.join('')
			)?.trim() ?? '';
		// Core builds the Activity link; still accept this origin only.
		const action =
			/^https?:$/.test(link?.protocol) &&
			link.origin === window.location.origin
				? { label: link.textContent.trim(), url: link.href }
				: null;
		const id = node.getAttribute('data-feedback-key');
		const target = notice && !timed(intent) ? node : null;
		if (show(text, { intent, id, target, action })) {
			// The notice is the record, so it stays; a success item goes.
			if (notice) {
				node.setAttribute(TAKEN, '');
				const pointer = state.items.find(
					(item) => item.target === node
				);
				if (pointer) {
					pointer.notice = true;
				}
			} else {
				node.remove();
			}
			return 1;
		}
		if (timed(intent) && !node.hasAttribute(SPOKEN)) {
			// Not shown: the static result stays, spoken politely once.
			node.setAttribute(SPOKEN, '');
			window.wp?.a11y?.speak?.(text, 'polite');
		}
		return 0;
	}

	function consume() {
		let taken = 0;
		place();
		prune();
		document
			.querySelectorAll('[data-ran-booster-feedback]')
			.forEach((container) => {
				container
					.querySelectorAll('[data-feedback-key]')
					.forEach((item) => (taken += present(item)));
				if (!container.querySelector('[data-feedback-key]')) {
					container.remove();
				}
			});
		document
			.querySelectorAll('[data-ran-booster-ui-notice][data-feedback-key]')
			.forEach((notice) => {
				taken +=
					notice.hidden || notice.hasAttribute(TAKEN)
						? 0
						: present(notice);
			});
		return taken;
	}

	// A result reached by a full page load carries its closed marker in the
	// address. Once the host has taken any item (success, pointer or info),
	// strip the marker so a reload or Back never shows the earlier result
	// again. The inline record of a failure stays in the page until the
	// person leaves it. Swaps are left to the mutation handler.
	const RESULT_ARGUMENT =
		/^(ran_booster_result|ran_booster_package|_ran_booster_notice_nonce|ran_booster_extension_result|ran_booster_extension_id|_ran_booster_bulk_notice_nonce|ran_booster_bulk_.+|ran_booster_interaction_(?:operation|target|outcome|return|error_region)|_ran_booster_interaction_nonce|ran_booster_release_(?:result|success|type|package|result_nonce|channel|reason|detail|workflow_.+))$/;

	function cleanResultUrl() {
		try {
			const url = new URL(window.location.href);
			const keys = Array.from(url.searchParams.keys()).filter((key) =>
				RESULT_ARGUMENT.test(key)
			);
			if (keys.length) {
				keys.forEach((key) => url.searchParams.delete(key));
				window.history.replaceState(window.history.state, '', url);
			}
		} catch {
			// The address stays as it is.
		}
	}

	function consumeLoaded() {
		if (consume() > 0) {
			// A provider interaction that reloaded the page: put focus back on the
			// section heading that a located swap used to move it to.
			const operation = new URL(window.location.href).searchParams.get(
				'ran_booster_interaction_operation'
			);
			if (operation?.startsWith('core:')) {
				document
					.querySelector('[data-ran-booster-provider-profile-focus]')
					?.focus({ preventScroll: true });
			}
			cleanResultUrl();
		}
	}

	Object.defineProperty(window, 'ranBoosterFeedback', {
		value: Object.freeze({ show, consume, cleanResultUrl }),
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', consumeLoaded, {
			once: true,
		});
	} else {
		consumeLoaded();
	}
	document.addEventListener('htmx:afterSwap', consume);
	// Never replay a confirmation from a restored page.
	window.addEventListener('pagehide', dispose);
	document.addEventListener('htmx:historyRestore', dispose);
	document.addEventListener('htmx:historyItemCreated', (event) => {
		const item = event.detail.item;
		const snapshot = document.createElement('template');
		snapshot.innerHTML = item.content;
		snapshot.content
			.querySelectorAll('.ran-booster-snackbar-host')
			.forEach((host) => host.remove());
		item.content = snapshot.innerHTML;
	});
})();
