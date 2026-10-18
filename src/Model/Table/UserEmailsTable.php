<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\UserEmail;

class UserEmailsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_user_emails');
        $this->setPrimaryKey('id');
        $this->setEntityClass(UserEmail::class);
        $this->addBehavior('Timestamp');
    }

    /**
     * Makes a user's other addresses exactly these.
     *
     * @param int $userId The user.
     * @param array<string, string|null> $emails Address => label.
     * @return bool Whether anything changed.
     */
    public function replaceFor(int $userId, array $emails): bool
    {
        $current = [];
        foreach ($this->find()->where(['user_id' => $userId]) as $row) {
            /** @var \RockSoftware\Users\Model\Entity\UserEmail $row */
            $current[$row->email] = $row;
        }
        $changed = false;
        foreach ($current as $email => $row) {
            if (!array_key_exists($email, $emails)) {
                $this->deleteOrFail($row);
                $changed = true;
            }
        }
        foreach ($emails as $email => $label) {
            $row = $current[$email] ?? $this->newEmptyEntity();
            if (isset($current[$email]) && $row->label === $label) {
                continue;
            }
            $row->set('user_id', $userId, ['guard' => false]);
            $row->set('email', $email, ['guard' => false]);
            $row->set('label', $label, ['guard' => false]);
            $this->saveOrFail($row);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Other addresses for these users.
     *
     * @param array<int> $userIds The users.
     * @return array<int, array<\RockSoftware\Users\Model\Entity\UserEmail>>
     */
    public function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $byUser = [];
        $rows = $this->find()
            ->where(['user_id IN' => $userIds])
            ->orderBy(['label' => 'ASC', 'email' => 'ASC']);
        foreach ($rows as $row) {
            /** @var \RockSoftware\Users\Model\Entity\UserEmail $row */
            $byUser[$row->user_id][] = $row;
        }

        return $byUser;
    }
}
