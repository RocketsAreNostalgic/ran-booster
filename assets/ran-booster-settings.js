(() => {
	if (window.ranBoosterSettingsInitialized) {
		return;
	}
	window.ranBoosterSettingsInitialized = true;
	const states = new Map();
	const requests = new WeakMap();
	const selector = '[data-ran-booster-settings-scope]';
	let nativeSubmitting;
	let activeRequest;
	const waiting = new Map();
	const startupGestureActive = () =>
		[...document.querySelectorAll(selector)].some(
			(form) =>
				['hide_github', 'self_update_discovery'].includes(
					form.dataset.ranBoosterSettingsScope
				) &&
				(form.matches(':active') || form.querySelector(':active'))
		);
	let startupPending = Boolean(window.htmx && startupGestureActive());
	const __ = window.wp?.i18n?.__ || ((message) => message);
	const fingerprint = (form) =>
		JSON.stringify(
			[...new FormData(form)].filter(([name]) =>
				name.startsWith('ran_booster[preferences][')
			)
		);
	const stateFor = (element) =>
		states.get(
			element?.closest?.(selector)?.dataset.ranBoosterSettingsScope
		);
	const focusId = (state, element) =>
		state.toggle
			?.closest('[data-ran-booster-ui-switch="general-preference"]')
			?.contains?.(element)
			? state.toggle.id
			: element.id;
	const pending = (state) =>
		Boolean(
			state.flight ||
			state.queued ||
			state.unknown ||
			(state.toggle
				? state.toggle.checked !== state.confirmed
				: fingerprint(state.form) !== state.initial)
		);
	const showError = (state, message) => {
		const error = state.form.querySelector(
			'[data-ran-booster-admin-mutation-error]'
		);
		const primary =
			error.querySelector('[data-ran-booster-ui-notice-primary]') ||
			(!error.hasAttribute('data-ran-booster-ui-notice') ? error : null);
		if (primary) {
			primary.textContent = message;
		}
		for (const detail of error.querySelectorAll(
			'[data-ran-booster-ui-notice-detail]'
		)) {
			detail.hidden = true;
		}
		for (const field of error.querySelectorAll(
			'[data-ran-booster-ui-notice-request-field]'
		)) {
			field.textContent = '';
		}
		error.hidden = false;
		error.setAttribute('role', 'alert');
		error.setAttribute('tabindex', '-1');
	};
	const update = (state) => {
		const dirty = pending(state);
		const status = state.form.querySelector(
			state.toggle
				? '[data-ran-booster-toggle-status]'
				: '[data-ran-booster-unsaved]'
		);
		let message;
		if (state.toggle) {
			message = state.confirmed
				? __('Enabled', 'ran-booster')
				: __('Disabled', 'ran-booster');
			const fallback = status?.querySelector(
				'[data-ran-booster-ui-badge-fallback]'
			);
			if (fallback) {
				if (fallback.textContent !== message) {
					fallback.textContent = message;
				}
				fallback.classList.toggle(
					'ran-booster-badge--ok',
					state.confirmed
				);
				fallback.classList.toggle(
					'ran-booster-badge--neutral',
					!state.confirmed
				);
				status.setAttribute('data-ran-booster-ui-badge-label', message);
				status.setAttribute(
					'data-ran-booster-ui-badge-intent',
					state.confirmed ? 'stable' : 'none'
				);
				status.dispatchEvent(
					new CustomEvent('ran-booster:ui-badge-update', {
						bubbles: true,
					})
				);
			}
		} else {
			message = dirty
				? __('Unsaved numeric changes.', 'ran-booster')
				: __('No pending numeric changes.', 'ran-booster');
			if (state.queued) {
				message = __('Waiting to save…', 'ran-booster');
			}
			if (state.flight) {
				message = __('Saving…', 'ran-booster');
			}
		}
		if (!state.toggle && status && status.textContent !== message) {
			status.textContent = message;
		}
		if (!state.toggle && status) {
			if (state.failed || state.unknown) {
				status.setAttribute('aria-live', 'off');
			} else {
				status.removeAttribute('aria-live');
			}
		}
		state.form.classList.toggle('ran-booster-settings--pending', dirty);
		const retry = state.form.querySelector(
			'[data-ran-booster-toggle-save]'
		);
		if (retry) {
			const hide = Boolean(
				window.htmx && !state.unknown && !state.failed
			);
			if (!hide || !startupPending) {
				retry.hidden = hide;
			}
		}
		state.toggle?.dispatchEvent(
			new CustomEvent('ran-booster:ui-switch-update', { bubbles: true })
		);
	};
	const bind = (deferredScope = null) => {
		const description = document.querySelector(
			'[data-ran-booster-autosave-description]'
		);
		if (description && !startupPending) {
			description.hidden = !window.htmx;
		}
		for (const form of document.querySelectorAll(selector)) {
			const scope = form.dataset.ranBoosterSettingsScope;
			if (states.get(scope)?.form === form) {
				continue;
			}
			const error = form.querySelector(
				'[data-ran-booster-admin-mutation-error]'
			);
			const state = {
				form,
				scope,
				toggle: form.querySelector('input[role="switch"]'),
				confirmed: form.dataset.ranBoosterConfirmed === '1',
				intent: 0,
				initial: form.dataset.ranBoosterBaseline,
				failed: error.hasAttribute('data-ran-booster-ui-notice')
					? error.hasAttribute('data-ran-booster-general-failed')
					: !error.hidden,
				unknown: false,
				flight: null,
			};
			states.set(scope, state);
			if (scope !== deferredScope) {
				update(state);
			}
		}
		for (const [scope, state] of states) {
			if (!state.form.isConnected) {
				states.delete(scope);
			}
		}
	};
	const submit = (state) => {
		if (
			!state.flight &&
			!state.toggle?.disabled &&
			state.form.isConnected
		) {
			window.htmx?.process?.(state.form);
			state.form.requestSubmit();
		}
	};
	const queue = (state) => {
		state.queued = true;
		if (!state.toggle && !state.locked) {
			state.locked = [
				...state.form.querySelectorAll('input[type="number"]'),
			].filter((control) => !control.disabled);
			state.locked.forEach((control) => {
				control.disabled = true;
			});
		}
		waiting.set(state.scope, state);
		update(state);
	};
	const unlock = (state) => {
		state.locked?.forEach((control) => {
			control.disabled = false;
		});
		state.locked = null;
		state.queued = false;
	};
	const uncertain = (state) => {
		state.flight = null;
		state.unknown = true;
		state.failed = true;
		showError(
			state,
			__('Save unconfirmed. Review and retry.', 'ran-booster')
		);
		update(state);
	};
	const scheduleStartupPresentation = () => {
		if (!startupPending) {
			return;
		}
		setTimeout(() => {
			if (!startupPending || startupGestureActive()) {
				return;
			}
			startupPending = false;
			const description = document.querySelector(
				'[data-ran-booster-autosave-description]'
			);
			if (description) {
				description.hidden = !window.htmx;
			}
			for (const state of states.values()) {
				const retry = state.form.querySelector(
					'[data-ran-booster-toggle-save]'
				);
				if (state.form.isConnected && retry) {
					retry.hidden = Boolean(
						window.htmx && !state.unknown && !state.failed
					);
				}
			}
		});
	};
	['click', 'pointerup', 'pointercancel', 'keyup'].forEach((event) =>
		document.addEventListener(event, scheduleStartupPresentation, true)
	);
	bind();
	document.addEventListener('input', (event) => {
		const state = stateFor(event.target);
		if (state && !state.toggle) {
			update(state);
		}
	});
	document.addEventListener('change', (event) => {
		const state = stateFor(event.target);
		if (!state || (state.toggle && event.target !== state.toggle)) {
			return;
		}
		if (state.toggle) {
			++state.intent;
		}
		update(state);
		if (state.toggle && window.htmx && !state.unknown && !state.failed) {
			submit(state);
		}
	});
	document.addEventListener(
		'submit',
		(event) => {
			const state = stateFor(event.target);
			if (!state) {
				return;
			}
			if (state.flight) {
				event.preventDefault();
				return;
			}
			if (window.htmx && activeRequest) {
				event.preventDefault();
				event.stopImmediatePropagation();
				queue(state);
				return;
			}
			if (!window.htmx) {
				nativeSubmitting = state;
				queueMicrotask(() => {
					if (event.defaultPrevented) {
						nativeSubmitting = null;
					}
				});
			}
		},
		true
	);
	document.addEventListener(
		'htmx:beforeRequest',
		(event) => {
			const state = stateFor(event.detail?.elt);
			if (!state) {
				if (
					[...states.values()].some(
						(item) =>
							pending(item) &&
							event.detail?.target?.contains(item.form)
					)
				) {
					if (
						// eslint-disable-next-line no-alert -- Enhanced navigation requires an explicit leave decision.
						!window.confirm(
							__(
								'Leave Settings with unsaved or unconfirmed changes?',
								'ran-booster'
							)
						)
					) {
						event.preventDefault();
					}
				}
				return;
			}
			if (state.flight || activeRequest) {
				event.preventDefault();
				return;
			}
			const origin = state.form.ownerDocument.activeElement;
			const record = {
				state,
				desired: state.toggle?.checked,
				intent: state.intent,
				region: state.form.parentElement,
				values: [...new FormData(state.form)].filter(([name]) =>
					name.startsWith('ran_booster[preferences][')
				),
				token: state.form.elements.namedItem('ran_booster[token]')
					.value,
				focus: state.form.parentElement.contains(origin)
					? focusId(state, origin)
					: null,
				active: origin,
				applied: false,
			};
			state.flight = record;
			activeRequest = record;
			requests.set(event.detail.xhr, record);
			update(state);
		},
		true
	);
	document.addEventListener(
		'htmx:beforeOnLoad',
		(event) => {
			const record = requests.get(event.detail?.xhr);
			if (!record) {
				return;
			}
			const { state } = record;
			if (!state.form.isConnected || state.flight !== record) {
				event.preventDefault();
				return;
			}
			try {
				const parsed = new window.DOMParser().parseFromString(
					event.detail.xhr.responseText,
					'text/html'
				);
				const responseForm = parsed
					.getElementById(`ran-booster-general-${state.scope}`)
					?.querySelector(selector);
				const badge =
					state.toggle &&
					responseForm?.querySelector(
						'[data-ran-booster-toggle-status][data-ran-booster-ui-badge]'
					);
				const error = responseForm?.querySelector(
					'[data-ran-booster-admin-mutation-error][data-ran-booster-ui-notice]'
				);
				const content = error?.querySelector(
					'[data-ran-booster-ui-notice-content]'
				);
				const baseline = JSON.parse(
					responseForm?.dataset.ranBoosterBaseline || 'null'
				);
				if (
					!responseForm ||
					responseForm.dataset.ranBoosterSettingsScope !==
						state.scope ||
					!responseForm.elements.namedItem('ran_booster[token]')
						?.value ||
					!error ||
					!content ||
					!content.querySelector(
						'[data-ran-booster-ui-notice-primary]'
					) ||
					!responseForm.querySelector(
						state.toggle
							? '[data-ran-booster-toggle-status]'
							: '[data-ran-booster-unsaved]'
					) ||
					!responseForm.querySelector(
						state.toggle
							? '[data-ran-booster-toggle-save]'
							: '#ran-booster-save-numeric-bottom'
					) ||
					!record.values.every(([name]) =>
						responseForm.elements.namedItem(name)
					) ||
					!Array.isArray(baseline) ||
					!baseline.every(
						(pair) =>
							Array.isArray(pair) &&
							pair.length === 2 &&
							typeof pair[0] === 'string' &&
							typeof pair[1] === 'string' &&
							Number.isFinite(Number(pair[1]))
					) ||
					(state.toggle &&
						(!badge ||
							!badge.querySelector(
								'[data-ran-booster-ui-badge-fallback]'
							) ||
							!badge.querySelector(
								'[data-ran-booster-ui-badge-mount]'
							) ||
							!responseForm.querySelector(
								'input[role="switch"]'
							) ||
							!['0', '1'].includes(
								responseForm.dataset.ranBoosterConfirmed
							)))
				) {
					event.preventDefault();
					return;
				}
			} catch {
				event.preventDefault();
				return;
			}
			const active = state.form.ownerDocument.activeElement;
			if (record.region.contains(active)) {
				record.focus = focusId(state, active);
				record.active = active;
			} else if (active !== state.form.ownerDocument.body) {
				record.focus = null;
				record.active = active;
			}
			try {
				const message = JSON.parse(
					event.detail.xhr.getResponseHeader('HX-Trigger-After-Swap')
				)?.['ran-booster:admin-mutation-success']?.message;
				record.confirmedResponse =
					typeof message === 'string' && message.length > 0;
			} catch {
				record.confirmedResponse = false;
			}
			state.form.toggleAttribute(
				'data-ran-booster-general-unconfirmed',
				Boolean(state.toggle && record.desired !== state.toggle.checked)
			);
		},
		true
	);
	document.addEventListener('htmx:afterSwap', (event) => {
		const record = requests.get(event.detail?.xhr);
		if (!record) {
			bind();
			return;
		}
		const { state } = record;
		if (
			record.applied ||
			event.target?.id !== `ran-booster-general-${state.scope}`
		) {
			return;
		}
		const region = document.getElementById(
			`ran-booster-general-${state.scope}`
		);
		if (
			!region ||
			region === record.region ||
			!region.querySelector(selector)
		) {
			uncertain(state);
			return;
		}
		record.applied = true;
		const desired = state.toggle?.checked;
		bind(state.scope);
		const next = states.get(state.scope);
		let saved = Boolean(
			record.confirmedResponse &&
			region.hasAttribute('data-ran-booster-general-saved')
		);
		const normalize = (values) =>
			JSON.stringify(
				values.map(([name, value]) => [
					name,
					name.endsWith('[max_download_mib]')
						? Math.round(Number(value) * 1048576)
						: Number(value),
				])
			);
		const conflict =
			saved &&
			(next.toggle
				? next.confirmed !== record.desired
				: normalize(JSON.parse(next.initial)) !==
					normalize(record.values));
		if (conflict) {
			saved = false;
			state.form.setAttribute('data-ran-booster-general-unconfirmed', '');
			let current = JSON.parse(next.initial)
				.map(([, value]) => value)
				.join(' / ');
			if (next.toggle) {
				current = next.confirmed
					? __('On', 'ran-booster')
					: __('Off', 'ran-booster');
			}
			showError(
				next,
				__(
					'Settings changed before confirmation. Review the current saved values before retrying:',
					'ran-booster'
				) +
					' ' +
					current
			);
			if (!next.toggle) {
				for (const [name, value] of record.values) {
					next.form.elements.namedItem(name).value = value;
				}
			}
		}
		record.saved = saved;
		next.failed = !saved;
		next.unknown =
			(!record.confirmedResponse &&
				region.hasAttribute('data-ran-booster-general-saved')) ||
			(!saved &&
				!conflict &&
				!next.form
					.querySelector('[data-ran-booster-admin-mutation-error]')
					.hasAttribute('data-ran-booster-general-failed'));
		if (next.toggle) {
			next.intent = state.intent;
			if (next.unknown) {
				next.confirmed = state.confirmed;
				next.form.dataset.ranBoosterConfirmed = state.confirmed
					? '1'
					: '0';
			}
			next.toggle.checked =
				saved || next.unknown || state.intent > record.intent
					? desired
					: next.confirmed;
		}
		if (next.unknown) {
			next.form.elements.namedItem('ran_booster[token]').value =
				record.token;
			if (!next.toggle) {
				for (const [name, value] of record.values) {
					next.form.elements.namedItem(name).value = value;
				}
			}
		}
		if (next.unknown) {
			uncertain(next);
		} else {
			update(next);
		}
		const navigation = region.querySelector(
			'template[data-ran-booster-general-navigation]'
		);
		if (navigation) {
			const host = document.querySelector('.ran-admin-shell__navigation');
			if (host) {
				const active = host.ownerDocument.activeElement;
				const focused = host.contains(active);
				const href = focused ? active.getAttribute('href') : null;
				host.dispatchEvent(
					new CustomEvent('htmx:beforeCleanupElement', {
						bubbles: true,
						detail: { elt: host },
					})
				);
				host.replaceChildren(navigation.content.cloneNode(true));
				if (focused) {
					(
						Array.from(host.querySelectorAll('a[href]')).find(
							(link) => link.getAttribute('href') === href
						) ||
						host.querySelector('a[aria-current="page"]') ||
						host.querySelector('a[href]')
					)?.focus({ preventScroll: true });
				}
				document.dispatchEvent(new Event('ran-booster:ui-ready'));
			}
			navigation.remove();
		}
		const active = next.form.ownerDocument.activeElement;
		const restoreFocus =
			record.focus &&
			(active === record.active ||
				active === next.form.ownerDocument.body ||
				(record.focus === next.toggle?.id &&
					active === next.toggle &&
					next.toggle.isConnected &&
					region.contains(active)));
		if (saved && restoreFocus) {
			const host =
				record.focus === next.toggle?.id &&
				next.toggle.closest(
					'[data-ran-booster-ui-switch="general-preference"]'
				);
			const control = host?.hasAttribute?.('data-ran-booster-ui-mounted')
				? host.querySelector(
						':scope > [data-ran-booster-ui-switch-mount] button[role="switch"]'
					)
				: null;
			(control || document.getElementById(record.focus))?.focus({
				preventScroll: true,
			});
		}
		if (!saved && restoreFocus) {
			region
				.querySelector(
					'[data-ran-booster-admin-mutation-error]:not([hidden])'
				)
				?.focus();
		} else if (
			saved &&
			next.toggle &&
			next.toggle.checked !== record.desired
		) {
			queue(next);
		}
	});
	document.addEventListener('htmx:afterRequest', (event) => {
		const record = requests.get(event.detail?.xhr);
		if (record && !record.applied && record.state.form.isConnected) {
			uncertain(record.state);
		}
		if (!record || record !== activeRequest) {
			return;
		}
		activeRequest = null;
		if (!record.saved) {
			for (const state of waiting.values()) {
				unlock(state);
				uncertain(state);
			}
			waiting.clear();
			return;
		}
		const next = waiting.values().next().value;
		if (next) {
			waiting.delete(next.scope);
			unlock(next);
			queueMicrotask(() => submit(next));
		}
	});
	window.addEventListener('beforeunload', (event) => {
		const submitting = nativeSubmitting;
		nativeSubmitting = null;
		if (
			[...states.values()].some(
				(state) => state !== submitting && pending(state)
			)
		) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
	const restored = () => {
		for (const state of waiting.values()) {
			unlock(state);
		}
		waiting.clear();
		activeRequest = null;
		bind();
		for (const state of states.values()) {
			// Cached history is not authoritative. A retry retains its original fence.
			uncertain(state);
		}
	};
	document.addEventListener('htmx:historyRestore', restored);
	window.addEventListener('pageshow', (event) => {
		if (event.persisted) {
			restored();
		}
	});
})();
