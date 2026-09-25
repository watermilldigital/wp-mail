<?php
/**
 * The email log: every wp_mail() call, one row in {prefix}watermill_mail_log.
 *
 * A row is written as the email starts (status 'sending'), then updated to:
 * - 'sent':    WordPress handed it to the mail server (or another plugin sent it via pre_wp_mail).
 * - 'failed':  the mail server or PHPMailer refused it; the error is kept.
 * - 'blocked': something short-circuited sending, e.g. WP Base outside production.
 * A row left at 'sending' means PHP died mid-send.
 */

const WATERMILL_MAIL_DB_VERSION = 1;

/**
 * The log table's name.
 */
function watermill_mail_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'watermill_mail_log';
}

// Mu-plugins have no activation hook, so create or upgrade the table when the stored version is behind.
add_action(
	'init',
	function (): void {
		if ( (int) get_option( 'watermill_mail_db_version' ) >= WATERMILL_MAIL_DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . watermill_mail_table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sent_at datetime NOT NULL,
				recipients text NOT NULL,
				subject text NOT NULL,
				message longtext NOT NULL,
				headers text NOT NULL,
				status varchar(10) NOT NULL,
				error text NOT NULL,
				PRIMARY KEY  (id),
				KEY sent_at (sent_at),
				KEY status (status)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'watermill_mail_db_version', WATERMILL_MAIL_DB_VERSION );
	}
);

/**
 * Set the status (and error) of the email being sent, then forget it.
 *
 * @param string $status 'sent', 'failed' or 'blocked'.
 * @param string $error  Error message, for 'failed'.
 */
function watermill_mail_finish( string $status, string $error = '' ): void {
	global $wpdb;

	if ( empty( $GLOBALS['watermill_mail_current'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	$wpdb->update(
		watermill_mail_table(),
		array(
			'status' => $status,
			'error'  => $error,
		),
		array( 'id' => $GLOBALS['watermill_mail_current'] )
	);
	$GLOBALS['watermill_mail_current'] = 0;
}

// Last, so the row records what's actually sent after every other filter.
add_filter(
	'wp_mail',
	function ( array $atts ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$logged = $wpdb->insert(
			watermill_mail_table(),
			array(
				'sent_at'    => current_time( 'mysql', true ),
				'recipients' => implode( ', ', (array) $atts['to'] ),
				'subject'    => (string) $atts['subject'],
				'message'    => (string) $atts['message'],
				'headers'    => implode( "\n", is_array( $atts['headers'] ) ? $atts['headers'] : explode( "\n", (string) $atts['headers'] ) ),
				'status'     => 'sending',
				'error'      => '',
			)
		);

		$GLOBALS['watermill_mail_current'] = $logged ? $wpdb->insert_id : 0;

		return $atts;
	},
	PHP_INT_MAX
);

// Any non-null result means something handled or stopped sending instead of PHPMailer: false is WP Base's block.
add_filter(
	'pre_wp_mail',
	function ( $result ) {
		if ( null !== $result ) {
			watermill_mail_finish( false === $result ? 'blocked' : 'sent' );
		}

		return $result;
	},
	PHP_INT_MAX
);

add_action( 'wp_mail_succeeded', fn() => watermill_mail_finish( 'sent' ) );
add_action( 'wp_mail_failed', fn( WP_Error $error ) => watermill_mail_finish( 'failed', $error->get_error_message() ) );

/**
 * Counts by status since a number of hours ago.
 *
 * @param int $hours How far back.
 * @return array{sent: int, failed: int, blocked: int}
 */
function watermill_mail_counts( int $hours ): array {
	global $wpdb;

	$counts = array(
		'sent'    => 0,
		'failed'  => 0,
		'blocked' => 0,
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE sent_at > UTC_TIMESTAMP() - INTERVAL %d HOUR GROUP BY status', watermill_mail_table(), $hours ) );

	foreach ( (array) $rows as $row ) {
		if ( isset( $counts[ $row->status ] ) ) {
			$counts[ $row->status ] = (int) $row->total;
		}
	}

	return $counts;
}

/**
 * The latest failed email since a number of hours ago, or null.
 *
 * @param int $hours How far back.
 */
function watermill_mail_last_failure( int $hours ): ?object {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'failed' AND sent_at > UTC_TIMESTAMP() - INTERVAL %d HOUR ORDER BY id DESC LIMIT 1", watermill_mail_table(), $hours ) );
}

// Daily clean-up: entries hold email bodies (personal data), so they don't stay forever.
add_action(
	'init',
	function (): void {
		if ( ! wp_next_scheduled( 'watermill_mail_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'watermill_mail_prune' );
		}
	}
);
add_action(
	'watermill_mail_prune',
	function (): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE sent_at < UTC_TIMESTAMP() - INTERVAL %d DAY', watermill_mail_table(), (int) watermill_mail_setting( 'LOG_DAYS' ) ) );
	}
);
