(function () {
	'use strict';

	// The one browser sentence for a rate-limited Git provider. The server sends the
	// provider label and a Unix retry time; the time is shown on this browser's own
	// clock and language, never the server's or the site's time zone.
	const message = (rateLimit) => {
		const { __, sprintf } = window.wp.i18n;
		const provider =
			typeof rateLimit?.provider === 'string' && rateLimit.provider
				? rateLimit.provider
				: __('The Git provider', 'ran-booster');
		const retryAt = rateLimit?.retry_at;
		const now = Date.now() / 1000;
		if (
			Number.isInteger(retryAt) &&
			retryAt > now &&
			retryAt <= now + 86400
		) {
			const options = { hour: 'numeric', minute: '2-digit' };
			const date = new Date(Math.ceil(retryAt / 60) * 60000);
			let time;
			try {
				time = date.toLocaleTimeString(
					window.document.documentElement?.lang || undefined,
					options
				);
			} catch {
				time = date.toLocaleTimeString(undefined, options);
			}
			return sprintf(
				/* translators: 1: Git provider name, such as GitHub. 2: Time on the viewer's clock, such as 2:14 am. */
				__(
					'%1$s is limiting lookups from this site. Try again after %2$s.',
					'ran-booster'
				),
				provider,
				time
			);
		}
		return sprintf(
			/* translators: %s: Git provider name, such as GitHub. */
			__(
				'%s is limiting lookups from this site. Try again later.',
				'ran-booster'
			),
			provider
		);
	};

	// A server notice prints the minutes sentence inside <time datetime>; with
	// JavaScript the same fact is shown as a clock time instead.
	const upgrade = (root) => {
		const elements = root?.querySelectorAll?.(
			'time[data-ran-booster-rate-limit]'
		);
		(elements ? Array.from(elements) : []).forEach((element) => {
			const retryAt = Date.parse(element.getAttribute('datetime'));
			if (Number.isNaN(retryAt)) {
				return;
			}
			element.textContent = message({
				provider: element.getAttribute('data-ran-booster-rate-limit'),
				retry_at: Math.floor(retryAt / 1000),
			});
		});
	};

	window.ranBoosterRateLimit = { message, upgrade };
	if (window.document.readyState === 'loading') {
		window.document.addEventListener('DOMContentLoaded', () =>
			upgrade(window.document)
		);
	} else {
		upgrade(window.document);
	}
	window.document.addEventListener('htmx:afterSwap', (event) =>
		upgrade(event.detail?.target || window.document)
	);
})();
