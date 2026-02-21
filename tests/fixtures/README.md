# Test Fixtures

Organized by application phase. Each fixture provides minimal input data to exercise the phase's logic.

## Directory Structure

```
fixtures/
├── config-loading/          # Phase 1: YAML parsing into LinkDefinition DTOs
│   ├── *.yaml               # Input YAML link configs
│   └── expected/*.json      # Expected parsed DTO structure
├── parameter-validation/    # Phase 2: Parameter validation & default resolution
│   └── *.json               # Input params + expected resolved output or errors
├── message-building/        # Phase 3: Template interpolation
│   └── *.json               # Template + params → expected message string
├── dispatch/                # Phase 4: Multi-channel notification dispatch
│   └── *.json               # Link definition + input → expected transports
└── http-layer/              # Phase 5: Controller request/response
    └── *.json               # HTTP method/uri/params → expected status + body
```

## Fixture Conventions

- **YAML files** are used for link configs (loaded by `LinkConfigLoader`)
- **JSON files** contain `description`, `input`, and `expected` fields
- Each fixture covers one scenario (happy path or specific edge case)
- Cross-references use relative paths (e.g., `"link_config": "config-loading/valid-full.yaml"`)
