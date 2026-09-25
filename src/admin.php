<?php
/**
 * In wp-admin: the Email log page (Tools → Email log), the Email dashboard widget, and warning banners for
 * administrators when email isn't set up, is failing, or the sending domain is missing SPF or DMARC.
 */

/**
 * The domain email is sent from, SPF and DMARC present on it (null: couldn't look up), cached for 12 hours.
 * Only meaningful in production: elsewhere the domain is usually localhost or a staging host.
 *
 * DKIM isn't checked: its record's name depends on the provider's selector.
 *
 * @return array{domain: string, spf: ?bool, dmarc: ?bool}
 */
function watermill_mail_dns(): array {
	$host   = preg_replace( '/^www\./', '', (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
	$from   = (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook: the address wp_mail() would use.
	$domain = strtolower( substr( (string) strrchr( $from, '@' ), 1 ) );
	$key    = 'watermill_mail_dns_' . md5( $domain );
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$has = function ( string $name, string $prefix ): ?bool {
		$records = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup warns; it's reported as unknown instead.
		if ( false === $records ) {
			return null;
		}
		foreach ( $records as $record ) {
			if ( str_starts_with( strtolower( $record['txt'] ?? '' ), strtolower( $prefix ) ) ) {
				return true;
			}
		}
		return false;
	};

	$dns = array(
		'domain' => $domain,
		'spf'    => $has( $domain, 'v=spf1' ),
		'dmarc'  => $has( '_dmarc.' . $domain, 'v=DMARC1' ),
	);
	set_transient( $key, $dns, 12 * HOUR_IN_SECONDS );

	return $dns;
}

/**
 * The Email log page URL.
 *
 * @param array<string, string|int> $args Extra query args.
 */
function watermill_mail_log_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'tools.php?page=watermill-mail-log' ) );
}

/**
 * A status as a coloured label.
 *
 * @param string $status Log status.
 */
function watermill_mail_status_label( string $status ): string {
	$labels                 = array(
		'sent'    => array( __( 'Sent', 'wp-mail' ), '#008a20' ),
		'failed'  => array( __( 'Failed', 'wp-mail' ), '#d63638' ),
		'blocked' => array( __( 'Blocked', 'wp-mail' ), '#646970' ),
		'sending' => array( __( 'Didn\'t finish', 'wp-mail' ), '#996800' ),
	);
	list( $label, $colour ) = $labels[ $status ] ?? array( $status, '#646970' );

	return sprintf( '<strong style="color: %s">%s</strong>', esc_attr( $colour ), esc_html( $label ) );
}

// Banners: failures first (something is broken now), then setup problems that make email unreliable.
add_action(
	'admin_notices',
	function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$failed = watermill_mail_counts( 24 )['failed'];
		if ( $failed ) {
			$last = watermill_mail_last_failure( 24 );
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				/* translators: %s: number of emails. */
				esc_html( sprintf( _n( '%s email failed to send in the last 24 hours.', '%s emails failed to send in the last 24 hours.', $failed, 'wp-mail' ), number_format_i18n( $failed ) ) ),
				/* translators: %s: error message. */
				esc_html( $last ? sprintf( __( 'Latest error: %s', 'wp-mail' ), $last->error ) : '' ),
				esc_url( watermill_mail_log_url( array( 'status' => 'failed' ) ) ),
				esc_html__( 'View email log', 'wp-mail' )
			);
		}

		if ( 'production' !== wp_get_environment_type() ) {
			return;
		}

		if ( ! watermill_mail_configured() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Email isn\'t set up.', 'wp-mail' ),
				esc_html__( 'WordPress is sending with PHP mail(), so emails, including enquiries, often land in spam or never arrive. Set the WP_MAIL_SMTP_* constants in wp-config.php.', 'wp-mail' )
			);
			return;
		}

		$dns     = watermill_mail_dns();
		$missing = array_keys(
			array_filter(
				array(
					'SPF'   => $dns['spf'],
					'DMARC' => $dns['dmarc'],
				),
				fn( ?bool $found ): bool => false === $found
			)
		);
		if ( $missing ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				/* translators: 1: record types, e.g. "SPF and DMARC", 2: domain. */
				esc_html( sprintf( __( 'No %1$s record for %2$s.', 'wp-mail' ), implode( ' and ', $missing ), $dns['domain'] ) ),
				esc_html__( 'Email from this site is likely to be marked as spam. Add the DNS records your email provider gives you.', 'wp-mail' )
			);
		}
	}
);

add_action(
	'wp_dashboard_setup',
	function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'watermill_mail',
			__( 'Email', 'wp-mail' ),
			function (): void {
				$counts     = watermill_mail_counts( 7 * 24 );
				$last       = watermill_mail_last_failure( 7 * 24 );
				$production = 'production' === wp_get_environment_type();
				// WP Base's non-production mail block lives in its `environment` file, which a site can skip (e.g. for Mailpit).
				?>
				<style>
					#watermill_mail .inside { margin: 0; padding: 0; }
					.watermill-mail-body { padding: 16px 12px 4px; }
					.watermill-mail-counts { display: flex; gap: 24px; margin: 8px 0 12px; }
					.watermill-mail-count { display: block; font-size: 24px; line-height: 1.2; font-weight: 600; }
					.watermill-mail-body .notice { margin: 12px 0; }
					.watermill-mail-body .notice p { margin: 8px 0; }
					.watermill-mail-footer { margin: 12px 0 0; padding: 10px 12px; border-top: 1px solid #f0f0f1; background: #f6f7f7; color: #646970; font-size: 12px; }
				</style>
				<div class="watermill-mail-body">
					<p>
						<?php
						if ( watermill_mail_configured() ) {
							/* translators: 1: SMTP host, 2: port. */
							printf( esc_html__( 'Sending through %1$s:%2$d.', 'wp-mail' ), '<code>' . esc_html( (string) watermill_mail_setting( 'SMTP_HOST' ) ) . '</code>', (int) watermill_mail_setting( 'SMTP_PORT' ) );
						} else {
							esc_html_e( 'Not set up: sending with PHP mail(), which often lands in spam.', 'wp-mail' );
						}
						?>
					</p>
					<?php if ( ! $production && ! in_array( 'environment', defined( 'WP_BASE_SKIP' ) ? (array) WP_BASE_SKIP : array(), true ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: environment type, e.g. local or staging. */
							printf( esc_html__( 'This is a %s environment: WP Base blocks sending, so emails are logged as Blocked.', 'wp-mail' ), esc_html( wp_get_environment_type() ) );
							?>
						</p>
					<?php endif; ?>

					<div class="watermill-mail-counts">
						<?php foreach ( $counts as $status => $total ) : ?>
							<div><span class="watermill-mail-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span><?php echo watermill_mail_status_label( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Last 7 days.', 'wp-mail' ); ?></p>

					<?php if ( $last ) : ?>
						<div class="notice notice-error inline">
							<p>
								<?php
								/* translators: 1: date, 2: error message. */
								printf( esc_html__( 'Latest failure, %1$s: %2$s', 'wp-mail' ), esc_html( get_date_from_gmt( $last->sent_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ), esc_html( $last->error ) );
								?>
							</p>
						</div>
					<?php endif; ?>

					<?php
					if ( $production && watermill_mail_configured() ) :
						$dns   = watermill_mail_dns();
						$state = fn( ?bool $found ): string => null === $found ? __( 'couldn\'t check', 'wp-mail' ) : ( $found ? '✓' : __( 'missing', 'wp-mail' ) );
						?>
						<p class="description">
							<?php
							/* translators: 1: domain, 2: SPF state, 3: DMARC state. */
							printf( esc_html__( '%1$s: SPF %2$s · DMARC %3$s. DKIM: check with your email provider.', 'wp-mail' ), '<code>' . esc_html( $dns['domain'] ) . '</code>', esc_html( $state( $dns['spf'] ) ), esc_html( $state( $dns['dmarc'] ) ) );
							?>
						</p>
					<?php endif; ?>
				</div>
				<p class="watermill-mail-footer">
					<a href="<?php echo esc_url( watermill_mail_log_url() ); ?>"><?php esc_html_e( 'Email log', 'wp-mail' ); ?></a>
					<?php
					/* translators: %d: days. */
					printf( esc_html__( '· entries are kept for %d days. Change with WP_MAIL_* constants in wp-config.php.', 'wp-mail' ), (int) watermill_mail_setting( 'LOG_DAYS' ) );
					?>
				</p>
				<?php
			}
		);
	}
);

// Send a test email to the current user, then report how it went on the log page.
add_action(
	'admin_post_watermill_mail_test',
	function (): void {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-mail' ), 403 );
		}
		check_admin_referer( 'watermill_mail_test' );

		$to = wp_get_current_user()->user_email;
		wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( 'Test email from %s', 'wp-mail' ), get_bloginfo( 'name' ) ),
			/* translators: %s: site URL. */
			sprintf( __( 'This is a test email from WP Mail on %s. If it arrived in your inbox (not spam), email is working.', 'wp-mail' ), home_url() )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table; the row just written.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM %i ORDER BY id DESC LIMIT 1', watermill_mail_table() ) );

		wp_safe_redirect(
			watermill_mail_log_url(
				array(
					'test'  => $row->status ?? 'failed',
					'email' => $row->id ?? 0,
				)
			)
		);
		exit;
	}
);

add_action(
	'admin_menu',
	function (): void {
		add_management_page( __( 'Email log', 'wp-mail' ), __( 'Email log', 'wp-mail' ), 'manage_options', 'watermill-mail-log', 'watermill_mail_log_page' );
	}
);

/**
 * Tools → Email log: the list, or one email when ?email= is set.
 */
function watermill_mail_log_page(): void {
	global $wpdb;

	$table = watermill_mail_table();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and paging.
	$id     = absint( $_GET['email'] ?? 0 );
	$test   = sanitize_key( $_GET['test'] ?? '' );
	$status = sanitize_key( $_GET['status'] ?? '' );
	$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
	// phpcs:enable

	echo '<div class="wrap">';

	if ( $test ) {
		$notices                = array(
			/* translators: %s: email address. */
			'sent'    => array( 'success', sprintf( __( 'Test email sent to %s. Check it arrives, and not in spam.', 'wp-mail' ), wp_get_current_user()->user_email ) ),
			'failed'  => array( 'error', __( 'The test email failed. The error is below.', 'wp-mail' ) ),
			'blocked' => array( 'info', __( 'The test email was blocked: WP Base stops sending outside production. It\'s logged below.', 'wp-mail' ) ),
		);
		list( $type, $message ) = $notices[ $test ] ?? $notices['failed'];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	if ( $id && ! $test ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$email = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );

		if ( ! $email ) {
			printf( '<h1>%s</h1><p>%s</p>', esc_html__( 'Email log', 'wp-mail' ), esc_html__( 'That email isn\'t in the log (entries are deleted after a while).', 'wp-mail' ) );
		} else {
			$html = str_contains( strtolower( $email->headers ), 'text/html' ) || preg_match( '/^\s*</', $email->message );
			?>
			<h1><?php echo esc_html( $email->subject ); ?></h1>
			<p><a href="<?php echo esc_url( watermill_mail_log_url() ); ?>">&larr; <?php esc_html_e( 'Email log', 'wp-mail' ); ?></a></p>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Date', 'wp-mail' ); ?></th><td><?php echo esc_html( get_date_from_gmt( $email->sent_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'To', 'wp-mail' ); ?></th><td><?php echo esc_html( $email->recipients ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Status', 'wp-mail' ); ?></th><td><?php echo watermill_mail_status_label( $email->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?><?php echo $email->error ? ': ' . esc_html( $email->error ) : ''; ?></td></tr>
				<?php if ( $email->headers ) : ?>
					<tr><th><?php esc_html_e( 'Headers', 'wp-mail' ); ?></th><td><pre style="margin: 0; white-space: pre-wrap;"><?php echo esc_html( $email->headers ); ?></pre></td></tr>
				<?php endif; ?>
			</table>
			<?php if ( $html ) : ?>
				<?php // Sandboxed: the body can contain anything a visitor typed into a form. ?>
				<iframe sandbox="" srcdoc="<?php echo esc_attr( $email->message ); ?>" style="width: 100%; height: 600px; background: #fff; border: 1px solid #c3c4c7;" title="<?php esc_attr_e( 'Email body', 'wp-mail' ); ?>"></iframe>
			<?php else : ?>
				<pre style="padding: 16px; background: #fff; border: 1px solid #c3c4c7; white-space: pre-wrap;"><?php echo esc_html( $email->message ); ?></pre>
			<?php endif; ?>
			<?php
		}

		echo '</div>';
		return;
	}

	$per_page = 50;
	$statuses = array( 'sent', 'failed', 'blocked', 'sending' );
	$filter   = in_array( $status, $statuses, true );
	$where    = $filter ? 'WHERE status = %s' : '';
	$args     = $filter ? array( $table, $status ) : array( $table );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; $where is a fixed placeholder string, its values spread in with $args.
	$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i $where", ...$args ) );
	$emails = $wpdb->get_results( $wpdb->prepare( "SELECT id, sent_at, recipients, subject, status, error FROM %i $where ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );
	// phpcs:enable
	?>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Email log', 'wp-mail' ); ?></h1>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
		<input type="hidden" name="action" value="watermill_mail_test">
		<?php wp_nonce_field( 'watermill_mail_test' ); ?>
		<button class="page-title-action"><?php esc_html_e( 'Send test email', 'wp-mail' ); ?></button>
	</form>
	<hr class="wp-header-end">

	<ul class="subsubsub">
		<?php
		$links = array( '' => __( 'All', 'wp-mail' ) ) + array_combine( $statuses, array( __( 'Sent', 'wp-mail' ), __( 'Failed', 'wp-mail' ), __( 'Blocked', 'wp-mail' ), __( 'Didn\'t finish', 'wp-mail' ) ) );
		$items = array();
		foreach ( $links as $key => $label ) {
			$items[] = sprintf( '<li><a href="%s"%s>%s</a></li>', esc_url( watermill_mail_log_url( $key ? array( 'status' => $key ) : array() ) ), $key === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
		}
		echo implode( ' | ', $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		?>
	</ul>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width: 16%"><?php esc_html_e( 'Date', 'wp-mail' ); ?></th>
				<th style="width: 22%"><?php esc_html_e( 'To', 'wp-mail' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'wp-mail' ); ?></th>
				<th style="width: 26%"><?php esc_html_e( 'Status', 'wp-mail' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $emails ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No emails logged yet.', 'wp-mail' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $emails as $email ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $email->sent_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td><?php echo esc_html( $email->recipients ); ?></td>
					<td><a href="<?php echo esc_url( watermill_mail_log_url( array( 'email' => $email->id ) ) ); ?>"><strong><?php echo esc_html( '' !== $email->subject ? $email->subject : __( '(no subject)', 'wp-mail' ) ); ?></strong></a></td>
					<td><?php echo watermill_mail_status_label( $email->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?><?php echo $email->error ? '<br><span class="description">' . esc_html( $email->error ) . '</span>' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		printf(
			'<div class="tablenav bottom"><div class="tablenav-pages">%s</div></div>',
			paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes it.
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'current' => $paged,
					'total'   => $pages,
				)
			)
		);
	}

	echo '</div>';
}
