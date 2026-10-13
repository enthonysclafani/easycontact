<?php
/** Run through `studio wp eval-file tests/integration.php` on a local test site. */
namespace EasyContact {
	/** Resolver fixture: no DNS queries leave this test process. */
	function dns_get_record( $domain, $type ) {
		return $GLOBALS['easycontact_test_dns'][ $domain ] ?? false;
	}
}

namespace {
	use EasyContact\AntiSpam;
	use EasyContact\Frontend;
	use EasyContact\Phone;
	use EasyContact\Plugin;
	use EasyContact\Privacy;
	use EasyContact\Review;
	use EasyContact\Schema;
	use EasyContact\State;
	use EasyContact\Submission;

	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( Plugin::class ) ) {
		exit( "Use the active plugin in a local WordPress CLI process.\n" );
	}
	require_once EASYCONTACT_DIR . 'includes/class-admin.php';

	final class EASYCONTACT_AntiSpam_Test {
		public static int $checks = 0;
		public static array $forms = array();
		public static array $entries = array();
		public static array $calls = array( 'mail' => 0, 'slack' => 0, 'mailerlite' => 0 );
		public static int $slack_code = 200;

		public static function check( bool $condition, string $label ): void {
			++self::$checks;
			if ( ! $condition ) {
				throw new RuntimeException( $label );
			}
		}

		public static function definition( array $config = array(), string $template = '' ): array {
			$template = $template ?: '[text* nome "Nome"]' . "\n" . '[email* email "Email"]' . "\n" . '[tel telefono "Telefono"]' . "\n" . '[textarea* messaggio "Messaggio"]' . "\n" . '[checkbox newsletter "Newsletter"]';
			$schema = Schema::parse( $template );
			self::check( ! is_wp_error( $schema ), 'Fixture schema parses' );
			return array( 'template' => $template, 'schema' => $schema, 'conditions' => Schema::rules( $schema['fields'], array() ), 'config' => array_merge( Plugin::config_defaults(), array( 'antispam_level' => '2', 'antispam_observe' => 'no', 'email_dns_check' => 'no', 'store_entries' => 'yes', 'mail_to' => 'receiver@example.org', 'phone_country' => 'IT' ), $config ) );
		}

		public static function form( array $definition ): int {
			$id = wp_insert_post( array( 'post_type' => Plugin::FORM, 'post_status' => 'publish', 'post_title' => 'EasyContact integration fixture' ), true );
			self::check( ! is_wp_error( $id ), 'Fixture form created' );
			self::$forms[] = $id;
			update_post_meta( $id, '_easycontact_definition', wp_slash( $definition ) );
			return $id;
		}

		public static function values( array $changes = array() ): array {
			return array_merge( array( 'nome' => 'Maria Rossi', 'email' => 'Maria+preventivo@example.org', 'telefono' => '+390236618300', 'messaggio' => 'Vorrei conoscere la disponibilità del servizio per il prossimo mese.', 'newsletter' => '' ), $changes );
		}

		public static function input( int $id, array $values, string $trap = '' ): array {
			$token = Frontend::issue( $id );
			// Use a signed start time from a human-length interaction without sleeps.
			$uuid = wp_generate_uuid4();
			$issued = time() - 10;
			$clock = $issued . '.' . $uuid . '.' . hash_hmac( 'sha256', get_current_blog_id() . '|' . $id . '|' . $issued . '|' . $uuid . '|clock', wp_salt( 'auth' ) );
			return array( 'fields' => $values, 'countries' => array( 'telefono' => 'IT' ), 'nonce' => $token['nonce'], 'ticket' => $token['ticket'], 'clock' => $clock, 'honeypot' => array( $token['honeypot'] => $trap ) );
		}

		public static function entry( int $id ): int {
			$state = State::read( substr( hash( 'sha256', $id ? self::$last_ticket : '' ), 0, 40 ) );
			$entry = (int) ( $state['entry'] ?? 0 );
			self::check( $entry > 0, 'Submission has one archive entry' );
			self::$entries[] = $entry;
			return $entry;
		}

		public static string $last_ticket = '';
		public static function submit( int $id, array $input ) {
			self::$last_ticket = $input['ticket'];
			// Separate simulated visitors so rate tests can deliberately exercise their own bucket.
			$_SERVER['REMOTE_ADDR'] = '2001:db8::' . count( self::$entries ) . ':' . self::$checks;
			return Frontend::submit( $id, $input );
		}
	}

	$original_settings = get_option( Plugin::OPTION );
	$original_user = get_current_user_id();
	$original_address = $_SERVER['REMOTE_ADDR'] ?? null;
	$original_request = $_REQUEST;
	$original_locale = $GLOBALS['locale'] ?? null;
	global $wpdb;
	$option_names = static fn() => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'easycontact_lock_%' OR option_name LIKE 'easycontact_state_%' OR option_name LIKE '_transient_easycontact_%' OR option_name LIKE '_transient_timeout_easycontact_%'" );
	$original_names = $option_names();
	$budget_key = 'easycontact_rate_' . substr( hash_hmac( 'sha256', get_current_blog_id() . '|quarantine_budget|site', wp_salt( 'auth' ) ), 0, 40 );
	$original_budget = get_transient( $budget_key );
	$mail_filter = static function () { ++EASYCONTACT_AntiSpam_Test::$calls['mail']; return true; };
	$http_filter = static function ( $preempt, $args, $url ) {
		if ( str_contains( $url, 'hooks.slack.com' ) ) {
			++EASYCONTACT_AntiSpam_Test::$calls['slack'];
			return array( 'response' => array( 'code' => EASYCONTACT_AntiSpam_Test::$slack_code ), 'headers' => array(), 'body' => 'ok', 'cookies' => array() );
		}
		if ( str_contains( $url, 'connect.mailerlite.com' ) ) {
			++EASYCONTACT_AntiSpam_Test::$calls['mailerlite'];
			return array( 'response' => array( 'code' => 201 ), 'headers' => array(), 'body' => '{"data":{"id":"123"}}', 'cookies' => array() );
		}
		return new WP_Error( 'test_external', 'External HTTP disabled in this process.' );
	};
	add_filter( 'pre_wp_mail', $mail_filter );
	add_filter( 'pre_http_request', $http_filter, 10, 3 );
	$failure = null;
	try {
		$settings = array_merge( Plugin::defaults(), array( 'mailerlite_token' => 'fixture-only', 'quarantine_limit' => 500 ) );
		update_option( Plugin::OPTION, $settings );
		wp_set_current_user( 0 );
		$t = EASYCONTACT_AntiSpam_Test::class;

		$t::check( AntiSpam::email( 'Case+alias@EXAMPLE.ORG' ) === 'Case+alias@example.org', 'Email preserves local part and plus aliases' );
		foreach ( array( 'a..b@example.org', '.abc@example.org', 'abc.@example.org', 'a@localhost', "a@ example.org", "a\r\n@example.org", 'a@@example.org' ) as $invalid ) {
			$t::check( false === AntiSpam::email( $invalid ), 'Reject malformed email' );
		}
		$t::check( AntiSpam::email( 'djaisfauaus@gmail.com' ) === 'djaisfauaus@gmail.com', 'A random-looking valid alias is not treated as proof of fraud' );
		$t::check( AntiSpam::domain( 'EXAMPLE.ORG' ) === 'example.org', 'Domains canonicalized' );
		$t::check( AntiSpam::domain( 'https://example.org' ) === '', 'Reject URLs as domains' );
		$t::check( is_wp_error( AntiSpam::phrase_list( 'cia' ) ), 'Prevent overly generic short phrases' );
		$t::check( AntiSpam::phrase_list( 'PACCHETTI SPECIALI' ) === 'pacchetti speciali', 'Phrases canonicalized' );
		foreach ( array( array( 'IT', '02 3661 8300', '+390236618300' ), array( 'US', '(202) 555-0123', '+12025550123' ), array( 'GB', '020 7946 0018', '+442079460018' ), array( 'FR', '01 42 68 53 00', '+33142685300' ), array( 'AU', '02 9374 4000', '+61293744000' ), array( 'IT', '0044 20 7946 0018', '+442079460018' ), array( 'IT', '٠٢ ٣٦٦١ ٨٣٠٠', '+390236618300' ) ) as [ $country, $input, $expected ] ) {
			$t::check( Phone::normalize( $input, $country ) === $expected, 'International telephone normalization' );
		}
		foreach ( array( '123', 'call 02 3661 8300', '+39 02 3661 8300 ext 42', '++390236618300', '02 3661 8300<script>' ) as $invalid ) {
			$t::check( is_wp_error( Phone::normalize( $invalid, 'IT' ) ), 'Whole phone value validated' );
		}
		$t::check( is_wp_error( Phone::normalize( '02 3661 8300', 'IT', true ) ), 'Mobile-only option rejects a landline' );
		$t::check( Phone::repetitive( '+393333333333' ), 'Detect repetitive Italian mobile number' );
		$GLOBALS['locale'] = 'it_IT';
		$t::check( Phone::default_country() === 'IT', 'Country follows site locale' );
		$GLOBALS['locale'] = 'en_GB';
		$t::check( Phone::default_country() === 'GB', 'Territory respected for shared languages' );
		$t::check( Phone::default_country( array( 'phone_country' => 'FR' ), array( 'phone_country' => 'AU' ) ) === 'AU', 'Field country override has precedence' );
		$GLOBALS['locale'] = $original_locale;

		$definition = $t::definition();
		$id = $t::form( $definition );
		$implicit = $definition;
		unset( $implicit['config']['antispam_level'] );
		$implicit_id = $t::form( $implicit );
		$t::check( AntiSpam::level( Plugin::definition( $implicit_id )['config'] ) === 2, 'Forms without an explicit level inherit the site level' );
		$t::check( AntiSpam::level( Plugin::config_defaults() ) === 2, 'New forms inherit balanced protection' );
		$validation = Schema::validate( $definition, $t::values( array( 'telefono' => '3333333333' ) ), array( 'telefono' => 'IT' ) );
		$t::check( isset( $validation['errors']['telefono'] ), 'Repetitive phone has an actionable field error at level 2' );
		$base = $definition;
		$base['config']['antispam_level'] = '1';
		$t::check( ! Schema::validate( $base, $t::values( array( 'telefono' => '3333333333' ) ) )['errors'], 'Level 1 keeps plausible repetitive numbers' );
		$exception = $definition;
		$exception['schema']['fields']['telefono']['phone_allow_repeated'] = true;
		$t::check( ! Schema::validate( $exception, $t::values( array( 'telefono' => '3333333333' ) ) )['errors'], 'A field can explicitly permit repetitive numbers' );
		$conditional = $definition;
		$conditional['conditions'] = Schema::rules( $definition['schema']['fields'], array( array( 'target' => 'telefono', 'source' => 'nome', 'operator' => 'equals', 'value' => 'show phone' ) ) );
		$conditional_id = $t::form( $conditional );
		$conditional_input = $t::input( $conditional_id, $t::values( array( 'telefono' => 'forged junk' ) ) );
		$conditional_input['countries']['telefono'] = 'ZZ';
		$t::check( ! is_wp_error( $t::submit( $conditional_id, $conditional_input ) ), 'Hidden malformed phone/country are discarded' );
		$conditional_entry = $t::entry( $conditional_id );
		$t::check( ! isset( get_post_meta( $conditional_entry, '_easycontact_values', true )['telefono'] ), 'Hidden phone never reaches storage' );

		$GLOBALS['easycontact_test_dns'] = array( 'null.example.org' => array( array( 'type' => 'MX', 'target' => '.', 'pri' => 0 ) ), 'fallback.example.org' => array( array( 'type' => 'A', 'ip' => '192.0.2.1' ) ), 'absent.example.org' => array() );
		$t::check( AntiSpam::mail_domain( 'null.example.org' ) === 'invalid', 'Null MX is rejected' );
		$t::check( AntiSpam::mail_domain( 'fallback.example.org' ) === 'valid', 'Address record permits SMTP fallback' );
		$t::check( AntiSpam::mail_domain( 'absent.example.org' ) === 'invalid', 'Domain without mail/address records is rejected' );
		$t::check( AntiSpam::mail_domain( 'timeout.example.org' ) === 'unknown', 'Resolver failure remains inconclusive' );
		$dns = $definition;
		$dns['config']['email_dns_check'] = 'yes';
		$t::check( isset( Schema::validate( $dns, $t::values( array( 'email' => 'person@null.example.org' ) ) )['errors']['email'] ), 'Optional DNS produces a field error' );
		$t::check( ! Schema::validate( $dns, $t::values( array( 'email' => 'person@timeout.example.org' ) ) )['errors'], 'DNS outage does not lose valid contacts' );

		$promotion = 'Buy now: guaranteed rankings. https://example.org/a https://example.org/b';
		$t::check( AntiSpam::assess( $id, $base, $t::values( array( 'messaggio' => $promotion ) ) )['status'] === 'accepted', 'Base level applies fewer rules' );
		$t::check( AntiSpam::assess( $id, $definition, $t::values( array( 'messaggio' => $promotion ) ) )['status'] === 'suspect', 'Promotional content alone is quarantined as suspect' );
		$t::check( AntiSpam::assess( $id, $definition, $t::values( array( 'email' => 'person@mailinator.com' ) ) )['status'] === 'suspect', 'Disposable domain alone is suspect at balanced level' );
		$t::check( AntiSpam::assess( $id, $definition, $t::values( array( 'email' => 'person@mailinator.com', 'messaggio' => $promotion ) ) )['status'] === 'spam', 'Independent domain and content signals establish spam' );
		$allow = $definition;
		$allow['config']['allowed_email_domains'] = 'mailinator.com';
		$t::check( in_array( 'disposable', AntiSpam::assess( $id, $allow, $t::values( array( 'email' => 'person@mailinator.com' ) ) )['reasons'], true ), 'Retired manual exceptions do not override public lists' );
		$observe = $definition;
		$observe['config']['antispam_observe'] = 'yes';
		$observed_phone = Schema::validate( $observe, $t::values( array( 'telefono' => '3333333333' ) ) );
		$t::check( ! $observed_phone['errors'] && in_array( 'repetitive_phone', AntiSpam::assess( $id, $observe, $observed_phone['values'] )['reasons'], true ), 'Observation records repetitive phone without rejecting it' );
		$observed = AntiSpam::assess( $id, $observe, $t::values( array( 'email' => 'person@mailinator.com', 'messaggio' => $promotion ) ) );
		$t::check( $observed['status'] === 'accepted' && $observed['detected'] === 'spam', 'Observation preserves detection without holding delivery' );
		$block = $base;
		$block['config']['antispam_observe'] = 'yes';
		$block['config']['blocked_email_domains'] = 'example.org';
		$t::check( AntiSpam::assess( $id, $block, $t::values() )['status'] === 'accepted', 'Retired manual blocks no longer affect submissions' );
		$t::check( AntiSpam::assess( $id, $observe, $t::values(), array( 'honeypot' => true ) )['status'] === 'spam', 'Honeypot remains effective in observation mode' );
		$t::check( ! EasyContact\DisposableDomains::contains( 'easycontact-unrelated-mailinator.com' ) && EasyContact\DisposableDomains::contains( 'inbox.mailinator.com' ), 'Public lists match parent domains without unrelated suffixes' );
		$strict = $definition;
		$strict['config']['antispam_level'] = '3';
		$strict_id = $t::form( $strict );
		$t::check( AntiSpam::assess( $strict_id, $strict, $t::values( array( 'email' => 'person@mailinator.com' ) ), array( 'elapsed' => 0 ) )['status'] === 'spam', 'Strict level combines weak timing and domain signals sooner' );
		$duplicate_id = $t::form( $definition );
		for ( $n = 0; $n < 4; ++$n ) {
			$duplicate = AntiSpam::assess( $duplicate_id, $definition, $t::values( array( 'email' => 'visitor' . $n . '@example.org', 'telefono' => '', 'messaggio' => 'This identical campaign requests an unrelated appointment each day.' ) ) );
		}
		$t::check( in_array( 'duplicate', $duplicate['reasons'], true ) && $duplicate['status'] === 'suspect', 'Similar content is counted across independent tickets' );
		for ( $n = 0; $n < 5; ++$n ) {
			$counter = State::count( 'integration_cap', 'fixture', 300, 2 );
		}
		$t::check( $counter === 2, 'Saturated counters remain enforced' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.41';
		$t::check( State::rate_allowed( $id, 1, 'test_rate' ) && ! State::rate_allowed( $id, 1, 'test_rate' ) && ! State::rate_allowed( $id, 1, 'test_rate' ), 'IP rate stays blocked after reaching its cap' );

		$delivery = $t::definition( array( 'slack_enabled' => true, 'slack_webhook' => 'https://hooks.slack.com/services/TEST/TEST/TEST', 'slack_fields' => 'nome,email,messaggio', 'mailerlite_enabled' => true, 'mailerlite_groups' => '1' ) );
		$delivery_id = $t::form( $delivery );
		$input = $t::input( $delivery_id, $t::values( array( 'newsletter' => '1' ) ) );
		$before = $t::$calls;
		$result = $t::submit( $delivery_id, $input );
		$t::check( ! is_wp_error( $result ) && empty( $result['held'] ), 'Clean submission is accepted' );
		$clean_entry = $t::entry( $delivery_id );
		$t::check( Review::category( $clean_entry ) === 'accepted' && Review::can_deliver( $clean_entry ), 'Clean archive has one accepted category' );
		foreach ( array( 'mail', 'mailerlite', 'slack' ) as $adapter ) {
			$t::check( $t::$calls[ $adapter ] === $before[ $adapter ] + 1, 'Clean destination receives once' );
		}
		$after = $t::$calls;
		$t::check( $t::submit( $delivery_id, $input ) === $result && $t::$calls === $after, 'Receipt retry never redelivers' );
		$changed = $input;
		$changed['fields']['nome'] = 'Changed person';
		$t::check( $t::submit( $delivery_id, $changed )->get_error_code() === 'used', 'One ticket cannot be reused for another payload' );
		$state = State::read( substr( hash( 'sha256', $input['ticket'] ), 0, 40 ) );
		$t::check( ! str_contains( wp_json_encode( $state ), 'Maria+preventivo' ) && ! isset( $state['values'] ), 'Receipts contain no raw contact fields' );
		$bad_token = $t::input( $delivery_id, $t::values() );
		$bad_token['clock'] .= '0';
		$t::check( $t::submit( $delivery_id, $bad_token )->get_error_code() === 'invalid', 'Tampered start clock is rejected' );
		$missing_trap = $t::input( $delivery_id, $t::values() );
		unset( $missing_trap['honeypot'] );
		$t::check( $t::submit( $delivery_id, $missing_trap )->get_error_code() === 'invalid', 'Missing rotating honeypot is rejected' );
		$token = Frontend::issue( $delivery_id, $input['clock'] );
		$t::check( $token['clock'] === $input['clock'] && ! isset( $input['honeypot'][ $token['honeypot'] ] ), 'Refresh preserves interaction time and rotates trap' );

		$bad_input = $t::input( $delivery_id, $t::values( array( 'newsletter' => '1' ) ), 'filled by bot' );
		$before = $t::$calls;
		$held = $t::submit( $delivery_id, $bad_input );
		$t::check( ! is_wp_error( $held ) && ! empty( $held['held'] ) && $t::$calls === $before, 'Spam is saved with every outbound destination held' );
		$held_entry = $t::entry( $delivery_id );
		$t::check( Review::category( $held_entry ) === 'spam' && Review::held( $held_entry ), 'Honeypot categorizes spam in private taxonomy' );
		$t::check( ! wp_next_scheduled( 'easycontact_retry_delivery', array( $held_entry ) ), 'Spam has no scheduled delivery' );
		$t::check( is_wp_error( Submission::retry( $held_entry ) ) && $t::$calls === $before, 'Cron cannot release spam' );
		wp_set_current_user( 1 );
		$t::check( Review::change( $held_entry, 'accepted' ) === true && Review::held( $held_entry ) && $t::$calls === $before, 'Manual category change preserves delivery hold' );
		$t::check( Review::write( $held_entry, 'spam', 'automatic', true ) && Review::category( $held_entry ) === 'accepted', 'Manual classification has precedence' );
		$t::check( is_wp_error( Submission::retry( $held_entry, true ) ) && $t::$calls === $before, 'Generic retry cannot approve held entry' );
		$block_job = static function ( $check, $post_id, $key ) use ( $held_entry ) { return $post_id === $held_entry && '_easycontact_retry' === $key ? false : $check; };
		add_filter( 'update_post_metadata', $block_job, 10, 3 );
		$failed_approval = Submission::retry( $held_entry, true, false, true );
		remove_filter( 'update_post_metadata', $block_job, 10 );
		$t::check( is_wp_error( $failed_approval ) && Review::held( $held_entry ) && $t::$calls === $before, 'Approval preparation failure keeps the hold and performs no delivery' );
		$lease = State::claim( 'retry_' . $held_entry );
		$t::check( is_wp_error( Review::change( $held_entry, 'spam' ) ), 'Classification shares delivery ownership' );
		State::release( 'retry_' . $held_entry, $lease );
		$t::check( Submission::retry( $held_entry, true, false, true ) === true && ! Review::held( $held_entry ), 'Explicit approval releases quarantined entry' );
		foreach ( array( 'mail', 'mailerlite', 'slack' ) as $adapter ) {
			$t::check( $t::$calls[ $adapter ] === $before[ $adapter ] + 1, 'Approval sends each held destination once' );
		}
		$after = $t::$calls;
		$t::check( is_wp_error( Submission::retry( $held_entry, true, false, true ) ) && $t::$calls === $after, 'A second approval cannot duplicate delivery' );
		$t::check( Review::change( $held_entry, 'spam' ) === true && Review::change( $held_entry, 'accepted' ) === true, 'Categories can be manually corrected' );
		$t::check( count( wp_get_object_terms( $held_entry, Review::TAXONOMY ) ) === 1, 'Taxonomy remains mutually exclusive' );
		$t::check( Submission::retry( $held_entry, true, false, true ) === true && $t::$calls === $after, 'Reapproval skips previously sent destinations' );
		$interrupted = Plugin::store( $delivery_id, $delivery, $t::values( array( 'newsletter' => '1' ) ), array( 'status' => 'accepted', 'detected' => 'accepted', 'score' => 0, 'level' => 2, 'observe' => false, 'reasons' => array() ) );
		$t::$entries[] = $interrupted;
		update_post_meta( $interrupted, '_easycontact_delivery', array( 'key' => 'fixture-interrupted', 'outcomes' => array( 'archive' => 'saved', 'mail' => 'sent', 'mailerlite' => 'sent', 'slack' => 'held' ) ) );
		$t::check( ! Review::held( $interrupted ) && Review::requires_approval( $interrupted ), 'Interrupted approval remains recoverable in the interface' );
		$before = $t::$calls;
		$t::check( Submission::retry( $interrupted, true, false, true ) === true && $t::$calls['slack'] === $before['slack'] + 1 && $t::$calls['mail'] === $before['mail'] && $t::$calls['mailerlite'] === $before['mailerlite'], 'Interrupted approval skips every previously sent destination' );
		update_post_meta( $interrupted, '_easycontact_initializing', '1' );
		$t::check( ! Review::can_deliver( $interrupted ) && is_wp_error( Review::change( $interrupted, 'spam' ) ), 'Partially initialized archive entries cannot be reviewed or delivered' );
		delete_post_meta( $interrupted, '_easycontact_initializing' );
		$legacy_id = $t::form( $base );
		$legacy_time = time();
		$legacy_uuid = wp_generate_uuid4();
		$legacy_ticket = $legacy_time . '.' . $legacy_uuid . '.' . hash_hmac( 'sha256', get_current_blog_id() . '|' . $legacy_id . '|' . $legacy_time . '|' . $legacy_uuid, wp_salt( 'auth' ) );
		$legacy_input = array( 'fields' => $t::values(), 'ticket' => $legacy_ticket, 'nonce' => wp_create_nonce( 'easycontact_submit_' . get_current_blog_id() . '_' . $legacy_id ), 'website' => '' );
		$t::check( 'expired' === $t::submit( $legacy_id, $legacy_input )->get_error_code(), 'Tickets without the current signature format are refused' );

		$no_archive = $t::definition( array( 'store_entries' => 'no', 'quarantine_entries' => 'no' ) );
		$no_archive_id = $t::form( $no_archive );
		$before = $t::$calls;
		$t::check( $t::submit( $no_archive_id, $t::input( $no_archive_id, $t::values(), 'bot' ) )->get_error_code() === 'spam' && $t::$calls === $before, 'Spam is rejected without explicit local-storage consent' );
		$t::check( ! get_posts( array( 'post_type' => Plugin::ENTRY, 'post_status' => 'private', 'meta_key' => '_easycontact_form_id', 'meta_value' => $no_archive_id ) ), 'No quarantined entry is created when storage is off' );
		$quarantine = $no_archive;
		$quarantine['config']['quarantine_entries'] = 'yes';
		$quarantine_id = $t::form( $quarantine );
		$t::check( ! is_wp_error( $t::submit( $quarantine_id, $t::input( $quarantine_id, $t::values( array( 'messaggio' => $promotion ) ) ) ) ), 'Explicit quarantine works independently of normal archive' );
		$quarantine_entry = $t::entry( $quarantine_id );
		$t::check( Review::category( $quarantine_entry ) === 'suspect', 'Probable spam is a distinct category' );
		$settings['quarantine_limit'] = 1;
		update_option( Plugin::OPTION, $settings );
		delete_transient( $budget_key );
		$t::check( ! is_wp_error( $t::submit( $quarantine_id, $t::input( $quarantine_id, $t::values(), 'bot' ) ) ), 'Quarantine accepts within budget' );
		$budget_entry = $t::entry( $quarantine_id );
		$t::check( $t::submit( $quarantine_id, $t::input( $quarantine_id, $t::values(), 'bot' ) )->get_error_code() === 'spam', 'Quarantine daily budget is bounded' );
		$settings['quarantine_limit'] = 500;
		update_option( Plugin::OPTION, $settings );
		delete_transient( $budget_key );

		$retry_definition = $t::definition( array( 'slack_enabled' => true, 'slack_webhook' => 'https://hooks.slack.com/services/TEST/TEST/TEST' ) );
		$retry_id = $t::form( $retry_definition );
		$t::$slack_code = 429;
		$t::submit( $retry_id, $t::input( $retry_id, $t::values() ) );
		$retry_entry = $t::entry( $retry_id );
		$t::check( (bool) wp_next_scheduled( 'easycontact_retry_delivery', array( $retry_entry ) ), 'Recoverable clean failure gets bounded cron retry' );
		$t::$slack_code = 200;
		$before = $t::$calls;
		$t::check( Submission::retry( $retry_entry ) === true && $t::$calls['mail'] === $before['mail'] && $t::$calls['slack'] === $before['slack'] + 1, 'Recovery sends only the failed destination' );
		$t::check( Review::change( $retry_entry, 'spam' ) === true && ! wp_next_scheduled( 'easycontact_retry_delivery', array( $retry_entry ) ), 'Manual spam classification cancels recovery' );

		$export = Privacy::export( 'Maria+preventivo@example.org' );
		$export_json = wp_json_encode( $export );
		$t::check( str_contains( $export_json, 'Classificazione anti-spam' ) && str_contains( $export_json, 'Punteggio anti-spam' ), 'Privacy export includes category and assessment' );
		$tax = get_taxonomy( Review::TAXONOMY );
		$t::check( ! post_type_supports( Plugin::ENTRY, 'editor' ) && ! post_type_supports( Plugin::ENTRY, 'title' ), 'Archived contact details have review controls without a content editor' );
		$t::check( ! $tax->public && ! $tax->show_in_rest && ! $tax->show_ui && $tax->cap->assign_terms === 'manage_options', 'Taxonomy is private and administrator-only' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
		Review::bulk( admin_url( 'edit.php' ), 'easycontact-classify-suspect', array( $clean_entry, $quarantine_entry ) );
		$t::check( Review::category( $clean_entry ) === 'suspect' && Review::category( $quarantine_entry ) === 'suspect', 'Bulk classification handles multiple entries' );
		$settings['spam_retention_days'] = 14;
		$settings['retention_days'] = 30;
		update_option( Plugin::OPTION, $settings );
		$retention = array();
		foreach ( array( array( 'spam', 15, false ), array( 'suspect', 15, false ), array( 'accepted', 15, true ), array( 'accepted', 31, false ), array( 'spam', 5, true ) ) as [ $category, $age, $keep ] ) {
			$entry = Plugin::store( $id, $definition, $t::values(), array( 'status' => $category, 'detected' => $category, 'score' => 0, 'level' => 2, 'observe' => false, 'reasons' => array() ) );
			$t::$entries[] = $entry;
			wp_update_post( array( 'ID' => $entry, 'post_date' => gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS ) ) );
			$retention[ $entry ] = $keep;
		}
		$restrict_cleanup = static function ( $query ) use ( $retention ) { if ( Plugin::ENTRY === $query->get( 'post_type' ) ) { $query->set( 'post__in', array_keys( $retention ) ); } };
		add_action( 'pre_get_posts', $restrict_cleanup );
		Plugin::cleanup();
		remove_action( 'pre_get_posts', $restrict_cleanup );
		foreach ( $retention as $entry => $keep ) {
			$t::check( (bool) get_post( $entry ) === $keep, 'Retention honors category-specific duration' );
		}
		$erase_values = $t::values( array( 'email' => 'erase-fixture@example.org' ) );
		$erase_entry = Plugin::store( $id, $definition, $erase_values );
		$t::$entries[] = $erase_entry;
		wp_schedule_single_event( time() + 60, 'easycontact_retry_delivery', array( $erase_entry ) );
		$t::check( Privacy::erase( 'erase-fixture@example.org' )['items_removed'] && ! get_post( $erase_entry ) && ! wp_next_scheduled( 'easycontact_retry_delivery', array( $erase_entry ) ), 'Privacy erasure also cancels pending work' );
		$invalid_config = EasyContact\Admin::config( array( 'antispam_level' => '99' ), $definition['config'], $definition['schema']['fields'] );
		$t::check( is_wp_error( $invalid_config ), 'Reject invalid per-form protection level' );
		$clean_settings = EasyContact\Admin::sanitize_settings( array( 'antispam_present' => '1', 'antispam_level' => '99', 'spam_retention_days' => '0', 'quarantine_limit' => '100000', 'blocked_email_domains' => 'https://invalid.example' ) );
		$t::check( $clean_settings['antispam_level'] === 2 && $clean_settings['spam_retention_days'] === 14 && $clean_settings['quarantine_limit'] === 500 && ! isset( $clean_settings['blocked_email_domains'] ) && ! isset( $clean_settings['allowed_email_domains'] ), 'Invalid settings preserve validated choices and retired manual lists are discarded' );
		$html = Frontend::render( $id, $definition );
		$t::check( str_contains( $html, 'data-easycontact-country' ) && str_contains( $html, 'name="countries[telefono]"' ) && str_contains( $html, 'data-easycontact-trap' ), 'Native markup includes international phone and honeypot controls' );
	} catch ( Throwable $error ) {
		$failure = $error->getMessage();
	} finally {
		remove_filter( 'pre_wp_mail', $mail_filter );
		remove_filter( 'pre_http_request', $http_filter, 10 );
		foreach ( array_unique( EASYCONTACT_AntiSpam_Test::$entries ) as $entry ) {
			wp_delete_post( $entry, true );
		}
		foreach ( EASYCONTACT_AntiSpam_Test::$forms as $form ) {
			// Also remove fixtures created before an assertion could register their entry ID.
			foreach ( get_posts( array( 'post_type' => Plugin::ENTRY, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_easycontact_form_id', 'meta_value' => $form, 'fields' => 'ids' ) ) as $entry ) {
				wp_delete_post( $entry, true );
			}
			wp_delete_post( $form, true );
		}
		update_option( Plugin::OPTION, $original_settings );
		foreach ( array_diff( $option_names(), $original_names ) as $name ) {
			if ( str_starts_with( $name, '_transient_timeout_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_timeout_' ) ) );
			} elseif ( str_starts_with( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $name );
			}
		}
		if ( false !== $original_budget ) {
			set_transient( $budget_key, $original_budget, max( 1, $original_budget['expires'] - time() ) );
		} else {
			delete_transient( $budget_key );
		}
		wp_set_current_user( $original_user );
		$_SERVER['REMOTE_ADDR'] = $original_address;
		$_REQUEST = $original_request;
		$GLOBALS['locale'] = $original_locale;
	}
	if ( null !== $failure ) {
		WP_CLI::error( 'Check ' . EASYCONTACT_AntiSpam_Test::$checks . ': ' . $failure );
	}
	WP_CLI::success( EASYCONTACT_AntiSpam_Test::$checks . ' integration checks passed; mail and HTTP were intercepted, fixtures removed.' );
}
