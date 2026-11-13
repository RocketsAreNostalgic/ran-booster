<?php

declare(strict_types=1);

namespace RAN\Admin\Feedback;

/*
 * Focused WordPress shims for FeedbackFlash. State lives in
 * $GLOBALS['ran_booster_feedback_test']: user, blog, caps, meta[user][key], frozen[user][key].
 */

/** @return array<string, mixed> */
function ran_booster_feedback_state(): array {
	return is_array( $GLOBALS['ran_booster_feedback_test'] ?? null ) ? $GLOBALS['ran_booster_feedback_test'] : array();
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return is_string( ran_booster_feedback_state()['translate'] ?? null ) ? ran_booster_feedback_state()['translate'] : $text;
}

function esc_html( mixed $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( mixed $value ): string {
	return esc_html( $value );
}

function get_current_user_id(): int {
	return (int) ( ran_booster_feedback_state()['user'] ?? 1 );
}

function get_current_blog_id(): int {
	return (int) ( ran_booster_feedback_state()['blog'] ?? 1 );
}

function current_user_can( string $capability ): bool {
	return in_array( $capability, ran_booster_feedback_state()['caps'] ?? array( 'manage_options' ), true );
}

function update_user_meta( int $user_id, string $key, mixed $value ): int {
	$GLOBALS['ran_booster_feedback_test']['meta'][ $user_id ][ $key ] = $value;

	return 1;
}

function get_user_meta( int $user_id, string $key, bool $single ): mixed {
	unset( $single );
	$state = ran_booster_feedback_state();

	// A frozen read models a second tab that read the row before the first tab deleted it.
	return $state['frozen'][ $user_id ][ $key ] ?? $state['meta'][ $user_id ][ $key ] ?? '';
}

function delete_user_meta( int $user_id, string $key, mixed $value = '' ): bool {
	$state = ran_booster_feedback_state();
	if ( ! array_key_exists( $key, $state['meta'][ $user_id ] ?? array() )
		|| ( '' !== $value && $state['meta'][ $user_id ][ $key ] !== $value ) ) {
		return false;
	}
	unset( $GLOBALS['ran_booster_feedback_test']['meta'][ $user_id ][ $key ] );

	return true;
}
