(function () {
	'use strict';

	if (window.ranBoosterNoticePreferenceInitialized) {
		return;
	}
	window.ranBoosterNoticePreferenceInitialized = true;
	let acknowledgedDevelopmentState = '';
	function removeDevelopmentNotice(notice) {
		if (notice.contains(notice.ownerDocument.activeElement)) {
			const heading = document.getElementById(
				'ran-booster-onboarding-heading'
			);
			if (heading) {
				heading.setAttribute('tabindex', '-1');
				heading.focus();
			}
		}
		notice.dispatchEvent(
			new window.CustomEvent('ran-booster:ui-notice-update', {
				bubbles: true,
			})
		);
		notice.remove();
	}
	function initDevelopmentNotice() {
		document
			.querySelectorAll('[data-ran-booster-development-safety]')
			.forEach(function (notice) {
				if (
					acknowledgedDevelopmentState &&
					notice.dataset.ranBoosterNoticeState ===
						acknowledgedDevelopmentState
				) {
					removeDevelopmentNotice(notice);
					return;
				}
				const button = notice.querySelector('.notice-dismiss');
				if (button) {
					button.style.display = '';
					button.hidden = false;
				}
			});
	}
	initDevelopmentNotice();
	document.addEventListener('htmx:load', initDevelopmentNotice);
	document.addEventListener('htmx:historyRestore', initDevelopmentNotice);
	const notices = [
		{
			selector: '[data-ran-booster-credential-expiry-notice]',
			config: 'ranBoosterCredentialExpiryNotice',
			mode: 'dismiss',
			modes: ['dismiss', 'snooze'],
		},
		{
			selector: '[data-ran-booster-background-failure-notice]',
			config: 'ranBoosterBackgroundFailureNotice',
			mode: 'acknowledge',
			modes: ['acknowledge'],
		},
		{
			selector: '[data-ran-booster-development-safety]',
			config: 'ranBoosterDevelopmentSafetyNotice',
			mode: 'acknowledge',
			modes: ['acknowledge'],
		},
	];
	const pending = new WeakSet();
	document.addEventListener(
		'click',
		function (event) {
			const button =
				event.target &&
				event.target.closest(
					'[data-ran-booster-notice-preference-mode], .notice-dismiss'
				);
			if (!button) {
				return;
			}
			const entry = notices.find(function (item) {
				return button.closest(item.selector);
			});
			const notice = entry && button.closest(entry.selector);
			const config = entry && window[entry.config];
			if (!config) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			const mode =
				button.dataset.ranBoosterNoticePreferenceMode || entry.mode;
			if (!entry.modes.includes(mode) || pending.has(notice)) {
				return;
			}
			pending.add(notice);
			notice.setAttribute('aria-busy', 'true');
			const buttons = Array.from(
				notice.querySelectorAll(
					'[data-ran-booster-notice-preference-mode], .notice-dismiss'
				)
			);
			const isDevelopment =
				entry.config === 'ranBoosterDevelopmentSafetyNotice';
			const ariaDisabled = isDevelopment
				? button.getAttribute('aria-disabled')
				: null;
			const disabled = buttons.map(function (item) {
				return item.disabled;
			});
			buttons.forEach(function (item) {
				if (isDevelopment && item === button) {
					item.setAttribute('aria-disabled', 'true');
				} else {
					item.disabled = true;
				}
			});
			const previousError = notice.querySelector(
				'[data-ran-booster-notice-preference-error]'
			);
			if (previousError) {
				previousError.textContent = '';
			}
			const developmentState = isDevelopment
				? notice.dataset.ranBoosterNoticeState
				: '';
			const body = new URLSearchParams({
				action: config.action,
				nonce: config.nonce,
				mode,
			});
			if (notice.dataset.ranBoosterNoticeState) {
				body.set('state', notice.dataset.ranBoosterNoticeState);
			}
			if (entry.config === 'ranBoosterCredentialExpiryNotice') {
				body.set('scope', notice.dataset.ranBoosterNoticeScope || '');
			}
			window
				.fetch(config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type':
							'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				})
				.then(function (response) {
					return response.json().then(function (result) {
						if (
							!response.ok ||
							!result ||
							result.success !== true
						) {
							const message =
								result && result.data && result.data.message;
							const error = new Error();
							error.noticeMessage =
								typeof message === 'string'
									? message
									: config.errorMessage;
							throw error;
						}
						if (
							developmentState &&
							notice.dataset.ranBoosterNoticeState !==
								developmentState
						) {
							return;
						}
						if (developmentState) {
							acknowledgedDevelopmentState = developmentState;
							removeDevelopmentNotice(notice);
							initDevelopmentNotice();
						} else {
							notice.dispatchEvent(
								new window.CustomEvent(
									'ran-booster:ui-notice-update',
									{
										bubbles: true,
									}
								)
							);
							notice.remove();
						}
					});
				})
				.catch(function (error) {
					let message = notice.querySelector(
						'[data-ran-booster-notice-preference-error]'
					);
					if (!message) {
						message = document.createElement('p');
						message.dataset.ranBoosterNoticePreferenceError =
							'true';
						message.setAttribute('role', 'alert');
						(
							notice.querySelector(
								'[data-ran-booster-ui-notice-content]'
							) || notice
						).append(message);
					}
					message.textContent =
						error.noticeMessage || config.errorMessage;
				})
				.finally(function () {
					pending.delete(notice);
					notice.removeAttribute('aria-busy');
					if (isDevelopment) {
						if (ariaDisabled === null) {
							button.removeAttribute('aria-disabled');
						} else {
							button.setAttribute('aria-disabled', ariaDisabled);
						}
					}
					buttons.forEach(function (item, index) {
						item.disabled = disabled[index];
					});
				});
		},
		true
	);
})();
