# Linker - Project Instructions

## Docker Execution

All `composer`, `bin/console`, `php`, and `vendor/bin/*` commands MUST run via:

```bash
docker compose exec php <command>
```

Use `make` targets when available (e.g., `make test`, `make qa`, `make fix`).

## Testing

Create **unit tests** (PHPUnit) AND **functional tests** (Codeception) for every new feature.

```bash
make test          # PHPUnit only
make codecept      # Codeception only
make test-all      # Both PHPUnit + Codeception
```

Run `make test-all` after changes.

## Static Analysis

```bash
make qa            # PHPStan + PHPCS
make fix           # Auto-fix code style (PHPCBF + PHP-CS-Fixer)
```

Run `make qa` after changes. Run `make fix` first if there are style issues.

## Conventions

- PHP 8.4, `declare(strict_types=1)` in every file
- PSR-12 coding standard
- Symfony attributes for routes and DI
- Readonly DTOs in `src/Dto/`
- Type declarations on all parameters and return types

## Architecture

- **Link configs**: `config/links/` — one YAML file per link (filename = link name)
- **DTOs**: `src/Dto/` — readonly value objects
- **Exceptions**: `src/Exception/` — domain-specific exceptions
- **Services**: `src/Service/` — business logic
- **Controllers**: `src/Controller/` — HTTP layer

## Adding a New Link

Create a new YAML file in `config/links/<link-name>.yaml`:

```yaml
parameters:
    param_name:
        required: true
        type: string
        default: 'optional default'
message_template: 'Message with {param_name}'
channels:
    - transport: slack
    - transport: email
      options:
          to: 'recipient@example.com'
          subject: 'Subject with {param_name}'
```

Supported transports: `slack`, `telegram`, `discord`, `sms`, `email`.
