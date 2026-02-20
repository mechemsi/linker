# Linker — Config-Driven Notification Router

Linker is a lightweight Symfony service that routes HTTP requests to multi-channel notifications using plain YAML configuration. Instead of writing code for every alerting integration, you define notification endpoints declaratively — specifying parameters, a message template, and target channels — and Linker handles validation, formatting, and dispatch to Slack, Telegram, Discord, SMS, and Email.

```text
GET /notify/server-alert?server=web1&status=down&message=disk+full
→ [down] Server web1: disk full → Slack + Telegram
```

### Features

- **Config-driven endpoints** — drop a YAML file in `config/links/` to create a new notification route; no code changes or redeployment required
- **Multi-channel dispatch** — fan out a single request to any combination of Slack, Telegram, Discord, SMS (Twilio), and Email
- **Parameter validation** — required/optional query parameters with type checking and defaults
- **Message templating** — `{placeholder}` interpolation in messages and email subjects
- **GET and POST support** — trigger notifications via either HTTP method
- **Structured JSON API** — consistent responses for success, missing links, invalid parameters, and transport failures

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

- [Docker](https://docs.docker.com/engine/install/) and [Docker Compose](https://docs.docker.com/compose/install/) v2.10+
- [Git](https://git-scm.com/)
- [Make](https://www.gnu.org/software/make/) (pre-installed on most Linux/macOS systems)

## Getting Started

### 1. Clone the repository

```bash
git clone <repository-url>
cd linker
```

### 2. Configure environment variables

Copy the example environment file and update it with your credentials:

```bash
cp .env.example .env.local
```

Edit `.env.local` and set at minimum:

```env
APP_SECRET=your-random-secret-string
```

Then configure the transports you plan to use (see [Transport DSNs](#transport-dsns) below). Transports left unconfigured default to `null://null` (messages are silently discarded).

### 3. Build and start

Run the first-time setup, which builds Docker images, starts containers, creates the database, and fixes file permissions:

```bash
make first-run
```

This is equivalent to running `make build`, `make up`, `make db-create`, `make db-test-create`, and `make perms` in sequence.

### 4. Verify the installation

```bash
# Check that containers are running
make ps

# Verify the notification route is registered
make routes

# Send a test request (requires a link definition in config/links/)
curl "http://localhost/notify/server-alert?server=web1&status=down&message=test"
```

### Subsequent starts

After the initial setup, start and stop the application with:

```bash
make up        # Start containers
make down      # Stop and remove containers
```

If port 80 is already in use, override the published port:

```bash
HTTP_PORT=8082 make up
```

## Configuration

### Environment Files

Linker uses the Symfony dotenv component. Files are loaded in this order (later files override earlier ones):

| File | Purpose | Committed? |
| ---- | ------- | ---------- |
| `.env.example` | Reference template with placeholder values | Yes |
| `.env.local` | **Your local overrides** — set real credentials here | No |
| `.env.test` | Test-environment defaults | Yes |
| `.env.test.local` | Local test overrides | No |

**Never commit secrets to `.env.example`.** Use `.env.local` for real credentials.

### Transport DSNs

Configure the transports you need in `.env.local`:

```env
# Slack — Bot token + channel
SLACK_DSN=slack://xoxb-your-token@default?channel=alerts

# Telegram — Bot token + chat ID
TELEGRAM_DSN=telegram://bot-token@default?channel=123456789

# Discord — Webhook token + ID
DISCORD_DSN=discord://token@default?webhook_id=123456

# SMS via Twilio — Account SID + Auth Token + sender number
TWILIO_DSN=twilio://SID:TOKEN@default?from=+1234567890

# Email via SMTP
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

Any transport left as `null://null` will silently discard messages, so you only need to configure the channels you actually use.

### Database

The database is configured automatically via `compose.yaml` environment variables. Default credentials:

| Variable | Default |
| -------- | ------- |
| `MYSQL_USER` | `app` |
| `MYSQL_PASSWORD` | `!ChangeMe!` |
| `MYSQL_DATABASE` | `app` |
| `MYSQL_PORT` | `3307` (host) → `3306` (container) |

To override, set these variables before running `make up`:

```bash
MYSQL_PASSWORD=my-secure-password make up
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

### Daily Workflow

All commands run inside Docker via `make` targets — you never need to install PHP or Composer on your host machine.

```bash
make up              # Start containers (HTTP mode on localhost)
make down            # Stop and remove containers
make stop            # Stop containers without removing them
make restart         # Restart containers (down + up)
make ps              # Show running containers
```

### Running Tests

Run the full test suite after every change:

```bash
make test-all        # Run everything (PHPUnit + Codeception)
make test            # PHPUnit unit tests only
make test-coverage   # PHPUnit with code coverage (requires Xdebug)
make codecept        # All Codeception tests
make codecept-functional  # Codeception functional tests only
make codecept-unit        # Codeception unit tests only
```

### Code Quality

Always run QA checks before committing:

```bash
make qa              # Static analysis (PHPStan level 6 + PHPCS PSR-12)
make fix             # Auto-fix style issues (PHPCBF + PHP-CS-Fixer)
make phpstan         # PHPStan only
make phpcs           # PHPCS check only
make lint            # All linters (PHPCS + PHP-CS-Fixer dry-run)
```

### Shell Access and Debugging

```bash
make sh              # Open a shell in the PHP container
make sh-root         # Open a root shell in the PHP container
make mysql           # Open MySQL CLI as app user
make mysql-root      # Open MySQL CLI as root
make logs            # Follow all container logs
make logs-php        # Follow PHP container logs only
make logs-db         # Follow database container logs only
```

### Dependency Management

```bash
make install                       # Install dependencies from lock file
make update                        # Update all dependencies
make require ARGS="vendor/pkg"     # Add a production dependency
make require-dev ARGS="vendor/pkg" # Add a dev dependency
```

### Database Management

```bash
make db-create       # Create the database (if not exists)
make db-drop         # Drop the database
make db-reset        # Drop, recreate, and run all migrations
make migrate         # Run pending migrations
make migrate-diff    # Generate a migration from entity changes
make migrate-status  # Show migration status
make schema-validate # Validate Doctrine schema against entities
make db-test-create  # Create the test database
```

### Symfony Console

```bash
make sf ARGS="..."   # Run any Symfony console command
make cc              # Clear Symfony cache
make routes          # List all registered routes
make about           # Show Symfony project info
```

### Code Generation

```bash
make entity ARGS="EntityName"         # Create a new Doctrine entity
make controller ARGS="ControllerName" # Create a new controller
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
