# Semantic versioning and rollback-safe migrations

## Versioning policy
- `MAJOR`: breaking API or behavior change.
- `MINOR`: backward-compatible feature additions.
- `PATCH`: backward-compatible bug fixes.

Plugin version is defined in `cargonizer-woocommerce.php` as `LP_CARGONIZER_VERSION`.

## Migration strategy
- Migrations are idempotent and additive only.
- Migration state stored in option `lp_cargonizer_db_version`.
- `MigrationManager` applies incremental migrations by comparing stored and target versions.
- Rollback safety: migrations avoid destructive updates and only backfill missing settings keys.

## Operational notes
- On downgrade, newer settings keys are ignored by previous versions.
- On re-upgrade, migration manager re-validates and backfills any missing defaults.
