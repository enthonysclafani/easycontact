<?php
/**
 * PSR-4 autoloader for the EasyFormly namespace, without Composer.
 *
 * The bundled phone library has its own isolated namespace and loader (Phone::boot()).
 *
 * @package EasyFormly
 */

namespace EasyFormly;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) || str_starts_with( $class_name, $prefix . 'Vendor\\' ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
