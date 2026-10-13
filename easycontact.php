<?php
/**
 * Plugin Name: EasyContact
 * Description: Moduli con anti-spam locale, telefoni internazionali, campi condizionali, MailerLite, Slack e Turnstile.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Update URI: false
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: easycontact
 * Domain Path: /languages
 *
 * @package EasyContact
 */

defined( 'ABSPATH' ) || exit;

define( 'EASYCONTACT_VERSION', '1.0.0' );
define( 'EASYCONTACT_FILE', __FILE__ );
define( 'EASYCONTACT_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASYCONTACT_URL', plugin_dir_url( __FILE__ ) );

require_once EASYCONTACT_DIR . 'includes/class-schema.php';
require_once EASYCONTACT_DIR . 'includes/class-phone.php';
require_once EASYCONTACT_DIR . 'includes/class-antispam.php';
require_once EASYCONTACT_DIR . 'includes/class-disposable-domains.php';
require_once EASYCONTACT_DIR . 'includes/class-review.php';
require_once EASYCONTACT_DIR . 'includes/class-plugin.php';
require_once EASYCONTACT_DIR . 'includes/class-delivery.php';
require_once EASYCONTACT_DIR . 'includes/class-state.php';
require_once EASYCONTACT_DIR . 'includes/class-submission.php';
require_once EASYCONTACT_DIR . 'includes/class-frontend.php';
require_once EASYCONTACT_DIR . 'includes/class-privacy.php';
if ( is_admin() ) {
	require_once EASYCONTACT_DIR . 'includes/class-admin.php';
}

register_activation_hook( __FILE__, array( 'EasyContact\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EasyContact\\Plugin', 'deactivate' ) );
EasyContact\Plugin::boot();
EasyContact\Phone::boot();
EasyContact\Review::boot();
