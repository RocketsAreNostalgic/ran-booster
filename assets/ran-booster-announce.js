(function () {
	'use strict';

	// Every Booster status or error announcement goes through this one helper,
	// which hands the message to WordPress core wp.a11y.speak (a declared
	// dependency). Scripts must not add role="alert", role="status" or
	// aria-live to the hosts they update. Errors are assertive; everything
	// else is polite. A host that already sits inside rendered live markup
	// announces itself, so passing it here prevents a second announcement.
	const live = '[role="alert"], [role="status"], [aria-live]';

	window.ranBoosterAnnounce = function (
		message,
		politeness = 'polite',
		host = null
	) {
		const text = typeof message === 'string' ? message.trim() : '';
		const region = host?.closest?.(live);
		if (!text || (region && region.getAttribute('aria-live') !== 'off')) {
			return false;
		}
		window.wp?.a11y?.speak?.(
			text,
			politeness === 'assertive' ? 'assertive' : 'polite'
		);
		return true;
	};
})();
