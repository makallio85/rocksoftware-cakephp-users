<?php
declare(strict_types=1);

/**
 * `users` is a consuming application's table as it stands: nothing for the
 * plugin, and a password a provider-backed row never has.
 */
return [
    [
        'table' => 'users',
        'columns' => [
            'id' => ['type' => 'integer', 'null' => false],
            'email' => ['type' => 'string', 'length' => 190, 'null' => false],
            'password' => ['type' => 'string', 'length' => 255, 'null' => false],
            'name' => ['type' => 'string', 'length' => 100, 'null' => false],
            'is_active' => ['type' => 'boolean', 'null' => false, 'default' => true],
            'created' => ['type' => 'datetime', 'null' => false],
            'modified' => ['type' => 'datetime', 'null' => false],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
            'email' => ['type' => 'unique', 'columns' => ['email']],
        ],
    ],
];
