/* global tb_show */

(function ($) {
	'use strict';

	$(function () {
		let opener = null;

		$('.wrap').on(
			'submit',
			'.ran-booster-extension-action',
			function (event) {
				const form = $(this);
				if (form.data('pending')) {
					event.preventDefault();
					return;
				}
				form.data('pending', true);
				const button = form.find('button[type="submit"]');
				button
					.prop('disabled', true)
					.attr('aria-label', button.data('pending'))
					.text(button.data('pending'));
				form.attr({
					'aria-busy': 'true',
					role: 'status',
					'aria-live': 'polite',
				});
			}
		);

		$('.wrap').on(
			'click',
			'.ran-booster-extension-details-link',
			function (event) {
				event.preventDefault();
				event.stopPropagation();
				opener = this;

				tb_show('', $(this).attr('href'), false);
				$('#TB_window')
					.attr({
						role: 'dialog',
						'aria-label': $(this).attr('aria-label'),
					})
					.addClass(
						'plugin-details-modal ran-booster-extension-modal'
					)
					.removeClass('thickbox-loading');
				$('#TB_closeWindowButton').trigger('focus');
			}
		);

		$(document.body).on(
			'thickbox:removed.ranBoosterExtensions',
			function () {
				if (opener) {
					opener.focus();
					opener = null;
				}
			}
		);
	});
})(jQuery);
