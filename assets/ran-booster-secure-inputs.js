(function () {
	'use strict';

	const webhookSecretToolResets = new WeakMap();
	let activeCredentialButton = null;
	let credentialRequestHandled = false;

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
		initPortabilityPasswordTools();
		initWebhookSecretTools();
		initWebhookUrlCopy();
		initCredentialSettings();
	});

	document.addEventListener(
		'ran-booster:provider-tasks-ready',
		function (event) {
			const root = event.detail?.root;
			if (!root) {
				return;
			}

			initWebhookUrlCopy(root);
			initCredentialSettings();
		}
	);

	document.addEventListener('htmx:afterSwap', handleProviderProfileSwap);
	document.addEventListener(
		'ran-booster:admin-mutation-success',
		handleProviderProfileSuccess
	);

	function handleProviderProfileSwap(event) {
		const target = event.detail?.target;
		if (target?.id !== 'ran-booster-provider-profile-region') {
			return;
		}

		document.body.classList.remove('ran-booster-repository-picker-open');
		activeCredentialButton = null;
		initWebhookSecretTools();
		initWebhookUrlCopy(target);
		initCredentialSettings();
	}

	function handleProviderProfileSuccess(event) {
		const operation =
			event.detail?.operation || event.detail?.value?.operation || '';
		if (
			![
				'core:save-access-profile',
				'core:delete-access-profile',
				'core:save-webhook-profile',
				'core:delete-webhook-profile',
			].includes(operation)
		) {
			return;
		}

		const focusTarget = document.querySelector(
			'#ran-booster-provider-profile-region [data-ran-booster-provider-profile-focus]'
		);
		focusTarget?.focus({ preventScroll: true });
	}

	function generateSecureBase64Url(byteLength) {
		if (
			!window.crypto ||
			typeof window.crypto.getRandomValues !== 'function' ||
			typeof window.btoa !== 'function' ||
			byteLength < 1 ||
			byteLength % 3 !== 0
		) {
			throw new Error();
		}

		const bytes = new Uint8Array(byteLength);
		window.crypto.getRandomValues(bytes);
		const generated = window
			.btoa(String.fromCharCode(...bytes))
			.replace(/\+/g, '-')
			.replace(/\//g, '_')
			.replace(/=+$/, '');

		if (generated.length !== (byteLength / 3) * 4) {
			throw new Error();
		}

		return generated;
	}

	function initGeneratedSecretTools(options) {
		const {
			input,
			visibility,
			visibilityIcon,
			generate,
			copy,
			copyIcon,
			copySuccessIcon,
			status,
			byteLength,
			onGenerate,
			onInput,
			normalizeValue,
		} = options;
		function showStatus(message, visuallyHidden = false) {
			status.textContent = message || '';
			status.classList.toggle(
				'screen-reader-text',
				Boolean(message) && visuallyHidden
			);
		}

		function resetCopyFeedback() {
			copy.setAttribute('aria-label', copy.dataset.copyLabel);
			copy.setAttribute('title', copy.dataset.copyLabel);
			copyIcon.removeAttribute('hidden');
			copySuccessIcon.setAttribute('hidden', '');
		}

		function updateCopyState() {
			const value = normalizeValue
				? normalizeValue(input.value)
				: input.value;
			copy.disabled = value === '';
		}

		function reset() {
			input.type = 'password';
			visibility.setAttribute('aria-pressed', 'false');
			visibility.setAttribute('aria-label', visibility.dataset.showLabel);
			visibility.setAttribute('title', visibility.dataset.showLabel);
			visibilityIcon.classList.add('dashicons-visibility');
			visibilityIcon.classList.remove('dashicons-hidden');
			updateCopyState();
			resetCopyFeedback();
			showStatus('');
		}

		generate.addEventListener('click', function () {
			try {
				const generated = generateSecureBase64Url(byteLength);
				input.value = generated;
				if (onGenerate) {
					onGenerate(generated);
				}
				updateCopyState();
				resetCopyFeedback();
				showStatus(status.dataset.generatedMessage, true);
			} catch {
				showStatus(status.dataset.generationFailedMessage);
			}
		});

		visibility.addEventListener('click', function () {
			const showing = input.type === 'password';
			const label = showing
				? visibility.dataset.hideLabel
				: visibility.dataset.showLabel;

			input.type = showing ? 'text' : 'password';
			visibility.setAttribute('aria-pressed', showing ? 'true' : 'false');
			visibility.setAttribute('aria-label', label);
			visibility.setAttribute('title', label);
			visibilityIcon.classList.toggle('dashicons-visibility', !showing);
			visibilityIcon.classList.toggle('dashicons-hidden', showing);
			input.focus();

			if (status.textContent === status.dataset.copyFailedMessage) {
				input.select();
			}
		});

		copy.addEventListener('click', async function () {
			const value = normalizeValue
				? normalizeValue(input.value)
				: input.value;
			if (value === '') {
				updateCopyState();
				return;
			}

			try {
				resetCopyFeedback();
				input.value = value;
				if (
					!window.navigator?.clipboard ||
					typeof window.navigator.clipboard.writeText !== 'function'
				) {
					throw new Error();
				}

				await window.navigator.clipboard.writeText(value);
				copy.setAttribute('aria-label', copy.dataset.copiedLabel);
				copy.setAttribute('title', copy.dataset.copiedLabel);
				copyIcon.setAttribute('hidden', '');
				copySuccessIcon.removeAttribute('hidden');
				showStatus(status.dataset.copiedMessage, true);
			} catch {
				resetCopyFeedback();
				input.focus();
				input.select();
				showStatus(status.dataset.copyFailedMessage);
			}
		});

		input.addEventListener('input', function () {
			if (onInput) {
				onInput();
			}
			updateCopyState();
			resetCopyFeedback();
			showStatus('');
		});

		reset();

		return { reset };
	}

	function initPortabilityPasswordTools() {
		const form = document.querySelector('[data-portability-export-form]');
		const credentialToggle = document.querySelector(
			'[data-portability-credential-toggle]'
		);
		const password = document.querySelector('[data-portability-password]');
		const confirmation = document.querySelector(
			'[data-portability-password-confirmation]'
		);
		const visibility = document.querySelector(
			'[data-portability-password-visibility]'
		);
		const visibilityIcon = document.querySelector(
			'[data-portability-password-visibility-icon]'
		);
		const generate = document.querySelector(
			'[data-portability-password-generate]'
		);
		const copy = document.querySelector('[data-portability-password-copy]');
		const copyIcon = document.querySelector(
			'[data-portability-password-copy-icon]'
		);
		const copySuccessIcon = document.querySelector(
			'[data-portability-password-copy-success-icon]'
		);
		const status = document.querySelector(
			'[data-portability-password-status]'
		);
		const validation = document.querySelector(
			'[data-portability-password-validation]'
		);
		const validationMessage = document.querySelector(
			'[data-portability-password-validation-message]'
		);

		if (
			!form ||
			!credentialToggle ||
			!password ||
			!confirmation ||
			!visibility ||
			!visibilityIcon ||
			!generate ||
			!copy ||
			!copyIcon ||
			!copySuccessIcon ||
			!status ||
			!validation ||
			!validationMessage
		) {
			return;
		}

		function clearValidation() {
			validation.hidden = true;
			validationMessage.textContent = '';
			password.removeAttribute('aria-invalid');
			confirmation.removeAttribute('aria-invalid');
		}

		function showValidation(message, field) {
			clearValidation();
			validationMessage.textContent = message;
			validation.hidden = false;
			field.setAttribute('aria-invalid', 'true');
			field.focus();
		}

		initGeneratedSecretTools({
			input: password,
			visibility,
			visibilityIcon,
			generate,
			copy,
			copyIcon,
			copySuccessIcon,
			status,
			byteLength: 24,
			onGenerate(generated) {
				confirmation.value = generated;
				clearValidation();
			},
			onInput: clearValidation,
		});

		confirmation.addEventListener('input', clearValidation);
		credentialToggle.addEventListener('change', function () {
			if (!credentialToggle.checked) {
				clearValidation();
			}
		});
		form.addEventListener('submit', function (event) {
			if (!credentialToggle.checked) {
				return;
			}

			if (password.value === '') {
				event.preventDefault();
				showValidation(validation.dataset.requiredMessage, password);
				return;
			}

			if (password.value !== confirmation.value) {
				event.preventDefault();
				showValidation(
					validation.dataset.mismatchMessage,
					confirmation
				);
			}
		});

		clearValidation();
	}

	function resetWebhookSecretTools(root) {
		const reset = webhookSecretToolResets.get(root);
		if (reset) {
			reset();
		}
	}

	function initWebhookSecretTools() {
		document
			.querySelectorAll('[data-webhook-secret-tools]')
			.forEach(function (root) {
				const secret = root.querySelector(
					'[data-webhook-secret-input]'
				);
				const visibility = root.querySelector(
					'[data-webhook-secret-visibility]'
				);
				const visibilityIcon = root.querySelector(
					'[data-webhook-secret-visibility-icon]'
				);
				const generate = root.querySelector(
					'[data-webhook-secret-generate]'
				);
				const copy = root.querySelector('[data-webhook-secret-copy]');
				const copyIcon = root.querySelector(
					'[data-webhook-secret-copy-icon]'
				);
				const copySuccessIcon = root.querySelector(
					'[data-webhook-secret-copy-success-icon]'
				);
				const status = root.querySelector(
					'[data-webhook-secret-status]'
				);

				if (
					!secret ||
					!visibility ||
					!visibilityIcon ||
					!generate ||
					!copy ||
					!copyIcon ||
					!copySuccessIcon ||
					!status
				) {
					return;
				}
				const tools = initGeneratedSecretTools({
					input: secret,
					visibility,
					visibilityIcon,
					generate,
					copy,
					copyIcon,
					copySuccessIcon,
					status,
					byteLength: 48,
					normalizeValue(value) {
						return value.trim();
					},
				});
				webhookSecretToolResets.set(root, tools.reset);

				const form = root.closest('form');
				if (form) {
					form.addEventListener('submit', function () {
						secret.value = secret.value.trim();
					});
				}
			});
	}

	function initWebhookUrlCopy(root = document) {
		root.querySelectorAll('[data-webhook-url-tools]').forEach(
			function (toolRoot) {
				if (toolRoot.dataset.ranBoosterWebhookUrlCopyBound === 'true') {
					return;
				}

				const input = toolRoot.querySelector('[data-webhook-url]');
				const copy = toolRoot.querySelector('[data-webhook-url-copy]');
				const status = toolRoot.querySelector(
					'[data-webhook-url-status]'
				);
				if (!input || !copy || !status) {
					return;
				}
				toolRoot.dataset.ranBoosterWebhookUrlCopyBound = 'true';

				copy.addEventListener('click', async function () {
					try {
						if (
							!window.navigator?.clipboard ||
							typeof window.navigator.clipboard.writeText !==
								'function'
						) {
							throw new Error();
						}
						await window.navigator.clipboard.writeText(input.value);
						copy.textContent = copy.dataset.copiedLabel;
						status.textContent = status.dataset.copiedMessage;
						status.classList.add('screen-reader-text');
					} catch {
						copy.textContent = copy.dataset.copyLabel;
						input.focus();
						input.select();
						status.textContent = status.dataset.copyFailedMessage;
						status.classList.remove('screen-reader-text');
					}
				});
			}
		);
	}

	function initCredentialSettings() {
		const openButtons = document.querySelectorAll(
			'.ran-booster-open-credential-modal'
		);
		const deleteButtons = document.querySelectorAll(
			'.ran-booster-open-delete-credential-modal'
		);

		if (!openButtons.length && !deleteButtons.length) {
			return;
		}

		openButtons.forEach(function (button) {
			if (button.dataset.ranBoosterCredentialOpenBound === 'true') {
				return;
			}
			button.dataset.ranBoosterCredentialOpenBound = 'true';

			button.addEventListener('click', function (event) {
				event.preventDefault();
				const kind = button.getAttribute('data-modal');
				const modal = document.querySelector(
					'[data-credential-modal="' + kind + '"]'
				);

				if (!modal) {
					return;
				}

				activeCredentialButton = button;
				populateCredentialModal(modal, button);
				modal.removeAttribute('hidden');
				document.body.classList.add(
					'ran-booster-repository-picker-open'
				);
				modal
					.querySelector('input:not([type="hidden"]), select')
					.focus();
			});
		});

		if (!credentialRequestHandled) {
			credentialRequestHandled = true;
			const requestedReplacement = new URLSearchParams(
				window.location.search
			).get('replace_credential');
			if (requestedReplacement) {
				const replacementButton = Array.from(openButtons).find(
					function (button) {
						return (
							button.getAttribute('data-modal') === 'access' &&
							button.getAttribute('data-id') ===
								requestedReplacement
						);
					}
				);
				if (replacementButton) {
					replacementButton.click();
					const replacementModal = document.querySelector(
						'[data-credential-modal="access"]'
					);
					replacementModal
						?.querySelector('.ran-booster-secret-input')
						?.focus();
				}
			} else {
				openRequestedWebhookSecretModal(openButtons);
			}
		}

		deleteButtons.forEach(function (button) {
			if (button.dataset.ranBoosterCredentialDeleteBound === 'true') {
				return;
			}
			button.dataset.ranBoosterCredentialDeleteBound = 'true';

			button.addEventListener('click', function () {
				const modal = document.querySelector(
					'[data-credential-delete-modal]'
				);

				if (!modal) {
					return;
				}

				activeCredentialButton = button;
				populateDeleteCredentialModal(modal, button);
				modal.removeAttribute('hidden');
				document.body.classList.add(
					'ran-booster-repository-picker-open'
				);
				modal.querySelector('[data-delete-credential-cancel]').focus();
			});
		});

		document
			.querySelectorAll('.ran-booster-credential-modal')
			.forEach(function (modal) {
				if (modal.dataset.ranBoosterCredentialModalBound === 'true') {
					return;
				}
				modal.dataset.ranBoosterCredentialModalBound = 'true';

				modal.addEventListener('click', function (event) {
					if (
						event.target === modal ||
						event.target.closest(
							'.ran-booster-close-credential-modal'
						)
					) {
						closeCredentialModal(modal);
					}
				});

				modal.addEventListener('keydown', function (event) {
					if (event.key === 'Escape') {
						event.preventDefault();
						closeCredentialModal(modal);
						return;
					}

					trapFocus(
						event,
						modal.querySelector(
							'.ran-booster-credential-modal__dialog'
						)
					);
				});

				const credentialKind = modal.querySelector(
					'.ran-booster-credential-kind'
				);
				const webhookScope = modal.querySelector(
					'.ran-booster-webhook-scope'
				);
				const selfDestruct = modal.querySelector(
					'.ran-booster-credential-self-destruct'
				);

				if (credentialKind) {
					credentialKind.addEventListener('change', function () {
						updateCredentialFields(modal);
					});
				}

				if (webhookScope) {
					webhookScope.addEventListener('change', function () {
						updateWebhookFields(modal);
					});
				}

				if (selfDestruct) {
					selfDestruct.addEventListener('change', function () {
						updateCredentialSelfDestructFields(modal);
					});
				}
			});

		document.querySelectorAll('[data-confirm]').forEach(function (button) {
			if (button.dataset.ranBoosterConfirmBound === 'true') {
				return;
			}
			button.dataset.ranBoosterConfirmBound = 'true';

			button.addEventListener('click', function (event) {
				// eslint-disable-next-line no-alert -- Native confirmation is intentional for destructive admin actions.
				if (!window.confirm(button.getAttribute('data-confirm'))) {
					event.preventDefault();
				}
			});
		});

		function closeCredentialModal(modal) {
			if (modal.hasAttribute('hidden')) {
				return;
			}

			modal.setAttribute('hidden', 'hidden');
			document.body.classList.remove(
				'ran-booster-repository-picker-open'
			);

			if (activeCredentialButton) {
				activeCredentialButton.focus();
			}
		}
	}

	function openRequestedWebhookSecretModal(openButtons) {
		const parameters = new URLSearchParams(window.location.search);
		if (parameters.get('add_webhook_secret') !== '1') {
			return;
		}

		const addButton = Array.from(openButtons).find(function (button) {
			return (
				button.getAttribute('data-modal') === 'webhook' &&
				!button.getAttribute('data-id')
			);
		});

		const requestedScope = parameters.get('webhook_scope');
		const requestedTarget = parameters.get('webhook_target');
		if (
			addButton &&
			['owner', 'repository'].includes(requestedScope) &&
			requestedTarget
		) {
			addButton.setAttribute('data-scope', requestedScope);
			addButton.setAttribute('data-target', requestedTarget);
		}

		addButton?.click();
	}

	function populateDeleteCredentialModal(modal, button) {
		const form = modal.querySelector('form');
		const usageTotal = Number.parseInt(
			button.getAttribute('data-usage-total') || '',
			10
		);
		const usageKnown = Number.isInteger(usageTotal) && usageTotal >= 0;
		const inUse = usageKnown && usageTotal > 0;
		const label = button.getAttribute('data-label') || 'this credential';
		const inUseMessage = modal.querySelector(
			'[data-delete-credential-in-use]'
		);
		const unusedMessage = modal.querySelector(
			'[data-delete-credential-unused]'
		);
		const publicDefaultMessage = modal.querySelector(
			'[data-delete-credential-public-default]'
		);
		const packageRegion = modal.querySelector(
			'[data-delete-credential-packages]'
		);
		const packageList = modal.querySelector(
			'[data-delete-credential-package-list]'
		);
		const confirmButton = modal.querySelector(
			'[data-delete-credential-confirm]'
		);
		const listedPackageCount = Number.parseInt(
			button.getAttribute('data-usage-listed') || '',
			10
		);
		const usageTemplateId =
			button.getAttribute('data-usage-template') || '';
		const usageTemplate = usageTemplateId
			? modal.ownerDocument.getElementById(usageTemplateId)
			: null;
		const showPackages =
			inUse &&
			Number.isInteger(listedPackageCount) &&
			listedPackageCount > 0 &&
			Boolean(usageTemplate);

		form.reset();
		form.elements['ran_booster[id]'].value =
			button.getAttribute('data-id') || '';
		modal.querySelector('[data-delete-credential-label]').textContent =
			'“' + label + '”';

		unusedMessage.toggleAttribute('hidden', !usageKnown || inUse);
		inUseMessage.toggleAttribute('hidden', !inUse);
		inUseMessage.textContent = inUse
			? 'This credential is still used by ' +
				usageTotal +
				' managed package' +
				(usageTotal === 1 ? '' : 's') +
				'. Assign another credential, or public access where appropriate, to every package listed under Usage and save those settings before deleting it.'
			: '';
		packageList.replaceChildren();
		if (showPackages) {
			packageList.appendChild(usageTemplate.content.cloneNode(true));
		}
		packageRegion.toggleAttribute('hidden', !showPackages);
		publicDefaultMessage.toggleAttribute(
			'hidden',
			button.getAttribute('data-public-lookup-default') !== '1'
		);
		confirmButton.disabled = !usageKnown || inUse;
	}

	function populateCredentialModal(modal, button) {
		const form = modal.querySelector('form');
		const isEdit = Boolean(button.getAttribute('data-id'));
		const modalKind = modal.getAttribute('data-credential-modal');
		const providerLabel = modal.getAttribute('data-provider-label') || '';
		const labelInput = form.elements['ran_booster[label]'];
		const webhookSecretTools = modal.querySelector(
			'[data-webhook-secret-tools]'
		);

		form.reset();
		if (webhookSecretTools) {
			resetWebhookSecretTools(webhookSecretTools);
		}
		form.elements['ran_booster[id]'].value =
			button.getAttribute('data-id') || '';
		labelInput.value = button.getAttribute('data-label') || '';
		modal.querySelector('.ran-booster-dialog__title').textContent =
			(isEdit ? 'Edit ' : 'Add ') +
			(modalKind === 'access'
				? providerLabel + ' repository credential'
				: 'Push-to-Deploy secret');
		modal.querySelector('.ran-booster-secret-input').required = !isEdit;

		if (modalKind === 'access') {
			const kindSelect = form.elements['ran_booster[kind]'];
			const expiryInput = form.elements['ran_booster[expires_on]'];
			const selfDestructInput =
				form.elements['ran_booster[self_destruct]'];
			const destroyOnInput = form.elements['ran_booster[destroy_on]'];
			kindSelect.value =
				button.getAttribute('data-kind') || kindSelect.options[0].value;
			if (expiryInput) {
				expiryInput.value =
					button.getAttribute('data-expires-on') || '';
			}
			if (selfDestructInput) {
				selfDestructInput.checked =
					button.getAttribute('data-self-destruct') === '1';
			}
			if (destroyOnInput) {
				destroyOnInput.value =
					button.getAttribute('data-destroy-on') || '';
			}

			let configuration = {};
			try {
				configuration = JSON.parse(
					button.getAttribute('data-configuration') || '{}'
				);
			} catch {
				configuration = {};
			}

			modal
				.querySelectorAll('.ran-booster-credential-config-field input')
				.forEach(function (input) {
					const match = input.name.match(
						/\[configuration\]\[([^\]]+)\]$/
					);
					const key = match ? match[1] : '';
					input.value =
						key && typeof configuration[key] === 'string'
							? configuration[key]
							: '';
				});
			updateCredentialFields(modal);
			updateCredentialSelfDestructFields(modal);
		} else {
			form.elements['ran_booster[scope]'].value =
				button.getAttribute('data-scope') || 'owner';
			form.elements['ran_booster[target]'].value =
				button.getAttribute('data-target') || '';
			updateWebhookFields(modal);
		}
	}

	function updateCredentialSelfDestructFields(modal) {
		const form = modal.querySelector('form');
		const checkbox = form.elements['ran_booster[self_destruct]'];
		const field = modal.querySelector(
			'.ran-booster-credential-destroy-date'
		);
		const input = form.elements['ran_booster[destroy_on]'];
		if (!checkbox || !field || !input) {
			return;
		}

		field.toggleAttribute('hidden', !checkbox.checked);
		input.required = checkbox.checked;
	}

	function updateCredentialFields(modal) {
		const select = modal.querySelector('.ran-booster-credential-kind');
		const kind = select.value;
		const option = select.options[select.selectedIndex];
		const secretInput = modal.querySelector('.ran-booster-secret-input');
		const secretLabel = modal.querySelector('.ran-booster-secret-label');

		secretLabel.textContent =
			option.getAttribute('data-secret-label') || 'Credential secret';
		secretInput.placeholder =
			option.getAttribute('data-secret-placeholder') || '';

		modal
			.querySelectorAll('.ran-booster-credential-config-field')
			.forEach(function (field) {
				const kinds = (field.getAttribute('data-kinds') || '').split(
					','
				);
				const active = kinds.includes(kind);
				const input = field.querySelector('input');

				field.toggleAttribute('hidden', !active);
				input.disabled = !active;
				input.required =
					active && field.getAttribute('data-required') === '1';
			});
	}

	function updateWebhookFields(modal) {
		const select = modal.querySelector('.ran-booster-webhook-scope');
		const option = select.options[select.selectedIndex];
		const field = modal.querySelector('.ran-booster-webhook-target-field');
		const input = field.querySelector('input');
		const required = option.getAttribute('data-requires-target') === '1';

		field.toggleAttribute('hidden', !required);
		input.required = required;
		input.disabled = !required;
		input.placeholder =
			option.getAttribute('data-target-placeholder') || '';
		const repositoryList = input.getAttribute('data-repository-list') || '';
		const ownerList = input.getAttribute('data-owner-list') || '';
		if (required && option.value === 'owner' && ownerList) {
			input.setAttribute('list', ownerList);
		} else if (
			required &&
			option.value === 'repository' &&
			repositoryList
		) {
			input.setAttribute('list', repositoryList);
		} else {
			input.removeAttribute('list');
		}
		field.querySelector('.ran-booster-webhook-target-label').textContent =
			option.getAttribute('data-target-label') || 'Target';
		field.querySelector('.ran-booster-webhook-target-help').textContent =
			option.getAttribute('data-description') || '';
	}

	function trapFocus(event, container) {
		if (event.key !== 'Tab') {
			return;
		}

		const focusable = Array.from(
			container.querySelectorAll(
				'a[href], button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"]):not([disabled])'
			)
		).filter(function (element) {
			if (
				('disabled' in element && element.disabled) ||
				element.tabIndex < 0 ||
				(element.tagName === 'INPUT' && element.type === 'hidden') ||
				!element.getClientRects().length
			) {
				return false;
			}

			let current = element;
			while (current) {
				if (
					current.hidden ||
					current.getAttribute('aria-hidden') === 'true'
				) {
					return false;
				}

				const style =
					current.ownerDocument.defaultView.getComputedStyle(current);
				if (
					style.display === 'none' ||
					style.visibility === 'hidden' ||
					style.visibility === 'collapse'
				) {
					return false;
				}

				current = current.parentElement;
			}

			return true;
		});
		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		const activeElement = container.ownerDocument.activeElement;

		if (!first || !last) {
			return;
		}

		if (event.shiftKey && activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}
})();
