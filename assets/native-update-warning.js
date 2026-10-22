(function ($, window, document) {
	'use strict';

	const requestKey = 'ran_booster_native_update_warning';
	const selector = '[data-ran-booster-native-update-warning]';

	function setWarningVisible(value) {
		if (true !== value && false !== value) {
			return;
		}

		$(selector).prop('hidden', !value);
	}

	$(document).on('heartbeat-send', function (event, data) {
		data[requestKey] = true;
	});

	$(document).on('heartbeat-tick', function (event, data) {
		if (!data || 'boolean' !== typeof data[requestKey]) {
			return;
		}

		setWarningVisible(data[requestKey]);
	});

	$(document).on('wp-plugin-update-success', function () {
		if (
			window.wp &&
			window.wp.heartbeat &&
			'function' === typeof window.wp.heartbeat.connectNow
		) {
			window.wp.heartbeat.connectNow();
		}
	});
})(jQuery, window, document);
