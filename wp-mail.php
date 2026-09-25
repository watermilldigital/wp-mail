<?php
/**
 * Plugin Name: WP Mail
 * Author: WaterMill Digital
 * Author URI: https://watermilldigital.com
 * Version: 1.0.1
 * Description: Sends WordPress email through SMTP, logs every email (Tools → Email log), and warns in wp-admin when email isn't set up or is failing.
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * Settings are wp-config.php constants, e.g.:
 *     define( 'WP_MAIL_SMTP_HOST', 'smtp.postmarkapp.com' );
 * See watermill_mail_setting() for every setting and its default.
 *
 * Functions use a watermill_mail_ prefix, not wp_mail_: core WordPress owns that one (wp_mail(), wp_mail_from, …).
 */

/**
 * A setting from its WP_MAIL_* constant, or the default.
 *
 * @param string $name Setting name without the WP_MAIL_ prefix.
 * @return mixed
 */
function watermill_mail_setting( string $name ) {
	$defaults = array(
		'SMTP_HOST'   => '',    // Empty: no SMTP, WordPress sends with PHP mail() and wp-admin warns (production only).
		'SMTP_PORT'   => 587,
		'SMTP_USER'   => '',    // Empty: no authentication.
		'SMTP_PASS'   => '',
		'SMTP_SECURE' => 'tls', // tls: STARTTLS, usually port 587. ssl: usually port 465. Empty: no encryption.
		'FROM_EMAIL'  => '',    // Empty: WordPress's default (wordpress at your domain). Use an address on a domain the provider sends for.
		'FROM_NAME'   => '',    // Empty: WordPress's default (WP Base sets it to the site name).
		'LOG_DAYS'    => 90,    // Log entries older than this are deleted daily. They hold email bodies, so personal data.
	);

	return defined( "WP_MAIL_$name" ) ? constant( "WP_MAIL_$name" ) : $defaults[ $name ];
}

/**
 * Whether email goes out through SMTP.
 */
function watermill_mail_configured(): bool {
	return '' !== (string) watermill_mail_setting( 'SMTP_HOST' );
}

require_once __DIR__ . '/src/smtp.php';
require_once __DIR__ . '/src/log.php';

if ( is_admin() ) {
	require_once __DIR__ . '/src/admin.php';
}
