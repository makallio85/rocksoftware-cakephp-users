<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service\Scim;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Model\Entity\ScimGroup;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\UserProvisioner;
use Throwable;

/**
 * The SCIM 2.0 endpoint Rock Software ID pushes people to,
 * `/auth/scim/v2` (`ScimMiddleware`). It answers what Authentik's SCIM client
 * calls: `ServiceProviderConfig`; `Users` (list with `userName eq` and
 * `externalId eq` filters, read, create, replace, delete); `Groups` (list with
 * `displayName eq`, read, create, replace, patch, delete). And one of its own,
 * `RockSoftwareAlerts`: what needs a person's attention here, which Rock
 * Software ID's hourly job reads and emails.
 *
 * The request carries the application's SCIM token, which Rock Software ID
 * delivered through `POST /auth/provision` and of which only a SHA-256 is
 * kept. No token stored means SCIM is not set up: every request is refused.
 */
class ScimServer
{
    use LocatorAwareTrait;

    public const MAX_RESULTS = 200;

    private AccessManager $access;

    /**
     * @param string $baseUrl The endpoint's own address, e.g. `https://app.example/auth/scim/v2`.
     * @param \RockSoftware\Users\Service\AccessManager|null $access Injected by tests.
     */
    public function __construct(private string $baseUrl, ?AccessManager $access = null)
    {
        $this->access = $access ?? new AccessManager();
    }

    /**
     * @param string $method The HTTP method.
     * @param string $path The path below `/auth/scim/v2`, e.g. `/Users/12`.
     * @param array<string, mixed> $query The query parameters.
     * @param string $body The raw body.
     * @param string $authorization The Authorization header.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function handle(
        string $method,
        string $path,
        array $query,
        string $body,
        string $authorization,
    ): ScimResponse {
        if (!$this->authorized($authorization)) {
            return ScimResponse::error(401, 'A valid SCIM token is required.');
        }
        $method = strtoupper($method);
        $payload = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return ScimResponse::error(400, 'The body is not a JSON object.', 'invalidSyntax');
            }
            $payload = $decoded;
        }

        try {
            return $this->route($method, rtrim($path, '/'), $query, $payload);
        } catch (Throwable $e) {
            Log::error(sprintf('SCIM %s %s failed: %s', $method, $path, get_class($e) . ': ' . $e->getMessage()));

            return ScimResponse::error(500, Configure::read('debug') === true
                ? sprintf('%s: %s', get_class($e), $e->getMessage())
                : 'The request could not be completed.');
        }
    }

    /**
     * @param string $method The HTTP method.
     * @param string $path The path.
     * @param array<string, mixed> $query The query.
     * @param array<string, mixed> $payload The decoded body.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    private function route(string $method, string $path, array $query, array $payload): ScimResponse
    {
        $users = new ScimUsers($this->access, $this->baseUrl);
        $groups = new ScimGroups($this->access, $this->baseUrl);

        if ($path === '/RockSoftwareAlerts') {
            return $method === 'GET' ? new ScimResponse(200, $this->alerts()) : $this->notAllowed();
        }
        if ($path === '/ServiceProviderConfig') {
            return $method === 'GET' ? new ScimResponse(200, $this->serviceProviderConfig()) : $this->notAllowed();
        }
        if ($path === '/Users') {
            return match ($method) {
                'GET' => $this->listUsers($users, $query),
                'POST' => $users->create($payload),
                default => $this->notAllowed(),
            };
        }
        if (preg_match('#^/Users/(\d+)$#', $path, $match)) {
            $user = $this->linkedUser((int)$match[1]);
            if ($user === null) {
                return ScimResponse::error(404, 'No such user.');
            }

            return match ($method) {
                'GET' => new ScimResponse(200, $users->resource($user)),
                'PUT' => $users->replace($user, $payload),
                'DELETE' => $users->delete($user),
                default => $this->notAllowed(),
            };
        }
        if ($path === '/Groups') {
            return match ($method) {
                'GET' => $this->listGroups($groups, $query),
                'POST' => $groups->create($payload),
                default => $this->notAllowed(),
            };
        }
        if (preg_match('#^/Groups/(\d+)$#', $path, $match)) {
            /** @var \RockSoftware\Users\Model\Entity\ScimGroup|null $group */
            $group = $this->fetchTable('RockSoftware/Users.ScimGroups')->find()
                ->where(['id' => (int)$match[1]])
                ->first();
            if (!$group instanceof ScimGroup) {
                return ScimResponse::error(404, 'No such group.');
            }

            return match ($method) {
                'GET' => new ScimResponse(200, $groups->resource($group)),
                'PUT' => $groups->replace($group, $payload),
                'PATCH' => $groups->patch($group, $payload),
                'DELETE' => $groups->delete($group),
                default => $this->notAllowed(),
            };
        }

        return ScimResponse::error(404, 'No such resource.');
    }

    /**
     * @param string $authorization The Authorization header.
     * @return bool
     */
    private function authorized(string $authorization): bool
    {
        if (!preg_match('/^Bearer\s+(\S+)$/', $authorization, $match)) {
            return false;
        }
        $setting = $this->fetchTable('RockSoftware/Users.Settings')->current();
        $hash = $setting?->get('scim_token_hash');
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return hash_equals($hash, hash('sha256', $match[1]));
    }

    /**
     * @param \RockSoftware\Users\Service\Scim\ScimUsers $users The user resource.
     * @param array<string, mixed> $query The query.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    private function listUsers(ScimUsers $users, array $query): ScimResponse
    {
        $provisioner = new UserProvisioner();
        $identities = $provisioner->identities();
        $filter = $this->filter($query, ['userName', 'externalId']);
        if ($filter === false) {
            return ScimResponse::error(
                400,
                'Only userName eq and externalId eq filters are supported.',
                'invalidFilter',
            );
        }
        $find = $identities->find();
        if ($filter !== null) {
            $find->where([$filter[0] === 'userName' ? 'user_name' : 'external_id' => $filter[1]]);
        }
        $system = UserProvisioner::systemUserIds();
        if ($system !== []) {
            $find->where(['user_id NOT IN' => $system]);
        }
        [$start, $count] = $this->page($query);
        $total = $find->count();
        $resources = [];
        foreach ((clone $find)->orderBy(['user_id' => 'ASC'])->limit($count)->offset($start - 1) as $identity) {
            /** @var \RockSoftware\Users\Model\Entity\Identity $identity */
            $user = $provisioner->findById($identity->user_id);
            if ($user !== null) {
                $resources[] = $users->resource($user);
            }
        }

        return new ScimResponse(200, $this->listResponse($resources, $total, $start));
    }

    /**
     * @param \RockSoftware\Users\Service\Scim\ScimGroups $groups The group resource.
     * @param array<string, mixed> $query The query.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    private function listGroups(ScimGroups $groups, array $query): ScimResponse
    {
        $filter = $this->filter($query, ['displayName', 'externalId']);
        if ($filter === false) {
            return ScimResponse::error(
                400,
                'Only displayName eq and externalId eq filters are supported.',
                'invalidFilter',
            );
        }
        $find = $this->fetchTable('RockSoftware/Users.ScimGroups')->find();
        if ($filter !== null) {
            $find->where([$filter[0] === 'displayName' ? 'display_name' : 'external_id' => $filter[1]]);
        }
        [$start, $count] = $this->page($query);
        $total = $find->count();
        $resources = [];
        foreach ((clone $find)->orderBy(['id' => 'ASC'])->limit($count)->offset($start - 1) as $group) {
            /** @var \RockSoftware\Users\Model\Entity\ScimGroup $group */
            $resources[] = $groups->resource($group);
        }

        return new ScimResponse(200, $this->listResponse($resources, $total, $start));
    }

    /**
     * A user Rock Software ID has pushed (linked), never a system user.
     *
     * @param int $id The user id.
     * @return \Cake\Datasource\EntityInterface|null
     */
    private function linkedUser(int $id): ?EntityInterface
    {
        if (in_array($id, UserProvisioner::systemUserIds(), true)) {
            return null;
        }
        $provisioner = new UserProvisioner();
        if ($provisioner->identities()->findForUser($id) === null) {
            return null;
        }

        return $provisioner->findById($id);
    }

    /**
     * @param array<string, mixed> $query The query.
     * @param array<string> $attributes The attributes a filter may name.
     * @return array{0: string, 1: string}|false|null The attribute and value; null for no filter.
     */
    private function filter(array $query, array $attributes): array|false|null
    {
        $filter = $query['filter'] ?? null;
        if ($filter === null || $filter === '') {
            return null;
        }
        if (!is_string($filter) || !preg_match('/^\s*(\w+)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $filter, $match)) {
            return false;
        }
        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute, $match[1]) === 0) {
                return [$attribute, stripcslashes($match[2])];
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $query The query.
     * @return array{0: int, 1: int} The 1-based start index and the page size.
     */
    private function page(array $query): array
    {
        $start = max(1, (int)($query['startIndex'] ?? 1));
        $count = (int)($query['count'] ?? self::MAX_RESULTS);

        return [$start, max(0, min(self::MAX_RESULTS, $count))];
    }

    /**
     * @param array<array<string, mixed>> $resources The page.
     * @param int $total All matches.
     * @param int $start The 1-based start index.
     * @return array<string, mixed>
     */
    private function listResponse(array $resources, int $total, int $start): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'itemsPerPage' => count($resources),
            'startIndex' => $start,
            'Resources' => $resources,
        ];
    }

    /**
     * What needs a person's attention here, for Rock Software ID's hourly job
     * to email: removals the mass-removal stop holds, and current email
     * conflicts. An `id` stays the same while the alert lasts, so the job
     * emails each once.
     *
     * @return array<string, mixed>
     */
    private function alerts(): array
    {
        $provisioner = new UserProvisioner();
        $resources = [];
        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable $held */
        $held = $this->fetchTable('RockSoftware/Users.HeldRevocations');
        foreach ($held->pending() as $row) {
            $user = $provisioner->findById($row->user_id);
            $resources[] = [
                'id' => 'revocation_held-' . $row->id,
                'type' => 'revocation_held',
                'email' => $user === null ? null : (string)$user->get(UserProvisioner::EMAIL),
                'name' => $user === null ? null : UserProvisioner::displayName($user),
                'since' => $row->created->toIso8601String(),
                'detail' => sprintf(
                    'Removing access is on hold (%s). Approve or reject it on the users page.',
                    RevocationType::label((string)$row->revocation_type?->value),
                ),
            ];
        }
        $conflicts = $provisioner->identities()->find()->where(['pending_email IS NOT' => null]);
        foreach ($conflicts as $identity) {
            /** @var \RockSoftware\Users\Model\Entity\Identity $identity */
            $pending = $identity->currentEmailConflict();
            $user = $pending === null ? null : $provisioner->findById($identity->user_id);
            if ($pending === null || $user === null) {
                continue;
            }
            $resources[] = [
                'id' => 'email_conflict-' . $identity->user_id . '-' . sha1($pending),
                'type' => 'email_conflict',
                'email' => (string)$user->get(UserProvisioner::EMAIL),
                'name' => UserProvisioner::displayName($user),
                'since' => $identity->synced_at?->toIso8601String(),
                'detail' => sprintf(
                    'Rock Software ID sent the address %s, which another user here already has.',
                    $pending,
                ),
            ];
        }

        return [
            'schemas' => ['urn:rocksoftware:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => count($resources),
            'Resources' => $resources,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProviderConfig(): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => self::MAX_RESULTS],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Bearer token',
                'description' => 'The SCIM token Rock Software ID delivered with the connection.',
            ]],
            'meta' => [
                'resourceType' => 'ServiceProviderConfig',
                'location' => $this->baseUrl . '/ServiceProviderConfig',
            ],
        ];
    }

    /**
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    private function notAllowed(): ScimResponse
    {
        return ScimResponse::error(405, 'This method is not supported here.');
    }
}
