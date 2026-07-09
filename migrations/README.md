# Database Migrations

This directory contains SQL migration files that are automatically executed when the database container is first initialized.

## Execution Order

Migration files are executed in **alphabetical order** by MySQL's Docker entrypoint. Files are numbered to ensure correct execution sequence:

1. `001_init.sql` - Main database schema and tables
2. `002_translations_ru.sql` - Russian translations
3. `003_translations_es.sql` - Spanish translations
4. `004_translations_de.sql` - German translations
5. `005_translations_fr.sql` - French translations
6. `006_translations_zh.sql` - Chinese translations

## Adding New Migrations

When creating new migration files:

1. Use numerical prefix (e.g., `007_add_feature.sql`)
2. Ensure the number is higher than existing migrations
3. Use descriptive names
4. Always use `ON DUPLICATE KEY UPDATE` for INSERT statements to make migrations idempotent

## Manual Execution

To manually run migrations in an existing database:

```bash
# Single migration
docker compose exec db sh -lc 'mysql -uroot -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"' < migrations/001_init.sql

# All migrations in order
for file in migrations/*.sql; do
  echo "Executing $file..."
  docker compose exec -T db sh -lc 'mysql -uroot -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"' < "$file"
done
```

## Regenerating Translation Migrations

Regeneration commands should use credentials from `.env` and must not hard-code database passwords. Prefer exporting through an explicit one-off script stored outside Git if real translation data or service credentials are involved.
