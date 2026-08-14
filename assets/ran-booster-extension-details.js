/* global tb_show */

(function ($) {
	'use strict';

	$(function () {
		let opener = null;

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
					.addClass('plugin-details-modal')
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
