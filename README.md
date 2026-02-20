# Linker — Notification Routing Service

Linker turns HTTP requests into multi-channel notifications. Define endpoints with simple YAML files — no code changes, no redeployment. Each request validates parameters, formats a message from a template, and dispatches it to any combination of Slack, Telegram, Discord, SMS, and Email.

```text
GET /notify/server-alert?server=web1&status=down&message=disk+full
→ [down] Server web1: disk full → Slack + Telegram
```

### Features

- **Zero-code endpoints** — drop a YAML file in `config/links/` to create a new notification route
- **Multi-channel dispatch** — send to Slack, Telegram, Discord, SMS (Twilio), and Email from a single request
- **Parameter validation** — required/optional query parameters with type checking and defaults
- **Message templating** — `{placeholder}` interpolation in messages and email subjects
- **GET and POST support** — trigger notifications via either HTTP method
- **Structured error responses** — JSON errors for missing links, invalid parameters, and transport failures

## How It Works

Each notification endpoint is defined by a YAML file in `config/links/`. The filename becomes the URL slug. No code changes needed to add new endpoints.

```text
HTTP request → NotifyController → LinkConfigLoader (reads YAML)
                                → MessageBuilder (validates params, formats message)
                                → LinkNotificationService (dispatches to channels)
```

### Supported Transports

| Transport    | Provider                  | Config                              |
| ------------ | ------------------------- | ----------------------------------- |
| `slack`      | Slack API                 | `SLACK_DSN`                         |
| `telegram`   | Telegram Bot API          | `TELEGRAM_DSN`                      |
| `discord`    | Discord Webhooks          | `DISCORD_DSN`                       |
| `sms`        | Twilio                    | `TWILIO_DSN` + `to` option          |
| `email`      | SMTP / any Symfony mailer | `MAILER_DSN` + `to`/`subject` options |

## Requirements

- [Docker Compose](https://docs.docker.com/compose/install/) v2.10+

## Getting Started

```bash
# Build and start containers
make build
make up

# Verify the route is registered
make routes
```

If port 80 is already in use:

```bash
HTTP_PORT=8082 HTTPS_PORT=8443 make up
```

## Configuration

### Transport DSNs

Copy `.env` to `.env.local` and set real transport credentials:

```env
SLACK_DSN=slack://xoxb-your-token@default?channel=alerts
TELEGRAM_DSN=telegram://bot-token@default?channel=123456789
DISCORD_DSN=discord://token@default?webhook_id=123456
TWILIO_DSN=twilio://SID:TOKEN@default?from=+1234567890
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

### Link Definitions

Each file in `config/links/` defines one notification endpoint:

```yaml
# config/links/server-alert.yaml
# Accessible at: GET /notify/server-alert?server=web1&status=down

parameters:
    server:
        required: true
        type: string
    status:
        required: true
        type: string
    message:
        required: false
        type: string
        default: 'No details provided'

message_template: '[{status}] Server {server}: {message}'

channels:
    - transport: slack
    - transport: telegram
```

Adding a new endpoint = adding a new YAML file. No code changes, no deployment needed beyond dropping the file in.

### Link Definition Reference

```yaml
parameters:
    <name>:
        required: true|false       # is this query param mandatory?
        type: string               # param type
        default: 'fallback value'  # used when required: false and param not provided

message_template: 'Text with {name} placeholders'

channels:
    - transport: slack|telegram|discord|sms|email
      options:                     # required for sms and email
          to: 'recipient'
          subject: 'Subject with {name} placeholders'  # email only
```

## Usage Examples

```bash
# Server alert → Slack + Telegram
curl "http://localhost/notify/server-alert?server=web1&status=down&message=disk+full"

# Deploy notification → Discord + SMS + Email
curl "http://localhost/notify/deploy-notify?app=myapp&version=2.0"

# Optional params use defaults (environment defaults to "production")
curl "http://localhost/notify/deploy-notify?app=myapp&version=2.0&environment=staging"

# POST works too
curl -X POST "http://localhost/notify/server-alert?server=db1&status=up"
```

### Response Format

**Success (200)**:
```json
{
    "status": "ok",
    "link": "server-alert",
    "channels_notified": ["slack", "telegram"]
}
```

**Link not found (404)**:
```json
{
    "status": "error",
    "message": "Link \"unknown\" not found."
}
```

**Missing parameters (400)**:
```json
{
    "status": "error",
    "message": "Invalid parameters: Missing required parameter \"server\".",
    "errors": ["Missing required parameter \"server\"."]
}
```

## Development

### Makefile Targets

```bash
make up              # Start containers
make down            # Stop containers
make sh              # Shell into PHP container

make test            # PHPUnit tests
make codecept        # Codeception tests
make test-all        # Both

make qa              # PHPStan + PHPCS
make fix             # Auto-fix code style
```

### Project Structure

```text
config/links/            # Link YAML definitions (one file per endpoint)
src/
  Controller/            # HTTP layer
  Dto/                   # Readonly value objects (LinkDefinition, etc.)
  Exception/             # Domain exceptions
  Service/
    LinkConfigLoader     # Parses YAML files into DTOs
    MessageBuilder       # Validates params, interpolates templates
    LinkNotificationService  # Orchestrates dispatch to transports
tests/
  Unit/Service/          # PHPUnit tests for each service
  Functional/            # Codeception tests for HTTP endpoints
```

### Running Tests

All commands run inside Docker:

```bash
make test-all        # Run everything
make test            # PHPUnit only
make codecept        # Codeception only
```

### Code Quality

```bash
make fix             # Auto-fix style (PHPCBF + PHP-CS-Fixer)
make qa              # Static analysis (PHPStan level 6 + PHPCS PSR-12)
```

## Tech Stack

- PHP 8.4 / Symfony 8
- FrankenPHP + Caddy
- MySQL 8
- Symfony Notifier (Slack, Telegram, Discord, Twilio)
- Symfony Mailer
- PHPUnit 12 + Codeception 5
- PHPStan + PHPCS + PHP-CS-Fixer

## License

MIT
