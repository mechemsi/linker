# PHPUnit 13 upgrade

The supplied repository already declares `phpunit/phpunit` ^13.4.6 and locks 13.4.6. Readme already lists PHPUnit 13. Re-ran the scoped Composer update with --no-scripts; no package versions changed.

Reviewed the official [PHPUnit 13 upgrade guidance](https://phpunit.de/announcements/phpunit-13.html), [13.0 changelog](https://github.com/sebastianbergmann/phpunit/blob/13.0.0/ChangeLog-13.0.md), the 13.1 through 13.4 changelogs, and [current deprecations](https://github.com/sebastianbergmann/phpunit/blob/13.4.6/DEPRECATIONS.md).

## Compatibility

PHPUnit 13.4.6 requires PHP >=8.4.1. The project's >=8.4.17 requirement and PHP 8.4 Docker image already satisfy this. Reviewed all three PHPUnit test classes, the launcher, XML configuration, Composer scripts, and CI invocations. No removed assertions, attributes, extension APIs, or deprecated any() matchers are used. All with() expectations already use explicit invocation counts. No test or application changes were necessary. The existing expectExceptionMessage() call is only soft-deprecated and still works.

Compatible companion versions already present in the supplied lockfile (historical changes below are recorded by the existing report, not independently verified against a baseline):

- codeception/codeception 5.3.4 to 5.3.7, lib-asserts 3.1.0 to 3.2.0, lib-innerbrowser 4.0.8 to 4.1.1, lib-web 2.0.1 to 2.1.1, and stub 4.2.1 to 4.3.0: the old versions exclude PHPUnit 13.
- doctrine/deprecations is locked at 1.2.0 and permits PHPUnit 13.
- friendsofphp/php-cs-fixer 3.93.1 to 3.95.27: supports the sebastian/diff version required by PHPUnit 13. Also brings ergebnis/agent-detector and an updated symfony/polyfill-mbstring.
- PHPUnit's required phpunit/* and sebastian/* dependencies, myclabs/deep-copy, and nikic/php-parser were updated. theseer/tokenizer was removed from the resolved dependency graph.

Codeception module versions, Guzzle, Symfony components, and unrelated tools retain their previous locked versions.

## Verification

- `composer update phpunit/phpunit:13.4.6 --no-scripts --no-interaction --ignore-platform-req=php --minimal-changes`: passed, no version changes; installed locked dependencies. The PHP requirement was ignored for dependency installation only because this environment has PHP 8.2.35. No persistent platform override was added.
- composer validate --strict --no-check-publish: passed.
- phpunit.dist.xml validated against the installed PHPUnit 13.4.6 XSD: passed.
- composer phpcs: passed, all 20 files.
- composer test: blocked by PHPUnit's PHP >=8.4.1 requirement.
- composer codecept:unit and composer codecept:functional: blocked by Symfony Console syntax unsupported by PHP 8.2.
- composer phpstan: blocked by missing var directory and unavailable generated Symfony container cache.
- composer cs-check: blocked by dependency syntax unsupported by PHP 8.2.
- Docker builds, service-backed tests, and coverage were not verified here. CI must run these on PHP 8.4.

The verification update did not require --no-security-blocking. Composer reports 13 advisories affecting 2 packages. No security policy was changed. No baseline Git metadata is available in this workspace to independently compare the historical companion updates.
