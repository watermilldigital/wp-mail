<?php
/**
 * End-to-end check against a real WordPress install. Sends emails that end each possible way (blocked, sent,
 * failed), checks the log recorded each, checks old entries are pruned, then deletes what it wrote.
 *
 * Run from this repo against a site with the plugin installed (tests/ isn't in the released package):
 *
 *     wp eval-file tests/smoke.php --path=/path/to/site/wp
 *
 * phpcs:disable -- test script.
 */

global $wpdb;

$table = watermill_mail_table();
$start = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $table" );

function watermill_mail_check( bool $ok, string $what ): void {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $what . PHP_EOL;
	if ( ! $ok ) {
		$GLOBALS['watermill_mail_failed'] = true;
	}
}

function watermill_mail_latest(): ?object {
	global $wpdb;
	return $wpdb->get_row( 'SELECT * FROM ' . watermill_mail_table() . ' ORDER BY id DESC LIMIT 1' );
}

watermill_mail_check( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'log table exists' );

// Sent: something else handles sending (returns true from pre_wp_mail), after any blocking filter.
$handled = fn() => true;
add_filter( 'pre_wp_mail', $handled, 11 );
wp_mail( array( 'a@example.com', 'b@example.com' ), 'Smoke sent', 'Body', array( 'Reply-To: c@example.com' ) );
remove_filter( 'pre_wp_mail', $handled, 11 );
$row = watermill_mail_latest();
watermill_mail_check( 'sent' === $row->status, 'handled email logged as sent' );
watermill_mail_check( 'a@example.com, b@example.com' === $row->recipients && 'Smoke sent' === $row->subject && 'Body' === $row->message, 'recipients, subject and body recorded' );
watermill_mail_check( str_contains( $row->headers, 'Reply-To: c@example.com' ), 'headers recorded' );

// Blocked: a short-circuit to false (what WP Base does outside production).
$block = fn() => false;
add_filter( 'pre_wp_mail', $block, 11 );
wp_mail( 'a@example.com', 'Smoke blocked', 'Body' );
remove_filter( 'pre_wp_mail', $block, 11 );
watermill_mail_check( 'blocked' === watermill_mail_latest()->status, 'short-circuited email logged as blocked' );

// Real WP Base, outside production: nothing added, so its block should be what ends it.
if ( 'production' !== wp_get_environment_type() ) {
	wp_mail( 'a@example.com', 'Smoke WP Base', 'Body' );
	watermill_mail_check( 'blocked' === watermill_mail_latest()->status, 'WP Base block logged as blocked (' . wp_get_environment_type() . ')' );
}

// Failed: let it through to PHPMailer, from a valid address, pointed at a port nothing listens on.
$through = fn() => null;
$valid   = fn() => 'smoke@example.com';
add_filter( 'wp_mail_from', $valid, 99 );
$dead    = function ( $mailer ) {
	$mailer->isSMTP();
	$mailer->Host    = '127.0.0.1';
	$mailer->Port    = 1;
	$mailer->Timeout = 2;
};
add_filter( 'pre_wp_mail', $through, 11 );
add_action( 'phpmailer_init', $dead, 99 );
wp_mail( 'a@example.com', 'Smoke failed', 'Body' );
remove_filter( 'pre_wp_mail', $through, 11 );
remove_action( 'phpmailer_init', $dead, 99 );
remove_filter( 'wp_mail_from', $valid, 99 );
$row = watermill_mail_latest();
watermill_mail_check( 'failed' === $row->status && '' !== $row->error, 'refused email logged as failed, with the error (' . $row->error . ')' );

$counts = watermill_mail_counts( 1 );
watermill_mail_check( $counts['sent'] >= 1 && $counts['failed'] >= 1 && $counts['blocked'] >= 1, 'counts see each status' );
watermill_mail_check( 'Smoke failed' === watermill_mail_last_failure( 1 )->subject, 'latest failure found' );

// Prune: an entry older than LOG_DAYS goes, a new one stays.
$wpdb->insert( $table, array( 'sent_at' => '2000-01-01 00:00:00', 'recipients' => 'old@example.com', 'subject' => 'Smoke old', 'message' => '', 'headers' => '', 'status' => 'sent', 'error' => '' ) );
$old = $wpdb->insert_id;
do_action( 'watermill_mail_prune' );
watermill_mail_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $old ) ), 'old entry pruned' );
watermill_mail_check( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $row->id ) ), 'recent entry kept' );
watermill_mail_check( (bool) wp_next_scheduled( 'watermill_mail_prune' ), 'daily prune scheduled' );

$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id > %d", $start ) );

echo empty( $GLOBALS['watermill_mail_failed'] ) ? 'All checks passed.' . PHP_EOL : 'Some checks FAILED.' . PHP_EOL;
