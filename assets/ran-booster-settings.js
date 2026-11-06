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
	const pending = (state) =>
		Boolean(
			state.flight ||
			state.queued ||
			state.unknown ||
			(state.toggle
				? state.toggle.checked !== state.confirmed
				: fingerprint(state.form) !== state.initial)
		);
	const unmount = (state) => {
		if (state.root) {
			state.root.unmount();
			state.root = null;
			state.input = null;
		}
		if (state.nativeRow) {
			state.nativeRow.hidden = false;
		}
	};
	const renderToggle = (state) => {
		if (!state.root) {
			return;
		}
		const { createElement, flushSync } = window.wp.element;
		flushSync(() => {
			state.root.render(
				createElement(window.wp.components.ToggleControl, {
					label: state.nativeRow
						.querySelector('label')
						.textContent.trim(),
					checked: state.toggle.checked,
					disabled: state.toggle.disabled,
					'aria-describedby':
						state.toggle.getAttribute('aria-describedby'),
					'aria-invalid':
						state.toggle.getAttribute('aria-invalid') || undefined,
					ref: (input) => {
						state.input = input;
					},
					onChange: (checked) => {
						state.toggle.checked = checked;
						state.toggle.dispatchEvent(
							new Event('change', { bubbles: true })
						);
					},
				})
			);
		});
	};
	const mount = (state) => {
		const element = window.wp?.element;
		if (
			!state.toggle ||
			state.root ||
			!state.host ||
			!state.nativeRow ||
			!window.wp?.components?.ToggleControl ||
			!element?.createRoot ||
			!element?.createElement ||
			!element?.flushSync
		) {
			return;
		}
		try {
			state.root = element.createRoot(state.host);
			renderToggle(state);
			if (state.input) {
				state.nativeRow.hidden = true;
			} else {
				unmount(state);
			}
		} catch {
			unmount(state);
		}
	};
	const update = (state) => {
		renderToggle(state);
		const dirty = pending(state);
		const status = state.form.querySelector(
			state.toggle
				? '[data-ran-booster-toggle-status]'
				: '[data-ran-booster-unsaved]'
		);
		let message = dirty
			? __('Unsaved numeric changes.', 'ran-booster')
			: __('No pending numeric changes.', 'ran-booster');
		if (state.toggle) {
			message = dirty
				? __('Not saved.', 'ran-booster')
				: __('Saved.', 'ran-booster');
		}
		if (state.failed) {
			message = __('Save needs review.', 'ran-booster');
		}
		if (state.unknown) {
			message = __('Save unconfirmed. Review and retry.', 'ran-booster');
		}
		if (state.queued) {
			message = __('Waiting to save…', 'ran-booster');
		}
		if (state.flight) {
			message = __('Saving…', 'ran-booster');
		}
		if (status && status.textContent !== message) {
			status.textContent = message;
		}
		state.form.classList.toggle('ran-booster-settings--pending', dirty);
		const retry = state.form.querySelector(
			'[data-ran-booster-toggle-save]'
		);
		if (retry) {
			retry.hidden = Boolean(
				window.htmx && !state.unknown && !state.failed
			);
		}
	};
	const bind = () => {
		for (const form of document.querySelectorAll(selector)) {
			const scope = form.dataset.ranBoosterSettingsScope;
			if (states.get(scope)?.form === form) {
				mount(states.get(scope));
				continue;
			}
			const old = states.get(scope);
			if (old) {
				unmount(old);
			}
			const state = {
				form,
				scope,
				toggle: form.querySelector('input[role="switch"]'),
				host: form.querySelector('[data-ran-booster-core-toggle]'),
				nativeRow: form.querySelector(
					'[data-ran-booster-native-toggle]'
				),
				confirmed: form.dataset.ranBoosterConfirmed === '1',
				initial: form.dataset.ranBoosterBaseline,
				failed: !form.querySelector(
					'[data-ran-booster-admin-mutation-error]'
				).hidden,
				unknown: false,
				flight: null,
			};
			states.set(scope, state);
			mount(state);
			update(state);
		}
		for (const [scope, state] of states) {
			if (!state.form.isConnected) {
				unmount(state);
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
		update(state);
	};
	bind();
	document.addEventListener('input', (event) => {
		const state = stateFor(event.target);
		if (state && !state.toggle) {
			update(state);
		}
	});
	document.addEventListener('change', (event) => {
		const state = stateFor(event.target);
		if (!state || (state.host && state.host.contains(event.target))) {
			return;
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
				region: state.form.parentElement,
				values: [...new FormData(state.form)].filter(([name]) =>
					name.startsWith('ran_booster[preferences][')
				),
				token: state.form.elements.namedItem('ran_booster[token]')
					.value,
				focus: state.form.parentElement.contains(origin)
					? origin.id
					: null,
				active: origin,
				coreFocus: origin === state.input,
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
				const baseline = JSON.parse(
					responseForm?.dataset.ranBoosterBaseline || 'null'
				);
				if (
					!responseForm ||
					responseForm.dataset.ranBoosterSettingsScope !==
						state.scope ||
					!responseForm.elements.namedItem('ran_booster[token]')
						?.value ||
					!responseForm.querySelector(
						'[data-ran-booster-admin-mutation-error]'
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
						(!responseForm.querySelector('input[role="switch"]') ||
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
				record.focus = active.id;
				record.coreFocus = active === state.input;
				record.active = active;
			} else if (active !== state.form.ownerDocument.body) {
				record.focus = null;
				record.coreFocus = false;
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
		bind();
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
			const error = next.form.querySelector(
				'[data-ran-booster-admin-mutation-error]'
			);
			error.hidden = false;
			error.setAttribute('role', 'alert');
			error.setAttribute('tabindex', '-1');
			let current = JSON.parse(next.initial)
				.map(([, value]) => value)
				.join(' / ');
			if (next.toggle) {
				current = next.confirmed
					? __('On', 'ran-booster')
					: __('Off', 'ran-booster');
			}
			error.textContent =
				__(
					'Settings changed before confirmation. Review the current saved values before retrying:',
					'ran-booster'
				) +
				' ' +
				current;
			if (!next.toggle) {
				for (const [name, value] of record.values) {
					next.form.elements.namedItem(name).value = value;
				}
			}
		}
		record.saved = saved;
		if (next.toggle) {
			next.toggle.checked = desired;
		}
		next.failed = !saved;
		next.unknown =
			!record.confirmedResponse &&
			region.hasAttribute('data-ran-booster-general-saved');
		if (next.unknown) {
			next.form.elements.namedItem('ran_booster[token]').value =
				record.token;
			if (!next.toggle) {
				for (const [name, value] of record.values) {
					next.form.elements.namedItem(name).value = value;
				}
			}
		}
		update(next);
		const navigation = region.querySelector(
			'template[data-ran-booster-general-navigation]'
		);
		if (navigation) {
			document
				.querySelector('.ran-admin-shell__navigation')
				?.replaceChildren(navigation.content.cloneNode(true));
			navigation.remove();
		}
		const active = next.form.ownerDocument.activeElement;
		const restoreFocus =
			(record.focus || record.coreFocus) &&
			(active === record.active ||
				active === next.form.ownerDocument.body);
		if (saved && restoreFocus) {
			(record.coreFocus
				? next.input
				: document.getElementById(record.focus)
			)?.focus({ preventScroll: true });
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
	document.addEventListener('htmx:beforeCleanupElement', (event) => {
		for (const state of states.values()) {
			if (
				event.target === state.form ||
				event.target.contains?.(state.form)
			) {
				unmount(state);
			}
		}
	});
	const unmountAll = () => states.forEach(unmount);
	document.addEventListener('htmx:beforeHistorySave', unmountAll);
	window.addEventListener('pagehide', unmountAll);
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
