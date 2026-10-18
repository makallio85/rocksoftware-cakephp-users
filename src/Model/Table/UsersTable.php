<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;

/**
 * The application's `users` table, whose people columns the plugin owns
 * (`email`, `name`, `phone`, `is_active`, `access_from`, `access_until`).
 * An application extends it in its own `UsersTable` to add associations and
 * behaviours; one without its own class gets this one.
 */
class UsersTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setPrimaryKey('id');
        $this->setDisplayField('name');
        if (!$this->hasBehavior('Timestamp')) {
            $this->addBehavior('Timestamp');
        }
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules The rules.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['email']));

        return $rules;
    }
}
