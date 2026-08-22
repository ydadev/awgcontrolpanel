-- Encrypted LDAP secret envelopes can exceed the historical VARCHAR(255) limit.
ALTER TABLE ldap_configs
    MODIFY COLUMN bind_password TEXT NOT NULL;
