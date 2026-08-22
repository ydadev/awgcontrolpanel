# Settings secret storage

`SecretStore` provides a versioned authenticated-encryption envelope for secrets owned by portal settings. It currently covers OpenRouter API keys, LDAP bind passwords and all newly saved SMTP passwords.

## Compatibility contract

- reads accept legacy plaintext for OpenRouter and LDAP so code can be deployed before data migration;
- writes always create an `awgsec:v1` envelope;
- existing legacy SMTP ciphertext remains readable and is replaced with the new envelope on the next password save;
- ciphertext is bound to a purpose such as `api_key:openrouter` or `ldap:bind_password`, so values cannot be silently swapped between fields;
- malformed, tampered or unknown-key envelopes fail authentication;
- LDAP fails closed on secret errors while local login remains available.

Run status without exposing values:

```bash
php bin/settings-secrets.php status
```

After a verified backup and schema migration 094, encrypt legacy settings secrets:

```bash
php bin/settings-secrets.php migrate --confirm=encrypt-settings-secrets
```

The command uses a database advisory lock and transaction, reports counts only, and verifies that no plaintext, stale-key or unreadable settings secret remains.

## Key configuration and rotation

`SECRET_STORE_KEY` is optional. When empty, the existing stable `SETTINGS_ENCRYPTION_KEY` is used. Never use a placeholder and never rotate or remove the old key before rewrap completes.

To rotate:

1. assign a new `SECRET_STORE_ACTIVE_KEY_ID` and `SECRET_STORE_KEY`;
2. add the old material to `SECRET_STORE_PREVIOUS_KEYS` as `old_id=old_key`;
3. recreate the portal containers and run `status`;
4. run the confirmed migration and verify `rewrap=0 unreadable=0`;
5. take a fresh encrypted backup;
6. only then remove the old key entry.

This component does not yet encrypt VPN server/client material. Those fields require a separate dual-read rollout because premature credential conversion or rotation can disconnect the panel from managed nodes.
