# Mail MCP

Plain PHP MCP server with an admin console, per-domain SMTP settings, rate limits, attachments, and a built-in OAuth authorization server for ChatGPT.

## Deploy on Laravel Forge

1. Create a Forge site with PHP 8.2+ and the PDO SQLite, Sodium, and Fileinfo extensions enabled; point its web root to this repository's `public/` directory.
2. Deploy the code and run `composer install --no-dev --optimize-autoloader`.
3. Copy `.env.example` to `.env`. Set `MCP_BASE_URL=https://mailmcp.on-forge.com`, set `SQLITE_PATH` to a writable absolute path in your Forge site's shared directory (for example `/home/forge/mailmcp.on-forge.com/shared/mail-mcp.sqlite`; adjust to the actual site path), and generate `APP_KEY` using the command shown in the file. Keep `APP_KEY` private and stable: SMTP passwords in the database are encrypted with it. Relative `SQLITE_PATH` values resolve from the project root; the default is `storage/mail-mcp.sqlite`.
4. Ensure PHP has PDO SQLite and Sodium enabled. Make the SQLite parent directory writable by the PHP-FPM user. Also keep `storage/` writable for sending limits and logs.
5. In Forge DNS, point `mailmcp.on-forge.com` to the server, enable HTTPS, and keep the site's document root at `public/`.
6. Open `https://mailmcp.on-forge.com/admin`. On first visit, create the administrator account. Save the password securely; it is stored as a password hash.
7. In the admin page, add each sending domain and its SMTP credentials. For AWS SES, use SES SMTP credentials and the regional SES SMTP endpoint, not AWS IAM access keys. Configure sender identities and production access in SES.
8. Create a site account for each team/site and assign only the sender domain(s) that account may use. Sign into ChatGPT's OAuth prompt with that account when connecting its MCP client.

Forge's standard Nginx front-controller rule (`try_files $uri $uri/ /index.php?$query_string;`) should route `/admin`, `/authorize`, OAuth metadata, and MCP requests to `public/index.php`. The repository root must not be web-accessible.

## Connect in ChatGPT

Create a custom MCP connection with:

- **Name:** Mail MCP
- **Description:** Send email using configured SMTP domains, including attachments.
- **Connection URL:** `https://mailmcp.on-forge.com/`
- **Authentication:** OAuth

The app provides OAuth authorization-server metadata, dynamic client registration, authorization-code flow with PKCE S256, login, an approval screen, and bearer access tokens. ChatGPT's client registration is saved in the admin panel. When ChatGPT opens the authorization page, sign in with the site account for the site/domain you want to connect and approve the displayed sender domains. Each token is tied to that account. The approval screen lets you switch accounts before granting access. Revoke a client from the admin panel to remove its access. The OAuth issuer is derived from `MCP_BASE_URL`; there are no separate OAuth provider credentials to copy into `.env`.

OAuth clients receive the `mail.send` scope. The admin page lets you set access-token lifetime (300 seconds to 30 days). Tokens are persisted as hashes in SQLite. There is no refresh-token grant; ChatGPT can run the authorization flow again when a token expires.

## Admin panel

At `/admin`, the administrator can:

- Add, update, or remove SMTP settings by sender domain.
- Send a real test email for a selected SMTP domain, optionally with a file attached, and inspect the delivery result. Test messages count against that domain's rate caps.
- Create/update site accounts and assign their allowed sending domains. Accounts can use only their assigned domains, even if they guess another `from` address in an MCP call. Removing a domain also removes it from every account.
- Set per-domain hourly and daily message limits, plus recipients per message.
- Set OAuth token lifetime and revoke dynamically registered OAuth clients.
- Change the admin password.

SMTP passwords are encrypted in SQLite using `APP_KEY` and Sodium secretbox. Back up the database and `.env` together. If `APP_KEY` is lost or changed, saved SMTP passwords cannot be decrypted and must be entered again. For an existing deployment, copy `storage/mail-mcp.sqlite` into the shared folder before changing `SQLITE_PATH`; pause PHP requests during the copy so SQLite writes are not missed. Keep the shared database file across releases.

Existing installs are migrated automatically when the updated code first runs. Existing administrator credentials are copied into the account login table. Assign sender domains to the administrator and create site accounts in the admin page. Existing OAuth access tokens are not associated with a site account and must be authorized again; reconnect ChatGPT using the intended site account.

## MCP tool

The MCP endpoint is the site root and exposes `send_email`. The sender's domain selects the matching SMTP account. Arguments: `from`, `to` (array), `subject`, and either `text` or `html`. Add both to send a multipart email with plain-text and HTML alternatives. HTML may use normal email markup such as paragraphs, headings, lists, links, and emphasis. Optional `cc`, `bcc`, and `reply_to` are supported.

Optional `attachments` is an array of objects with `filename`, `content_base64`, and optional `mime_type`. `content_base64` is the raw base64 encoding of the file bytes, without a `data:` URL prefix. Example: `{ "filename": "report.pdf", "content_base64": "JVBERi0x...", "mime_type": "application/pdf" }`. Attachments are assembled in memory and passed to PHPMailer as string attachments; local filesystem paths are not accepted. The admin SMTP test can also attach a file to verify that the recipient receives it. The test sends a real email.

Attachment limits are set in `.env`. Sending limits count messages by domain and are persisted in `storage/limits/`; they are shared across requests on one server. Rate-limit storage should also persist across deploys.

SMTP operations are bounded by `SMTP_TIMEOUT_SECONDS` (default 15 seconds, configurable from 5 to 60). If delivery fails, the MCP tool returns an error and the server error log records the SMTP response without logging credentials.

Application errors are written as one JSON record per line to `storage/logs/mail-mcp-YYYY-MM-DD.log` (UTC). Each MCP response includes an `X-Request-ID`; SMTP and internal errors include that ID in the returned error so it can be matched to the log. The file is created with owner-only permissions. Make sure `storage/` is writable and retained across deploys. If the log file cannot be written, errors fall back to PHP's configured `error_log`, which Forge exposes in its server/site logs. Logs omit bearer tokens, passwords, email bodies, and attachment data.

## Security and operations

- Require HTTPS. The admin session cookie is Secure, HttpOnly, and SameSite=Lax.
- Create a strong unique admin password and keep the `APP_KEY` secret.
- Keep SQLite files, `.env`, Composer files, and source code outside the web root.
- The OAuth server supports public clients using authorization code plus PKCE S256. It does not use client secrets, and access tokens are opaque random values checked against the token table.
- Configure backups for the file set in `SQLITE_PATH`; it contains encrypted SMTP credentials, account hashes, registered clients, and active tokens.
