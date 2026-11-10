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

	onDomReady(initEnhancedMutationFeedback);

	/**
	 * Keep the interaction layer deliberately inert until a Core-rendered form
	 * opts in. The server remains responsible for choosing the HTMX request,
	 * rendering an authoritative region and emitting a safe named success event.
	 */
	function initEnhancedMutationFeedback() {
		if (window.ranBoosterEnhancedMutationFeedbackInitialized) {
			return;
		}

		const enhancedFormSelector = '[data-ran-booster-enhanced-mutation]';
		const submitters = new WeakMap();
		const busyStates = new WeakMap();
		let pendingInteractionState = null;
		let sourceRequest = null;
		const requests = new WeakMap();
		let armedSuccess = null;
		let snackbar = null;

		function disposeSnackbar() {
			if (!snackbar) {
				return;
			}
			const previous = snackbar;
			snackbar = null;
			if (previous.reposition) {
				window.removeEventListener('resize', previous.reposition);
				previous.observer?.disconnect();
			}
			previous.root.unmount();
			previous.host.remove();
		}

		function restoreSnackbarFocus(removing = null) {
			if (
				!snackbar?.host.contains(
					snackbar.host.ownerDocument.activeElement
				)
			) {
				return;
			}
			const candidates = [
				...(snackbar.returnControl ? [snackbar.returnControl] : []),
				...document.querySelectorAll(
					'[data-ran-booster-package-settings-save]'
				),
				...document.querySelectorAll(
					'#ran-booster-save-numeric-bottom'
				),
				...document.querySelectorAll(
					'#ran-booster-general-region [data-ran-booster-ui-switch-mount] button[role="switch"], #ran-booster-general-region input[type="number"], #ran-booster-general-region input[role="switch"]'
				),
				...document.querySelectorAll('#wpadminbar a[href]'),
			];
			for (const candidate of candidates) {
				if (
					!candidate.isConnected ||
					candidate.disabled ||
					removing?.contains?.(candidate) ||
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
				if (snackbar.host.ownerDocument.activeElement === candidate) {
					break;
				}
			}
		}

		function presentGeneralSnackbar(
			message,
			region = document.getElementById('ran-booster-general-region'),
			returnControl = null
		) {
			const element = window.wp?.element;
			const List = window.wp?.components?.SnackbarList;
			if (
				!element?.createRoot ||
				!element.flushSync ||
				!List ||
				!region
			) {
				return false;
			}
			const probe = document.createElement('div');
			probe.className = 'components-snackbar';
			probe.style.cssText = 'position:fixed;visibility:hidden';
			document.body.append(probe);
			const style = window.getComputedStyle(probe);
			const cssReady =
				parseFloat(style.paddingLeft) > 0 &&
				style.backgroundColor !== 'rgba(0, 0, 0, 0)' &&
				style.backgroundColor !== 'transparent';
			probe.remove();
			if (!cssReady) {
				return false;
			}
			restoreSnackbarFocus();
			disposeSnackbar();
			const host = document.createElement('div');
			host.className = 'ran-booster-general-snackbar';
			region.insertAdjacentElement('afterend', host);
			try {
				snackbar = {
					host,
					region,
					returnControl,
					root: element.createRoot(host),
				};
			} catch {
				host.remove();
				return false;
			}

			try {
				const current = snackbar;
				element.flushSync(() =>
					current.root.render(
						element.createElement(List, {
							notices: [{ id: 'general-save', content: message }],
							onRemove: () => {
								if (snackbar !== current) {
									return;
								}
								restoreSnackbarFocus();
								disposeSnackbar();
							},
						})
					)
				);
				if (current.host.querySelector('.components-snackbar')) {
					current.reposition = () => {
						const rect = region.getBoundingClientRect();
						current.host.style.setProperty(
							'--ran-booster-snackbar-center',
							`${rect.left + rect.width / 2}px`
						);
					};
					current.reposition();
					window.addEventListener('resize', current.reposition);
					if (window.ResizeObserver) {
						current.observer = new window.ResizeObserver(
							current.reposition
						);
						current.observer.observe(region);
						const content = document.getElementById('wpcontent');
						if (content) {
							current.observer.observe(content);
						}
					}
					return true;
				}
				disposeSnackbar();
				return false;
			} catch {
				disposeSnackbar();
				return false;
			}
		}

		function captureRequest(event, form) {
			const detail = event.detail;
			const general = form.matches?.('[data-ran-booster-general-form]');
			const operation = (form.form || form).elements?.namedItem(
				'ran_booster[action]'
			)?.value;
			const packageType = form.matches?.(
				'[data-ran-booster-package-settings-save]'
			)
				? /^edit-(plugin|theme)$/.exec(operation || '')?.[1]
				: null;
			const identifier = packageType
				? form.form.elements.namedItem(`ran_booster[${packageType}]`)
						?.value
				: null;
			if (
				!detail?.xhr ||
				!detail.target?.id ||
				(general &&
					![
						'ran-booster-general-region',
						'ran-booster-general-hide_github',
						'ran-booster-general-self_update_discovery',
						'ran-booster-general-numeric',
					].includes(detail.target.id)) ||
				(packageType &&
					(!identifier || detail.target.id !== 'wpbody-content'))
			) {
				return;
			}
			const record = {
				target: detail.target,
				form,
				xhr: detail.xhr,
				payload: null,
				general,
				packageType,
				identifier,
				operation,
			};
			requests.set(detail.xhr, record);
			requests.set(detail.target, record);
			const onLoad = (response) => {
				if (response.detail?.xhr !== record.xhr) {
					return;
				}
				if (
					document.getElementById(record.target.id) !==
						record.target ||
					!record.target.isConnected ||
					!form.isConnected ||
					(general && !record.target.contains(form)) ||
					(!general && requests.get(record.target) !== record)
				) {
					response.preventDefault();
					return;
				}
				record.ready = true;
				record.suppress = form.hasAttribute?.(
					'data-ran-booster-general-unconfirmed'
				);
				try {
					const header = record.xhr.getResponseHeader(
						'HX-Trigger-After-Swap'
					);
					record.payload =
						JSON.parse(header)?.[
							'ran-booster:admin-mutation-success'
						];
				} catch {
					record.payload = null;
				}
				record.providerSave =
					(operation === 'save-public-lookup-profile' &&
						record.target.id ===
							'ran-booster-public-lookup-profile-region' &&
						record.payload?.operation === undefined) ||
					(['save-access-profile', 'save-webhook-profile'].includes(
						operation
					) &&
						record.target.id ===
							'ran-booster-provider-profile-region' &&
						record.payload?.operation === `core:${operation}`);
				if (
					[
						'save-access-profile',
						'save-webhook-profile',
						'save-public-lookup-profile',
					].includes(operation) &&
					!record.providerSave
				) {
					record.payload = null;
				}
			};
			form.addEventListener('htmx:beforeOnLoad', onLoad, true);
			detail.xhr.addEventListener(
				'loadend',
				() => {
					form.removeEventListener('htmx:beforeOnLoad', onLoad, true);
				},
				{ once: true }
			);
		}

		function enhancedFormFrom(element) {
			if (!element) {
				return null;
			}

			if (element.matches?.(enhancedFormSelector)) {
				return element;
			}

			return element.closest?.(enhancedFormSelector) || null;
		}

		function requestForm(event) {
			return (
				enhancedFormFrom(event.detail?.requestConfig?.elt) ||
				enhancedFormFrom(event.detail?.elt || event.target)
			);
		}

		function isStaleSourceRequest(event, form) {
			const record = requests.get(event.detail?.xhr);
			return (
				(form?.hasAttribute?.('data-ran-booster-source-choice') &&
					event.detail?.xhr !== sourceRequest) ||
				(record && requests.get(record.target) !== record)
			);
		}

		function isPackageMutation(form) {
			return form?.hasAttribute?.('data-ran-booster-package-mutation');
		}

		function isScopedFailureStatus(status) {
			return (
				status === 400 ||
				status === 409 ||
				status === 422 ||
				status === 500
			);
		}

		function captureInteractionState(form, xhr) {
			pendingInteractionState = {
				disclosureStates: {
					...Object.fromEntries(
						Array.from(
							document.querySelectorAll(
								'[data-ran-booster-package-disclosure], [data-ran-booster-logging-disclosure]'
							),
							(disclosure) => [disclosure.id, disclosure.open]
						).filter(([id]) => id)
					),
					...(window.ranBoosterUI?.disclosureOpenStates?.() ?? {}),
				},
				form,
				xhr,
				scrollY: window.scrollY,
			};
			if (window.ranBoosterUI) {
				window.ranBoosterUI.rememberedDisclosureStates =
					pendingInteractionState.disclosureStates;
			}
		}

		function restoreInteractionState(complete = false) {
			const state = pendingInteractionState;
			if (!state) {
				return;
			}

			Array.from(
				document.querySelectorAll(
					'[data-ran-booster-package-disclosure], [data-ran-booster-logging-disclosure]'
				)
			).forEach(function (disclosure) {
				const open = state.disclosureStates?.[disclosure.id];
				if (typeof open === 'boolean') {
					disclosure.open = open;
				}
			});
			if (!complete) {
				return;
			}

			pendingInteractionState = null;
			if (window.ranBoosterUI) {
				window.ranBoosterUI.rememberedDisclosureStates = undefined;
			}
			if (typeof state.scrollY === 'number') {
				window.requestAnimationFrame(function () {
					window.scrollTo(0, state.scrollY);
				});
			}
		}

		function errorRegion(form) {
			const target = form.dataset.ranBoosterErrorTarget || '';
			if (/^#[A-Za-z][A-Za-z0-9_-]*$/.test(target)) {
				return document.getElementById(target.slice(1));
			}

			if (!isPackageMutation(form)) {
				return null;
			}

			return document.querySelector(
				'.ran-booster-admin .notice-error:not([data-ran-booster-provider-storage-notice]):not([data-ran-booster-assistance-site-notice]), .ran-booster-admin .error:not([data-ran-booster-provider-storage-notice]):not([data-ran-booster-assistance-site-notice])'
			);
		}

		function setBusy(form, xhr) {
			if (form.getAttribute('aria-busy') === 'true') {
				return;
			}

			const submitter =
				submitters.get(form) ||
				(form.matches?.('a, button')
					? form
					: form.querySelector?.('[type="submit"]:not([disabled])'));
			if (!submitter) {
				return;
			}

			const switchScope = [
				'hide_github',
				'self_update_discovery',
			].includes(form.dataset?.ranBoosterSettingsScope);
			const controls = Array.from(form.elements || [submitter])
				.filter(
					(control) =>
						!(
							form.dataset?.ranBoosterSettingsScope &&
							(control.matches?.(
								'input[role="switch"], fieldset'
							) ||
								(switchScope &&
									control.closest?.(
										'[data-ran-booster-ui-switch="general-preference"][data-ran-booster-ui-mounted] > [data-ran-booster-ui-switch-mount]'
									)))
						)
				)
				.map(function (control) {
					return {
						ariaDisabled: control.getAttribute?.('aria-disabled'),
						disabled: control.disabled === true,
						control,
					};
				});
			busyStates.set(form, {
				xhr,
				controls,
				submitter,
				submitterAriaBusy: submitter.getAttribute('aria-busy'),
			});
			form.setAttribute('aria-busy', 'true');
			if (!switchScope) {
				form.classList.add('ran-booster-enhanced-mutation-is-busy');
			}
			controls.forEach(function (state) {
				state.control.disabled = true;
				state.control.setAttribute?.('aria-disabled', 'true');
			});
			submitter.setAttribute('aria-busy', 'true');
			if (!switchScope) {
				submitter.classList.add(
					'ran-booster-enhanced-mutation__submitter--busy'
				);
				if (!form.hasAttribute?.('data-ran-booster-source-choice')) {
					submitter.classList.add('ran-booster-update-is-active');
				}
			}
		}

		function resetBusy(form, xhr) {
			const state = busyStates.get(form);
			if (state?.xhr && state.xhr !== xhr) {
				return;
			}
			if (state) {
				state.controls.forEach(function (controlState) {
					controlState.control.disabled = controlState.disabled;
					if (controlState.ariaDisabled === null) {
						controlState.control.removeAttribute?.('aria-disabled');
					} else {
						controlState.control.setAttribute?.(
							'aria-disabled',
							controlState.ariaDisabled
						);
					}
				});
				const submitter = state.submitter;
				if (state.submitterAriaBusy === null) {
					submitter.removeAttribute('aria-busy');
				} else {
					submitter.setAttribute(
						'aria-busy',
						state.submitterAriaBusy
					);
				}
				submitter.classList.remove(
					'ran-booster-enhanced-mutation__submitter--busy'
				);
				submitter.classList.remove('ran-booster-update-is-active');
				busyStates.delete(form);
			}

			form.removeAttribute('aria-busy');
			form.classList.remove('ran-booster-enhanced-mutation-is-busy');
		}

		// A credential result fills its own table row, shown only with a result.
		function syncCredentialResultRow(region) {
			const row = region?.closest?.(
				'.ran-booster-provider-management-table__result'
			);
			if (row) {
				const notice = region.querySelector?.(
					'[data-ran-booster-ui-notice]'
				);
				row.hidden = region.hidden || !notice || notice.hidden;
			}
		}

		function focusError(form, fallbackMessage, region = errorRegion(form)) {
			if (!region) {
				return;
			}

			const isCredential = form.hasAttribute(
				'data-ran-booster-credential-validation'
			);
			const isBranchNotice =
				form.dataset.ranBoosterErrorTarget ===
					'#ran-booster-repository-branch-check-error' &&
				region.hasAttribute?.('data-ran-booster-ui-notice');
			const credentialResult = isCredential
				? region.querySelector?.('[data-ran-booster-credential-result]')
				: null;
			const message = (
				fallbackMessage ||
				(credentialResult
					? credentialResult.textContent
					: renderedFailureMessage(region))
			).trim();
			if (!message) {
				region.hidden = true;
				if (isCredential) {
					syncCredentialResultRow(region);
				}
				return;
			}
			if (fallbackMessage) {
				if (isBranchNotice) {
					window.ranBoosterNotice.setIntent(region, 'error');
				}
				if (credentialResult) {
					credentialResult.textContent = message;
					credentialResult.className =
						'ran-booster-credential-health__error';
					credentialResult.hidden = false;
				} else {
					const primary = region.querySelector?.(
						'[data-ran-booster-ui-notice-primary]'
					);
					if (primary) {
						if (isBranchNotice && primary.childElementCount) {
							const retained = document.createElement('div');
							retained.hidden = true;
							retained.setAttribute(
								'data-ran-booster-ui-notice-detail',
								''
							);
							retained.append(...primary.childNodes);
							primary.parentElement.append(retained);
						}
						primary.textContent = message;
						region
							.querySelectorAll(
								'[data-ran-booster-ui-notice-detail]'
							)
							.forEach((detail) => {
								detail.hidden = true;
								detail.setAttribute('hx-disable', '');
								// HTMX cleans disabled subtrees without initializing them.
								window.htmx?.process?.(detail);
							});
						region
							.querySelectorAll(
								'[data-ran-booster-ui-notice-request-field]'
							)
							.forEach((field) => {
								field.textContent = '';
							});
					} else if (
						!region.hasAttribute?.('data-ran-booster-ui-notice')
					) {
						region.textContent = message;
					}
				}
			}
			region.hidden = false;
			if (isCredential) {
				const notice = credentialResult?.closest?.(
					'[data-ran-booster-ui-notice]'
				);
				if (notice) {
					window.ranBoosterNotice.setIntent(notice, 'error');
					notice.hidden = false;
				}
				syncCredentialResultRow(region);
				region.removeAttribute?.('role');
				region.focus?.({ preventScroll: true });
				window.ranBoosterAnnounce(message, 'assertive');
				return;
			}
			if (!region.hasAttribute('tabindex')) {
				region.setAttribute('tabindex', '-1');
			}
			window.ranBoosterAnnounce(message, 'assertive', region);
			const focus = () => {
				if (
					region.isConnected !== false &&
					renderedFailureMessage(region) === message
				) {
					region.focus?.({ preventScroll: true });
				}
			};
			if (isBranchNotice) {
				// Intent changes can queue a second initialization after restore.
				queueMicrotask(() => queueMicrotask(focus));
			} else {
				focus();
			}
		}

		function renderedFailureNotice(form, scope, allowGeneric) {
			const branch = form?.hasAttribute?.(
				'data-ran-booster-relocate-rendered-error'
			);
			const selectors = branch
				? ['[data-ran-booster-repository-branch-check]']
				: [];
			if (!branch || allowGeneric) {
				selectors.push('.notice-error, .error');
			}
			return selectors
				.flatMap((selector) => [
					...(scope?.matches?.(selector) ? [scope] : []),
					...Array.from(scope?.querySelectorAll?.(selector) || []),
				])
				.find(function (candidate) {
					return (
						(candidate.hasAttribute?.(
							'data-ran-booster-repository-branch-check'
						) ||
							candidate.hasAttribute?.(
								'data-ran-booster-admin-mutation-error'
							) ||
							candidate.closest?.('.ran-booster-admin')) &&
						!candidate.hasAttribute?.(
							'data-ran-booster-provider-storage-notice'
						) &&
						!candidate.hasAttribute?.(
							'data-ran-booster-assistance-site-notice'
						) &&
						candidate.hidden !== true &&
						renderedFailureMessage(candidate) !== ''
					);
				});
		}

		function renderedFailureMessage(notice) {
			if (!notice) {
				return '';
			}

			const primary =
				notice.querySelector?.(
					'[data-ran-booster-ui-notice-primary]'
				) || notice.querySelector?.('p');
			return (
				primary ? primary.textContent : notice.textContent || ''
			).trim();
		}

		function focusRenderedFailure(form, scope, allowGeneric) {
			const notice = renderedFailureNotice(form, scope, allowGeneric);
			if (!notice) {
				return false;
			}
			focusError(form, undefined, notice);
			return true;
		}

		function relocateRenderedFailure(form, scope, allowGeneric) {
			if (
				!form?.hasAttribute?.(
					'data-ran-booster-relocate-rendered-error'
				)
			) {
				return false;
			}

			const region = errorRegion(form);
			const notice = renderedFailureNotice(form, scope, allowGeneric);
			const message = renderedFailureMessage(notice);
			if (!region || !message) {
				return false;
			}
			if (notice === region) {
				focusError(form);
				return true;
			}
			const sourcePrimary =
				notice.querySelector?.(
					'[data-ran-booster-ui-notice-primary]'
				) || notice.querySelector?.('p');
			if (
				!sourcePrimary ||
				notice.contains?.(region) ||
				typeof window.htmx?.swap !== 'function'
			) {
				return focusRenderedFailure(form, scope, allowGeneric);
			}

			const isBranchNotice =
				form.dataset.ranBoosterErrorTarget ===
					'#ran-booster-repository-branch-check-error' &&
				region.hasAttribute?.('data-ran-booster-ui-notice');
			const content = isBranchNotice
				? region.querySelector('[data-ran-booster-ui-notice-content]')
				: region;
			const intent = notice.getAttribute?.(
				'data-ran-booster-ui-notice-intent'
			);
			if (isBranchNotice) {
				window.ranBoosterNotice.setIntent(
					region,
					intent === 'warning' ? 'warning' : 'error'
				);
			}
			const swap = {
				swapStyle: 'outerHTML',
				swapDelay: 0,
				settleDelay: 0,
				transition: false,
				ignoreTitle: true,
			};
			// Cleanup restores original component content and HTMX bindings first.
			window.htmx.swap(notice, '', swap);
			const sourceContent =
				notice.querySelector?.(
					'[data-ran-booster-ui-notice-content]'
				) || notice;
			const paragraph = isBranchNotice
				? region.querySelector('[data-ran-booster-ui-notice-primary]')
				: region.querySelector?.('p');
			content
				?.querySelectorAll?.('[data-ran-booster-ui-notice-detail]')
				.forEach((detail) => window.htmx.swap(detail, '', swap));
			if (paragraph) {
				Array.from(paragraph.children).forEach((child) =>
					window.htmx.swap(child, '', swap)
				);
				paragraph.replaceChildren(...sourcePrimary.childNodes);
				sourcePrimary.remove();
			} else {
				region.textContent = message;
			}
			if (content && sourceContent.childNodes.length) {
				const detail = document.createElement('div');
				detail.setAttribute('data-ran-booster-ui-notice-detail', '');
				detail.append(...sourceContent.childNodes);
				content.append(detail);
			}
			window.htmx.process?.(content || region);
			focusError(form);
			return true;
		}

		function cleanPackageResultUrl() {
			const notices = Array.from(
				document.querySelectorAll(
					'#wpbody-content [data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
				)
			);
			if (notices.length > 0) {
				const canonicalUrl = new URL(window.location.href);
				'ran_booster_result ran_booster_package _ran_booster_notice_nonce'
					.split(' ')
					.forEach((key) => canonicalUrl.searchParams.delete(key));
				const history = window.history;
				history.replaceState(history.state, '', canonicalUrl);
			}
			return notices.length > 0;
		}

		function consumeReleaseWorkflowResult() {
			if (
				document.querySelector(
					'#wpbody-content [data-ran-booster-release-workflow-result]'
				) === null
			) {
				return;
			}

			const canonicalUrl = new URL(window.location.href);
			'ran_booster_release_workflow_result ran_booster_release_workflow_success ran_booster_release_workflow_type ran_booster_release_workflow_package ran_booster_release_workflow_failure_stage ran_booster_release_workflow_diagnostic ran_booster_release_workflow_diagnostic_available ran_booster_release_workflow_reference ran_booster_release_workflow_channel ran_booster_release_workflow_preview ran_booster_release_workflow_result_nonce ran_booster_release_workflow_source_revision ran_booster_release_workflow_provider ran_booster_release_workflow_repository ran_booster_release_workflow_message ran_booster_release_workflow_remediation'
				.split(' ')
				.forEach((key) => canonicalUrl.searchParams.delete(key));
			const history = window.history;
			history.replaceState(history.state, '', canonicalUrl);
		}

		document.addEventListener(
			'submit',
			function (event) {
				const form = enhancedFormFrom(event.target);
				if (form && event.submitter) {
					submitters.set(form, event.submitter);
				}
			},
			true
		);

		consumeReleaseWorkflowResult();

		document.addEventListener('click', function (event) {
			const submitter = enhancedFormFrom(event.target);
			if (submitter?.matches?.('[type="submit"][form]')) {
				event.preventDefault();
			}
		});

		document.addEventListener('htmx:beforeRequest', function (event) {
			const form = requestForm(event);
			if (form) {
				if (form.getAttribute('aria-busy') === 'true') {
					event.preventDefault();
					return;
				}

				if (form.hasAttribute?.('data-ran-booster-source-choice')) {
					sourceRequest = event.detail.xhr;
					const progress = document.querySelector(
						'[data-ran-booster-source-progress]'
					);
					if (progress) {
						progress.hidden = false;
					}
				}
				captureRequest(event, form);
				if (!form.dataset?.ranBoosterSettingsScope) {
					captureInteractionState(form, event.detail.xhr);
				}
				setBusy(form, event.detail.xhr);
			}
		});

		document.addEventListener(
			'htmx:beforeCleanupElement',
			function (event) {
				const element = event.target;
				if (
					snackbar &&
					(element === snackbar.host ||
						element?.contains?.(snackbar.host) ||
						element === snackbar.region ||
						element?.contains?.(snackbar.region))
				) {
					restoreSnackbarFocus(element);
					disposeSnackbar();
				}
				if (
					element?.matches?.(
						'[data-ran-booster-credential-validation]'
					)
				) {
					window.htmx?.trigger(element, 'htmx:abort');
				}
			}
		);

		document.addEventListener('htmx:beforeTransition', function (event) {
			if (requestForm(event)) {
				// The browser's View Transition snapshot occupies the top layer,
				// where feedback cannot remain visible. Keep the existing
				// Core-owned busy feedback without a view transition.
				event.preventDefault();
			}
		});

		document.addEventListener('htmx:beforeSwap', function (event) {
			const form = requestForm(event);
			if (form && isScopedFailureStatus(event.detail?.xhr?.status)) {
				event.detail.shouldSwap = !form.hasAttribute?.(
					'data-ran-booster-source-choice'
				);
			}
		});

		document.addEventListener('htmx:afterSwap', function (event) {
			const general = requests.get(event.detail?.xhr);
			const region = document.getElementById(
				general?.target.id || 'ran-booster-general-region'
			);
			if (
				general?.payload &&
				!general.consumed &&
				region &&
				region !== general.target &&
				(general.general
					? region.hasAttribute('data-ran-booster-general-saved')
					: general.ready &&
						general.xhr.status >= 200 &&
						general.xhr.status < 300 &&
						requests.get(general.target) === general)
			) {
				general.suppress ||= general.form.hasAttribute?.(
					'data-ran-booster-general-unconfirmed'
				);
				armedSuccess = general;
				queueMicrotask(() => {
					if (armedSuccess === general) {
						armedSuccess = null;
					}
				});
			}
			const form = requestForm(event) || pendingInteractionState?.form;
			if (form?.dataset?.ranBoosterSettingsScope) {
				return;
			}
			if (!form || isStaleSourceRequest(event, form)) {
				return;
			}
			if (form.hasAttribute?.('data-ran-booster-credential-validation')) {
				const result = errorRegion(form);
				if (event.target && event.target !== result) {
					// The out-of-band Health label reports its own swap.
					return;
				}
				// Before the success trigger looks for the visible result.
				syncCredentialResultRow(result);
			}
			if (
				!pendingInteractionState?.xhr ||
				pendingInteractionState.xhr === event.detail?.xhr
			) {
				restoreInteractionState();
			}
			consumeReleaseWorkflowResult();
			const save = requests.get(event.detail?.xhr);
			if (
				save?.packageType &&
				save.ready &&
				save.xhr.status >= 200 &&
				save.xhr.status < 300 &&
				!save.consumed &&
				requests.get(save.target) === save
			) {
				const updated = document.getElementById(save.target.id);
				const notice = updated?.querySelector(
					'[data-ran-booster-package-saved]'
				);
				const edit = updated?.querySelector(
					'#ran-booster-package-edit-form'
				);
				if (
					updated !== save.target &&
					edit?.elements.namedItem('ran_booster[action]')?.value ===
						`edit-${save.packageType}` &&
					edit.elements.namedItem(`ran_booster[${save.packageType}]`)
						?.value === save.identifier &&
					notice?.dataset.ranBoosterSavedPackage ===
						save.identifier &&
					notice.dataset.ranBoosterSavedType === save.packageType
				) {
					save.saveNotice = notice;
					save.saveRegion = updated;
					cleanPackageResultUrl();
					return;
				}
			}

			const success = cleanPackageResultUrl();
			if (success && !isScopedFailureStatus(event.detail?.xhr?.status)) {
				const updated = save && document.getElementById(save.target.id);
				if (
					save?.ready &&
					!save.packageType &&
					save.xhr.status >= 200 &&
					save.xhr.status < 300 &&
					updated &&
					updated !== save.target
				) {
					save.resultRegion = updated;
					save.resultMessage = Array.from(
						updated.querySelectorAll(
							'[data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
						)
					)
						.filter((notice) => {
							const live = notice.closest(
								'[role="status"], [role="alert"], [aria-live]'
							);
							return (
								notice.getClientRects().length > 0 &&
								(!live ||
									live.getAttribute('aria-live') === 'off')
							);
						})
						.map((notice) => notice.textContent.trim())
						.filter(Boolean)
						.join(' ');
				}
				return;
			}

			const scope = event.detail?.elt || event.target;
			const failed = isScopedFailureStatus(event.detail?.xhr?.status);
			if (relocateRenderedFailure(form, scope, failed)) {
				return;
			}

			if (form && failed) {
				if (
					form.hasAttribute('data-ran-booster-credential-validation')
				) {
					focusError(form);
					return;
				}
				const target = form.dataset.ranBoosterErrorTarget || '';
				const explicit = /^#[A-Za-z][A-Za-z0-9_-]*$/.test(target)
					? document.getElementById(target.slice(1))
					: null;
				if (
					explicit &&
					(scope === explicit || scope?.contains?.(explicit)) &&
					renderedFailureMessage(explicit)
				) {
					focusError(form);
				} else if (
					!focusRenderedFailure(form, scope, true) &&
					explicit
				) {
					focusError(
						form,
						wp.i18n.__(
							'We could not complete that request. Please try again.',
							'ran-booster'
						)
					);
				}
				return;
			}

			if (isPackageMutation(form) && errorRegion(form)) {
				focusRenderedFailure(form, scope, false);
			}
		});

		document.addEventListener('htmx:afterSettle', function (event) {
			const form = requestForm(event) || pendingInteractionState?.form;
			if (form?.dataset?.ranBoosterSettingsScope) {
				return;
			}
			if (form && !isStaleSourceRequest(event, form)) {
				const state = pendingInteractionState;
				const save = requests.get(event.detail?.xhr);
				if (!state?.xhr || state.xhr === event.detail?.xhr) {
					restoreInteractionState(true);
				}
				if (
					save?.resultMessage &&
					!save.consumed &&
					requests.get(save.target) === save &&
					document.getElementById(save.target.id) ===
						save.resultRegion
				) {
					save.consumed = true;
					window.ranBoosterAnnounce(save.resultMessage);
				}
				if (
					save?.packageType &&
					requests.get(save.target) === save &&
					!save.consumed &&
					save.saveNotice?.isConnected &&
					document.getElementById(save.target.id) === save.saveRegion
				) {
					save.consumed = true;
					const message = save.saveNotice.textContent.trim();
					if (
						message &&
						presentGeneralSnackbar(message, save.saveRegion)
					) {
						save.saveNotice.remove();
					} else {
						window.ranBoosterAnnounce(message);
					}
				}
			}
		});

		document.addEventListener(
			'ran-booster:admin-mutation-success',
			function (event) {
				const message =
					event.detail?.message || event.detail?.value?.message;
				if (
					typeof message !== 'string' ||
					!message ||
					armedSuccess?.payload?.message !== message
				) {
					return;
				}
				const confirmed = armedSuccess;
				confirmed.consumed = true;
				armedSuccess = null;
				if (confirmed.suppress) {
					return;
				}
				const region = document.getElementById(confirmed.target.id);
				if (!region || region === confirmed.target) {
					return;
				}
				if (confirmed.general || confirmed.providerSave) {
					const returned = confirmed.providerSave
						? Array.from(region.querySelectorAll('form')).filter(
								(form) =>
									form.elements.namedItem(
										'ran_booster[action]'
									)?.value === confirmed.operation
							)
						: [];
					const control =
						returned
							.flatMap((form) =>
								Array.from(
									form.querySelectorAll('[type="submit"]')
								)
							)
							.find(
								(button) =>
									!button.disabled &&
									button.getClientRects().length > 0
							) ||
						(confirmed.providerSave &&
							region.querySelector(
								'[data-ran-booster-provider-profile-focus]'
							)) ||
						submitters.get(confirmed.form);
					if (
						presentGeneralSnackbar(
							message,
							confirmed.general ? undefined : region,
							control
						)
					) {
						return;
					}
				}
				const existing = Array.from(region.querySelectorAll('p')).find(
					(node) =>
						node.getClientRects().length > 0 &&
						node.textContent.trim() === message
				);
				if (!existing) {
					const feedback = document.createElement('p');
					feedback.textContent = message;
					// A region can name where its confirmation belongs. The
					// text is for assistive technology only, so it stays
					// visually hidden beside the actions that caused it.
					const anchor = region.querySelector?.(
						'[data-ran-booster-success-feedback]'
					);
					if (anchor) {
						feedback.className = 'screen-reader-text';
						anchor.append(feedback);
					} else {
						const result =
							region.tagName === 'TR'
								? region.cells[region.cells.length - 1] ||
									region.insertCell()
								: region;
						result.append(feedback);
					}
				}
				window.ranBoosterAnnounce(message, 'polite', existing);
			}
		);

		document.addEventListener('htmx:afterRequest', function (event) {
			if (event.detail?.xhr === sourceRequest && sourceRequest) {
				const progress = document.querySelector(
					'[data-ran-booster-source-progress]'
				);
				if (progress) {
					progress.hidden = true;
				}
			}
			const form = requestForm(event);
			if (form) {
				resetBusy(form, event.detail?.xhr);
			}
		});

		[
			'htmx:responseError',
			'htmx:sendError',
			'htmx:swapError',
			'htmx:targetError',
			'htmx:timeout',
		].forEach(function (eventName) {
			document.addEventListener(eventName, function (event) {
				const form = requestForm(event);
				if (form) {
					resetBusy(form, event.detail?.xhr);
				}
				if (form && !isStaleSourceRequest(event, form)) {
					if (
						eventName === 'htmx:responseError' &&
						!form.hasAttribute?.(
							'data-ran-booster-source-choice'
						) &&
						isScopedFailureStatus(event.detail?.xhr?.status)
					) {
						return;
					}

					if (
						form.dataset?.ranBoosterSettingsScope &&
						window.ranBoosterSettingsInitialized
					) {
						return;
					}
					pendingInteractionState = null;
					focusError(
						form,
						wp.i18n.__(
							'We could not complete that request. Please try again.',
							'ran-booster'
						)
					);
				}
			});
		});

		const clearSnackbar = function () {
			restoreSnackbarFocus();
			disposeSnackbar();
		};
		window.addEventListener('pagehide', clearSnackbar);
		document.addEventListener('htmx:beforeHistorySave', clearSnackbar);
		window.ranBoosterEnhancedMutationFeedbackInitialized = true;
	}
})();
