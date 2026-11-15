# PHPUnit 13 upgrade

The supplied repository already declares `phpunit/phpunit` ^13.4.6 and locks 13.4.6. Readme already lists PHPUnit 13. Re-ran the scoped Composer update with --no-scripts; no package versions changed.

Reviewed the official [PHPUnit 13 upgrade guidance](https://phpunit.de/announcements/phpunit-13.html), [13.0 changelog](https://github.com/sebastianbergmann/phpunit/blob/13.0.0/ChangeLog-13.0.md), [13.1](https://github.com/sebastianbergmann/phpunit/blob/13.1.0/ChangeLog-13.1.md), [13.2](https://github.com/sebastianbergmann/phpunit/blob/13.2.0/ChangeLog-13.2.md), [13.3](https://github.com/sebastianbergmann/phpunit/blob/13.3.0/ChangeLog-13.3.md), and [13.4](https://github.com/sebastianbergmann/phpunit/blob/13.4.6/ChangeLog-13.4.md) changelogs, and [current deprecations](https://github.com/sebastianbergmann/phpunit/blob/13.4.6/DEPRECATIONS.md).

## Compatibility

PHPUnit 13.4.6 requires PHP >=8.4.1. The project's >=8.4.17 requirement and PHP 8.4 Docker image already satisfy this. Reviewed all three PHPUnit test classes, the launcher, XML configuration, Composer scripts, and CI invocations. No removed assertions, attributes, extension APIs, or deprecated any() matchers are used. All with() expectations already use explicit invocation counts. No test or application changes were necessary. The existing expectExceptionMessage() call is only soft-deprecated and still works.

The scoped update made no companion package changes. The supplied lockfile already contains Codeception 5.3.7 and PHP CS Fixer 3.95.27 alongside PHPUnit 13.4.6. There is no baseline Git metadata in this workspace, so historical dependency changes cannot be independently verified.

Reviewed the PHPUnit 13 removals, including `isType()`, `assertContainsOnly()`, `assertNotContainsOnly()`, `containsOnly()`, removed process-isolation attributes and extension APIs. None are used by project code. The mock argument expectations in `tests/Unit/Service/LinkNotificationServiceTest.php` already specify `once()` or `exactly()`. The launcher and CI use no removed CLI options.

## Verification

- `composer update phpunit/phpunit:13.4.6 --no-scripts --no-interaction --ignore-platform-req=php --minimal-changes`: passed, no version changes; installed locked dependencies. The PHP requirement was ignored for dependency installation only because this environment has PHP 8.2.35. No persistent platform override was added.
- composer validate --strict --no-check-publish: passed.
- phpunit.dist.xml validated against the installed PHPUnit 13.4.6 XSD: passed.
- composer phpcs: passed, all 20 files.
- composer test: blocked by PHPUnit's PHP >=8.4.1 requirement.
- composer codecept:unit and composer codecept:functional: blocked by Symfony Console syntax unsupported by PHP 8.2.
- composer phpstan: blocked by the missing `var` directory referenced in `excludePaths`; analysis did not start. The generated Symfony container cache is also unavailable.
- composer cs-check: blocked by dependency syntax unsupported by PHP 8.2.
- Docker builds, service-backed tests, and coverage were not verified here. CI must run these on PHP 8.4.

The verification update did not require --no-security-blocking. Composer reports 13 advisories affecting 2 packages. No security policy was changed. No baseline Git metadata is available in this workspace to independently compare the historical companion updates.
