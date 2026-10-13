<?php
global $wpdb;
wp_schedule_event( time(), 'daily', 'easycontact_daily_cleanup' );
$sql = 'CREATE TABLE wp_easycontact_entries ( id INT )';
echo '<p style="color: red">x</p>';
add_action( 'wp_enqueue_scripts', 'easycontact_fixture_assets' );
