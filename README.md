# Microsoft Graph Mailer for TYPO3

Sends TYPO3 system emails via the **Microsoft Graph API** using OAuth 2.0
client credentials. Built on top of
[`wapplersystems/oauth-service`](https://github.com/WapplerSystems/t3-oauth-service)
for token acquisition, caching, refresh and BE-configurable client setup.

No SMTP. No mailbox password. No interactive user consent needed.

## Why

Office 365 / Exchange Online is phasing out Basic Authentication for SMTP
AUTH. Modern delivery uses the Microsoft Graph `sendMail` endpoint with
an OAuth2 bearer token. This extension wires that endpoint into TYPO3's
existing Symfony Mailer pipeline so any `MailerInterface` consumer
(form_extended, fe_registration, EXT:news, scheduler reports, …) sends
through Graph transparently.

## Requirements

- TYPO3 v14
- PHP 8.2+
- `wapplersystems/oauth-service` ≥ release with `tx_oauthsvc_client.metadata`
- A Microsoft 365 tenant + Entra ID app registration with the
  `Mail.Send` **Application** permission (admin-consent granted)
- A dedicated service mailbox restricted via Application Access Policy

## Installation

```bash
composer require wapplersystems/microsoft-graph-mailer
typo3 extension:setup
```

## Configuration

### 1. Azure / Microsoft Entra ID — App registration

1. <https://entra.microsoft.com> → **Applications → App registrations → New registration**
   - Name: `TYPO3 Mailer – yourdomain.com`
   - Account type: *Single tenant*
   - Redirect URI: leave empty (app-only flow, no user login)
2. **API permissions → Microsoft Graph → Application permissions**
   - Add `Mail.Send`
   - Click **Grant admin consent**
3. **Certificates & secrets → New client secret**
   - Copy the *Value* (only shown once)
4. Note from the **Overview** page:
   - Directory (tenant) ID
   - Application (client) ID

### 2. Restrict the app to one mailbox (Exchange Online PowerShell)

Without this step the app could send from *any* mailbox in the tenant.

```powershell
Connect-ExchangeOnline

New-DistributionGroup -Name "TYPO3MailerAllowedSenders" `
  -Type Security -Members noreply@yourdomain.com

New-ApplicationAccessPolicy `
  -AppId <CLIENT-ID> `
  -PolicyScopeGroupId TYPO3MailerAllowedSenders@yourdomain.com `
  -AccessRight RestrictAccess `
  -Description "TYPO3 Mailer restricted to noreply mailbox"
```

### 3. TYPO3 — OAuth Services backend module

Go to **System → OAuth Services → Clients → New**:

| Field           | Value                                                              |
|-----------------|--------------------------------------------------------------------|
| Provider        | `microsoft_graph`                                                  |
| Active          | ✅                                                                  |
| Client ID       | *Application (client) ID from Azure*                               |
| Client Secret   | *Client secret value from Azure*                                   |
| Scopes          | `https://graph.microsoft.com/.default`                             |
| Metadata (JSON) | `{"tenant_id": "<directory tenant id>", "sender_upn": "noreply@yourdomain.com"}` |

Save. The token is fetched lazily on the first mail send and cached by
`oauth-service` (TYPO3 `oauth_service` cache backend) for its `expires_in`
lifetime.

### 4. Wire the transport

Add to `config/system/additional.php` (or set via *Admin Tools → Settings →
Configure Installation-wide Options → MAIL*):

```php
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] =
    \WapplerSystems\MicrosoftGraphMailer\Mailer\GraphTransport::class;
```

That is the only TYPO3-side configuration. No DSN, no SMTP settings, no
mailbox password. The transport reads `sender_upn` and `tenant_id` from
the OAuth client's metadata at send time.

### 5. (Recommended) Scheduler

`oauth-service` ships two console commands. For client-credentials only
the cache invalidation matters, but the monitor catches misconfigured
credentials early:

```bash
typo3 oauth-service:monitor-connections    # daily
```

## Testing

```bash
typo3 microsoft-graph-mailer:test you@example.com
```

A successful send returns silently. On error the transport throws a
`GraphMailerException` with the Microsoft `request-id` header — quote
that in any Microsoft support case.

## Failure handling — the spool fallback

To avoid silently dropping emails when Microsoft Graph rejects a request
(no OAuth client configured, expired secret, license issue, 401/403/404
from `sendMail`, …), the transport writes the original message as an
`.eml` file plus a `.json` payload record to a spool directory and lets
the caller proceed. Default location:

```
<typo3_var>/typo3-mail-spool/
```

Each undelivered message becomes a pair:

- `YYYYMMDD-HHMMSS-recipient_at_domain-xxxxxxxx.eml` — full RFC 5322
  message; openable in any mail client, useful for human inspection or
  manual resend
- `YYYYMMDD-HHMMSS-recipient_at_domain-xxxxxxxx.json` — failure
  metadata (timestamp, reason, recipients, subject) plus the original
  Microsoft Graph `sendMail` payload (`graph_payload`) so an automated
  retry does not need to re-parse the .eml

### Audit

```bash
typo3 microsoft-graph-mailer:list-undelivered
```

shows everything currently in the spool with the original failure
reason and retry count.

### Automatic retry (recommended)

`typo3 microsoft-graph-mailer:resend-undelivered` iterates the spool
and re-posts each entry's `graph_payload` to Microsoft Graph. On
success the pair is deleted; on failure the entry stays in the spool
with `retry_count` incremented and `last_retry_error` recorded.

Options:
- `--limit N` — process at most N entries per run (default 50)
- `--max-age-days D` — delete (abandon) entries older than D days; 0 disables
- `--dry-run` — list what would be retried without contacting Graph
- `--dir PATH` — override the spool directory

Schedule this command in the TYPO3 Scheduler module as
**"Microsoft Graph: resend undelivered emails"** (registered task) every
five minutes. The task exposes a *Batch size* and *Max age (days)* field
in the BE form.

### Configuration

```php
// Override the spool path:
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_graph_fallback_directory'] = '/srv/mail-spool/graph';

// Disable the fallback entirely (re-raises GraphMailerException, may break form-submits etc.):
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_graph_fallback_directory'] = false;
```

## Multiple senders

The transport always targets the *first* active `microsoft_graph` OAuth
client. To send from a different mailbox in the same tenant, change the
`sender_upn` metadata value. To use a different tenant entirely, create
a second client row and deactivate the first.

## Limitations

- **`saveToSentItems` is hard-set to `false`** — the service mailbox is
  typically `noreply@…` and shouldn't accumulate sent mail. If you need
  Sent-Folder copies, fork the transport.
- **Internet message headers** are limited by Graph to `x-`-prefixed
  custom headers. Standard MIME headers (From/To/Subject/…) are mapped
  to the corresponding Graph message fields automatically.
- **Throttling**: Microsoft Graph applies a 10,000 messages/24h limit
  per app per mailbox. For bulk newsletter traffic use a dedicated
  newsletter service (CleverReach, Mailchimp, …) instead.

## License

GPL-2.0-or-later
