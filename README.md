# Agri E-commerce Backend

WordPress + WooCommerce backend for the Agri E-commerce mini program. The business logic lives in the custom plugin at `wp-content/plugins/myshop-core`.

## Requirements

- PHP 7.4+ (recommended 8.x)
- MySQL 5.7+ / MariaDB 10.3+
- WordPress 6.x
- WooCommerce 7.x+

## Local development (Laragon)

1. Place this repo under your Laragon `www` directory.
2. Create a database and configure `wp-config.php`.
3. Install WordPress normally, then activate WooCommerce.
4. Activate the `myshop-core` plugin.

## Key paths

- Plugin code: `wp-content/plugins/myshop-core`
- API controllers: `wp-content/plugins/myshop-core/api`

## Branches

- `dev`: active development
- `trial`: staging / pre-release validation
- `master`: production release

## Notes

- Do not commit secrets (API keys, tokens, private keys) into the repository.
- Configuration should be handled via environment or server-side settings.
