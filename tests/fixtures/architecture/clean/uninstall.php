<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
wp_unschedule_hook( 'easycontact_daily_cleanup' );
