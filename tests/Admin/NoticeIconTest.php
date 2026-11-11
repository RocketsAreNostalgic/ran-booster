<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\NoticeIcon;

final class NoticeIconTest extends TestCase {

	public function test_each_intent_draws_one_decorative_notice_icon(): void {
		foreach ( array(
			'info'    => 1,
			'warning' => 1,
			'success' => 2,
			'error'   => 1,
		) as $intent => $paths ) {
			$svg = NoticeIcon::markup( $intent );
			self::assertStringStartsWith( '<svg class="ran-wp-ui-notice__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">', $svg, $intent );
			self::assertStringEndsWith( '</svg>', $svg );
			self::assertSame( $paths, substr_count( $svg, '<path d="' ), $intent );
			self::assertStringNotContainsString( 'role=', $svg );
			self::assertStringNotContainsString( '<title', $svg );
		}
		self::assertStringContainsString( '<path d="M9 12L11 14L15 10"></path>', NoticeIcon::markup( 'success' ) );
		self::assertStringNotContainsString( 'stroke-linejoin', NoticeIcon::markup( 'error' ) );
		self::assertNotSame( NoticeIcon::markup( 'warning' ), NoticeIcon::markup( 'error' ) );
	}

	public function test_neutral_and_unknown_intents_have_no_icon(): void {
		foreach ( array( 'neutral', '', 'high', 'Error', '"><script>' ) as $intent ) {
			self::assertSame( '', NoticeIcon::markup( $intent ) );
		}
	}

	public function test_print_intent_prints_the_same_markup(): void {
		ob_start();
		NoticeIcon::print_intent( 'error' );
		self::assertSame( NoticeIcon::markup( 'error' ), ob_get_clean() );
	}

	public function test_print_template_prints_one_keyed_icon_per_intent(): void {
		ob_start();
		NoticeIcon::print_template();
		$template = (string) ob_get_clean();
		if ( 1 !== preg_match( '#^<template id="ran-booster-notice-icons">(.*)</template>$#s', $template, $m ) ) {
			self::fail( 'icon template' );
		}
		$icons = $m[1];
		self::assertSame( 4, substr_count( $icons, '<svg ' ) );
		foreach ( array( 'info', 'warning', 'success', 'error' ) as $intent ) {
			// Dropping the key yields exactly the static icon.
			self::assertStringContainsString( str_replace( '<svg ', '<svg data-ran-booster-notice-icon="' . $intent . '" ', NoticeIcon::markup( $intent ) ), $icons, $intent );
		}
		self::assertStringNotContainsString( 'neutral', $icons );
		self::assertSame( 'ran-booster-notice-icons', NoticeIcon::TEMPLATE_ID );
	}
}
