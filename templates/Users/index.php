<?php
/**
 * The users page. Bootstrap 5 markup; an application can restyle it by
 * overriding `templates/plugin/RockSoftware/Users/Users/index.php`.
 *
 * @var \Cake\View\View $this
 * @var iterable<\Cake\Datasource\EntityInterface> $users
 * @var string $search
 * @var string $filter
 * @var array<string, string> $filters
 * @var array<int, array{state: string, linked: bool, conflict: string|null, held: bool, system: bool}> $statuses
 * @var array<int, \RockSoftware\Users\Model\Entity\SessionEvent> $lastSignIns
 * @var array<array{label: string, value: callable}> $columns
 * @var array<array{label: string, url: callable}> $actions
 * @var int $heldCount
 */
use RockSoftware\Users\Model\Entity\SignInMethodType;
use RockSoftware\Users\Service\UserProvisioner;

$this->assign('title', __d('rock_software_users', 'Users'));
$rows = [];
foreach ($users as $user) {
    $id = (int)$user->get('id');
    $last = $lastSignIns[$id] ?? null;
    $phone = $user->get(UserProvisioner::PHONE);
    $buttons = [];
    foreach ($actions as $action) {
        $url = ($action['url'])($user);
        if ($url !== null) {
            $buttons[] = $this->Html->link($action['label'], $url, ['class' => 'btn btn-sm btn-outline-secondary text-nowrap']);
        }
    }
    $rows[] = [
        'id' => $id,
        'user' => $user,
        'name' => UserProvisioner::displayName($user),
        'email' => (string)$user->get(UserProvisioner::EMAIL),
        'phone' => is_string($phone) && $phone !== '' ? $phone : null,
        'status' => $statuses[$id],
        'last' => $last === null ? __d('rock_software_users', 'Never') : $this->Time->format($last->created, 'd.M.yyyy HH.mm'),
        'method' => $last?->sign_in_method_type === null ? '' : SignInMethodType::label($last->sign_in_method_type->value),
        'extra' => array_map(fn(array $column): string => (string)($column['value'])($user), $columns),
        'buttons' => $buttons,
    ];
}
$viewUrl = fn(int $id): array => ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'view', $id];
?>
<div class="rsu-users" style="max-width: 1200px">
    <?php if ($heldCount > 0) : ?>
        <div class="alert d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3" role="alert" style="background:#fff1e5;border:1px solid #ffb77c;color:#1f2328">
            <div>
                <strong style="color:#953800"><?= __dn('rock_software_users', 'Removing access is on hold for {0} person', 'Removing access is on hold for {0} people', $heldCount, $heldCount) ?></strong>
                <div class="small text-body-secondary"><?= __d('rock_software_users', 'Rock Software ID asked to remove at least half of this application\'s users within 15 minutes. Until you decide, these people cannot start a new sign-in.') ?></div>
            </div>
            <?= $this->Html->link(
                __d('rock_software_users', 'Review'),
                ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'heldRevocations'],
                ['class' => 'btn fw-semibold', 'style' => 'border-color:#953800;color:#953800'],
            ) ?>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= __d('rock_software_users', 'Users') ?></h1>
            <p class="text-body-secondary mb-0"><?= __d('rock_software_users', 'People come from Rock Software ID. Here you set what they may do in this application.') ?></p>
        </div>
        <form method="get" action="<?= h($this->Url->build(['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'index'])) ?>" class="d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small text-body-secondary mb-1" for="rsu-users-q"><?= __d('rock_software_users', 'Search') ?></label>
                <input type="search" class="form-control" id="rsu-users-q" name="q" value="<?= h($search) ?>" placeholder="<?= h(__d('rock_software_users', 'Name, email or phone')) ?>">
            </div>
            <div>
                <label class="form-label small text-body-secondary mb-1" for="rsu-users-access"><?= __d('rock_software_users', 'Access') ?></label>
                <select class="form-select" id="rsu-users-access" name="access">
                    <?php foreach ($filters as $value => $label) : ?>
                        <option value="<?= h($value) ?>"<?= $filter === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline-secondary"><?= __d('rock_software_users', 'Filter') ?></button>
        </form>
    </div>

    <?php if ($rows === []) : ?>
        <p class="text-body-secondary"><?= __d('rock_software_users', 'No users match.') ?></p>
    <?php else : ?>
        <div class="card border-0 shadow-sm d-none d-md-block">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="small text-body-secondary">
                            <th scope="col"><?= __d('rock_software_users', 'Name') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Email · phone') ?></th>
                            <?php foreach ($columns as $column) : ?>
                                <th scope="col"><?= h($column['label']) ?></th>
                            <?php endforeach; ?>
                            <th scope="col"><?= __d('rock_software_users', 'Access') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Last signed in') ?></th>
                            <?php if ($actions !== []) : ?><th scope="col"><span class="visually-hidden"><?= __d('rock_software_users', 'Actions') ?></span></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?= $this->Html->link($row['name'], $viewUrl($row['id']), ['class' => 'fw-semibold']) ?></td>
                                <td>
                                    <div><?= h($row['email']) ?></div>
                                    <div class="small text-body-secondary"><?= $row['phone'] === null ? __d('rock_software_users', 'No phone') : h($row['phone']) ?></div>
                                </td>
                                <?php foreach ($row['extra'] as $value) : ?>
                                    <td><?= h($value) ?></td>
                                <?php endforeach; ?>
                                <td><?= $this->element('RockSoftware/Users.access_status', ['user' => $row['user'], 'status' => $row['status']]) ?></td>
                                <td><?= h($row['last']) ?><?php if ($row['method'] !== '') : ?><div class="small text-body-secondary"><?= h($row['method']) ?></div><?php endif; ?></td>
                                <?php if ($actions !== []) : ?><td class="text-end"><div class="d-inline-flex flex-wrap gap-1 justify-content-end"><?= implode('', $row['buttons']) ?></div></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <ul class="list-unstyled d-md-none d-flex flex-column gap-2 mb-0">
            <?php foreach ($rows as $row) : ?>
                <li>
                    <a class="card border shadow-sm text-reset text-decoration-none p-3 d-flex flex-column gap-1" href="<?= h($this->Url->build($viewUrl($row['id']))) ?>">
                        <span class="d-flex justify-content-between align-items-start gap-2">
                            <strong class="fw-semibold"><?= h($row['name']) ?></strong>
                            <span class="text-end"><?= $this->element('RockSoftware/Users.access_status', ['user' => $row['user'], 'status' => $row['status'], 'compact' => true]) ?></span>
                        </span>
                        <span class="small text-body-secondary"><?= h($row['email']) ?></span>
                        <?php foreach ($row['extra'] as $i => $value) : ?>
                            <span class="small"><?= h($columns[$i]['label']) ?>: <?= h($value) ?></span>
                        <?php endforeach; ?>
                        <span class="small text-body-secondary"><?= h($row['last']) ?><?= $row['method'] !== '' ? ' · ' . h($row['method']) : '' ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($this->Paginator->hasPage(2)) : ?>
            <nav class="mt-3" aria-label="<?= h(__d('rock_software_users', 'Pages')) ?>">
                <ul class="pagination flex-wrap mb-0">
                    <?= $this->Paginator->prev('‹') ?>
                    <?= $this->Paginator->numbers() ?>
                    <?= $this->Paginator->next('›') ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
