# WP Mailer

A mailing-list plugin that runs inside WordPress. It doesn't need any external service or Composer packages.

- **Audience:** picks recipients from any MySQL table, for example a table that another plugin of yours owns. You build the audience with simple rules (`city = Lisbon AND tier is one of gold, silver`).
- **HTML templates:** create, edit, import, export and duplicate templates, each with a revision history. The editor has syntax highlighting and a live desktop/mobile preview.
- **Campaigns:** subject with merge tags, preheader, template plus content, test sends, preview, send now or schedule, pause/resume/cancel.
- **Sending:** goes over your SMTP server in rate-limited batches (emails per minute) from a one-minute cron tick.
- **Tracking:**
  - **Opens:** a 1×1 pixel. Likely-automated opens (Apple Mail Privacy Protection, scanners) are flagged.
  - **Clicks:** every link goes through a signed redirect.
  - **Unsubscribes:** a confirmation page plus RFC 8058 one-click unsubscribe.
  - **Bounces:** read from a mailbox over IMAP and matched to the exact message using a VERP return path.
- **Suppression list:** hard bounces, repeated soft bounces, unsubscribes and manual entries are never mailed again.
- **Reports:** sent/delivered, open and click rates, click-to-open, bounces, unsubscribes, per-link clicks, hourly activity, a filterable recipient list and CSV export.

## Install

1. Copy the `wp-mailer` folder to `wp-content/plugins/` and activate **WP Mailer**.
2. Go to **Mailing List → Settings**:
   - **Audience:** choose your contacts table, its email column and its ID column.
   - **Sender / SMTP:** enter your SMTP host, port, encryption, username and password. Use **Send SMTP test to me** to check them.
   - **Bounces:** set a bounce address such as `bounces@yourdomain.com` and that mailbox's IMAP login. Use **Test IMAP connection** to check it.
3. Set up a real cron job so sending doesn't depend on site traffic:

   ```php
   // wp-config.php
   define( 'DISABLE_WP_CRON', true );
   ```

   ```cron
   * * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null
   ```

4. Optional: add `define( 'WPM_SECRET_KEY', 'a long random string' );` to `wp-config.php`. SMTP and IMAP passwords are encrypted with that key instead of your site salts.

Requirements: WordPress 6.2+, PHP 8.1+ with `openssl` and `dom`. The PHP `imap` extension is **not** needed because the plugin includes its own IMAP client.

## How it works

### Audience

The plugin reads your contacts table but never writes to it. Rules are stored as JSON on each campaign. Column names are checked against `SHOW COLUMNS` and all values go through `$wpdb->prepare()`.

When you press **Send**, the matching contacts are copied into `wp_wpm_sends`. At that point:

- invalid emails, duplicates and suppressed addresses are skipped;
- each contact's row is stored for merge tags.

That freezes the audience, so later changes to your table don't affect a campaign that's already going out.

### Templates and merge tags

| Tag | Meaning |
| --- | --- |
| `{{content}}` | The campaign's content (one template serves many campaigns) |
| `{{first_name}}`, `{{city}}`, … | Any column of your contacts table (lower-case) |
| `{{first_name\|there}}` | Value with a fallback when empty |
| `{{email}}`, `{{date}}` | Recipient address, today's date |
| `{{unsubscribe_url}}`, `{{view_in_browser_url}}` | Per-recipient links |
| `{{preheader}}` | Where to place the hidden preheader (it goes after `<body>` otherwise) |

If a template has no `{{unsubscribe_url}}`, an unsubscribe footer is added automatically.

When a campaign starts:

1. Its HTML is assembled (template + content).
2. CSS is inlined, because Gmail and Outlook ignore `<style>`. `@media` rules are kept.
3. Links are registered for click tracking.
4. The result is **snapshotted**, so later template edits never change a campaign that has already been sent.

Add `data-notrack` to a link to leave it untracked.

### Tracking endpoints

Tracking runs through public REST routes. Each token is HMAC-signed, so IDs can't be guessed or forged.

| Route | Purpose |
| --- | --- |
| `GET /wp-json/wpm/v1/o/{token}` | Open pixel |
| `GET /wp-json/wpm/v1/c/{token}` | Click. It only redirects to a URL stored for that campaign, so it can't be used as an open redirect. |
| `GET/POST /wp-json/wpm/v1/u/{token}` | Unsubscribe. GET shows a confirmation so link scanners can't unsubscribe people. POST also handles one-click unsubscribe. |
| `GET /wp-json/wpm/v1/v/{token}` | View in browser |

IP addresses are stored only as salted hashes. User agents and IP hashes are erased after the retention period you set in Settings.

The plugin also hooks into WordPress's personal-data tools:

- **Tools → Export Personal Data** exports an address's sends.
- **Tools → Erase Personal Data** removes them.

### Bounces

Every message is sent with:

- a unique return path such as `bounces+123-9f8e7d6c5b@yourdomain.com`, where `123` is the send ID and the rest is a signature;
- an `X-WPM-Send` header as a fallback.

Every 10 minutes the plugin reads unread mail in the bounce mailbox and handles it like this:

| Message | What happens |
| --- | --- |
| RFC 3464 report with `Status: 5.x.x` (except 5.2.x mailbox full) | Hard bounce → suppressed immediately |
| `4.x.x` or mailbox full | Soft bounce. Suppressed after *N* soft bounces in 90 days (configurable). |
| Non-standard bounce (Exim, Exchange, …) | Classified by known phrases. Base64 and quoted-printable parts are decoded first. |
| Out-of-office, delay notice | Ignored (not a bounce) |
| SMTP rejection while sending (`550 User unknown`) | Hard bounce right away |

Matched messages are moved to a `WPM-Processed` folder. Anything that doesn't match stays in the inbox, marked as read, for you to review.

> **Mailbox requirement:** the bounce mailbox must accept plus-addressing (`bounces+anything@`). Most providers do, including Gmail/Workspace, Microsoft 365, Fastmail and Postfix/Dovecot with `recipient_delimiter = +`. Otherwise it must be a catch-all.
>
> **SMTP requirement:** the custom return path needs **Use SMTP** to be on. PHP's `mail()` refuses `+` in the return path and Q-encodes long headers.
>
> Some providers force the return path to the account you log in with. In that case bounces arrive in that mailbox: point IMAP at it, and bounces are still matched through the `X-WPM-Send` header.

### Deliverability checklist

- SPF and DKIM for the From domain at your SMTP provider, plus a DMARC record.
- Keep **Emails per minute** under your provider's limits.
- Watch the bounce rate on reports. Above about 2–3% usually means the list needs cleaning.

## Development

```sh
php tests/run.php            # unit tests, no WordPress needed
php tests/run.php dsn        # only tests whose name contains "dsn"
```

The unit tests cover:

- token signing and forgery checks;
- segment-to-SQL (including injection attempts);
- CSS inlining;
- HTML-to-text;
- the full render pipeline;
- DSN parsing against sample Postfix, Gmail, Exim, Exchange (base64), out-of-office and delay messages in `tests/fixtures/bounces`;
- the IMAP client against a scripted server.

Layout:

```
wp-mailer.php            bootstrap + autoloader
src/Install/Schema.php   tables (dbDelta) and capabilities
src/Audience/            contacts table access, segment → SQL
src/Template/            template storage + revisions, CSS inliner, HTML→text
src/Campaign/            campaign storage + stats, renderer (merge, links, pixel)
src/Send/                queue (batches, pause/resume), mailer (SMTP, VERP, headers)
src/Track/               signed tokens, REST endpoints, event recording
src/Bounce/              IMAP client, DSN parser, bounce processor
src/Admin/               admin screens
templates/               starter templates installed on activation
```

Capabilities:

- `wpm_manage`: campaigns, reports, suppressions.
- `wpm_manage_templates`: raw template HTML.
- `manage_options`: settings.

Administrators get the first two on activation.
