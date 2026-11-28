(function ($, window, document) {
	'use strict';

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
})(jQuery, window, document);
