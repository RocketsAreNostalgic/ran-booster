(function () {
	'use strict';

	// After a Set up, or a Repair or Validate whose ping was sent, the webhook
	// result checks back for the signed delivery: a few reads of the stored
	// record over about ten seconds, timed from when listening starts, so a
	// hidden tab never restarts the window. A confirmed record brings one
	// render of the panel's own page, which swaps in the badge heading, the
	// steps and the result. Otherwise the server's pending sentence returns.
	// Only one result listens at a time.
	const CHECKS = [1000, 2000, 3000, 5000, 7000, 10000];
	// The waiting state's one intent.
	const WAITING_INTENT = 'info';
	const SECTION = '.ran-booster-repository-webhook-section';
	const PARTS = [
		'#ran-booster-repository-webhook-automation-heading',
		'.ran-booster-repository-webhook-lifecycle',
		'.ran-booster-repository-webhook-management__result',
	];
	const LISTEN = 'data-ran-booster-webhook-listen';
	let active = null;

	function text(node) {
		return (
			node
				?.querySelector('[data-ran-booster-ui-notice-content]')
				?.textContent.replace(/\s+/g, ' ')
				.trim() || ''
		);
	}

	// Ends listening and puts the pending sentence back. A settled warning
	// gets the feedback host's pointer, which is silent, so it is announced
	// here, once.
	function stop(state, settled) {
		if (state.stopped) {
			return;
		}
		state.stopped = true;
		window.clearTimeout(state.timer);
		if (active === state) {
			active = null;
		}
		state.target.textContent = state.original;
		window.ranBoosterNotice?.setIntent(state.notice, state.intent);
		if (!settled || !state.notice.isConnected) {
			return;
		}
		const message = text(state.notice);
		window.ranBoosterFeedback?.show(message, {
			intent: state.intent,
			id: 'repository-webhook-management:delivery',
			target: state.notice,
		});
		window.ranBoosterAnnounce?.(message);
	}

	async function confirm(state) {
		let page = null;
		try {
			const response = await window.fetch(state.settleUrl, {
				credentials: 'same-origin',
			});
			if (!response.ok) {
				throw new Error('Webhook panel unavailable.');
			}
			page = new window.DOMParser().parseFromString(
				await response.text(),
				'text/html'
			);
		} catch {
			stop(state, true);
			return;
		}
		if (state.stopped) {
			return;
		}
		const section = state.notice.closest(SECTION);
		const swaps = PARTS.map((part) => [
			section?.querySelector(part),
			page.querySelector(SECTION + ' ' + part),
		]);
		if (
			typeof window.htmx?.swap !== 'function' ||
			swaps.some(([current, next]) => !current || !next)
		) {
			stop(state, true);
			return;
		}
		state.stopped = true;
		active = null;
		const focused = swaps.some(([current]) =>
			current.contains(section.ownerDocument.activeElement)
		);
		swaps.forEach(([current, next]) =>
			window.htmx.swap(current, next.outerHTML, {
				swapStyle: 'outerHTML',
				swapDelay: 0,
				settleDelay: 0,
				transition: false,
				ignoreTitle: true,
			})
		);
		const result = section.querySelector(
			PARTS[2] + ' [data-ran-booster-ui-notice]'
		);
		// Focus inside a replaced part would fall to the page, so it moves to
		// the result, which is then read on focus rather than announced.
		if (focused && result) {
			result.setAttribute('tabindex', '-1');
			result.focus();
		} else {
			window.ranBoosterAnnounce?.(text(result));
		}
	}

	async function check(state) {
		if (state.stopped) {
			return;
		}
		if (!state.notice.isConnected) {
			stop(state, false);
			return;
		}
		let delivered = false;
		try {
			const response = await window.fetch(state.statusUrl, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			});
			const payload = await response.json();
			if (!response.ok || payload?.success !== true) {
				throw new Error('Webhook delivery status unavailable.');
			}
			delivered = payload.data?.delivered === true;
		} catch {
			stop(state, true);
			return;
		}
		if (state.stopped) {
			return;
		}
		if (delivered) {
			await confirm(state);
		} else if (++state.index < CHECKS.length) {
			schedule(state);
		} else {
			stop(state, true);
		}
	}

	function schedule(state) {
		// A check a hidden tab held back skips the slots it missed, so the
		// window keeps its end and at most one late read follows.
		while (
			state.index < CHECKS.length - 1 &&
			state.start + CHECKS[state.index] <= Date.now()
		) {
			state.index++;
		}
		state.timer = window.setTimeout(
			() => check(state),
			Math.max(0, state.start + CHECKS[state.index] - Date.now())
		);
	}

	function listen(notice) {
		const target = notice.querySelector(
			'[data-ran-booster-webhook-listen-text]'
		);
		const statusUrl = notice.getAttribute(LISTEN);
		const settleUrl = notice.getAttribute(
			'data-ran-booster-webhook-settle'
		);
		const label = notice.getAttribute(
			'data-ran-booster-webhook-listen-label'
		);
		// The marker goes now, so a saved or restored page never listens again.
		notice.removeAttribute(LISTEN);
		if (!target || !statusUrl || !settleUrl || !label) {
			return;
		}
		if (active) {
			stop(active, false);
		}
		active = {
			notice,
			target,
			statusUrl,
			settleUrl,
			original: target.textContent,
			intent: notice.getAttribute('data-ran-booster-ui-notice-intent'),
			start: Date.now(),
			index: 0,
			stopped: false,
			timer: 0,
		};
		target.textContent = label;
		window.ranBoosterNotice?.setIntent(notice, WAITING_INTENT);
		schedule(active);
	}

	function scan(root) {
		const found = root?.matches?.('[' + LISTEN + ']')
			? [root]
			: Array.from(root?.querySelectorAll?.('[' + LISTEN + ']') || []);
		// Only the newest result listens.
		if (found.length) {
			listen(found[found.length - 1]);
		}
	}

	function leave() {
		if (active) {
			stop(active, false);
		}
	}

	document.addEventListener('htmx:load', (event) => scan(event.detail?.elt));
	document.addEventListener('htmx:beforeCleanupElement', (event) => {
		if (active && event.detail?.elt?.contains?.(active.notice)) {
			stop(active, false);
		}
	});
	document.addEventListener('htmx:beforeHistorySave', leave);
	window.addEventListener('pagehide', leave);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => scan(document), {
			once: true,
		});
	} else {
		scan(document);
	}
})();
