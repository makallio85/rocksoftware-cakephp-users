<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\AccessChange;
use RockSoftware\Users\Model\Entity\AccessChangeType;

/**
 * The access history: who got and lost access to this application, when and
 * why. Rows are only ever added.
 *
 * @property \RockSoftware\Users\Model\Table\AccessChangeTypesTable $AccessChangeTypes
 * @property \RockSoftware\Users\Model\Table\RevocationTypesTable $RevocationTypes
 */
class AccessChangesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_access_changes');
        $this->setPrimaryKey('id');
        $this->setEntityClass(AccessChange::class);
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->belongsTo('AccessChangeTypes', [
            'className' => 'RockSoftware/Users.AccessChangeTypes',
            'foreignKey' => 'access_change_type_id',
        ]);
        $this->belongsTo('RevocationTypes', [
            'className' => 'RockSoftware/Users.RevocationTypes',
            'foreignKey' => 'revocation_type_id',
        ]);
    }

    /**
     * Writes one row.
     *
     * @param int $userId The user.
     * @param string $type One of the `AccessChangeType` value constants.
     * @param array{revocation?: string|null, actor_user_id?: int|null, detail?: string|null} $data What is known.
     * @return \RockSoftware\Users\Model\Entity\AccessChange
     */
    public function record(int $userId, string $type, array $data = []): AccessChange
    {
        $revocation = $data['revocation'] ?? null;
        $detail = $data['detail'] ?? null;
        $row = $this->newEmptyEntity();
        $values = [
            'user_id' => $userId,
            'access_change_type_id' => $this->AccessChangeTypes->idFor($type),
            'revocation_type_id' => $revocation === null ? null : $this->RevocationTypes->idFor($revocation),
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'detail' => is_string($detail) && $detail !== '' ? mb_substr($detail, 0, 255) : null,
        ];
        foreach ($values as $field => $value) {
            $row->set($field, $value, ['guard' => false]);
        }

        /** @var \RockSoftware\Users\Model\Entity\AccessChange */
        return $this->saveOrFail($row);
    }

    /**
     * A user's history, newest first.
     *
     * @param int $userId The user.
     * @param int $limit At most this many rows.
     * @return array<\RockSoftware\Users\Model\Entity\AccessChange>
     */
    public function forUser(int $userId, int $limit = 100): array
    {
        return $this->find()
            ->contain(['AccessChangeTypes', 'RevocationTypes'])
            ->where([$this->aliasField('user_id') => $userId])
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->limit($limit)
            ->all()
            ->toList();
    }

    /**
     * How many revocations took effect since a moment.
     *
     * @param \Cake\I18n\DateTime $since The start of the window.
     * @return int
     */
    public function revokedSince(DateTime $since): int
    {
        return $this->find()
            ->where([
                $this->aliasField('access_change_type_id') => $this->AccessChangeTypes
                    ->idFor(AccessChangeType::REVOKED),
                $this->aliasField('created') . ' >=' => $since,
            ])
            ->count();
    }
}
