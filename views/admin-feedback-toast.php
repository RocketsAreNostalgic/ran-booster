<?php

defined( 'ABSPATH' ) || exit;

// One static, Core-owned host for explicit enhanced admin mutations. Responses
// never replace this element: the runtime writes its message while hidden, then
// animates only after its final dimensions have been resolved.
?>
<div id="ran-booster-admin-feedback-toast" class="notice-success ran-booster-feedback-toast" data-ran-booster-feedback-toast data-ran-booster-feedback-timeout="6000" hidden>
	<p data-ran-booster-feedback-toast-message></p>
</div>
