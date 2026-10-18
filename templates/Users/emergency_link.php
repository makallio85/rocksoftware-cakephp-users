<?php
/**
 * A single-use emergency sign-in link: how long it stays valid, then the
 * link, shown this once.
 *
 * @var \Cake\View\View $this
 * @var \Cake\Datasource\EntityInterface $user
 * @var string|null $link
 * @var \Cake\I18n\DateTime|null $until
 * @var array<int> $choices
 */
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;

$name = UserProvisioner::displayName($user);
$this->assign('title', __d('rock_software_users', 'Emergency sign-in link for {0}', $name));
$back = ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'view', (int)$user->get('id')];
$label = fn(int $minutes): string => $minutes % 60 === 0
    ? __dn('rock_software_users', '{0} hour', '{0} hours', intdiv($minutes, 60), intdiv($minutes, 60))
    : __dn('rock_software_users', '{0} minute', '{0} minutes', $minutes, $minutes);
?>
<div class="rsu-emergency-link" style="max-width: 600px">
    <p class="mb-2"><?= $this->Html->link('‹ ' . $name, $back) ?></p>
    <section class="card border-0 shadow-sm">
        <div class="card-body d-flex flex-column gap-3">
            <h1 class="h5 fw-semibold mb-0"><?= __d('rock_software_users', 'Emergency sign-in link for {0}', $name) ?></h1>
            <p class="small text-body-secondary mb-0"><?= __d('rock_software_users', 'For when Rock Software ID is down. The link signs {0} in to this application once, without Rock Software ID.', $name) ?></p>
            <?php if ($link === null) : ?>
                <?= $this->Form->create(null) ?>
                <label class="form-label small text-body-secondary" for="rsu-link-minutes"><?= __d('rock_software_users', 'Valid for') ?></label>
                <div class="d-flex flex-wrap gap-2">
                    <select class="form-select" style="width: 200px" id="rsu-link-minutes" name="minutes">
                        <?php foreach ($choices as $minutes) : ?>
                            <option value="<?= $minutes ?>"<?= $minutes === 240 ? ' selected' : '' ?>><?= h($label($minutes)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $this->Form->button(__d('rock_software_users', 'Create link'), ['class' => 'btn btn-primary']) ?>
                </div>
                <?= $this->Form->end() ?>
            <?php else : ?>
                <div>
                    <label class="form-label small text-body-secondary" for="rsu-link"><?= __d('rock_software_users', 'Link') ?></label>
                    <input type="text" class="form-control font-monospace" id="rsu-link" value="<?= h($link) ?>" readonly autocomplete="off" spellcheck="false">
                </div>
                <p class="small text-body-secondary mb-0">
                    <?= __d(
                        'rock_software_users',
                        'Shown only now. Valid until {0}, for one sign-in. Give it to {1} yourself, by phone or in person. Issuing it is recorded in the access history.',
                        UserAccess::format($until),
                        $name,
                    ) ?>
                </p>
                <div><?= $this->Html->link(__d('rock_software_users', 'Done'), $back, ['class' => 'btn btn-primary']) ?></div>
            <?php endif; ?>
        </div>
    </section>
</div>
