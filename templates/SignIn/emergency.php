<?php
/**
 * Confirmation for a single-use emergency sign-in link. The link signs in on
 * POST only, so opening it (or a link preview fetching it) uses nothing up.
 * Rendered in the same sign-in card as the sign-in page.
 *
 * @var \Cake\View\View $this
 */
$this->assign('title', __d('rock_software_users', 'Emergency sign-in'));
?>
<h2 class="h6 mb-2"><?= __d('rock_software_users', 'Emergency sign-in') ?></h2>
<p class="text-body-secondary small mb-3">
    <?= __d('rock_software_users', 'This link signs you in once, without Rock Software ID. Use it only when Rock Software ID is unavailable.') ?>
</p>
<?= $this->Form->create(null) ?>
<div class="d-grid">
    <?= $this->Form->button(
        '<i class="bi bi-box-arrow-in-right me-1"></i> ' . h(__d('rock_software_users', 'Sign in')),
        ['class' => 'btn btn-primary btn-lg', 'escapeTitle' => false],
    ) ?>
</div>
<?= $this->Form->end() ?>
