(function () {
	'use strict';

	function onDomReady(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback, {
				once: true,
			});
			return;
		}

		callback();
	}

	onDomReady(function () {
		consumeAdvancedSettingsOpenRequest();
		initPackageAdmin();
	});

	document.addEventListener('htmx:afterSwap', function (event) {
		if (event.detail?.target?.id === 'wpbody-content') {
			consumeAdvancedSettingsOpenRequest();
			const developmentNotice = document.querySelector(
				'[data-ran-booster-core-development-notice]'
			);
			if (developmentNotice) {
				document
					.querySelector('.ran-booster-brand')
					?.after(developmentNotice);
			}
			initPackageAdmin();
		}
	});

	function consumeAdvancedSettingsOpenRequest() {
		const url = new URL(window.location.href);
		if (!url.searchParams.has('ran_booster_open_advanced')) {
			return;
		}

		url.searchParams.delete('ran_booster_open_advanced');
		window.history.replaceState(window.history.state, '', url);
	}

	function initPackageAdmin() {
		initPackageMutationForms();
		initPackageOperationControls();
		initPackageSourceControls();
		initPackageSourceUnsavedGuard();

		document
			.querySelectorAll('.button-update-package')
			.forEach(function (button) {
				if (button.dataset?.ranBoosterUpdateBound === 'true') {
					return;
				}
				if (button.dataset) {
					button.dataset.ranBoosterUpdateBound = 'true';
				}
				button.addEventListener('click', function (event) {
					if (
						!confirmPackageReinstall(this) ||
						!markPackageUpdateSubmitting(this)
					) {
						event.preventDefault();
					}
					this.blur();
				});
			});

		initBulkPackageControls();
		initConfirmedPackageRemovals();
		initPackageUpdateProgress();
		initDevelopmentSafetyNoticeDismissal();
	}

	function initPackageSourceControls() {
		document
			.querySelectorAll('[data-ran-booster-package-create="1"]')
			.forEach(function (form) {
				if (form.dataset?.ranBoosterSourceControlsBound === 'true') {
					return;
				}

				const repository = form.querySelector(
					'.ran-booster-repository-input'
				);
				const sourceControls = form.querySelector(
					'[data-ran-booster-source-controls]'
				);
				const installActions = form.querySelector(
					'[data-ran-booster-branch-install-actions]'
				);

				if (!repository || !sourceControls || !installActions) {
					return;
				}

				const guidance = form.querySelector(
					'[data-ran-booster-source-repository-guidance]'
				);
				form.dataset.ranBoosterSourceControlsBound = 'true';

				const synchronizeAvailability = function () {
					const repositoryReady =
						repository.value.trim() !== '' &&
						(typeof repository.checkValidity !== 'function' ||
							repository.checkValidity());
					const mutationAvailable =
						form.getAttribute(
							'data-ran-booster-package-mutation-available'
						) !== '0';

					sourceControls.disabled = !repositoryReady;
					if (guidance) {
						guidance.hidden = repositoryReady;
					}
					installActions
						.querySelectorAll('button')
						.forEach(function (button) {
							button.disabled =
								!repositoryReady || !mutationAvailable;
						});
				};

				repository.addEventListener('input', synchronizeAvailability);
				repository.addEventListener('change', synchronizeAvailability);
				form.addEventListener(
					'ran-booster:repository-context-changed',
					synchronizeAvailability
				);
				synchronizeAvailability();
			});

		document
			.querySelectorAll('[data-ran-booster-source-controls]')
			.forEach(function (sourceControls) {
				if (
					sourceControls.dataset?.ranBoosterSourceTabsBound === 'true'
				) {
					return;
				}

				const tabs = Array.from(
					sourceControls.querySelectorAll(
						'[data-ran-booster-source-choice]'
					)
				);
				const panes = Array.from(
					sourceControls.querySelectorAll(
						'[data-ran-booster-source-pane]'
					)
				);
				const form =
					typeof sourceControls.closest === 'function'
						? sourceControls.closest('form')
						: null;
				const branchActions = form?.querySelector(
					'[data-ran-booster-branch-install-actions]'
				);

				if (!tabs.length || !panes.length) {
					return;
				}

				sourceControls.dataset.ranBoosterSourceTabsBound = 'true';

				const selectTab = function (tab) {
					const source = tab.getAttribute(
						'data-ran-booster-source-choice'
					);
					if (
						!source ||
						tab.disabled ||
						tab.getAttribute('aria-disabled') === 'true'
					) {
						return;
					}

					tabs.forEach(function (candidate) {
						const selected = candidate === tab;
						candidate.classList.toggle('is-selected', selected);
						candidate.setAttribute(
							'aria-pressed',
							selected ? 'true' : 'false'
						);
					});
					panes.forEach(function (pane) {
						pane.hidden =
							pane.getAttribute(
								'data-ran-booster-source-pane'
							) !== source;
					});
					if (branchActions) {
						branchActions.hidden = source !== 'branch';
					}
					if (
						typeof sourceControls.dispatchEvent === 'function' &&
						typeof window !== 'undefined' &&
						typeof window.CustomEvent === 'function'
					) {
						sourceControls.dispatchEvent(
							new window.CustomEvent(
								'ran-booster:package-source-changed',
								{
									bubbles: true,
									detail: { source },
								}
							)
						);
					}
				};

				tabs.forEach(function (tab) {
					if (tab.getAttribute('href') !== null) {
						return;
					}
					tab.addEventListener('click', function () {
						selectTab(tab);
					});
				});
			});
	}

	function initPackageSourceUnsavedGuard() {
		const settingsForm = document.querySelector(
			'#ran-booster-package-edit-form'
		);
		const sourceShell = document.querySelector(
			'[data-ran-booster-source-controls]'
		);

		if (
			!settingsForm ||
			!sourceShell ||
			settingsForm.dataset?.ranBoosterSourceUnsavedGuardBound === 'true'
		) {
			return;
		}

		settingsForm.dataset.ranBoosterSourceUnsavedGuardBound = 'true';

		const controls = Array.from(settingsForm.elements || []).filter(
			function (control) {
				return (
					!control.disabled &&
					![
						'button',
						'hidden',
						'image',
						'reset',
						'submit',
						'radio',
					].includes(control.type)
				);
			}
		);
		const baseline = new Map(
			controls.map(function (control) {
				return [control, settingValue(control)];
			})
		);
		const isDirty = function () {
			return controls.some(function (control) {
				return baseline.get(control) !== settingValue(control);
			});
		};
		function settingValue(control) {
			return ['checkbox', 'radio'].includes(control.type)
				? control.checked
				: control.value;
		}
		const notice = sourceShell.querySelector(
			'[data-ran-booster-source-unsaved-notice]'
		);
		const hideNoticeIfClean = function () {
			if (!isDirty() && notice) {
				notice.hidden = true;
			}
		};
		const blockIfDirty = function (event) {
			if (!isDirty()) {
				hideNoticeIfClean();
				return false;
			}

			event.preventDefault();
			event.stopImmediatePropagation?.();
			if (notice) {
				notice.hidden = false;
			}
			return true;
		};
		controls.forEach(function (control) {
			control.addEventListener('input', hideNoticeIfClean);
			control.addEventListener('change', hideNoticeIfClean);
		});

		sourceShell
			.querySelectorAll('[data-ran-booster-source-choice]')
			.forEach(function (choice) {
				if (
					choice.getAttribute('href') === null ||
					choice.disabled ||
					choice.getAttribute('aria-disabled') === 'true'
				) {
					return;
				}
				choice.addEventListener('click', blockIfDirty, true);
			});

		document
			.querySelectorAll('form[data-ran-booster-source-transition]')
			.forEach(function (transitionForm) {
				transitionForm.addEventListener('submit', blockIfDirty, true);
				transitionForm.addEventListener(
					'htmx:beforeRequest',
					blockIfDirty,
					true
				);
			});
	}

	function initPackageOperationControls() {
		document
			.querySelectorAll('.ran-booster-deployment-policy-field')
			.forEach(function (field) {
				if (
					field.dataset?.ranBoosterOperationControlsBound === 'true'
				) {
					return;
				}

				const select = field.querySelector(
					'.ran-booster-deployment-policy-input'
				);
				if (!select) {
					return;
				}
				const operation = field.closest(
					'.ran-booster-package-operation-settings'
				);
				const notice = field.querySelector(
					'[data-ran-booster-local-development-warning]'
				);
				const reinstall = operation?.querySelector(
					'[data-ran-booster-settings-reinstall]'
				);
				const guidance = operation?.querySelector(
					'[data-ran-booster-reinstall-guidance]'
				);

				const sync = function () {
					if (notice) {
						notice.hidden = select.value === 'disabled';
					}
					if (reinstall) {
						const available =
							reinstall.getAttribute(
								'data-ran-booster-reinstall-capable'
							) === '1' && select.value !== 'disabled';
						reinstall.disabled = !available;
						reinstall.setAttribute(
							'data-update-can-run',
							available ? '1' : '0'
						);
						if (guidance) {
							guidance.hidden = available;
						}
					}
				};

				if (field.dataset) {
					field.dataset.ranBoosterOperationControlsBound = 'true';
				}
				select.addEventListener('change', sync);
				sync();
			});
	}

	/**
	 * Apply the shared HTMX contract to every Core package operation form.
	 *
	 * Core-rendered package POST forms opt in explicitly. This keeps transport
	 * decoration in one place without inferring authority from hidden fields.
	 * Add-on actions continue to receive the same contract from
	 * AdminActionRenderer.
	 */
	function initPackageMutationForms() {
		document
			.querySelectorAll(
				'.ran-booster-admin form[method="post"][data-ran-booster-package-mutation]'
			)
			.forEach(function (form) {
				if (form.hasAttribute('data-ran-booster-native-submit')) {
					return;
				}

				const nativeAction = form.getAttribute('action') || '';
				let hxPost = nativeAction;
				try {
					const actionUrl = new URL(nativeAction);
					hxPost = `${actionUrl.pathname}${actionUrl.search}${actionUrl.hash}`;
				} catch {
					// Relative actions already have the HTMX-safe form.
				}
				let requiresProcessing = false;
				const attributes = {
					'data-ran-booster-enhanced-mutation': '',
					'data-ran-booster-error-target':
						'#ran-booster-package-mutation-error',
					'hx-post': hxPost,
					'hx-select': '#wpbody-content',
					'hx-swap': 'outerHTML show:none',
					'hx-sync': 'this:drop',
					'hx-target': '#wpbody-content',
				};

				Object.entries(attributes).forEach(function ([name, value]) {
					if (!form.hasAttribute(name)) {
						form.setAttribute(name, value);
						requiresProcessing = true;
					}
				});

				if (requiresProcessing && window.htmx?.process) {
					window.htmx.process(form);
				}
			});
	}

	function confirmPackageReinstall(button) {
		const message =
			button.getAttribute('data-reinstall-confirm-message') || '';

		// eslint-disable-next-line no-alert -- Reinstall intentionally requires the browser's blocking confirmation.
		return !message || window.confirm(message);
	}

	function markPackageUpdateSubmitting(button) {
		return button.getAttribute('aria-busy') !== 'true';
	}

	function initConfirmedPackageRemovals() {
		document
			.querySelectorAll('[data-ran-booster-confirmed-package-removal]')
			.forEach(function (form) {
				if (form.dataset.ranBoosterPackageRemovalBound === 'true') {
					return;
				}

				const confirmation = form.querySelector(
					'[data-ran-booster-package-removal-confirm]'
				);
				const submit = form.querySelector(
					'[data-ran-booster-package-removal-submit]'
				);
				if (!confirmation || !submit) {
					return;
				}

				form.dataset.ranBoosterPackageRemovalBound = 'true';

				function synchronize() {
					submit.disabled = !confirmation.checked;
				}

				confirmation.addEventListener('change', synchronize);
				form.addEventListener('submit', function (event) {
					if (!confirmation.checked) {
						event.preventDefault();
						confirmation.focus();
						synchronize();
					}
				});
				synchronize();
			});
	}

	function initDevelopmentSafetyNoticeDismissal() {
		const notice = document.querySelector(
			'[data-ran-booster-development-safety]'
		);
		const settings = window.ranBoosterDevelopmentSafetyNotice || {};
		if (
			!notice ||
			notice.dataset?.ranBoosterDevelopmentSafetyBound === 'true' ||
			!settings.ajaxUrl ||
			!settings.action ||
			!settings.nonce
		) {
			return;
		}
		if (notice.dataset) {
			notice.dataset.ranBoosterDevelopmentSafetyBound = 'true';
		}

		notice.addEventListener('click', function (event) {
			const dismissButton =
				event.target && typeof event.target.closest === 'function'
					? event.target.closest('.notice-dismiss')
					: null;
			if (!dismissButton) {
				return;
			}

			const body = new window.URLSearchParams();
			body.set('action', settings.action);
			body.set('nonce', settings.nonce);

			window
				.fetch(settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type':
							'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				})
				.catch(function () {
					// WordPress has already dismissed the current notice.
				});
		});
	}

	function initBulkPackageControls() {
		const form = document.querySelector('[data-ran-booster-bulk-form]');
		if (
			!form ||
			!form.id ||
			form.dataset?.ranBoosterBulkControlsBound === 'true'
		) {
			return;
		}
		if (form.dataset) {
			form.dataset.ranBoosterBulkControlsBound = 'true';
		}

		const selectAll = document.querySelector(
			'[data-ran-booster-select-all]'
		);
		const status = form.querySelector(
			'[data-ran-booster-selection-status]'
		);
		const apply = form.querySelector('[data-ran-booster-bulk-apply]');
		const action = form.querySelector(
			'select[name="ran_booster[bulk_action]"]'
		);

		if (!selectAll || !status || !apply || !action) {
			return;
		}

		const checkboxes = Array.from(
			document.querySelectorAll('[data-ran-booster-package-checkbox]')
		).filter(function (checkbox) {
			return (
				checkbox.getAttribute('form') === form.id && !checkbox.disabled
			);
		});
		const singular =
			form.getAttribute('data-package-type-singular') || 'package';
		const plural =
			form.getAttribute('data-package-type-label') || 'packages';
		const reinstallConfirmSingular =
			form.getAttribute('data-reinstall-confirm-singular') || '';
		const reinstallConfirmPlural =
			form.getAttribute('data-reinstall-confirm-plural') || '';

		if (!checkboxes.length) {
			selectAll.disabled = true;
			apply.disabled = true;
			status.textContent = '0 ' + plural + ' selected';
			return;
		}

		function updateSelection() {
			const selectedCheckboxes = checkboxes.filter(function (checkbox) {
				return checkbox.checked;
			});
			const selected = selectedCheckboxes.length;
			const eligibleForReinstall = selectedCheckboxes.filter(
				function (checkbox) {
					return (
						checkbox.getAttribute(
							'data-ran-booster-branch-reinstall-eligible'
						) !== '0'
					);
				}
			).length;

			selectAll.checked = selected === checkboxes.length;
			selectAll.indeterminate =
				selected > 0 && selected < checkboxes.length;
			const selectionMessage =
				selected +
				' ' +
				(selected === 1 ? singular : plural) +
				' selected';
			status.textContent =
				action.value === 'queue-update' &&
				selected > eligibleForReinstall
					? selectionMessage +
						'. ' +
						String(eligibleForReinstall) +
						' eligible for branch Reinstall.'
					: selectionMessage;
			apply.disabled =
				selected < 1 ||
				!action.value ||
				(action.value === 'queue-update' && eligibleForReinstall < 1);

			return { eligibleForReinstall, selected };
		}

		selectAll.addEventListener('change', function () {
			checkboxes.forEach(function (checkbox) {
				checkbox.checked = selectAll.checked;
			});
			updateSelection();
		});

		checkboxes.forEach(function (checkbox) {
			checkbox.addEventListener('change', updateSelection);
		});
		action.addEventListener('change', updateSelection);

		form.addEventListener('submit', function (event) {
			const selection = updateSelection();
			const selected = selection.selected;
			if (selected < 1) {
				event.preventDefault();
				status.textContent = 'Select at least one ' + singular + '.';
				checkboxes[0].focus();
				return;
			}

			if (action.value === 'queue-update') {
				if (selection.eligibleForReinstall < 1) {
					event.preventDefault();
					status.textContent =
						'Select at least one branch package eligible for Reinstall.';
					return;
				}
				const message =
					selection.eligibleForReinstall === 1
						? reinstallConfirmSingular
						: reinstallConfirmPlural.replace(
								'{count}',
								String(selection.eligibleForReinstall)
							);
				// eslint-disable-next-line no-alert -- Bulk reinstall intentionally requires one count-aware confirmation.
				if (message && !window.confirm(message)) {
					event.preventDefault();
					status.textContent = 'Reinstall cancelled.';
				}
			}
		});

		updateSelection();
	}

	function initPackageUpdateProgress() {
		const settings = window.ranBoosterPackageProgress || {};
		if (
			!settings.ajaxUrl ||
			!settings.action ||
			!settings.nonce ||
			!settings.labels
		) {
			return;
		}

		const tracked = new Map();
		const summary = document.querySelector(
			'[data-ran-booster-update-summary]'
		);
		const summaryMessage = summary?.querySelector(
			'[data-ran-booster-update-summary-message]'
		);
		const skipped = Math.max(
			0,
			Number(summary?.getAttribute('data-skipped')) || 0
		);
		let observedFailure = false;

		function announceSuccess(message) {
			if (typeof window.CustomEvent !== 'function') {
				return;
			}

			document.dispatchEvent(
				new window.CustomEvent('ran-booster:admin-mutation-success', {
					detail: { message },
				})
			);
		}
		document
			.querySelectorAll(
				'[data-ran-booster-package-progress][data-package-source="branch"]'
			)
			.forEach(function (row) {
				const id = row.getAttribute('data-attempt-id') || '';
				const reference =
					row.getAttribute('data-attempt-reference') || '';
				const state = row.getAttribute('data-attempt-state') || '';
				if (
					/^[1-9][0-9]*$/.test(id) &&
					/^[a-f0-9]{32}$/.test(reference) &&
					(state === 'queued' || state === 'running')
				) {
					tracked.set(id, { reference, row });
				}
			});

		function summaryText(template, values) {
			return Object.entries(values).reduce(function (
				message,
				[name, value]
			) {
				return message.replaceAll('{' + name + '}', String(value));
			}, template);
		}

		function updateSummary() {
			if (!summary || !summaryMessage) {
				return;
			}

			let queued = 0;
			let running = 0;
			tracked.forEach(function ({ row }) {
				const state = row.getAttribute('data-attempt-state');
				if (state === 'queued') {
					++queued;
				} else if (state === 'running') {
					++running;
				}
			});

			summary.classList.remove(
				'notice-error',
				'notice-success',
				'notice-warning'
			);
			if (tracked.size) {
				summary.classList.add('notice-warning');
				summaryMessage.textContent = summaryText(
					settings.labels.summaryActive,
					{ queued, running, skipped }
				);
				return;
			}

			summary.classList.add(
				observedFailure || skipped ? 'notice-warning' : 'notice-success'
			);
			summaryMessage.textContent = summaryText(
				settings.labels.summaryFinished,
				{ skipped }
			);
		}

		if (!tracked.size) {
			if (summary && Number(summary.getAttribute('data-queued')) > 0) {
				observedFailure = true;
				updateSummary();
			}
			return;
		}

		updateSummary();

		const interval = Math.max(1000, Number(settings.interval) || 3000);
		const maxPolls = Math.max(1, Number(settings.maxPolls) || 200);
		let failures = 0;
		let polls = 0;

		function stopWithMessage(message) {
			tracked.forEach(function ({ row }) {
				const status = row.querySelector(
					'[data-ran-booster-update-message]'
				);
				if (status) {
					status.textContent = message;
				}
			});
			tracked.clear();
			observedFailure = true;
			updateSummary();
		}

		function updateRow(row, state) {
			const labels = settings.labels;
			const stateLabels = {
				queued: labels.queued,
				running: labels.running,
				succeeded: labels.succeeded,
				failed: labels.failed,
				needs_attention: labels.needsAttention,
			};
			const variants = {
				queued: 'pending',
				running: 'pending',
				succeeded: 'ok',
				failed: 'error',
				needs_attention: 'error',
			};
			if (!stateLabels[state] || !variants[state]) {
				return false;
			}

			row.setAttribute('data-attempt-state', state);
			const badge = row.querySelector(
				'[data-ran-booster-activity-badge]'
			);
			if (badge) {
				badge.hidden = false;
				badge.className =
					'ran-booster-badge ran-booster-badge--' + variants[state];
				badge.textContent = 'Deployment: ' + stateLabels[state];
			}

			const activityState = row.nextElementSibling?.querySelector(
				'[data-ran-booster-activity-state]'
			);
			if (activityState) {
				activityState.className =
					'ran-booster-deployment-state ran-booster-deployment-state--' +
					state;
				activityState.textContent = stateLabels[state];
			}

			const button = row.querySelector(
				'[data-ran-booster-update-button]'
			);
			const buttonLabel = row.querySelector(
				'[data-ran-booster-update-label]'
			);
			const message = row.querySelector(
				'[data-ran-booster-update-message]'
			);
			const inProgress = state === 'queued' || state === 'running';
			const needsAttention = state === 'needs_attention';

			if (button) {
				button.disabled =
					inProgress ||
					needsAttention ||
					button.getAttribute('data-update-can-run') !== '1';
				if (inProgress) {
					button.setAttribute('aria-busy', 'true');
					button.setAttribute('aria-disabled', 'true');
				} else {
					button.removeAttribute('aria-busy');
					button.removeAttribute('aria-disabled');
				}
				button.classList.toggle(
					'ran-booster-update-is-active',
					inProgress
				);
			}
			if (buttonLabel && !inProgress) {
				let nextLabel = button?.getAttribute('data-idle-label') || '';
				if (state === 'needs_attention') {
					nextLabel = labels.needsAttention;
				}
				buttonLabel.textContent = nextLabel;
			}
			if (message) {
				let nextMessage = '';
				if (state === 'succeeded') {
					nextMessage = labels.successMessage;
				} else if (state === 'failed') {
					nextMessage = labels.failureMessage;
				} else if (state === 'needs_attention') {
					nextMessage = labels.attentionMessage;
				}
				message.textContent = nextMessage;
			}
			if (state === 'failed' || state === 'needs_attention') {
				observedFailure = true;
			} else if (state === 'succeeded') {
				announceSuccess(labels.successMessage);
			}

			return !inProgress;
		}

		function schedule() {
			if (tracked.size && polls < maxPolls) {
				window.setTimeout(poll, interval);
			} else if (tracked.size) {
				stopWithMessage(settings.labels.unavailableMessage);
			}
		}

		async function poll() {
			if (document.hidden) {
				schedule();
				return;
			}

			++polls;
			const body = new window.URLSearchParams();
			body.set('action', settings.action);
			body.set('nonce', settings.nonce);
			body.set(
				'package_type',
				document
					.querySelector('[data-ran-booster-bulk-form]')
					?.getAttribute('data-package-type-singular') || ''
			);
			tracked.forEach(function ({ reference }, id) {
				body.set('attempts[' + id + ']', reference);
			});

			try {
				const response = await window.fetch(settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type':
							'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				});
				const payload = await response.json();
				if (!response.ok || !payload.success || !payload.data?.items) {
					throw new Error('Package update progress failed.');
				}

				failures = 0;
				tracked.forEach(function (entry, id) {
					const item = payload.data.items[id];
					if (
						!item ||
						String(item.attempt_id) !== id ||
						item.reference !== entry.reference
					) {
						return;
					}
					if (updateRow(entry.row, item.state)) {
						tracked.delete(id);
					}
				});
				updateSummary();
			} catch {
				++failures;
				if (failures >= 3) {
					stopWithMessage(settings.labels.unavailableMessage);
				}
			}

			schedule();
		}

		schedule();
	}
})();
