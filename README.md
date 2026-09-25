<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/logo-dark.svg">
    <img src=".github/logo-light.svg" alt="WaterMill" width="220" height="30">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/watermilldigital/wp-mail/tags"><img src="https://img.shields.io/badge/version-v1.0.1-blue" alt="Version"></a>
  <img src="https://img.shields.io/badge/php-%5E8.4-777bb4" alt="PHP ^8.4">
  <img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later">
</p>

<p align="center"><strong>Email that arrives, a record of every message, and a warning when it doesn't.</strong></p>

# WP Mail

Email for WaterMill WordPress sites, instead of WP Mail SMTP and similar plugins. It's a must-use plugin with no settings screen: settings are constants in `wp-config.php`.

## What it does

- **SMTP:** when `WP_MAIL_SMTP_HOST` is set, every email WordPress sends goes through that server (Postmark, Brevo, SES, Mailpit locally…). Without it, WordPress sends with PHP `mail()` as usual.
- **Email log:** every `wp_mail()` call is recorded in its own table with the date, recipients, subject, headers, body and a status:
  - **Sent:** handed to the mail server.
  - **Failed:** refused, with the error.
  - **Blocked:** stopped before sending, e.g. by [WP Base](https://github.com/watermilldigital/wp-base) outside production. Staging shows what *would* have gone out.
  - **Didn't finish:** PHP died mid-send.

  Entries are deleted after `WP_MAIL_LOG_DAYS` (90 by default), because bodies hold personal data such as form enquiries.
- **wp-admin** (administrators only):
  - **Tools → Email log:** the list, filterable by status, each email viewable in full. HTML bodies are shown in a sandboxed frame. A **Send test email** button sends one to you and reports the result.
  - **Email dashboard widget:** how email is sent, the last 7 days' counts by status, and the latest failure. In production it also shows whether the sending domain has SPF and DMARC records.
  - **Banners:** an error when any email failed in the last 24 hours. In production, a warning when SMTP isn't set up, or when the sending domain has no SPF or DMARC record. DKIM isn't checked, because its record name depends on the provider.

## Settings

All optional. These are the defaults:

```php
define( 'WP_MAIL_SMTP_HOST', '' );      // Empty: no SMTP, PHP mail() (and a warning in production).
define( 'WP_MAIL_SMTP_PORT', 587 );
define( 'WP_MAIL_SMTP_USER', '' );      // Empty: no authentication.
define( 'WP_MAIL_SMTP_PASS', '' );
define( 'WP_MAIL_SMTP_SECURE', 'tls' ); // 'tls' (STARTTLS, usually 587), 'ssl' (usually 465) or '' (none, e.g. Mailpit).
define( 'WP_MAIL_FROM_EMAIL', '' );     // Empty: WordPress's wordpress@yourdomain. Use a domain your provider can send for.
define( 'WP_MAIL_FROM_NAME', '' );      // Empty: WordPress's default (WP Base makes it the site name).
define( 'WP_MAIL_LOG_DAYS', 90 );
```

Keep the credentials out of the repo: define them from environment variables in `wp-config.php`. For email to reach inboxes, the sending domain also needs the SPF and DKIM records your provider gives you, plus a DMARC record.

## Email locally with Mailpit

[Mailpit](https://mailpit.axllent.org) catches every email in a local web inbox instead of sending it. Point SMTP at it with no TLS:

```php
define( 'WP_MAIL_SMTP_HOST', '127.0.0.1' );
define( 'WP_MAIL_SMTP_PORT', 1025 );
define( 'WP_MAIL_SMTP_SECURE', '' );
```

Outside production WP Base blocks all email before it reaches SMTP, so also skip its `environment` file locally: `define( 'WP_BASE_SKIP', array( 'environment' ) );`. Never do that on staging, which would then email real people from a copy of real data.

## Install

```sh
composer config repositories.wp-mail vcs https://github.com/watermilldigital/wp-mail
composer require watermilldigital/wp-mail:^1.0
```

It needs the same `wordpress-muplugin` installer path and `mu-plugins/autoloader.php` as [WP Base](https://github.com/watermilldigital/wp-base#install). The log table is created on the first request after installing.

## Development

```sh
composer install
composer check   # phpstan + phpcs
```

The end-to-end smoke test sends emails that end each possible way (sent, blocked, and failed against a closed port), checks the log recorded each, checks old entries are pruned, then deletes what it wrote. `tests/` isn't in the released package, so run it from this clone against a site that has the plugin installed:

```sh
wp eval-file tests/smoke.php --path=/path/to/site/wp
```

It tests the copy the site loads, not this clone's `src/`. To test unreleased changes, put this clone's files in the site's `mu-plugins/wp-mail/` first.

Release by bumping the version badge at the top of this README and the `Version:` header in `wp-mail.php`, then tagging (`git tag v1.1.0 && git push origin v1.1.0`). The badge is static because shields.io can't read tags from a private repo. After tagging, run `composer update watermilldigital/wp-mail` in each project.

## License

Copyright © WaterMill Digital. Licensed under [GPL-2.0-or-later](LICENSE), the same licence as WordPress.
