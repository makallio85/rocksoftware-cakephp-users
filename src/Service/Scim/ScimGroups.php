<?php
declare(strict_types=1);

namespace RockSoftware\Users\Service\Scim;

use Cake\ORM\Locator\LocatorAwareTrait;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Model\Entity\ScimGroup;
use RockSoftware\Users\Model\Table\ScimGroupMembersTable;
use RockSoftware\Users\Model\Table\ScimGroupsTable;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * SCIM `/Groups`. Rock Software ID pushes the application's groups (its staff
 * group, the group's descendants and `rocksoftware-identity-admin`); they
 * grant nothing by themselves, but being in none of them any more is the
 * moment a person leaves the application: Rock Software ID sends that as a
 * membership change at once, and the `DELETE /Users` only with its next full
 * sync. So a person whose last membership goes loses access then
 * (`RevocationType::LEFT_GROUPS`), and one who comes back into a group gets it
 * again when that is how they lost it.
 *
 * The SCIM `id` is `rock_software_users_scim_groups.id`; a member's `value` is
 * the user's SCIM `id`, `users.id`.
 */
class ScimGroups
{
    use LocatorAwareTrait;

    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    /**
     * @param \RockSoftware\Users\Service\AccessManager $access Gives and takes access.
     * @param string $baseUrl The endpoint's own address, for `meta.location`.
     */
    public function __construct(private AccessManager $access, private string $baseUrl)
    {
    }

    /**
     * `POST /Groups`.
     *
     * @param array<string, mixed> $payload The SCIM group.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function create(array $payload): ScimResponse
    {
        $name = $payload['displayName'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return ScimResponse::error(400, 'displayName is required.', 'invalidValue');
        }
        $externalId = isset($payload['externalId']) && is_scalar($payload['externalId'])
            ? (string)$payload['externalId']
            : null;
        $groups = $this->groups();
        $group = $externalId === null ? null : $groups->find()->where(['external_id' => $externalId])->first();
        $group ??= $groups->newEmptyEntity();
        $group->set('display_name', mb_substr(trim($name), 0, 255), ['guard' => false]);
        $group->set('external_id', $externalId, ['guard' => false]);
        $groups->saveOrFail($group);
        if (array_key_exists('members', $payload)) {
            $this->setMembers($group, $this->memberIds($payload['members']));
        }

        return new ScimResponse(201, $this->resource($group));
    }

    /**
     * `PUT /Groups/{id}`. Members are replaced only when the payload carries
     * them: with PATCH supported, Rock Software ID sends them separately.
     *
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @param array<string, mixed> $payload The SCIM group.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function replace(ScimGroup $group, array $payload): ScimResponse
    {
        $this->setAttributes($group, $payload);
        if (array_key_exists('members', $payload)) {
            $this->setMembers($group, $this->memberIds($payload['members']));
        }

        return new ScimResponse(200, $this->resource($group));
    }

    /**
     * `PATCH /Groups/{id}`: `add`/`remove`/`replace` on `members`, and
     * `replace` of the group's attributes.
     *
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @param array<string, mixed> $payload The PatchOp request.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function patch(ScimGroup $group, array $payload): ScimResponse
    {
        $operations = $payload['Operations'] ?? $payload['operations'] ?? null;
        if (!is_array($operations)) {
            return ScimResponse::error(400, 'Operations is required.', 'invalidSyntax');
        }
        $members = $this->currentMemberIds($group);
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                return ScimResponse::error(400, 'Each operation is an object.', 'invalidSyntax');
            }
            $op = strtolower((string)($operation['op'] ?? ''));
            $path = isset($operation['path']) ? (string)$operation['path'] : null;
            $value = $operation['value'] ?? null;

            if ($path === null || $path === '') {
                if ($op !== 'replace' && $op !== 'add' || !is_array($value)) {
                    return ScimResponse::error(400, 'An operation without a path replaces attributes.', 'invalidPath');
                }
                $this->setAttributes($group, $value);
                if (array_key_exists('members', $value)) {
                    $members = $this->memberIds($value['members']);
                }
                continue;
            }
            if (in_array($path, ['displayName', 'externalId'], true) && $op === 'replace') {
                $this->setAttributes($group, [$path => $value]);
                continue;
            }
            if ($path === 'members') {
                $ids = $this->memberIds($value);
                if ($op === 'add') {
                    $members = array_values(array_unique([...$members, ...$ids]));
                } elseif ($op === 'replace') {
                    $members = $ids;
                } elseif ($op === 'remove') {
                    $members = $ids === [] ? [] : array_values(array_diff($members, $ids));
                } else {
                    return ScimResponse::error(400, sprintf('Unknown operation %s.', $op), 'invalidSyntax');
                }
                continue;
            }
            if ($op === 'remove' && preg_match('/^members\[value eq "([^"]+)"\]$/', $path, $match)) {
                $members = array_values(array_diff($members, [(int)$match[1]]));
                continue;
            }

            return ScimResponse::error(400, sprintf('Unsupported path %s.', $path), 'invalidPath');
        }
        $this->setMembers($group, $members);

        return new ScimResponse(200, $this->resource($group));
    }

    /**
     * `DELETE /Groups/{id}`: the group and its memberships go; members left in
     * no group lose access.
     *
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @return \RockSoftware\Users\Service\Scim\ScimResponse
     */
    public function delete(ScimGroup $group): ScimResponse
    {
        $this->setMembers($group, []);
        $this->groups()->deleteOrFail($group);

        return new ScimResponse(204);
    }

    /**
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @return array<string, mixed>
     */
    public function resource(ScimGroup $group): array
    {
        $resource = [
            'schemas' => [self::SCHEMA],
            'id' => (string)$group->id,
            'displayName' => $group->display_name,
            'members' => array_map(
                fn(int $id): array => ['value' => (string)$id],
                $this->currentMemberIds($group),
            ),
            'meta' => [
                'resourceType' => 'Group',
                'location' => $this->baseUrl . '/Groups/' . $group->id,
            ],
        ];
        if ($group->external_id !== null) {
            $resource['externalId'] = $group->external_id;
        }

        return $resource;
    }

    /**
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @param array<string, mixed> $values `displayName`, `externalId`.
     * @return void
     */
    private function setAttributes(ScimGroup $group, array $values): void
    {
        if (isset($values['displayName']) && is_string($values['displayName']) && trim($values['displayName']) !== '') {
            $group->set('display_name', mb_substr(trim($values['displayName']), 0, 255), ['guard' => false]);
        }
        if (array_key_exists('externalId', $values)) {
            $group->set('external_id', is_scalar($values['externalId']) ? (string)$values['externalId'] : null, [
                'guard' => false,
            ]);
        }
        if ($group->isDirty()) {
            $this->groups()->saveOrFail($group);
        }
    }

    /**
     * Makes the group's members exactly these users, and gives or takes access
     * for the people the change concerns.
     *
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @param array<int> $userIds The users.
     * @return void
     */
    private function setMembers(ScimGroup $group, array $userIds): void
    {
        $provisioner = new UserProvisioner();
        $known = [];
        foreach ($userIds as $id) {
            if ($provisioner->findById($id) !== null) {
                $known[] = $id;
            }
        }
        $current = $this->currentMemberIds($group);
        $added = array_values(array_diff($known, $current));
        $removed = array_values(array_diff($current, $known));
        if ($added === [] && $removed === []) {
            return;
        }

        $members = $this->members();
        $members->getConnection()->transactional(function () use ($members, $group, $added, $removed): void {
            if ($removed !== []) {
                $members->deleteAll(['scim_group_id' => $group->id, 'user_id IN' => $removed]);
            }
            foreach ($added as $id) {
                $member = $members->newEmptyEntity();
                $member->set('scim_group_id', $group->id, ['guard' => false]);
                $member->set('user_id', $id, ['guard' => false]);
                $members->saveOrFail($member);
            }
        });

        foreach ($removed as $id) {
            $user = $provisioner->findById($id);
            if ($user !== null && !$members->isMember($id)) {
                $this->access->requestRevocation($user, RevocationType::LEFT_GROUPS);
            }
        }
        foreach ($added as $id) {
            $user = $provisioner->findById($id);
            if ($user !== null && $this->lostAccessByLeaving($id)) {
                $this->access->grant($user);
            }
        }
    }

    /**
     * Whether the user's access is gone, or held, because they left the
     * application's groups: coming back into one gives it back. A person
     * deactivated in Rock Software ID stays without access whatever their
     * groups.
     *
     * @param int $userId The user.
     * @return bool
     */
    private function lostAccessByLeaving(int $userId): bool
    {
        $held = $this->fetchTable('RockSoftware/Users.HeldRevocations')->pendingFor($userId);
        if ($held !== null) {
            return $held->revocation_type?->value === RevocationType::LEFT_GROUPS;
        }
        $user = (new UserProvisioner())->findById($userId);
        if ($user === null || (bool)$user->get(UserProvisioner::IS_ACTIVE)) {
            return false;
        }
        /** @var \RockSoftware\Users\Model\Table\AccessChangesTable $changes */
        $changes = $this->fetchTable('RockSoftware/Users.AccessChanges');
        $last = $changes->find()
            ->contain(['RevocationTypes'])
            ->where([
                $changes->aliasField('user_id') => $userId,
                $changes->aliasField('access_change_type_id') => $changes->AccessChangeTypes
                    ->idFor(AccessChangeType::REVOKED),
            ])
            ->orderBy([$changes->aliasField('created') => 'DESC', $changes->aliasField('id') => 'DESC'])
            ->first();

        return $last?->revocation_type?->value === RevocationType::LEFT_GROUPS;
    }

    /**
     * @param \RockSoftware\Users\Model\Entity\ScimGroup $group The group.
     * @return array<int>
     */
    private function currentMemberIds(ScimGroup $group): array
    {
        return array_map('intval', $this->members()->find()
            ->select(['user_id'])
            ->where(['scim_group_id' => $group->id])
            ->orderBy(['user_id' => 'ASC'])
            ->all()
            ->extract('user_id')
            ->toList());
    }

    /**
     * @param mixed $members The SCIM `members` (or a PATCH value).
     * @return array<int>
     */
    private function memberIds(mixed $members): array
    {
        if (!is_array($members)) {
            return [];
        }
        if (isset($members['value'])) {
            $members = [$members];
        }
        $ids = [];
        foreach ($members as $member) {
            $value = is_array($member) ? ($member['value'] ?? null) : null;
            if (is_scalar($value) && ctype_digit((string)$value)) {
                $ids[] = (int)$value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return \RockSoftware\Users\Model\Table\ScimGroupsTable
     */
    private function groups(): ScimGroupsTable
    {
        /** @var \RockSoftware\Users\Model\Table\ScimGroupsTable */
        return $this->fetchTable('RockSoftware/Users.ScimGroups');
    }

    /**
     * @return \RockSoftware\Users\Model\Table\ScimGroupMembersTable
     */
    private function members(): ScimGroupMembersTable
    {
        /** @var \RockSoftware\Users\Model\Table\ScimGroupMembersTable */
        return $this->fetchTable('RockSoftware/Users.ScimGroupMembers');
    }
}
