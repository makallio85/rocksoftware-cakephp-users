# Agent guide — rocksoftware-cakephp-users

A **CakePHP 5 plugin** (Composer package `makallio85/rocksoftware-cakephp-users`, formerly `makallio85/cakephp-sso`; public repository; CakePHP plugin `RockSoftware/Users`) that signs
users in through the central identity provider with OpenID Connect. It is installed by
every Rock Software CakePHP application, so a change here reaches all of them. The
container defined in `docker-compose.yml` is a development sandbox, not a deployment.

## Quick start

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

## Rules

1. **Never merge a PR.** Open PRs against `dev` only, never `master`.
2. **Security-critical code.** Token validation (`src/Service/OidcClient.php`), the
   callback's state/nonce/PKCE checks, and the redirect allowlist in
   `SignInController::safeRedirect()` each have tests that prove a forged or replayed
   response is refused. Never weaken a check to make a test pass; add a test for every
   new rejection path.
3. **No application knowledge.** The plugin establishes identity and provides the pieces
   every application needs around it (sign-in page with password sign-in, session check
   and limits, sign-in history, the users page, the user menu, the settings page). Installing stays three steps (require, `addPlugin`, migrate): the
   plugin wires its own middleware in `UsersPlugin::middleware()`, every setting has a
   default in `UsersPlugin::DEFAULTS`, and its data lives in its own tables
   (`rock_software_users_*`). **Of `users`, the plugin owns the people columns** —
   `email`, `name`, `phone`, `is_active`, `access_from`, `access_until` — which its
   migration adds to the application's existing table; everything else on `users` is the
   application's. Roles, permissions, audit logging and an application's own second
   factor stay in the application, reached through the events and `RockSoftwareUsers.settingsAccess`.
4. **Rock Software ID owns people.** They arrive through SCIM (`src/Service/Scim/`);
   the application manages only permissions. **Never delete a user row**: revoke access
   (`AccessManager`), so history and permissions survive and a returning person gets
   them back. Every way in (Rock Software ID, password, emergency link, the session
   check) asks `UserAccess`; add any new one there too. SCIM requests are tested over
   HTTP the way Authentik's client sends them (`ScimMiddlewareTest`).
5. **Texts are translatable.** Every user-facing string goes through `__d('rock_software_users', …)` and
   has a Finnish entry in `resources/locales/fi/rock_software_users.po` in the same change
   (`__dn` plurals as `msgid_plural` / `msgstr[0]` / `msgstr[1]`).
6. **Configuration**: the provider connection lives in `rock_software_users_settings` (secret encrypted
   by `SecretCipher`); application shape lives in `Configure::read('RockSoftwareUsers')`. Nothing in
   environment variables.
7. **Tests** run on SQLite against `tests/TestCase/FakeProvider.php`. Fixtures hold a
   fresh installation's rows only; `identity_source_types` rows come from the
   migration, and each test creates the users it needs. The test application has no
   sign-in wiring of its own, which is what proves the plugin needs none.
8. Do not commit `vendor/`, `composer.lock`, `tmp/` or `.phpunit.cache/`.
