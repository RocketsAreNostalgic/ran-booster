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
	const INTENTS = ['success', 'error', 'warning'];

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
						explicitDismiss: true,
						// The inline failure a pointer leads to owns the
						// announcement, so the pointer stays silent.
						...(item.intent === 'success'
							? {}
							: { spokenMessage: '' }),
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
		if (item.intent === 'success' && !item.hover && !item.focus) {
			item.started = Date.now();
			item.timer = window.setTimeout(
				() => remove(item.uid),
				item.remaining
			);
		}
	}

	function hold(uid, kind, on) {
		const item = find(uid);
		if (!item || item.intent !== 'success' || item[kind] === on) {
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
	// goes instead of holding one of the slots.
	function prune() {
		state?.items
			.filter((item) => item.target && !item.target.isConnected)
			.forEach((item) => remove(item.uid));
	}

	// Show one confirmed result (fixed Core copy). Success items dismiss
	// themselves after DURATION. An error or warning is only a pointer to its
	// persistent inline message (options.target), never timed or shown alone.
	// Returns false when nothing was shown so the caller keeps its own result.
	function show(message, options = {}) {
		const { intent = 'success', target = null, action = null } = options;
		const text = typeof message === 'string' ? message.trim() : '';
		const id = options.id || text;
		if (
			!text ||
			!INTENTS.includes(intent) ||
			(intent !== 'success' && !target?.isConnected)
		) {
			return false;
		}
		prune();
		const duplicate = state?.items.find((item) => item.id === id);
		if (duplicate) {
			state.returnControl = options.returnControl || state.returnControl;
			duplicate.remaining = DURATION;
			if (intent === 'success') {
				// The component speaks only on mount; a repeat is spoken here.
				window.wp?.a11y?.speak?.(text, 'polite');
			} else {
				duplicate.target = target;
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
		if (intent !== 'success') {
			item.action = {
				label: window.wp.i18n.__('Show details', 'ran-booster'),
				onClick: () => {
					const { target: current } = item;
					if (!current.matches('a[href], button, input, select')) {
						current.setAttribute('tabindex', '-1');
					}
					current.focus();
				},
			};
		}
		if (state.items.length >= LIMIT) {
			// The oldest success goes. A pointer to a persistent error is never
			// dropped: with only pointers visible the new item is not shown (a
			// success keeps its static form, a pointer's inline message is the
			// record). To be revisited after use.
			const evicted = state.items.find((i) => i.intent === 'success');
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

	// Convert the server-rendered fallback into snackbars, leaving any item
	// that cannot be shown (no host, no inline target, qualified result) as
	// the visible static result.
	function consume() {
		place();
		prune();
		document
			.querySelectorAll('[data-ran-booster-feedback]')
			.forEach((container) => {
				const items = Array.from(
					container.querySelectorAll('[data-feedback-key]')
				);
				// A consumed failure leaves a mark so a later pass still keeps the
				// success of a qualified result static.
				const mark = 'data-ran-booster-feedback-qualified';
				const failure =
					container.hasAttribute(mark) ||
					items.some(
						(item) => item.getAttribute('data-intent') !== 'success'
					);
				if (failure) {
					container.setAttribute(mark, '');
				}
				items.forEach((item) => {
					const intent = item.getAttribute('data-intent');
					const target = document.getElementById(
						item.getAttribute('data-feedback-target') || ''
					);
					if (intent === 'success' ? failure : !target) {
						return;
					}
					const link = item.querySelector(
						'a.ran-booster-feedback__activity'
					);
					const text = Array.from(item.childNodes)
						.filter((node) => node !== link)
						.map((node) => node.textContent)
						.join('');
					// Core builds the Activity link; still accept this origin only.
					const action =
						/^https?:$/.test(link?.protocol) &&
						link.origin === window.location.origin
							? { label: link.textContent.trim(), url: link.href }
							: null;
					const id = item.getAttribute('data-feedback-key');
					if (show(text, { intent, id, target, action })) {
						item.remove();
					} else if (
						intent === 'success' &&
						!item.hasAttribute('data-ran-booster-feedback-spoken')
					) {
						// Not shown: the static result stays, spoken politely once.
						item.setAttribute(
							'data-ran-booster-feedback-spoken',
							''
						);
						window.wp?.a11y?.speak?.(text.trim(), 'polite');
					}
				});
				if (!container.querySelector('[data-feedback-key]')) {
					container.remove();
				}
			});
	}

	Object.defineProperty(window, 'ranBoosterFeedback', {
		value: Object.freeze({ show, consume }),
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', consume, { once: true });
	} else {
		consume();
	}
	document.addEventListener('htmx:afterSwap', consume);
	// Never replay a confirmation from a restored page.
	window.addEventListener('pagehide', dispose);
	document.addEventListener('htmx:beforeHistorySave', dispose);
})();
