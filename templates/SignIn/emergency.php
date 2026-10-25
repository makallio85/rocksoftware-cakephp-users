<?php
/**
 * Confirmation for a single-use emergency sign-in link.
 *
 * @var \Cake\View\View $this
 */
$this->assign('title', __d('rock_software_users', 'Emergency sign-in'));
?>
<h1><?= __d('rock_software_users', 'Emergency sign-in') ?></h1>
<p><?= __d('rock_software_users', 'This link signs you in once, without Rock Software ID. Use it only when Rock Software ID is unavailable.') ?></p>
<?= $this->Form->create(null) ?>
<?= $this->Form->button(__d('rock_software_users', 'Sign in'), ['class' => 'rsu-button btn btn-primary']) ?>
<?= $this->Form->end() ?>
