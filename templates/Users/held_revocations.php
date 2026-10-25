<?php
/**
 * The removals the mass-removal stop held, with the decision for all of them.
 *
 * @var \Cake\View\View $this
 * @var array<array{held: \RockSoftware\Users\Model\Entity\HeldRevocation, user: \Cake\Datasource\EntityInterface|null}> $rows
 * @var int $activeCount
 */
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Service\UserProvisioner;

$this->assign('title', __d('rock_software_users', 'Removing access is on hold'));
$format = fn($time): string => $this->Time->format($time, 'd.M.yyyy HH.mm');
?>
<div class="rsu-held" style="max-width: 1000px">
    <p class="mb-2"><?= $this->Html->link('‹ ' . __d('rock_software_users', 'Users'), ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'index']) ?></p>
    <h1 class="h4 mb-1"><?= __d('rock_software_users', 'Removing access is on hold') ?></h1>
    <?php if ($rows === []) : ?>
        <p class="text-body-secondary"><?= __d('rock_software_users', 'No removal is on hold.') ?></p>
    <?php else : ?>
        <p class="text-body-secondary mb-3" style="max-width: 760px">
            <?= __dn(
                'rock_software_users',
                'Rock Software ID asked to remove access for {0} person while this application has {1} people with access. That is usually a mistake in a group, so the removal waits for you. Until you decide, nobody on this list can start a new sign-in.',
                'Rock Software ID asked to remove access for {0} people while this application has {1} people with access. That is usually a mistake in a group, so these removals wait for you. Until you decide, nobody on this list can start a new sign-in.',
                count($rows),
                count($rows),
                $activeCount,
            ) ?>
        </p>
        <div class="card border-0 shadow-sm mb-3">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="small text-body-secondary">
                            <th scope="col"><?= __d('rock_software_users', 'Name') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Email address') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Asked at') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Why') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td class="fw-semibold"><?= $row['user'] === null ? '#' . (int)$row['held']->user_id : h(UserProvisioner::displayName($row['user'])) ?></td>
                                <td><?= $row['user'] === null ? '' : h((string)$row['user']->get(UserProvisioner::EMAIL)) ?></td>
                                <td class="text-nowrap"><?= h($format($row['held']->created)) ?></td>
                                <td><?= h(RevocationType::label((string)$row['held']->revocation_type?->value)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column align-items-start gap-2">
                        <h2 class="h6 fw-semibold mb-0"><?= __d('rock_software_users', 'It was a mistake') ?></h2>
                        <p class="small text-body-secondary mb-1"><?= __d('rock_software_users', 'Fix the group in Rock Software ID first. The removals are dropped and these people keep their access.') ?></p>
                        <?= $this->Form->postButton(
                            __d('rock_software_users', 'Keep their access'),
                            ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'rejectRevocations'],
                            ['class' => 'btn btn-primary'],
                        ) ?>
                    </div>
                </section>
            </div>
            <div class="col-md-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column align-items-start gap-2">
                        <h2 class="h6 fw-semibold mb-0"><?= __d('rock_software_users', 'They really leave') ?></h2>
                        <p class="small text-body-secondary mb-1"><?= __d('rock_software_users', 'Their access is removed now and they are signed out. Their rows and permissions stay, for history and a possible return.') ?></p>
                        <?= $this->Form->postButton(
                            __d('rock_software_users', 'Remove their access'),
                            ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'approveRevocations'],
                            ['class' => 'btn btn-outline-danger', 'confirm' => __d('rock_software_users', 'Remove access for everyone on this list?')],
                        ) ?>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>
