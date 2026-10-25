<?php
declare(strict_types=1);

namespace RockSoftware\Users\Model\Table;

use Cake\ORM\Table;
use RockSoftware\Users\Model\Entity\SignInMethodType;

class SignInMethodTypesTable extends Table
{
    /**
     * @var array<string, int>
     */
    private array $ids = [];

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rock_software_users_sign_in_method_types');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->setEntityClass(SignInMethodType::class);
    }

    /**
     * @param string $value One of the `SignInMethodType` value constants.
     * @return int
     */
    public function idFor(string $value): int
    {
        if (!isset($this->ids[$value])) {
            /** @var \RockSoftware\Users\Model\Entity\SignInMethodType $row */
            $row = $this->find()->where(['value' => $value])->firstOrFail();
            $this->ids[$value] = $row->id;
        }

        return $this->ids[$value];
    }
}
