<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\SessionEvent;
use RockSoftware\Users\Model\Entity\SessionEventType;

/**
 * The sign-in history.
 *
 * @property \RockSoftware\Users\Model\Table\SessionEventTypesTable $SessionEventTypes
 * @property \RockSoftware\Users\Model\Table\SignInMethodTypesTable $SignInMethodTypes
 * @method \RockSoftware\Users\Model\Entity\SessionEvent newEmptyEntity()
 */
class SessionEventsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_session_events');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SessionEvent::class);
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->belongsTo('SessionEventTypes', [
            'className' => 'RockSoftware/Users.SessionEventTypes',
            'foreignKey' => 'session_event_type_id',
        ]);
        $this->belongsTo('SignInMethodTypes', [
            'className' => 'RockSoftware/Users.SignInMethodTypes',
            'foreignKey' => 'sign_in_method_type_id',
        ]);
    }

    /**
     * Writes one row.
     *
     * @param string $type One of the `SessionEventType` value constants.
     * @param array{user_id?: int|null, email?: string|null, method?: string|null, actor_user_id?: int|null, ip_address?: string|null, user_agent?: string|null} $data What is known.
     * @return \RockSoftware\Users\Model\Entity\SessionEvent
     */
    public function record(string $type, array $data = []): SessionEvent
    {
        $email = $data['email'] ?? null;
        $event = $this->newEmptyEntity();
        $values = [
            'user_id' => $data['user_id'] ?? null,
            'email' => is_string($email) && $email !== '' ? mb_strtolower(mb_substr($email, 0, 190)) : null,
            'session_event_type_id' => $this->SessionEventTypes->idFor($type),
            'sign_in_method_type_id' => isset($data['method'])
                ? $this->SignInMethodTypes->idFor((string)$data['method'])
                : null,
            'actor_user_id' => $data['actor_user_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ];
        // One field at a time: set() with an array is deprecated from 5.2.
        foreach ($values as $field => $value) {
            $event->set($field, $value, ['guard' => false]);
        }

        /** @var \RockSoftware\Users\Model\Entity\SessionEvent */
        return $this->saveOrFail($event);
    }

    /**
     * Refused password attempts in the last minutes, for one email address and
     * for one IP address.
     *
     * @param string $email The address typed.
     * @param string|null $ip The client's address.
     * @param int $minutes The window.
     * @return array{email: int, ip: int}
     */
    public function recentPasswordFailures(string $email, ?string $ip, int $minutes): array
    {
        $since = DateTime::now()->subMinutes($minutes);
        $failures = fn(): SelectQuery => $this->find()
            ->innerJoinWith('SessionEventTypes')
            ->where([
                'SessionEventTypes.value IN' => SessionEventType::PASSWORD_FAILURES,
                $this->aliasField('created') . ' >=' => $since,
            ]);

        return [
            'email' => $failures()->where([$this->aliasField('email') => mb_strtolower($email)])->count(),
            'ip' => $ip === null ? 0 : $failures()->where([$this->aliasField('ip_address') => $ip])->count(),
        ];
    }

    /**
     * Each user's latest sign-in, by user id.
     *
     * @param array<int> $userIds The users.
     * @return array<int, \RockSoftware\Users\Model\Entity\SessionEvent>
     */
    public function latestSignIns(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $latest = $this->find()
            ->select(['id' => $this->find()->func()->max($this->aliasField('id'))])
            ->innerJoinWith('SessionEventTypes')
            ->where([
                $this->aliasField('user_id') . ' IN' => $userIds,
                'SessionEventTypes.value' => SessionEventType::SIGNED_IN,
            ])
            ->groupBy([$this->aliasField('user_id')]);
        $rows = $this->find()
            ->contain(['SignInMethodTypes'])
            ->where([$this->aliasField('id') . ' IN' => $latest])
            ->all();

        $byUser = [];
        foreach ($rows as $row) {
            /** @var \RockSoftware\Users\Model\Entity\SessionEvent $row */
            $byUser[(int)$row->user_id] = $row;
        }

        return $byUser;
    }

    /**
     * One user's history, newest first.
     *
     * @param int $userId The user.
     * @param int $limit How many rows.
     * @return array<\RockSoftware\Users\Model\Entity\SessionEvent>
     */
    public function forUser(int $userId, int $limit = 100): array
    {
        /** @var array<\RockSoftware\Users\Model\Entity\SessionEvent> */
        return $this->find()
            ->contain(['SessionEventTypes', 'SignInMethodTypes'])
            ->where([$this->aliasField('user_id') => $userId])
            ->orderBy([$this->aliasField('created') => 'DESC', $this->aliasField('id') => 'DESC'])
            ->limit($limit)
            ->all()
            ->toList();
    }

    /**
     * When an administrator last ended the user's sessions, or null.
     *
     * @param int $userId The user.
     * @return \Cake\I18n\DateTime|null
     */
    public function sessionsEndedAt(int $userId): ?DateTime
    {
        /** @var \RockSoftware\Users\Model\Entity\SessionEvent|null $row */
        $row = $this->find()
            ->innerJoinWith('SessionEventTypes')
            ->where([
                $this->aliasField('user_id') => $userId,
                'SessionEventTypes.value' => SessionEventType::SESSIONS_ENDED,
            ])
            ->orderBy([$this->aliasField('id') => 'DESC'])
            ->first();

        return $row?->created;
    }

    /**
     * Deletes rows older than the retention period.
     *
     * @param int $days The retention period.
     * @return int Rows deleted.
     */
    public function prune(int $days): int
    {
        return $this->deleteAll(['created <' => DateTime::now()->subDays(max(1, $days))]);
    }
}
