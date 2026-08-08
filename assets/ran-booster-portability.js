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
		initPortabilityModeChooser();
		initPortabilityExportDownload();
		initPortabilityExportSelection();
		initPortabilityPreview();
	});

	function initPortabilityExportSelection() {
		const master = document.querySelector(
			'[data-portability-export-select-all]'
		);
		const choices = Array.from(
			document.querySelectorAll('[data-portability-export-select]')
		);
		if (!master || !choices.length) {
			return;
		}

		const credentialRows = Array.from(
			document.querySelectorAll(
				'[data-portability-export-credential-row]'
			)
		);
		const credentialChoices = Array.from(
			document.querySelectorAll('[data-portability-export-credential]')
		);
		const form = document.querySelector('[data-portability-export-form]');
		const summary = document.querySelector(
			'[data-portability-export-summary]'
		);
		const submit = document.querySelector(
			'[data-portability-export-submit]'
		);

		function updateMaster() {
			const selected = choices.filter((choice) => choice.checked);
			const indexes = new Set(
				selected.map((choice) =>
					choice.getAttribute('data-portability-export-package-index')
				)
			);
			let credentialsChanged = false;
			credentialRows.forEach(function (row) {
				const relevant = (
					row.dataset.portabilityCredentialPackages || ''
				)
					.split(' ')
					.some((index) => indexes.has(index));
				const choice = row.querySelector(
					'[data-portability-export-credential]'
				);
				row.hidden = !relevant;
				if (choice) {
					choice.disabled = !relevant;
					credentialsChanged =
						(!relevant && choice.checked) || credentialsChanged;
					choice.checked = relevant && choice.checked;
				}
			});
			master.checked = selected.length === choices.length;
			master.indeterminate = selected.length > 0 && !master.checked;
			if (submit) {
				submit.disabled = selected.length === 0;
			}
			const credentialCount = credentialChoices.filter(
				(choice) => choice.checked && !choice.disabled
			).length;
			if (summary) {
				const packages = (
					selected.length === 1
						? summary.dataset.packageSingular
						: summary.dataset.packagePlural
				).replace('%d', selected.length);
				const credentials = (
					credentialCount === 1
						? summary.dataset.credentialSingular
						: summary.dataset.credentialPlural
				).replace('%d', credentialCount);
				summary.textContent = credentialCount
					? summary.dataset.protectedTemplate
							.replace('%1$s', packages)
							.replace('%2$s', credentials)
					: summary.dataset.packageOnlyTemplate.replace(
							'%s',
							packages
						);
			}
			if (
				credentialsChanged &&
				form &&
				typeof window.CustomEvent === 'function'
			) {
				form.dispatchEvent(
					new window.CustomEvent(
						'ran-booster:portability-credentials-changed'
					)
				);
			}
		}

		master.addEventListener('change', function () {
			choices.forEach(function (choice) {
				choice.checked = master.checked;
			});
			updateMaster();
		});
		choices.forEach(function (choice) {
			choice.addEventListener('change', updateMaster);
		});
		credentialChoices.forEach(function (choice) {
			choice.addEventListener('change', updateMaster);
		});
		updateMaster();
	}

	function initPortabilityExportDownload() {
		function announceSuccess(text) {
			if (typeof window.CustomEvent !== 'function') {
				return;
			}

			document.dispatchEvent(
				new window.CustomEvent('ran-booster:admin-mutation-success', {
					detail: { message: text },
				})
			);
		}

		const root = document.querySelector('.ran-booster-portability');
		const settings = window.ranBoosterPortability;
		const form = root
			? root.querySelector('[data-portability-export-form]')
			: null;
		const submit = form
			? form.querySelector('[data-portability-export-submit]')
			: null;
		const message = root
			? root.querySelector('[data-portability-export-message]')
			: null;
		const messageText = message
			? message.querySelector('[data-portability-export-message-text]')
			: null;

		if (
			!form ||
			!submit ||
			!message ||
			!messageText ||
			!settings?.ajaxUrl
		) {
			return;
		}

		function showMessage(text) {
			message.hidden = !text;
			messageText.textContent = text || '';
		}

		form.addEventListener('submit', async function (event) {
			if (event.defaultPrevented) {
				return;
			}
			event.preventDefault();
			showMessage('');
			submit.disabled = true;
			submit.setAttribute('aria-busy', 'true');

			try {
				const data = new FormData(form);
				data.append('response_format', 'json');
				const response = await window.fetch(settings.ajaxUrl, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
				});
				const contentType = response.headers.get('content-type') || '';
				if (!response.ok || !contentType.includes('application/zip')) {
					const payload = await response.json().catch(() => null);
					throw new Error(
						payload?.data?.message ||
							'Booster could not export this Blueprint. Please try again.'
					);
				}

				const url = window.URL.createObjectURL(await response.blob());
				const link = document.createElement('a');
				link.href = url;
				link.download = 'ran-booster-blueprint.zip';
				document.body.appendChild(link);
				link.click();
				link.remove();
				window.URL.revokeObjectURL(url);
				announceSuccess('Transporter Blueprint download started.');
			} catch (error) {
				showMessage(
					error instanceof Error
						? error.message
						: 'Booster could not export this Blueprint. Please try again.'
				);
			} finally {
				submit.disabled = false;
				submit.removeAttribute('aria-busy');
			}
		});
	}

	function initPortabilityModeChooser() {
		const root = document.querySelector('.ran-booster-portability');

		if (!root) {
			return;
		}

		const chooserHeading = root.querySelector(
			'#ran-booster-portability-mode-heading'
		);
		const modeButtons = Array.from(
			root.querySelectorAll('[data-portability-mode]')
		);
		const flows = Array.from(
			root.querySelectorAll('.ran-booster-portability__flow')
		);
		const switchButtons = root.querySelectorAll(
			'[data-portability-switch]'
		);

		function selectMode(mode) {
			modeButtons.forEach(function (button) {
				const selected =
					button.getAttribute('data-portability-mode') === mode;
				button.setAttribute(
					'aria-expanded',
					selected ? 'true' : 'false'
				);
				button.setAttribute(
					'aria-pressed',
					selected ? 'true' : 'false'
				);
			});

			flows.forEach(function (flow) {
				const selected = flow.id === 'ran-booster-portability-' + mode;
				flow.hidden = !selected;

				if (selected) {
					const heading = flow.querySelector('h3');
					if (heading) {
						heading.focus();
					}
				}
			});
		}

		modeButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				selectMode(button.getAttribute('data-portability-mode'));
			});
		});

		switchButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				flows.forEach(function (flow) {
					flow.hidden = true;
				});
				modeButtons.forEach(function (modeButton) {
					modeButton.setAttribute('aria-expanded', 'false');
					modeButton.setAttribute('aria-pressed', 'false');
				});
				if (chooserHeading) {
					chooserHeading.focus();
				}
			});
		});

		const requestedMode = window.location.hash.match(
			/^#ran-booster-portability-([a-z0-9-]+)$/
		)?.[1];
		if (
			requestedMode &&
			modeButtons.some(
				(button) =>
					button.getAttribute('data-portability-mode') ===
					requestedMode
			)
		) {
			selectMode(requestedMode);
		}
	}

	function initPortabilityPreview() {
		function announceSuccess(text) {
			if (typeof window.CustomEvent !== 'function') {
				return;
			}

			document.dispatchEvent(
				new window.CustomEvent('ran-booster:admin-mutation-success', {
					detail: { message: text },
				})
			);
		}

		const root = document.querySelector('.ran-booster-portability');
		const settings = window.ranBoosterPortability;
		const form = root
			? root.querySelector('[data-portability-preview]')
			: null;
		const review = root
			? root.querySelector('[data-portability-review]')
			: null;
		const message = root
			? root.querySelector('[data-portability-preview-message]')
			: null;
		const previewSubmit = form
			? form.querySelector('[data-portability-preview-submit]')
			: null;
		const previewLabel = previewSubmit
			? previewSubmit.querySelector('[data-portability-preview-label]')
			: null;
		const apply = root
			? root.querySelector('[data-portability-apply]')
			: null;
		const applyLabel = apply
			? apply.querySelector('[data-portability-apply-label]')
			: null;
		const results = root
			? root.querySelector('[data-portability-apply-results]')
			: null;

		if (!root || !form || !review || !message || !settings?.ajaxUrl) {
			return;
		}

		const file = form.querySelector('input[type="file"]');
		const emptyReview = review.innerHTML;
		const selections = new Map();
		const previewIdleLabel = previewLabel?.textContent || '';
		const applyIdleLabel = applyLabel?.textContent || '';

		function setProgressButtonBusy(button, label, idleLabel, busy) {
			if (!button) {
				return;
			}

			button.disabled = busy;
			button.classList.toggle('ran-booster-update-is-active', busy);
			if (busy) {
				button.setAttribute('aria-busy', 'true');
				button.setAttribute('aria-disabled', 'true');
			} else {
				button.removeAttribute('aria-busy');
				button.removeAttribute('aria-disabled');
			}
			if (label) {
				label.textContent = busy
					? button.dataset.busyLabel || idleLabel
					: idleLabel;
			}
		}

		function setPreviewBusy(busy) {
			setProgressButtonBusy(
				previewSubmit,
				previewLabel,
				previewIdleLabel,
				busy
			);
		}

		function setApplyBusy(busy) {
			setProgressButtonBusy(apply, applyLabel, applyIdleLabel, busy);
			if (!busy) {
				updateSelectionControls();
			}
		}

		function showMessage(text, type) {
			message.hidden = !text;
			message.className = text ? 'notice inline notice-' + type : '';
			message.textContent = text || '';
		}

		function resetReview(clearResults = true) {
			review.innerHTML = emptyReview;
			if (apply) {
				apply.disabled = true;
			}
			if (clearResults && results) {
				results.replaceChildren();
			}
		}

		function appendTargetCredentials(data) {
			root.querySelectorAll(
				'[data-portability-target-credential]'
			).forEach(function (select) {
				const row = select.getAttribute('data-portability-row');
				if (row !== null && select.value) {
					data.append(
						'target_credentials[' + row + ']',
						select.value
					);
				}
			});
		}

		function actionableRows() {
			return Array.from(
				review.querySelectorAll(
					'[data-portability-row][data-portability-action]'
				)
			).filter(function (row) {
				return ['install', 'adopt'].includes(
					row.getAttribute('data-portability-action')
				);
			});
		}

		function rememberSelections() {
			actionableRows().forEach(function (row) {
				const choice = row.querySelector('[data-portability-select]');
				const index = row.getAttribute('data-portability-row');
				if (choice && index !== null) {
					selections.set(index, choice.checked);
				}
			});
		}

		function updateSelectionControls() {
			const choices = actionableRows()
				.map(function (row) {
					return row.querySelector('[data-portability-select]');
				})
				.filter(Boolean);
			const selected = choices.filter(function (choice) {
				return choice.checked;
			}).length;
			const master = review.querySelector(
				'[data-portability-select-all]'
			);

			if (master) {
				master.checked =
					choices.length > 0 && selected === choices.length;
				master.indeterminate =
					selected > 0 && selected < choices.length;
			}
			if (apply) {
				apply.disabled = selected === 0;
			}
		}

		function restoreSelections() {
			actionableRows().forEach(function (row) {
				const choice = row.querySelector('[data-portability-select]');
				const index = row.getAttribute('data-portability-row');
				if (choice && index !== null && selections.has(index)) {
					choice.checked = selections.get(index);
				}
			});
			updateSelectionControls();
		}

		function selectedRows() {
			return actionableRows().filter(function (row) {
				return row.querySelector('[data-portability-select]')?.checked;
			});
		}

		function updateApplyButton() {
			rememberSelections();
			updateSelectionControls();
		}

		function renderResult(result, row, rendered = null) {
			if (!results) {
				return null;
			}
			let resultType = 'success';
			if (result.status === 'failed') {
				resultType = 'error';
			} else if (result.status === 'skipped') {
				resultType = 'warning';
			} else if (result.status === 'pending') {
				resultType = 'info';
			}
			const item = rendered?.item || document.createElement('div');
			const text = rendered?.text || document.createElement('p');
			const type =
				row?.getAttribute('data-portability-package-type') || 'Package';
			const name =
				row?.getAttribute('data-portability-package-name') ||
				row?.getAttribute('data-portability-package-identifier') ||
				'Unknown';
			const identifier =
				row?.getAttribute('data-portability-package-identifier') || '';
			const shortLabel = type + ' “' + name + '”';
			const label = identifier
				? shortLabel + ' (' + identifier + ')'
				: shortLabel;
			const resultMessage =
				result.message || 'Package result unavailable.';

			item.className =
				'notice inline notice-' +
				resultType +
				' ran-booster-portability__apply-result';
			text.textContent = label + ' — ' + resultMessage;
			if (!rendered) {
				item.appendChild(text);
				results.appendChild(item);
			}

			return { item, text };
		}

		async function preview(
			clearResults = true,
			showButtonBusy = true,
			announceCompletion = false
		) {
			if (!file?.files?.length) {
				return;
			}

			rememberSelections();
			const data = new FormData(form);
			appendTargetCredentials(data);
			if (showButtonBusy) {
				setPreviewBusy(true);
			}
			resetReview(clearResults);
			showMessage('Reviewing blueprint…', 'info');

			try {
				const response = await window.fetch(settings.ajaxUrl, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
				});
				const payload = await response.json();
				if (
					!payload?.success ||
					typeof payload.data?.html !== 'string'
				) {
					throw new Error(
						payload?.data?.message ||
							'Unable to review this blueprint.'
					);
				}
				review.innerHTML = payload.data.html;
				restoreSelections();
				showMessage('', 'info');
				if (announceCompletion) {
					announceSuccess('Transporter Blueprint reviewed.');
				}
			} catch (error) {
				showMessage(
					error instanceof Error
						? error.message
						: 'Unable to review this blueprint.',
					'error'
				);
			} finally {
				if (showButtonBusy) {
					setPreviewBusy(false);
				}
			}
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			preview(true, true, true);
		});
		form.addEventListener('change', function (event) {
			if (event.target === file) {
				selections.clear();
				resetReview();
				showMessage('', 'info');
			}
		});
		form.addEventListener('input', function (event) {
			if (event.target.matches('input[name="password"]')) {
				selections.clear();
				resetReview();
				showMessage('', 'info');
			}
		});
		root.addEventListener('change', function (event) {
			if (event.target.matches('[data-portability-target-credential]')) {
				preview();
			}
			if (event.target.matches('[data-portability-select-all]')) {
				actionableRows().forEach(function (row) {
					const choice = row.querySelector(
						'[data-portability-select]'
					);
					if (choice) {
						choice.checked = event.target.checked;
					}
				});
				updateApplyButton();
			} else if (event.target.matches('[data-portability-select]')) {
				updateApplyButton();
			}
		});

		apply?.addEventListener('click', async function () {
			const rows = selectedRows();
			if (
				apply.getAttribute('aria-busy') === 'true' ||
				!rows.length ||
				!file?.files?.length
			) {
				return;
			}
			setApplyBusy(true);
			try {
				let appliedEverySelection = true;
				if (results) {
					results.replaceChildren();
				}

				for (const row of rows) {
					const rendered = renderResult(
						{ status: 'pending', message: 'Applying…' },
						row
					);
					const data = new FormData(form);
					data.set('action', 'ran_booster_apply_blueprint');
					data.set('nonce', form.dataset.portabilityApplyNonce || '');
					data.set(
						'row',
						row.getAttribute('data-portability-row') || ''
					);
					data.set(
						'review_action',
						row.getAttribute('data-portability-action') || ''
					);
					data.set(
						'adopt',
						row.getAttribute('data-portability-action') === 'adopt'
							? '1'
							: '0'
					);
					appendTargetCredentials(data);
					try {
						const response = await window.fetch(settings.ajaxUrl, {
							method: 'POST',
							body: data,
							credentials: 'same-origin',
						});
						const payload = await response.json();
						const result = payload?.success
							? payload.data
							: {
									status: 'failed',
									message:
										payload?.data?.message ||
										'Package apply failed.',
								};
						if (
							!result ||
							!['installed', 'adopted'].includes(result.status)
						) {
							appliedEverySelection = false;
						}
						renderResult(result, row, rendered);
					} catch {
						appliedEverySelection = false;
						renderResult(
							{
								status: 'failed',
								message:
									'Package apply failed. Review the blueprint again.',
							},
							row,
							rendered
						);
					}
				}

				await preview(false, false);
				if (appliedEverySelection) {
					announceSuccess(
						'All selected Transporter Blueprint changes were applied.'
					);
				}
			} finally {
				setApplyBusy(false);
			}
		});
	}
})();
