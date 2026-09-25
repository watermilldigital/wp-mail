<?php
/**
 * SMTP: when WP_MAIL_SMTP_HOST is set, every wp_mail() goes through it. Otherwise WordPress is left alone.
 */

add_action(
	'phpmailer_init',
	function ( PHPMailer\PHPMailer\PHPMailer $mailer ): void {
		if ( ! watermill_mail_configured() ) {
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's properties.
		$mailer->isSMTP();
		$mailer->Host       = (string) watermill_mail_setting( 'SMTP_HOST' );
		$mailer->Port       = (int) watermill_mail_setting( 'SMTP_PORT' );
		$mailer->SMTPSecure = (string) watermill_mail_setting( 'SMTP_SECURE' );
		$mailer->SMTPAuth   = '' !== (string) watermill_mail_setting( 'SMTP_USER' );
		$mailer->Username   = (string) watermill_mail_setting( 'SMTP_USER' );
		$mailer->Password   = (string) watermill_mail_setting( 'SMTP_PASS' );
		$mailer->Timeout    = 10; // Seconds. PHPMailer's default is 300: a dead server would hang the request that sends.
		// phpcs:enable
	}
);

// Later than WP Base (10), so a set WP_MAIL_FROM_NAME wins over its site-name default.
add_filter(
	'wp_mail_from',
	fn( string $from ): string => (string) watermill_mail_setting( 'FROM_EMAIL' ) ?: $from, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty setting keeps the default.
	20
);
add_filter(
	'wp_mail_from_name',
	fn( string $name ): string => (string) watermill_mail_setting( 'FROM_NAME' ) ?: $name, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty setting keeps the default.
	20
);
