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
Installing it is three steps and no code, beside the settings plugin every application loads.

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
| `POST /auth/settings/access-tokens` | The audience (and issuer) of access tokens for the bearer paths |
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

1. **Composer.** `composer require makallio85/rocksoftware-cakephp-users:^3.3` (Packagist; releases are
   tags on `dev`). It brings
   [`makallio85/rocksoftware-cakephp-settings`](https://github.com/makallio85/rocksoftware-cakephp-settings),
   which holds the application's name (`application.name`).
2. **Plugins.** In `Application::bootstrap()`, the settings plugin (installed as its README says) and
   this one:

   ```php
   $this->addPlugin(\RockSoftware\Settings\SettingsPlugin::class);
   $this->addPlugin(\RockSoftware\Users\UsersPlugin::class);
   ```
3. **Migrations.** The application's first, then the plugins':
   `bin/cake migrations migrate && bin/cake migrations migrate -p RockSoftware/Settings && bin/cake migrations migrate -p RockSoftware/Users`
   (in the deploy entrypoint and in `tests/bootstrap.php`).

That is all. Then connect it to the provider (below) and sign in at `/auth`.

### Settings

Every setting has a default; override them under `RockSoftwareUsers` in `config/app.php`. None is a
secret.

| Setting | Default | |
|---|---|---|
| `signInRedirect` | `'/'` | Where a person lands after signing in |
| `publicPaths` | `[]` | `fnmatch` patterns reachable without signing in, e.g. `'/health'`, `'/public-api/*'` |
| `settingsAccess` | `null` | Who may open `/auth/settings`: `fn($identity, $request): bool`. Null means anyone signed in, who is already in the required group |
| `usersAccess` | `null` | Who may open `/auth/users`, the same kind of callable. Null means whoever may open the settings page |
| `layout` | `null` | Layout of the sign-in and emergency pages; null picks one (see *Sign-in pages*) |
| `systemUsers` | `[]` | Ids of `users` rows the application runs jobs as (an application's system user): SCIM never touches them |
| `alertWebhookUrl` | `null` | An https address that also receives every alert as JSON (an IT monitoring application) |
| `timezone` | `null` | The zone moments are shown in (the user menu's sign-in time, access dates); null follows the application: `Ui.displayTimezone`, else `App.displayTimezone`, else Europe/Helsinki |
| `createUsers` | `true` | Create a user on first sign-in when none matches |
| `authentication` | `'auto'` | Provide authentication when the application has no `AuthenticationMiddleware`; `true`/`false` force it |
| `requireSignIn` | `'auto'` | Require sign-in everywhere when the plugin provides authentication; `true`/`false` force it |
| `signInUrl` | `'/auth'` | The sign-in page |
| `cacheConfig` | `'default'` | Cache for the provider's discovery document and keys |
| `passwordSignIn` | `false` | `true` when people may also sign in with a password from the users table: the sign-in page shows the form and `/auth/settings` the switch (see below) |
| `provisioning` | see below | `POST /auth/provision`; `false` switches it off |
| `bearer` | `['paths' => [], 'resourceMetadataUrl' => null, 'rateLimit' => 20, 'rateWindow' => 900, 'subjectRateLimit' => 600, 'subjectRateWindow' => 300]` | Access tokens on API and MCP paths (see *Bearer tokens*) |

An application with its own `AuthenticationMiddleware` keeps it, and keeps deciding which
of its pages are public; point its `unauthenticatedRedirect` at `/auth`.

### Optional

- **Menu.** Link to `/auth/settings` (`['plugin' => 'RockSoftware/Users', 'controller' => 'Settings',
  'action' => 'index']`) and `/auth/users` (`'controller' => 'Users'`). Their
  controllers extend the application's own `App\Controller\AppController`, so they
  render in the application's layout. Put the user menu in the layout (below).
- **Look.** The sign-in pages are the same in every application (see *Sign-in pages*);
  do not override them. `templates/plugin/RockSoftware/Users/Settings/index.php` and
  `templates/plugin/RockSoftware/Users/Users/*.php` can be overridden (Bootstrap 5 markup by default).
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
  | `RockSoftwareUsers.accessTokensChanged` | `user`, `audience`, `issuer` | Audit log |
  | `RockSoftwareUsers.bearerAccepted` | `user`, `clientId`, `scope`, `jti`, `subject`, `method`, `path`, `status` | Audit log of every request signed in with an access token |
  | `RockSoftwareUsers.bearerRefused` | `reason` (`missing_token`, `invalid_token`, `not_configured`, `provider_unavailable`, `token_revoked`, `unknown_user`, `not_in_group`, `no_access`, `rate_limited`, `subject_rate_limited`), `status`, `user`, `clientId`, `subject`, `jti`, `ipAddress`, `method`, `path` | Audit log of refused ones |
  | `RockSoftwareUsers.accessGranted` | `user`, `actorUserId` | A person got access (again) |
  | `RockSoftwareUsers.accessRevoked` | `user`, `revocation`, `actorUserId` | A person lost access, e.g. list their open work |
  | `RockSoftwareUsers.alertRaised` | `alert`, `message`, `user`, `count` | Send it on with the application's mailer |
  | `RockSoftwareUsers.userListColumns` | `columns` (`ArrayObject`), `users` | Add columns to the users page: `['label' => …, 'value' => fn($user): string]` |
  | `RockSoftwareUsers.userActions` | `actions` (`ArrayObject`) | A button per user on the list and the user's page: `['label' => …, 'url' => fn($user): string\|array\|null]`, e.g. *Manage permissions* |
  | `RockSoftwareUsers.userViewSections` | `sections` (`ArrayObject`), `user` | Add a section to a user's page: `['title' => …, 'element' => …, 'data' => [...]]`, rendered with the application's element and `$user` |

- **Own second factor.** Skip it when `\RockSoftware\Users\Session\SignInSession::isProviderSession($session)`
  is true: the provider already required a second factor. A plugin password session is
  not one; an application with its own second factor keeps its own password form.

### Moving to 3.3

The application's name comes from the settings plugin's `application.name`: the sign-in card's
heading, the page title and the alert webhook's `application` field. `RockSoftwareUsers.appName` is
no longer read; remove it and seed the name in a migration of the application's own with
`Settings::set('application.name', 'Example App')`. With the UI plugin, 3.3 needs its 2.0, which
reads the same setting (`composer.json` conflicts with UI 1.x).

### Moving to 3.0

1. `composer require makallio85/rocksoftware-cakephp-users:^3.2`.
2. Run the application's migrations **before** the plugin's (entrypoint and
   `tests/bootstrap.php`).
3. A column with another name than the plugin's (`full_name`, say) is the
   application's to rename in its own migration; `userModel` and `fields` are gone.
4. Listeners on `userDeactivated` / `userActivated` go: access changes in Rock
   Software ID. Listen to `accessGranted` / `accessRevoked` instead.
5. Profile pages show name, email and phone read-only: Rock Software ID owns them.
6. Emergency links are written to a file, never printed (below).
7. Deploy, press *Allow Rock Software ID to connect again*, then
   run the identity provider's registration step again with a new secret to deliver
   the SCIM token.

### Moving from cakephp-sso 1.x

1. `composer remove makallio85/cakephp-sso && composer require makallio85/rocksoftware-cakephp-users:^2.0`.
2. `addPlugin('RockSoftware/Users')`; migrations `-p RockSoftware/Users`; settings move
   from `Sso` to `RockSoftwareUsers` (`loginUrl` → `signInUrl`, `loginRedirect` →
   `signInRedirect`); events and links per the table above.
3. Drop the old `sso_*` tables and `sso_phinxlog` when convenient; nothing reads them.
4. Deploy, then reconnect: run the identity provider's registration step again with a
   new secret (it reads `/auth/client-metadata` and pushes to `/auth/provision`). Each
   person's account is linked again on their next sign-in by verified email address.

## Connecting to the provider

The identity provider's registration step registers the application in Rock Software ID
and pushes the connection to `POST /auth/provision`, so nothing is typed into the
application.

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

By hand, register the application in Authentik's admin UI as an OAuth2/OpenID
provider and application, then enter the issuer, client ID, secret and required group
on `/auth/settings`, or in the application container:

```bash
bin/cake rock_software_users configure \
    --issuer https://id.rocksoftware.fi/application/o/<slug>/ \
    --client-id <client id> \
    --required-group <app>-staff \
    --enable
# the client secret is asked for interactively
```

## Bearer tokens (API and MCP)

An API or MCP endpoint of the application can be signed in to with an access token
from Rock Software ID (`Authorization: Bearer …`) instead of a browser session, so a
tool such as Claude Code acts as the person who signed in to it. Three facts install it:

1. **Paths.** List the path prefixes in `config/app.php`; nothing is a bearer path by
   default:

   ```php
   'RockSoftwareUsers' => ['bearer' => [
       'paths' => ['/mcp'],                       // covers /mcp and /mcp/…
       'resourceMetadataUrl' => 'https://app.example/.well-known/oauth-protected-resource', // optional
   ]],
   ```
2. **Audience.** On Settings › Identity provider › *Access tokens*, enter the client ID
   the tokens are issued for (the endpoint's own client in Rock Software ID) and, when
   that client is another application in Rock Software ID than the sign-in one, its
   issuer (on the same host; it must answer before it is saved). Until an audience is
   set, every token is refused.
3. **CSRF.** A bearer request carries no session and so no CSRF token. Let the
   application's CSRF middleware skip exactly those requests:

   ```php
   ->add((new CsrfProtectionMiddleware())->skipCheckCallback(BearerTokenMiddleware::isBearerRequest(...)))
   ```

   (`RockSoftware\Users\Middleware\BearerTokenMiddleware`; combine it with an existing
   callback with `||`).

How a request on a bearer path is handled (`BearerTokenMiddleware`, placed right after
authentication):

- **With a session cookie** it is a browser's request and goes the usual way (session,
  CSRF); a token never mixes with a session. **Without one** it needs a token, and no
  session is started for it.
- The token is checked locally, never sent anywhere: signed with RS256 or ES256 by a key
  the issuer publishes (cached, fetched again once for an unknown key id, at most every
  30 seconds), with a key id; `iss` equal to the issuer; `aud` (a string or a list)
  containing the audience; `exp` and `iat` present; `exp`, `nbf` and `iat` with 60
  seconds of leeway. `none`, HMAC and other algorithms are refused, and so are ID
  tokens (a `nonce` without a `scope`), logout tokens and a `typ` other than `JWT` or
  `at+jwt`.
- Its `sub` must be a user linked to Rock Software ID (`external_id`), in the
  connection's required group (the token's `groups`), with access now (`UserAccess`),
  and the token must be issued after the user's sessions were last ended (back-channel
  logout, *End sessions*). Nobody is created from a token.
- **Let in:** the identity is in the application's identity attribute (`identity`, through
  its authentication service when it has one), like a signed-in session, plus
  `rockSoftwareUsers.bearer` (`clientId` from `azp`/`client_id`, `scope` as a list,
  `jti`, `subject`) and `rockSoftwareUsers.isViaAgent` = `true`.
- **Refused:** an empty body. 401 with `WWW-Authenticate: Bearer realm="<application
  name>"` for a missing token, plus `error="invalid_token"` for a refused one, plus
  `resource_metadata="…"` when configured; 403 for a valid token whose user has no
  access; 429 (`Retry-After`) to an invalid token once its client address sent
  `rateLimit` of them within `rateWindow` seconds (a valid token from that address
  still gets in), and to a valid one once its person made `subjectRateLimit` requests
  within `subjectRateWindow` seconds. When Rock Software ID cannot be reached, the
  refusal is not counted against the client.

**3.4 changes for every sign-in:** ID and logout tokens must carry a key id (`kid`;
firebase/php-jwt already required one), and the keys are fetched again only for an
unknown key id, at most every 30 seconds (before: after any failed check).

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
- **All addresses.** The address for this application's company is `users.email`;
  the person's other addresses (another company's, the account address) come along
  and are kept in `rock_software_users_user_emails`, shown on the users page and
  searched, so one person with two addresses is recognised as one.
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

**Alerts by email.** `GET /auth/scim/v2/RockSoftwareAlerts` (the same token) lists
what needs a person here: removals on hold and current email conflicts, each with an
id that stays while it lasts. Rock Software ID's hourly job reads it from every
application and emails each new alert once, so no application needs its own mail
set-up for them.

**Before switching SCIM on** for an application that already has users,
`bin/cake rock_software_users scim_precheck` (the people JSON from Rock Software ID on
stdin or `--file`) shows what SCIM
would do with each user, with the same matching rules, and what to fix first: rows
that would be duplicated, addresses taken by another linked user, linked users who
would lose access. It changes nothing.

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

## Sign-in pages

The sign-in page (`/auth`), the emergency sign-in page, the "sign-in is not configured
yet" notice and the sign-in errors (flash messages) are one set of Bootstrap 5 templates,
shown 1:1 in every application. An application keeps no copy of them.

They render in a sign-in card. `UsersPlugin::signInLayout()` picks its layout:

1. `RockSoftwareUsers.layout`, when the application names one;
2. the UI plugin's `auth` layout (`RockSoftware/Ui.auth`), when the application loads
   [`makallio85/rocksoftware-cakephp-ui`](https://github.com/makallio85/rocksoftware-cakephp-ui)
   2.0 or later (a Composer *suggest*, not a requirement). The card's brand mark comes from
   `Ui.brandIcon`;
3. otherwise the plugin's own `sign_in` layout: the same card in plain Bootstrap class
   names, styled in the layout itself.

Either card is headed by the settings plugin's `application.name`, with
`application.organization_name` — the organisation that runs the application — under it
when it is not empty. Both are on the settings page (Settings › Application).

No page runs a script, so a Content Security Policy without `'unsafe-inline'` scripts
holds. Texts are English and Finnish.

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
claim (a profile scope mapping in the identity provider), kept in the session at sign-in.
Nothing is listed after a password or emergency sign-in.

The header's "Signed in with … at HH.mm" is in the display time zone (the `timezone`
setting above), not in UTC.

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
| `GET /auth/client-metadata` | The addresses the identity provider must register (callback, sign-out return paths from `signInUrl`, back-channel logout, provisioning) and the plugin version. Public, answered before the application's authentication; the identity provider's registration step reads it |
| `bin/cake rock_software_users status` | The stored connection as JSON (configured, enabled, issuer, client ID, required group), never the secret |
| `bin/cake rock_software_users restore_access <email>` | Give one person access back from the server |
| `bin/cake rock_software_users revocations [list\|approve\|reject]` | The removals the mass-removal stop held |
| `bin/cake rock_software_users scim_precheck [--file people.json]` | What switching SCIM on would do; changes nothing |

## Development

```bash
composer install
vendor/bin/phpunit
composer cs-check
```

Tests use SQLite and a fake provider (`tests/TestCase/FakeProvider.php`) served through
CakePHP's HTTP client mocks, so no live provider is needed.
