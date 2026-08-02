(function () {
	'use strict';

	document.addEventListener('click', function (event) {
		const dismissButton = event.target.closest(
			'[data-ran-booster-credential-expiry-notice] .notice-dismiss'
		);
		const config = window.ranBoosterCredentialExpiryNotice;

		if (!dismissButton || !config) {
			return;
		}

		const body = new URLSearchParams({
			action: config.action,
			nonce: config.nonce,
		});

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
			.catch(function () {
				// WordPress already closed the notice. A failed write makes it
				// reappear on the next administration request.
			});
	});
})();
