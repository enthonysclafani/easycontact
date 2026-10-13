<?php
defined( 'ABSPATH' ) || exit;

final class Hooks {
	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_filter(
			'the_title',
			static function ( $title ) {
				add_filter( 'the_content', 'trim' );
				return "{$title}";
			}
		);
	}
}
