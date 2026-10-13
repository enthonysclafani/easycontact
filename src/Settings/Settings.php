<?php
/**
 * Site settings: one non-autoloaded option with conservative defaults.
 *
 * @package EasyContact
 */

namespace EasyContact\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The `easycontact_settings` option, read with defaults for missing keys.
 */
final class Settings {
	public const OPTION = 'easycontact_settings';

	/**
	 * Return conservative defaults for site settings.
	 *
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function defaults(): array {
		return array(
			'store_entries'       => false,
			'retention_days'      => 30,
			'spam_retention_days' => 14,
			'antispam_level'      => 2,
			'antispam_observe'    => false,
			'quarantine_entries'  => false,
			'quarantine_limit'    => 500,
			'email_dns_check'     => false,
			'phone_country'       => '',
			'spam_phrases'        => '',
			'allow_erasure'       => true,
			'delete_on_uninstall' => false,
			'mailerlite_token'    => '',
			'turnstile_site_key'  => '',
			'turnstile_secret'    => '',
		);
	}

	/**
	 * Read site settings with defaults.
	 *
	 * @return array<string, mixed> Result of this operation.
	 */
	public static function get(): array {
		$settings = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $settings ) ? $settings : array() );
	}
}
