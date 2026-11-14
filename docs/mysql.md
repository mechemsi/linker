# Using MySQL

## Upgrading this repository from 8.0 to 8.4

The Compose database now defaults to MySQL 8.4, pinned to the multi-platform
Docker Hub digest for 8.4.13. Doctrine and the PHP image bootstrap URL use 8.4.
If overriding `MYSQL_VERSION`, also set `MYSQL_IMAGE_DIGEST` to that tag's digest;
changing the tag alone does not change the pinned image. Remove any old 8.0
version overrides for the 8.4 deployment. Update external `DATABASE_URL` values too.

This project uses Doctrine, not Prisma. There are currently no application
entities or PHP migrations, so no preparatory schema migration is justified by
repository code. That does not establish compatibility of a populated database.
Do not deploy the new image against an existing volume before completing stage 1.
If checks identify schema changes, ship those in a separate preparation PR on
8.0 before deploying this image change.

### Stage 1: prepare while still running 8.0

1. Record `SELECT VERSION()` and the current image digest. Bring the existing
   server to a current supported 8.0 patch first if needed. MySQL supports a
   direct 8.0 to 8.4 LTS upgrade; installing 8.1, 8.2 and 8.3 is unnecessary.
2. Take a full backup including accounts, grants, routines, triggers and events.
   Verify restoration into a separate 8.0 instance. Keep an untouched backup
   outside the database volume and record a recovery procedure before proceeding.
3. With MySQL Shell at least as new as the target server, connect as an
   administrator to the old server and run the upgrade checker. For this pin,
   use the following in MySQL Shell JavaScript mode, supplying the real server
   configuration path if configuration checks request it:

   ```javascript
   util.checkForServerUpgrade({user: 'root', host: '127.0.0.1', port: 3307},
       {targetVersion: '8.4.13', configPath: '/path/to/my.cnf'})
   ```

   Resolve every error and review all warnings, including removed server options
   such as `default_authentication_plugin`, reserved words and nonstandard foreign
   keys referencing partial or non-unique keys. Rebuild spatial indices as
   directed by the 8.4 upgrade guide if any exist. Do not disable foreign key
   checks to force the upgrade through.
4. In an administrator SQL session, inspect existing authentication plugins:

   ```sql
   SELECT User, Host, plugin FROM mysql.user;
   ```

   MySQL 8.4 disables `mysql_native_password` by default. For each account using
   it, including administrator, backup and application accounts, migrate on 8.0
   using its actual host and password, for example:

   ```sql
   ALTER USER 'app'@'%' IDENTIFIED WITH caching_sha2_password BY '<actual password>';
   ```

   Keep real passwords out of committed files and shell history. Test a fresh
   application connection and all other clients after each account change.
   PHP 8.4's existing `pdo_mysql` supports this plugin. Changing Compose password
   variables or init scripts does not update accounts in an existing volume.
5. Rehearse the complete upgrade on a restored copy with the same server config
   and this pinned image. Run `make test-all`, `make qa`, and an application
   smoke test. A clean database CI run does not test an existing-volume upgrade.

### Stage 2: upgrade during a maintenance window

Stop application traffic and writers. Take the final restorable backup. While
8.0 is still running, set `SET GLOBAL innodb_fast_shutdown = 0` in an administrator
session, then stop the database cleanly. Allow enough shutdown time for its data
size. Never use `docker compose down -v` or delete the database volume.

Deploy the 8.4 configuration and pull the pinned database image. Start only the
database first (`docker compose up -d database`). MySQL performs its own system
schema upgrade at startup; an ORM migration cannot substitute for this step.
Inspect `docker compose logs database` and confirm `SELECT VERSION()` returns
8.4.13 before starting PHP and restoring traffic. Verify an authenticated
application `SELECT 1`, notification requests, and database logs. Allow time for
the upgrade rather than treating `mysqladmin ping` alone as an application check.

Rollback requires restoring the pre-upgrade backup into a separate 8.0 volume
and using the recorded old image/configuration. Do not start MySQL 8.0 against a
volume already upgraded by 8.4. Writes accepted after the upgrade need a separate
recovery plan if rollback is required.

References reviewed:

- [8.4.0 release notes](https://dev.mysql.com/doc/relnotes/mysql/8.4/en/news-8-4-0.html)
- [Changes since 8.0](https://dev.mysql.com/doc/refman/8.4/en/mysql-nutshell.html)
- [Upgrade paths](https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html)
- [Upgrade prerequisites](https://dev.mysql.com/doc/refman/8.4/en/upgrade-prerequisites.html)
- [Incompatible changes](https://dev.mysql.com/doc/refman/8.4/en/upgrading-from-previous-series.html)
- [MySQL Shell upgrade checker](https://dev.mysql.com/doc/mysql-shell/8.4/en/mysql-shell-utilities-upgrade.html)

## Original Flex recipe setup

The Docker configuration of this repository is extensible thanks to Flex recipes.
By default, the recipe installs PostgreSQL.

If you prefer to work with MySQL, follow these steps:

First, install the `symfony/orm-pack` package as described:

```console
docker compose exec php composer req symfony/orm-pack
```

## Docker Configuration

Change the database image to use MySQL instead of PostgreSQL in `compose.yaml`:

<!-- markdownlint-disable MD013 -->

```diff
 ###> doctrine/doctrine-bundle ###
-    image: postgres:${POSTGRES_VERSION:-16}-alpine
+    image: mysql:${MYSQL_VERSION:-8.4}
     environment:
-      POSTGRES_DB: ${POSTGRES_DB:-app}
+      MYSQL_DATABASE: ${MYSQL_DATABASE:-app}
       # You should definitely change the password in production
+      MYSQL_RANDOM_ROOT_PASSWORD: "true"
-      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-!ChangeMe!}
+      MYSQL_PASSWORD: ${MYSQL_PASSWORD:-!ChangeMe!}
-      POSTGRES_USER: ${POSTGRES_USER:-app}
+      MYSQL_USER: ${MYSQL_USER:-app}
     healthcheck:
-      test: ["CMD", "pg_isready", "-d", "${POSTGRES_DB:-app}", "-U", "${POSTGRES_USER:-app}"]
+      test: ["CMD", "mysqladmin" ,"ping", "-h", "localhost"]
       timeout: 5s
       retries: 5
       start_period: 60s
     volumes:
-      - database_data:/var/lib/postgresql/data:rw
+      - database_data:/var/lib/mysql:rw
       # You may use a bind-mounted host directory instead, so that it is harder to accidentally remove the volume and lose all your data!
-      # - ./docker/db/data:/var/lib/postgresql/data:rw
+      # - ./docker/db/data:/var/lib/mysql:rw
 ###< doctrine/doctrine-bundle ###
```

<!-- markdownlint-enable MD013 -->

Depending on the database configuration,
modify the environment in the same file at `services.php.environment.DATABASE_URL`:

```yaml
DATABASE_URL: mysql://${MYSQL_USER:-app}:${MYSQL_PASSWORD:-!ChangeMe!}@database:3306/${MYSQL_DATABASE:-app}?serverVersion=${MYSQL_VERSION:-8.4}&charset=${MYSQL_CHARSET:-utf8mb4}
```

Since we changed the port, we also have to define this in the `compose.override.yaml`:

```diff
 ###> doctrine/doctrine-bundle ###
   database:
     ports:
-      - "5432"
+      - "3306"
 ###< doctrine/doctrine-bundle ###
```

Last but not least, we need to install the MySQL driver in `Dockerfile`:

```diff
 ###> doctrine/doctrine-bundle ###
-RUN install-php-extensions pdo_pgsql
+RUN install-php-extensions pdo_mysql
 ###< doctrine/doctrine-bundle ###
```

## Change Environment

Change the database configuration in `.env`:

```dotenv
DATABASE_URL=mysql://${MYSQL_USER:-app}:${MYSQL_PASSWORD:-!ChangeMe!}@database:3306/${MYSQL_DATABASE:-app}?serverVersion=${MYSQL_VERSION:-8.4}&charset=${MYSQL_CHARSET:-utf8mb4}
```

## Final steps

Rebuild the Docker environment:

```console
docker compose down --remove-orphans && docker compose build --pull --no-cache
```

Start the services:

```console
docker compose up --wait
```

Test your setup:

<!-- markdownlint-disable MD013 -->

```console
docker compose exec php bin/console dbal:run-sql -q "SELECT 1" && echo "OK" || echo "Connection is not working"
```

<!-- markdownlint-enable MD013 -->
