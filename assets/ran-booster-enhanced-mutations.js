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
		const enhancedFormSelector = '[data-ran-booster-enhanced-mutation]';
		const toastSelector = '[data-ran-booster-feedback-toast]';
		const submitters = new WeakMap();
		const busyStates = new WeakMap();
		let pendingInteractionState = null;
		const toastAnimations = new WeakMap();
		const toastTimers = new WeakMap();

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

		function captureInteractionState(form) {
			pendingInteractionState = {
				disclosureStates: Object.fromEntries(
					Array.from(
						document.querySelectorAll(
							'[data-ran-booster-package-disclosure]'
						),
						(disclosure) => [disclosure.id, disclosure.open]
					).filter(([id]) => id)
				),
				form,
				scrollY: window.scrollY,
				successMessage: '',
			};
		}

		function restoreInteractionState(complete = false) {
			const state = pendingInteractionState;
			if (!state) {
				return;
			}

			Array.from(
				document.querySelectorAll(
					'[data-ran-booster-package-disclosure]'
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
				'#wpbody-content .notice-error, #wpbody-content .error'
			);
		}

		function announce(message, type) {
			if (!message || !window.wp?.a11y?.speak) {
				return;
			}

			window.wp.a11y.speak(
				message,
				type === 'error' ? 'assertive' : 'polite'
			);
		}

		function setBusy(form) {
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

			const controls = Array.from(form.elements || [submitter]).map(
				function (control) {
					return {
						ariaDisabled: control.getAttribute?.('aria-disabled'),
						disabled: control.disabled === true,
						control,
					};
				}
			);
			busyStates.set(form, {
				controls,
				submitter,
				submitterAriaBusy: submitter.getAttribute('aria-busy'),
			});
			form.setAttribute('aria-busy', 'true');
			form.classList.add('ran-booster-enhanced-mutation-is-busy');
			controls.forEach(function (state) {
				state.control.disabled = true;
				state.control.setAttribute?.('aria-disabled', 'true');
			});
			submitter.setAttribute('aria-busy', 'true');
			submitter.classList.add(
				'ran-booster-enhanced-mutation__submitter--busy'
			);
			submitter.classList.add('ran-booster-update-is-active');
		}

		function resetBusy(form) {
			const state = busyStates.get(form);
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

		function focusError(form, fallbackMessage) {
			const region = errorRegion(form);
			if (!region) {
				return;
			}

			const message = (
				fallbackMessage ||
				region.textContent ||
				''
			).trim();
			if (!message) {
				region.hidden = true;
				return;
			}
			if (fallbackMessage) {
				region.textContent = message;
			}
			region.hidden = false;
			region.setAttribute('role', 'alert');
			if (!region.hasAttribute('tabindex')) {
				region.setAttribute('tabindex', '-1');
			}
			region.focus?.({ preventScroll: true });
			announce(region.textContent.trim(), 'error');
		}

		function renderedFailureNotice(form) {
			const region = errorRegion(form);
			return Array.from(
				document.querySelectorAll(
					'#wpbody-content .notice-error, #wpbody-content .error'
				)
			).find(function (candidate) {
				return (
					candidate !== region &&
					candidate.hidden !== true &&
					(candidate.textContent || '').trim() !== ''
				);
			});
		}

		function renderedFailureMessage(notice) {
			if (!notice) {
				return '';
			}

			return (
				notice?.querySelector?.('p')?.textContent ||
				notice?.textContent ||
				''
			).trim();
		}

		function focusRenderedFailure(form) {
			const notice = renderedFailureNotice(form);
			const message = renderedFailureMessage(notice);
			if (!message) {
				return false;
			}

			notice.setAttribute?.('role', 'alert');
			if (!notice.hasAttribute?.('tabindex')) {
				notice.setAttribute?.('tabindex', '-1');
			}
			notice.focus?.({ preventScroll: true });
			announce(message, 'error');
			return true;
		}

		function hideToast(toast) {
			toast.classList.remove('is-visible');
			toast.hidden = true;
			toastAnimations.delete(toast);
			toastTimers.delete(toast);
		}

		function prefersReducedMotion() {
			return (
				window.matchMedia?.('(prefers-reduced-motion: reduce)')
					?.matches === true
			);
		}

		function dismissToast(toast) {
			const timeout = toastTimers.get(toast);
			if (timeout) {
				window.clearTimeout(timeout);
			}
			const previousAnimation = toastAnimations.get(toast);
			if (previousAnimation) {
				previousAnimation.cancel();
			}
			if (prefersReducedMotion()) {
				hideToast(toast);
				return;
			}

			const animation = toast.animate?.(
				[
					{ opacity: 1, transform: 'translateY(0)' },
					{
						opacity: 0,
						transform:
							'translateY(calc(100% + var(--ran-booster-space-20)))',
					},
				],
				{ duration: 160, easing: 'ease-in', fill: 'both' }
			);
			if (!animation) {
				hideToast(toast);
				return;
			}

			toastAnimations.set(toast, animation);
			animation.onfinish = function () {
				hideToast(toast);
			};
		}

		function showToast(message = '') {
			const toast = document.querySelector(toastSelector);
			if (!toast) {
				return;
			}
			const messageElement = toast.querySelector?.(
				'[data-ran-booster-feedback-toast-message]'
			);
			if (message && messageElement) {
				messageElement.textContent = message;
			}

			const timeout = Number.parseInt(
				toast.dataset.ranBoosterFeedbackTimeout || '6000',
				10
			);
			const previousTimeout = toastTimers.get(toast);
			if (previousTimeout) {
				window.clearTimeout(previousTimeout);
			}
			const previousAnimation = toastAnimations.get(toast);
			if (previousAnimation) {
				previousAnimation.cancel();
			}

			// Prepare the populated toast completely before it can paint. Its
			// stacking order and footer-edge entrance frame are active before
			// visibility is restored, so no underlying edge can flash.
			toast.style.visibility = 'hidden';
			toast.style.zIndex = '100';
			toast.hidden = false;
			toast.getBoundingClientRect?.();
			const animation = prefersReducedMotion()
				? null
				: toast.animate?.(
						[
							{
								opacity: 0,
								transform:
									'translateY(calc(100% + var(--ran-booster-space-20)))',
							},
							{ opacity: 1, transform: 'translateY(0)' },
						],
						{ duration: 160, easing: 'ease-out', fill: 'both' }
					);
			if (animation) {
				animation.pause();
				animation.currentTime = 0;
				toastAnimations.set(toast, animation);
			}

			const revealToast = function () {
				if (animation && toastAnimations.get(toast) !== animation) {
					return;
				}
				toast.classList.add('is-visible');
				toast.style.visibility = '';
				animation?.play();
				announce(
					(messageElement?.textContent || toast.textContent).trim(),
					'polite'
				);
			};
			if (animation && window.requestAnimationFrame) {
				window.requestAnimationFrame(revealToast);
			} else {
				revealToast();
			}
			toastTimers.set(
				toast,
				window.setTimeout(
					function () {
						dismissToast(toast);
					},
					Number.isFinite(timeout) && timeout > 0 ? timeout : 6000
				)
			);
		}

		function consumeEnhancedSuccess() {
			const notices = Array.from(
				document.querySelectorAll(
					'#wpbody-content [data-ran-booster-package-success]:not([data-ran-booster-update-summary])'
				)
			);
			const messages = [];
			if (notices.length > 0) {
				const canonicalUrl = new URL(window.location.href);
				'ran_booster_result ran_booster_package _ran_booster_notice_nonce'
					.split(' ')
					.forEach((key) => canonicalUrl.searchParams.delete(key));
				const history = window.history;
				history.replaceState(history.state, '', canonicalUrl);
			}

			notices.forEach(function (notice) {
				const message = notice.textContent?.trim() || '';
				if (message && !messages.includes(message)) {
					messages.push(message);
				}
				notice.remove?.();
			});

			return messages.join(' ');
		}

		function dispatchSuccess(message) {
			if (!message || typeof window.CustomEvent !== 'function') {
				return;
			}

			document.dispatchEvent(
				new window.CustomEvent('ran-booster:admin-mutation-success', {
					detail: { message },
				})
			);
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

				captureInteractionState(form);
				setBusy(form);
			}
		});

		document.addEventListener('htmx:beforeTransition', function (event) {
			if (requestForm(event)) {
				// The browser's View Transition snapshot occupies the top layer,
				// where no toast z-index can outrank it. Enhanced mutations use
				// the Core-owned button and toast motion instead.
				event.preventDefault();
			}
		});

		document.addEventListener('htmx:beforeSwap', function (event) {
			const form = requestForm(event);
			if (form && isScopedFailureStatus(event.detail?.xhr?.status)) {
				event.detail.shouldSwap = true;
			}
		});

		document.addEventListener('htmx:afterSwap', function (event) {
			const form = requestForm(event) || pendingInteractionState?.form;
			if (!form) {
				return;
			}

			restoreInteractionState();
			const successMessage = consumeEnhancedSuccess();
			if (successMessage) {
				pendingInteractionState ||= {
					scrollY: null,
					successMessage: '',
				};
				pendingInteractionState.successMessage = successMessage;
				return;
			}

			if (form && isScopedFailureStatus(event.detail?.xhr?.status)) {
				if (!focusRenderedFailure(form)) {
					focusError(form);
				}
				return;
			}

			if (isPackageMutation(form) && errorRegion(form)) {
				if (!focusRenderedFailure(form)) {
					focusError(form);
				}
			}
		});

		document.addEventListener('htmx:afterSettle', function (event) {
			const form = requestForm(event) || pendingInteractionState?.form;
			if (form) {
				const successMessage = pendingInteractionState?.successMessage;
				restoreInteractionState(true);
				dispatchSuccess(successMessage);
			}
		});

		document.addEventListener(
			'ran-booster:admin-mutation-success',
			function (event) {
				const message =
					event.detail?.message || event.detail?.value?.message;
				if (typeof message === 'string' && message) {
					showToast(message);
				}
			}
		);

		document.addEventListener('htmx:afterRequest', function (event) {
			const form = requestForm(event);
			if (form) {
				resetBusy(form);
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
					if (
						eventName === 'htmx:responseError' &&
						isScopedFailureStatus(event.detail?.xhr?.status)
					) {
						return;
					}

					resetBusy(form);
					pendingInteractionState = null;
					focusError(
						form,
						'We could not complete that request. Please try again.'
					);
				}
			});
		});
	}
})();
