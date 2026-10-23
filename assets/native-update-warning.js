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

	$(document).on('wp-plugin-updating', function (event, args) {
		const nativeError = window.wp?.updates?.updatePluginError;
		if (
			('plugins' !== window.pagenow &&
				'plugins-network' !== window.pagenow) ||
			!args ||
			'function' !== typeof nativeError ||
			args.error !== nativeError
		) {
			return;
		}

		args.error = function (response) {
			const updates = window.wp.updates;
			if (
				!updates.isValidResponse(response, 'update') ||
				updates.maybeHandleCredentialError(response, 'update-plugin')
			) {
				return;
			}

			const { __, _x, sprintf } = window.wp.i18n;
			const message = sprintf(
				/* translators: %s: Error string for a failed update. */
				__('Update failed: %s', 'ran-booster'),
				response.errorMessage
			);
			const $row = response.plugin
				? $('tr[data-plugin="' + response.plugin + '"]')
				: $('tr[data-slug="' + response.slug + '"]');
			$row.removeClass('is-enqueued');
			const $message = $row
				.find('.update-message')
				.removeClass('updating-message notice-warning')
				.addClass('notice-error')
				.find('p')
				.html(message);

			if (response.pluginName) {
				const ariaLabel = sprintf(
					/* translators: %s: Plugin name and version. */
					_x('%s update failed.', 'plugin', 'ran-booster'),
					response.pluginName
				);
				$message.attr('aria-label', ariaLabel);
			} else {
				$message.removeAttr('aria-label');
			}

			$('#wp-admin-bar-updates').removeClass('spin');
			window.wp.a11y.speak(message, 'assertive');
			$(document).trigger('wp-plugin-update-error', response);
		};
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
