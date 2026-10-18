# rocksoftware-cakephp-users

CakePHP 5 plugin for everything about signing in to a Rock Software application. It
signs users in through the central Rock Software identity provider
(`id.rocksoftware.fi`, Authentik) with OpenID Connect, and provides password sign-in,
session limits, the sign-in history, the users page and the user menu. No application
handles sign-in itself.

Formerly `makallio85/cakephp-sso` (plugin `Sso`, routes `/sso/*`). 2.0 renamed
everything; an application moving over reconnects to Rock Software ID (below).

| | Name |
|---|---|
| Composer package | `makallio85/rocksoftware-cakephp-users` |
| CakePHP plugin, namespace | `RockSoftware/Users`, `RockSoftware\Users\` |
| Routes | `/auth/*` |
| Settings, events, session keys | `RockSoftwareUsers.*` |
| Tables | `rock_software_users_*` |
| Translation domain | `rock_software_users` |
| Commands | `bin/cake rock_software_users …` |

Rock Software ID owns a person's whole lifecycle: who they are, when their access
starts and ends, and which applications they may use. It pushes people to the
application through **SCIM** before their first day; the plugin keeps them in the
application's `users` table, whose people columns it owns, and refuses every way in
outside their access. **An application manages only permissions.** No user is ever
deleted: access is revoked, and the row, its history and its permissions stay.
Installing it is three steps and no code.

Design: [makallio85/rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5);
the lifecycle: [#33](https://github.com/makallio85/rocksoftware-cakephp-users/issues/33).

## What it does

| Route | Purpose |
|---|---|
| `GET /auth?redirect=/path` | The sign-in page: "Sign in with Rock Software ID", the email and password form while password sign-in is on, and the reason for a failed sign-in |
| `GET /auth/start?redirect=/path` | Starts the authorization code flow with PKCE |
| `POST /auth/password` | The sign-in page's email and password form |
| `GET /auth/callback` | Redirect URI registered at the provider |
| `GET\|POST /auth/sign-out` | Signs out here and at the provider |
| `POST /auth/backchannel-logout` | Back-channel logout URI registered at the provider |
| `GET\|POST /auth/emergency/{token}` | Single-use sign-in from `bin/cake rock_software_users emergency_sign_in` |
| `GET /auth/settings`, `POST /auth/settings/update`, `POST /auth/settings/test` | Settings › Identity provider: issuer, client ID, write-only secret, required group. Save checks the server first and stores nothing if it does not answer |
| `POST /auth/settings/password-sign-in`, `POST /auth/settings/sessions` | The password sign-in switch; the session limits and the sign-in history's retention |
| `GET /auth/users`, `GET /auth/users/{id}`, `POST …/end-sessions`, `GET\|POST …/emergency-link` | The users page, read-only: people, their access, access and sign-in history |
| `GET /auth/users/held-removals`, `POST …/approve`, `POST …/reject` | Removals the mass-removal stop held |
| `/auth/scim/v2/*` | SCIM 2.0: Rock Software ID pushes people and groups (bearer token) |

Loading the plugin wires it into the application: its middleware hook places the
back-channel logout before CSRF, provides session authentication when the application
has none, adds `SessionCheckMiddleware` (signs out a session whose user is gone or
without access now, or which started before a back-channel logout) and requires sign-in for
every route except the sign-in routes and `RockSoftwareUsers.publicPaths`. Every text has a Finnish
translation (`resources/locales/fi/rock_software_users.po`, domain `rock_software_users`).

- ID and logout tokens are verified against the provider's JWKS with `firebase/php-jwt`;
  only asymmetric algorithms are accepted. Issuer, audience, nonce, state and PKCE are
  checked by the plugin.
- The link between a user and the provider lives in the plugin's `rock_software_users_identities` table
  (`user_id`, `external_id`, `synced_at`, `last_login_at`, `sessions_valid_from`), so
  the application's `users` table needs no columns for it.
- People arrive through SCIM (below). A sign-in still finds a user through their
  identity, else links one on a **verified** email address, else creates one, for an
  application SCIM does not reach yet. Email and name are rewritten on every sign-in.
  A new row's NOT NULL `password` gets an unusable value.
- **Access** (`UserAccess`): `is_active` off, `access_from` in the future or
  `access_until` passed refuses Rock Software ID, password and emergency sign-in, and
  ends a running session on its next request.
- A required provider group (`rock_software_users_settings.required_group`) gates access.
- The connection lives in `rock_software_users_settings`; the client secret is encrypted with a key
  derived from `Security.salt`.
- After every `bin/cake migrations migrate` or `rollback`, the plugin empties the schema
  cache (`cacheMetadata`) of the application's connections, so a migration that changes a
  table (moving over drops `users.password`, for one) never leaves queries reading the old
  layout.

Authorization stays in the application. The plugin only establishes who the user is.

## Installing in an application

The application creates its `users` table (`id`, `email`) in its own migrations. The
plugin **adopts** it: its migration adds the columns it owns when they are missing —
`name`, `phone`, `is_active`, `access_from`, `access_until` — and a unique index on
`email`. It never drops or changes an application's column, ids stay, and it stops
before changing anything when two users share an address. The plugin ships
`RockSoftware\Users\Model\Table\UsersTable`; an application's own `UsersTable` may
extend it, and one without its own class gets it.

1. **Composer.** `composer require makallio85/rocksoftware-cakephp-users:^3.0` (Packagist; releases are
   tags on `master`).
2. **Plugin.** `$this->addPlugin(\RockSoftware\Users\UsersPlugin::class);` in `Application::bootstrap()`.
3. **Migrations.** The application's first, then the plugin's:
   `bin/cake migrations migrate && bin/cake migrations migrate -p RockSoftware/Users`
   (in the deploy entrypoint and in `tests/bootstrap.php`).

That is all. Then connect it to the provider (below) and sign in at `/auth`.

### Settings

Every setting has a default; override them under `RockSoftwareUsers` in `config/app.php`. None is a
secret.

| Setting | Default | |
|---|---|---|
| `appName` | `null` | Shown on the plugin's sign-in page |
| `signInRedirect` | `'/'` | Where a person lands after signing in |
| `publicPaths` | `[]` | `fnmatch` patterns reachable without signing in, e.g. `'/health'`, `'/public-api/*'` |
| `settingsAccess` | `null` | Who may open `/auth/settings`: `fn($identity, $request): bool`. Null means anyone signed in, who is already in the required group |
| `usersAccess` | `null` | Who may open `/auth/users`, the same kind of callable. Null means whoever may open the settings page |
| `layout` | `'RockSoftware/Users.sign_in'` | Layout of the sign-in and emergency pages; name the application's own to restyle them |
| `systemUsers` | `[]` | Ids of `users` rows the application runs jobs as (konekallio-erp's R2D2): SCIM never touches them |
| `alertWebhookUrl` | `null` | An https address that also receives every alert as JSON (an IT monitoring application) |
| `timezone` | `'Europe/Helsinki'` | The zone access dates and link expiry are shown in |
| `createUsers` | `true` | Create a user on first sign-in when none matches |
| `authentication` | `'auto'` | Provide authentication when the application has no `AuthenticationMiddleware`; `true`/`false` force it |
| `requireSignIn` | `'auto'` | Require sign-in everywhere when the plugin provides authentication; `true`/`false` force it |
| `signInUrl` | `'/auth'` | The sign-in page |
| `cacheConfig` | `'default'` | Cache for the provider's discovery document and keys |
| `passwordSignIn` | `false` | `true` when people may also sign in with a password from the users table: the sign-in page shows the form and `/auth/settings` the switch (see below) |
| `provisioning` | see below | `POST /auth/provision`; `false` switches it off |

An application with its own `AuthenticationMiddleware` keeps it, and keeps deciding which
of its pages are public; point its `unauthenticatedRedirect` at `/auth`.

### Optional

- **Menu.** Link to `/auth/settings` (`['plugin' => 'RockSoftware/Users', 'controller' => 'Settings',
  'action' => 'index']`) and `/auth/users` (`'controller' => 'Users'`). Their
  controllers extend the application's own `App\Controller\AppController`, so they
  render in the application's layout. Put the user menu in the layout (below).
- **Look.** Override `templates/plugin/RockSoftware/Users/SignIn/index.php` (sign-in page),
  `templates/plugin/RockSoftware/Users/Settings/index.php` or `templates/plugin/RockSoftware/Users/Users/*.php`
  (Bootstrap 5 markup by default).
- **Events.**

  | Event | Data | Typical use |
  |---|---|---|
  | `RockSoftwareUsers.beforeUserSave` | `user`, `claims`, `isNew` | Fill columns the plugin does not know |
  | `RockSoftwareUsers.afterSignIn` | `user`, `claims`, `method` | Audit log |
  | `RockSoftwareUsers.beforeSignOut` | `user` | Audit log |
  | `RockSoftwareUsers.backchannelLogout` | `user` | Audit log |
  | `RockSoftwareUsers.provisioned` | `issuer`, `clientId`, `requiredGroup` | Audit log; tell the administrators |
  | `RockSoftwareUsers.passwordSignInChanged` | `user`, `enabled` | Audit log |
  | `RockSoftwareUsers.sessionLimitsChanged` | `user`, `limits` | Audit log |
  | `RockSoftwareUsers.sessionsEnded` | `user`, `actor` | Audit log |
  | `RockSoftwareUsers.accessGranted` | `user`, `actorUserId` | A person got access (again) |
  | `RockSoftwareUsers.accessRevoked` | `user`, `revocation`, `actorUserId` | A person lost access, e.g. list their open work |
  | `RockSoftwareUsers.alertRaised` | `alert`, `message`, `user`, `count` | Send it on with the application's mailer |
  | `RockSoftwareUsers.userListColumns` | `columns` (`ArrayObject`), `users` | Add columns to the users page: `['label' => …, 'value' => fn($user): string]` |
  | `RockSoftwareUsers.userActions` | `actions` (`ArrayObject`) | A button per user on the list and the user's page: `['label' => …, 'url' => fn($user): string\|array\|null]`, e.g. *Manage permissions* |
  | `RockSoftwareUsers.userViewSections` | `sections` (`ArrayObject`), `user` | Add a section to a user's page: `['title' => …, 'element' => …, 'data' => [...]]`, rendered with the application's element and `$user` |

- **Own second factor.** Skip it when `\RockSoftware\Users\Session\SignInSession::isProviderSession($session)`
  is true: the provider already required a second factor. A plugin password session is
  not one; an application with its own second factor keeps its own password form.

### Moving to 3.0

1. `composer require makallio85/rocksoftware-cakephp-users:^3.0`.
2. Run the application's migrations **before** the plugin's (entrypoint and
   `tests/bootstrap.php`).
3. A column with another name than the plugin's (`full_name`, say) is the
   application's to rename in its own migration; `userModel` and `fields` are gone.
4. Listeners on `userDeactivated` / `userActivated` go: access changes in Rock
   Software ID. Listen to `accessGranted` / `accessRevoked` instead.
5. Profile pages show name, email and phone read-only: Rock Software ID owns them.
6. Emergency links are written to a file, never printed (below).
7. Deploy, press *Allow Rock Software ID to connect again*, then
   `new-id-app.sh --update --rotate-secret` to deliver the SCIM token.

### Moving from cakephp-sso 1.x

1. `composer remove makallio85/cakephp-sso && composer require makallio85/rocksoftware-cakephp-users:^2.0`.
2. `addPlugin('RockSoftware/Users')`; migrations `-p RockSoftware/Users`; settings move
   from `Sso` to `RockSoftwareUsers` (`loginUrl` → `signInUrl`, `loginRedirect` →
   `signInRedirect`); events and links per the table above.
3. Drop the old `sso_*` tables and `sso_phinxlog` when convenient; nothing reads them.
4. Deploy, then reconnect: `new-id-app.sh --update --rotate-secret` (it reads
   `/auth/client-metadata` and pushes to `/auth/provision`). Each person's account is
   linked again on their next sign-in by verified email address.

## Connecting to the provider

`new-id-app.sh` in infra-docs registers the application in Rock Software ID and pushes
the connection to `POST /auth/provision`, so nothing is typed into the application. The
design is in [rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5),
*Connecting applications automatically*.

The request carries the issuer, client ID, client secret and required group, and a
bearer token the provider issued through token exchange for that client. The plugin
accepts it only when:

- the application has no connection yet, or an administrator pressed *Allow Rock
  Software ID to connect again* on `/auth/settings` within the last hour; every
  provisioning closes it again;
- the issuer is directly under `provisioning.issuerPrefix`
  (`https://id.rocksoftware.fi/application/o/`), checked before anything is fetched,
  and the provider's keys are on the same host;
- the token is signed with that issuer's key, its `iss` and `aud` equal the issuer and
  client ID it delivers, it was issued to `provisioning.subject` (`onboarding`) at most
  `provisioning.maxAge` (300) seconds ago, and its `jti` was never used before.

Any refusal is a bare 401 (400 for a malformed request, 429 over
`provisioning.rateLimit` per `provisioning.rateWindow`), with the reason in the log and
never the request body. Each provisioning is kept in `rock_software_users_provisionings`.

By hand, register the application in Authentik's admin UI (the steps are in
[rocksoftware-identity#5](https://github.com/makallio85/rocksoftware-identity/issues/5)),
then enter the issuer, client ID, secret and required group on `/auth/settings`, or in
the application container:

```bash
bin/cake rock_software_users configure \
    --issuer https://id.rocksoftware.fi/application/o/<slug>/ \
    --client-id <client id> \
    --required-group <app>-staff \
    --enable
# the client secret is asked for interactively
```

## Password sign-in

With `passwordSignIn` set to `true`, the sign-in page shows an email and password form
under the Rock Software ID button, checked against the users table's password column
(`fields.password`) with CakePHP's default hasher, so existing passwords keep working;
an outdated hash is replaced on the next sign-in. Refused attempts are recorded in the
sign-in history and throttled from it: 5 per address or 20 per client address within
15 minutes. There is no self-service reset; an administrator sets a password with

```bash
bin/cake rock_software_users set_password person@example.com   # reads the password from standard input
```

or the person signs in through Rock Software ID.

Settings › Identity provider shows *Turn password sign-in off / on*. The switch is stored
in `rock_software_users_settings.password_sign_in_enabled`, with who changed it and when. Off hides the
form and ends open password sessions on their next request: the plugin's own, and any
session an application's own password form established. Provider sessions, emergency
links and token requests are untouched.

It is on until someone turns it off, and always on before the first connection. Turning
it **off** needs a session signed in through the provider (not a password session, not an
emergency link) and a connection that answers, so nobody locks themselves out; turning it
**on** works from any session allowed on the page.

An application that still has a password form of its own (while it moves over;
applications do not handle sign-in themselves) enforces the switch there:

```php
if (!$this->fetchTable('RockSoftware/Users.Settings')->passwordSignInAllowed()) {
    // hide the form, and refuse a password POST like a wrong password
}
```

## People from Rock Software ID (SCIM)

Rock Software ID's SCIM provider pushes the application's people and groups to
`/auth/scim/v2` with a bearer token it delivered through `/auth/provision` (only its
SHA-256 is kept; no token means every SCIM request is refused).

- **Created in advance.** A person is pushed when they are added in Rock Software ID,
  with `access_from` (their first day), so permissions can be set before they start.
  Matched by `externalId` (= the OIDC `sub`), else by email to an unlinked row; the
  SCIM `id` is `users.id`.
- **Kept current.** Name, company email, phone (E.164) and the access dates
  (`urn:rocksoftware:params:scim:schemas:extension:access:2.0:User`) come with every
  push. An address another user already has is refused (409) and shown as *Email
  conflict*.
- **Revoked, never deleted.** `active: false`, `DELETE`, and leaving the last of the
  application's groups (sent at once as a membership change) turn `is_active` off. A
  later `active: true`, or coming back into a group after leaving, gives the same row
  access again. `RockSoftwareUsers.systemUsers` are never touched.
- **Mass-removal stop.** When the removals within 15 minutes reach half of the
  application's users, they are held and an alert raised (`alertRaised`, and
  `alertWebhookUrl`) until a person approves or rejects them on the users page or with
  `bin/cake rock_software_users revocations [list|approve|reject]`. With one user, that
  user's own removal always waits.
- **The way back in.** `bin/cake rock_software_users restore_access <email>` gives one
  person access back from the server when a mistake took it. The next push overrules it.

Every change is in the access history (`rock_software_users_access_changes`, typed by
`rock_software_users_access_change_types` and `rock_software_users_revocation_types`).

## Users page and sign-in history

`/auth/users` is read-only: people come from Rock Software ID and the application sets
their permissions on its own pages. It lists name, email, phone, access (*Has access*,
*Starts …*, *Ended …*, *Access removed*, *Email conflict*, *Not in Rock Software ID*)
and the last sign-in, filtered by access (default: has access or starting), as cards at
phone width. A user's page shows their details, the access history and the sign-in
history (successful and refused sign-ins, sign-outs, back-channel logouts, with the
client's IP address), and offers:

- the application's buttons (`RockSoftwareUsers.userActions`), e.g. *Manage permissions*;
- **Emergency sign-in link**: a single-use link for this person, shown once, for when
  Rock Software ID is down; only for someone with access now;
- **End sessions**: every session of the user in this application ends on its next
  request.

Roles stay the application's, added to the page through
`RockSoftwareUsers.userListColumns`, `RockSoftwareUsers.userViewSections` and
`RockSoftwareUsers.userActions`.

The history lives in `rock_software_users_session_events` (types in `rock_software_users_session_event_types` and
`rock_software_users_sign_in_method_types`) and is kept for 180 days unless Settings › Identity provider
says otherwise.

## Sessions

Settings › Identity provider › Sessions sets the longest session (hours) and the idle
time after which a person is signed out (minutes). Both are off by default. They apply
to every session the plugin established.

## User menu

The signed-in person's menu, for the application's layout. A ready dropdown (no script,
colours from `--rsu-menu-*` CSS variables):

```php
<?= $this->element('RockSoftware/Users.user_menu') ?>
```

Or the same entries as data, to render in the application's own menu:

```php
$this->loadHelper('RockSoftware/Users.UserMenu');
foreach ($this->UserMenu->items() as $item) {
    // header (label, detail) · app (label, url, current) · link (label, url)
    // · sign_out (label, url, method = post: render with $this->Form->postLink())
}
```

The applications listed are the ones the person may open, from the provider's `apps`
claim (rocksoftware-identity's profile scope mapping), kept in the session at sign-in.
Nothing is listed after a password or emergency sign-in.

## Emergency sign-in

When the provider is unavailable, someone with a shell in the application container runs:

```bash
bin/cake rock_software_users emergency_sign_in person@example.com   # one person
bin/cake rock_software_users emergency_sign_in --all                # everyone with access now
```

**Links are never printed.** The command asks where to write them (or takes
`--file <path>`), creates the file with mode 0600 and never overwrites one. Each link
works once and expires after `--valid` (default `4h`, at most `24h`); issuing it is
recorded in the person's access history. People without access get none. The users
page makes one for one person too.

`--create` also creates the user when no one has the address, for an empty
development database such as a pull request preview. It is refused when the
application runs with `debug` off.

## For automation

| What | Use |
|---|---|
| `GET /auth/client-metadata` | The addresses the identity provider must register (callback, sign-out return paths from `signInUrl`, back-channel logout, provisioning) and the plugin version. Public, answered before the application's authentication; `new-id-app.sh` reads it |
| `bin/cake rock_software_users status` | The stored connection as JSON (configured, enabled, issuer, client ID, required group), never the secret |
| `bin/cake rock_software_users restore_access <email>` | Give one person access back from the server |
| `bin/cake rock_software_users revocations [list\|approve\|reject]` | The removals the mass-removal stop held |

## Development

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

Tests use SQLite and a fake provider (`tests/TestCase/FakeProvider.php`) served through
CakePHP's HTTP client mocks, so no live provider is needed.
