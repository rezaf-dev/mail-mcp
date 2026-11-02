# Mail MCP

Plain PHP MCP server with an admin console, per-domain SMTP settings, rate limits, attachments, and a built-in OAuth authorization server for ChatGPT.

## Deploy on Laravel Forge

1. Create a Forge site with PHP 8.2+ and point its web root to this repository's `public/` directory.
2. Deploy the code and run `composer install --no-dev --optimize-autoloader`.
3. Copy `.env.example` to `.env`. Set `MCP_BASE_URL=https://mailmcp.on-forge.com` and generate `APP_KEY` using the command shown in the file. Keep that key private and stable: SMTP passwords in the database are encrypted with it.
4. Ensure PHP has PDO SQLite and Sodium enabled. Make `storage/` writable by the PHP-FPM user (for example, `chmod 750 storage`). Keep `storage/mail-mcp.sqlite` persistent across deploys.
5. In Forge DNS, point `mailmcp.on-forge.com` to the server, enable HTTPS, and keep the site's document root at `public/`.
6. Open `https://mailmcp.on-forge.com/admin`. On first visit, create the administrator account. Save the password securely; it is stored as a password hash.
7. In the admin page, add each sending domain and its SMTP credentials. For AWS SES, use SES SMTP credentials and the regional SES SMTP endpoint, not AWS IAM access keys. Configure sender identities and production access in SES.

Forge's standard Nginx front-controller rule (`try_files $uri $uri/ /index.php?$query_string;`) should route `/admin`, `/authorize`, OAuth metadata, and MCP requests to `public/index.php`. The repository root must not be web-accessible.

## Connect in ChatGPT

Create a custom MCP connection with:

- **Name:** Mail MCP
- **Description:** Send email using configured SMTP domains, including attachments.
- **Connection URL:** `https://mailmcp.on-forge.com/`
- **Authentication:** OAuth

The app provides OAuth authorization-server metadata, dynamic client registration, authorization-code flow with PKCE S256, login, an approval screen, and bearer access tokens. ChatGPT's client registration is saved in the admin panel. When ChatGPT opens the authorization page, sign in with the admin account and approve access. Revoke a client from the admin panel to remove its access. The OAuth issuer is derived from `MCP_BASE_URL`; there are no separate OAuth provider credentials to copy into `.env`.

OAuth clients receive the `mail.send` scope. The admin page lets you set access-token lifetime (300 seconds to 30 days). Tokens are persisted as hashes in SQLite. There is no refresh-token grant; ChatGPT can run the authorization flow again when a token expires.

## Admin panel

At `/admin`, the administrator can:

- Add, update, or remove SMTP settings by sender domain.
- Set per-domain hourly and daily message limits, plus recipients per message.
- Set OAuth token lifetime and revoke dynamically registered OAuth clients.
- Change the admin password.

SMTP passwords are encrypted in SQLite using `APP_KEY` and Sodium secretbox. Back up the database and `.env` together. If `APP_KEY` is lost or changed, saved SMTP passwords cannot be decrypted and must be entered again.

## MCP tool

The MCP endpoint is the site root and exposes `send_email`. The sender's domain selects the matching SMTP account. Arguments: `from`, `to` (array), `subject`, `text` or `html`, optional `cc`, `bcc`, `reply_to`, and optional `attachments` array. Each attachment has `filename`, `content_base64`, and optional `mime_type`.

Attachment limits are set in `.env`. Sending limits count messages by domain and are persisted in `storage/limits/`; they are shared across requests on one server. Rate-limit storage should also persist across deploys.

## Security and operations

- Require HTTPS. The admin session cookie is Secure, HttpOnly, and SameSite=Lax.
- Create a strong unique admin password and keep the `APP_KEY` secret.
- Keep SQLite files, `.env`, Composer files, and source code outside the web root.
- The OAuth server supports public clients using authorization code plus PKCE S256. It does not use client secrets, and access tokens are opaque random values checked against the token table.
- Configure backups for `storage/mail-mcp.sqlite`; the file contains encrypted SMTP credentials, admin hashes, registered clients, and active tokens.
