# Email Two-Factor Authentication

The web panel can require a six-digit email code after a correct password.
This applies to administrators and regular users who have site access. API JWT
token issuance keeps its existing password-based behavior.

## Security behavior

- Codes are generated with `random_int`, stored only as password hashes, and expire after 10 minutes.
- A code is bound to the browser session that requested it.
- Five incorrect code attempts invalidate the challenge.
- Resending is available after 60 seconds and is limited to five messages per challenge.
- New challenge creation is limited per user and source IP.
- SMTP passwords are encrypted with libsodium before storage.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS requests.

## Required environment setting

Set a stable random key of at least 32 characters in `.env`:

```dotenv
SETTINGS_ENCRYPTION_KEY=replace-with-a-long-random-secret
```

If this variable is absent, the panel falls back to `JWT_SECRET`. A dedicated
key is recommended. Do not change the key after saving SMTP credentials unless
you are ready to enter the SMTP password again.

## Setup

1. Sign in as an administrator.
2. Open `Settings -> 2FA`.
3. Enter the SMTP host, port, encryption mode, login, password, sender address,
   and sender name.
4. Keep the enable checkbox off and save the settings.
5. Send a test email. The panel records which exact SMTP configuration passed.
6. Enable two-factor authentication and save again.
7. Sign out and verify a complete password-plus-code login.

For Mail.ru implicit TLS, use `smtp.mail.ru`, port `465`, and `SSL/TLS`.
IMAP and POP3 are not required for sending login codes.

## Existing installations

Apply migration `084_create_email_login_challenges.sql`:

```bash
set -a; source .env; set +a
docker compose exec -T db mysql \
  -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  < migrations/084_create_email_login_challenges.sql
docker compose exec web composer install --no-interaction --prefer-dist
```

## Recovery

If SMTP becomes unavailable and administrators cannot sign in, disable 2FA
from the database on the panel host:

```bash
set -a; source .env; set +a
docker compose exec -T db mysql \
  -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  -e "UPDATE settings SET value = JSON_SET(value, '$.enabled', false)
      WHERE user_id IS NULL AND namespace = 'security'
        AND \`key\` = 'email_two_factor';"
```

VPN client configurations continue working while web login is unavailable.
