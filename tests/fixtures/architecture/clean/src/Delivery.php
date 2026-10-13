<?php
defined( 'ABSPATH' ) || exit;

wp_safe_remote_post( 'https://example.org', array( 'timeout' => 8 ) );
