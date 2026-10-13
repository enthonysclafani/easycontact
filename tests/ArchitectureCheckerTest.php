<?php
/**
 * Tests for tools/check-architecture.php on fixtures with allowed and forbidden code.
 *
 * @package EasyContact
 */

namespace EasyContact\Tests;

use EasyContact\Tools\ArchitectureChecker;
use PHPUnit\Framework\TestCase as Base;

require_once dirname( __DIR__ ) . '/tools/check-architecture.php';

/**
 * The checker finds every forbidden construct and nothing else.
 */
class ArchitectureCheckerTest extends Base {

	public function test_the_plugin_respects_the_invariants(): void {
		$this->assertSame( array(), ( new ArchitectureChecker( dirname( __DIR__ ) ) )->check() );
	}

	public function test_allowed_code_passes(): void {
		$this->assertSame( array(), ( new ArchitectureChecker( __DIR__ . '/fixtures/architecture/clean' ) )->check() );
	}

	public function test_forbidden_code_is_reported(): void {
		$found = array_map(
			static fn( array $violation ): string => $violation['file'] . ':' . $violation['line'] . ' ' . $violation['rule'],
			( new ArchitectureChecker( __DIR__ . '/fixtures/architecture/violations' ) )->check()
		);

		$this->assertSame(
			array(
				'easycontact.php:1 direct-access-guard',
				'easycontact.php:2 no-direct-db',
				'easycontact.php:3 cron',
				'easycontact.php:4 no-custom-tables',
				'easycontact.php:5 no-inline-css',
				'easycontact.php:6 assets',
				'includes/class-extra.php:4 forbidden-php',
				'includes/class-extra.php:5 no-disk-writes',
				'includes/class-extra.php:6 http',
				'includes/class-extra.php:7 assets',
				'includes/class-extra.php:8 forbidden-php',
				'uninstall.php:1 direct-access-guard',
				'composer.json:1 no-runtime-deps',
			),
			$found
		);
	}
}
