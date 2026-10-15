<?php
/**
 * Tests for tools/check-architecture.php on fixtures with allowed and forbidden code.
 *
 * @package EasyFormly
 */

namespace EasyFormly\Tests;

use EasyFormly\Tools\ArchitectureChecker;
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
				'easyformly.php:1 direct-access-guard',
				'easyformly.php:2 no-direct-db',
				'easyformly.php:3 cron',
				'easyformly.php:4 no-custom-tables',
				'easyformly.php:5 no-inline-css',
				'easyformly.php:6 assets',
				'src/Extra.php:4 forbidden-php',
				'src/Extra.php:5 no-disk-writes',
				'src/Extra.php:6 http',
				'src/Extra.php:7 assets',
				'src/Extra.php:8 forbidden-php',
				'src/Extra.php:9 hooks-in-register',
				'uninstall.php:1 direct-access-guard',
				'composer.json:1 no-runtime-deps',
				'assets/extra.css:1 one-stylesheet',
				'assets/admin.css:5 one-stylesheet',
			),
			$found
		);
	}
}
