# Mail MCP

Plain PHP MCP server with an admin console, per-domain SMTP, Resend, or Amazon SES API v2 settings, rate limits, attachments, and a built-in OAuth authorization server for ChatGPT.

## Deploy on Laravel Forge

1. Create a Forge site with PHP 8.2+ and the PDO SQLite, Sodium, and Fileinfo extensions enabled; point its web root to this repository's `public/` directory.
2. Deploy the code and run `composer install --no-dev --optimize-autoloader`.
3. Copy `.env.example` to `.env`. Set `MCP_BASE_URL=https://mailmcp.on-forge.com`, set `SQLITE_PATH` to a writable absolute path in your Forge site's shared directory (for example `/home/forge/mailmcp.on-forge.com/shared/mail-mcp.sqlite`; adjust to the actual site path), and generate `APP_KEY` using the command shown in the file. Keep `APP_KEY` private and stable: SMTP passwords, Resend API keys, and SES API secret keys in the database are encrypted with it. Relative `SQLITE_PATH` values resolve from the project root; the default is `storage/mail-mcp.sqlite`.
4. Ensure PHP has PDO SQLite and Sodium enabled. Make the SQLite parent directory writable by the PHP-FPM user. Also keep `storage/` writable for sending limits and logs.
5. In Forge DNS, point `mailmcp.on-forge.com` to the server, enable HTTPS, and keep the site's document root at `public/`.
6. Open `https://mailmcp.on-forge.com/admin`. On first visit, create the administrator account. Save the password securely; it is stored as a password hash.
7. In the admin page, add each sending domain and choose SMTP, Resend, or Amazon SES API v2. For SES API, provide the AWS region and a dedicated IAM access key with `ses:SendEmail` permission; the secret key is encrypted in SQLite. Verify the sender identity in SES and enable production sending as needed. For Resend, enter a Resend API key with email sending access and verify the domain in Resend. For SMTP, use SES SMTP credentials with the regional SMTP endpoint.
8. Create a site account for each team/site and assign only the sender domain(s) that account may use. Sign into ChatGPT's OAuth prompt with that account when connecting its MCP client.

Forge's standard Nginx front-controller rule (`try_files $uri $uri/ /index.php?$query_string;`) should route `/admin`, `/authorize`, OAuth metadata, and MCP requests to `public/index.php`. The repository root must not be web-accessible.

## Connect in ChatGPT

Create a custom MCP connection with:

- **Name:** Mail MCP
- **Description:** Send email using configured SMTP, Resend, or Amazon SES API domains, including attachments.
- **Connection URL:** `https://mailmcp.on-forge.com/`
- **Authentication:** OAuth

The app provides OAuth authorization-server metadata, dynamic client registration, authorization-code flow with PKCE S256, login, an approval screen, and bearer access tokens. ChatGPT's client registration is saved in the admin panel. When ChatGPT opens the authorization page, sign in with the site account for the site/domain you want to connect and approve the displayed sender domains. Each token is tied to that account. The approval screen lets you switch accounts before granting access. Revoke a client from the admin panel to remove its access. The OAuth issuer is derived from `MCP_BASE_URL`; there are no separate OAuth provider credentials to copy into `.env`.

OAuth clients receive the `mail.send` scope. The admin page lets you set access-token lifetime (300 seconds to 30 days). Tokens are persisted as hashes in SQLite. There is no refresh-token grant; ChatGPT can run the authorization flow again when a token expires.

## Admin panel

At `/admin`, the administrator can:

- Add, update, or remove SMTP, Resend, or Amazon SES API settings by sender domain.
- Send a real test email for a selected domain, optionally with a file attached, and inspect the delivery result. Test messages count against that domain's rate caps.
- Create/update site accounts and assign their allowed sending domains. Accounts can use only their assigned domains, even if they guess another `from` address in an MCP call. Removing a domain also removes it from every account.
- Set per-domain hourly and daily message limits, plus recipients per message.
- Set OAuth token lifetime and revoke dynamically registered OAuth clients.
- Change the admin password.

SMTP passwords, Resend API keys, and SES IAM secret keys are encrypted in SQLite using `APP_KEY` and Sodium secretbox. Back up the database and `.env` together. If `APP_KEY` is lost or changed, saved provider secrets cannot be decrypted and must be entered again. For an existing deployment, copy `storage/mail-mcp.sqlite` into the shared folder before changing `SQLITE_PATH`; pause PHP requests during the copy so SQLite writes are not missed. Keep the shared database file across releases.

Existing installs are migrated automatically when the updated code first runs. Existing administrator credentials are copied into the account login table. Assign sender domains to the administrator and create site accounts in the admin page. Existing OAuth access tokens are not associated with a site account and must be authorized again; reconnect ChatGPT using the intended site account.

## Test mail delivery locally

For a full local app test, use a separate local database and mail provider settings; do not copy production credentials or the production SQLite database onto a development machine.

```sh
composer install
cp .env.example .env
```

In the local `.env`, set `APP_ENV=local`, `MCP_BASE_URL=http://127.0.0.1:8000`, `SQLITE_PATH=storage/mail-mcp-local.sqlite`, and generate a local `APP_KEY`. Start the PHP server with the front controller as its router:

```sh
php -S 127.0.0.1:8000 -t public public/index.php
```

Open `http://127.0.0.1:8000/admin`, create a local administrator, add SMTP, Resend, or SES API settings, then use **Test mail delivery** to send a real message to an address you control. Attach a small file there to test attachments. The local session cookie is non-Secure only when `APP_ENV=local` and the host is loopback; production cookies remain Secure.

To check only DNS, TCP reachability, and TLS from a terminal, use the SES host and port configured in the app:

```sh
SES_SMTP_HOST=email-smtp.<region>.amazonaws.com
getent hosts "$SES_SMTP_HOST"
nc -vz -w 5 "$SES_SMTP_HOST" 587
timeout 10 openssl s_client -starttls smtp -connect "$SES_SMTP_HOST:587" -servername "$SES_SMTP_HOST" -brief </dev/null
```

That terminal check does not authenticate or send a message. Run it on the Forge server to check Forge's outbound network path; running it locally checks only your development machine's network.

## MCP tool

The MCP endpoint is the site root and exposes `send_email`. The sender's domain selects the matching SMTP, Resend, or SES API credentials. Arguments: `from`, `to` (array), `subject`, and either `text` or `html`. Add both to send a multipart email with plain-text and HTML alternatives. HTML may use normal email markup such as paragraphs, headings, lists, links, and emphasis. Optional `cc`, `bcc`, and `reply_to` are supported.

Optional `attachments` is an array of objects with `filename`, `content_base64`, and optional `mime_type`. `content_base64` is the raw base64 encoding of the file bytes, without a `data:` URL prefix. Example: `{ "filename": "report.pdf", "content_base64": "JVBERi0x...", "mime_type": "application/pdf" }`. Attachments are assembled in memory and passed to PHPMailer as string attachments; local filesystem paths are not accepted. The admin delivery test can also attach a file to verify that the recipient receives it. The test sends a real email.

Attachment limits are set in `.env`. Sending limits count messages by domain and are persisted in `storage/limits/`; they are shared across requests on one server. Rate-limit storage should also persist across deploys.

SMTP, Resend, and SES API calls are bounded by `SMTP_TIMEOUT_SECONDS` (default 15 seconds, configurable from 5 to 60). SES API uses HTTPS and AWS Signature Version 4, and submits a raw MIME message so HTML, text alternatives, and attachments are preserved. If delivery fails, the MCP tool returns an error and the server log records the provider response without logging credentials.

Application errors are written as one JSON record per line to `storage/logs/mail-mcp-YYYY-MM-DD.log` (UTC). Each MCP response includes an `X-Request-ID`; provider and internal errors include that ID in the returned error so it can be matched to the log. The file is created with owner-only permissions. Make sure `storage/` is writable and retained across deploys. If the log file cannot be written, errors fall back to PHP's configured `error_log`, which Forge exposes in its server/site logs. Logs omit bearer tokens, passwords, email bodies, and attachment data.

## Security and operations

- Require HTTPS. The admin session cookie is Secure, HttpOnly, and SameSite=Lax.
- Create a strong unique admin password and keep the `APP_KEY` secret.
- Keep SQLite files, `.env`, Composer files, and source code outside the web root.
- The OAuth server supports public clients using authorization code plus PKCE S256. It does not use client secrets, and access tokens are opaque random values checked against the token table.
- Configure backups for the file set in `SQLITE_PATH`; it contains encrypted SMTP credentials, account hashes, registered clients, and active tokens.
