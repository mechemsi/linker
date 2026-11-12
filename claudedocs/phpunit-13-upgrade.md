# PHPUnit 13 upgrade

Updated `phpunit/phpunit` from 12.5.8 to 13.4.6 in composer.lock and raised the manifest constraint to ^13.4.6. Updated the README testing version.

Reviewed the official [PHPUnit 13 upgrade guidance](https://phpunit.de/announcements/phpunit-13.html), [13.0 changelog](https://github.com/sebastianbergmann/phpunit/blob/13.0.0/ChangeLog-13.0.md), the 13.1 through 13.4 changelogs, and [current deprecations](https://github.com/sebastianbergmann/phpunit/blob/13.4.6/DEPRECATIONS.md).

## Compatibility

PHPUnit 13.4.6 requires PHP >=8.4.1. The project's >=8.4.17 requirement and PHP 8.4 Docker image already satisfy this. Reviewed all three PHPUnit test classes, the launcher, XML configuration, Composer scripts, and CI invocations. No removed assertions, attributes, extension APIs, or deprecated any() matchers are used. All with() expectations already use explicit invocation counts. No test or application changes were necessary. The existing expectExceptionMessage() call is only soft-deprecated and still works.

Required companion updates:

- codeception/codeception 5.3.4 to 5.3.7, lib-asserts 3.1.0 to 3.2.0, lib-innerbrowser 4.0.8 to 4.1.1, lib-web 2.0.1 to 2.1.1, and stub 4.2.1 to 4.3.0: the old versions exclude PHPUnit 13.
- doctrine/deprecations 1.1.5 to 1.1.6: removes its conflict with PHPUnit 13.
- friendsofphp/php-cs-fixer 3.93.1 to 3.95.27: supports the sebastian/diff version required by PHPUnit 13. Also brings ergebnis/agent-detector and an updated symfony/polyfill-mbstring.
- PHPUnit's required phpunit/* and sebastian/* dependencies, myclabs/deep-copy, and nikic/php-parser were updated. theseer/tokenizer was removed from the resolved dependency graph.

Codeception module versions, Guzzle, Symfony components, and unrelated tools retain their previous locked versions.

## Verification

- Composer update and install completed with --no-scripts. Used --ignore-platform-req=php because this environment only has PHP 8.2.35. No persistent platform override was added.
- composer validate --strict --no-check-publish: passed.
- phpunit.dist.xml validated against the installed PHPUnit 13.4.6 XSD: passed.
- composer phpcs: passed, all 20 files.
- composer test: blocked by PHPUnit's PHP >=8.4.1 requirement.
- composer codecept:unit and composer codecept:functional: blocked by missing .env.
- composer phpstan: blocked by missing var directory and unavailable generated Symfony container cache.
- composer cs-check: blocked by dependency syntax unsupported by PHP 8.2.
- Docker builds, service-backed tests, and coverage were not verified here. CI must run these on PHP 8.4.

Composer's advisory blocking prevented retaining existing unrelated locked dependencies during resolution. Used --no-security-blocking for the scoped update to preserve them, without changing repository security policy. Composer reports 44 advisories across 18 packages in the resulting graph; these reported dependency security issues remain outside this upgrade.
