<?php
/**
 * The sign-in page: Rock Software ID, and the email and password form while
 * password sign-in is on. A failed or refused sign-in comes back here with
 * its reason as a flash message, which the layout renders. An application
 * gives it its look by overriding this template at
 * `templates/plugin/RockSoftware/Users/SignIn/index.php`, or by naming its own layout in
 * `RockSoftwareUsers.layout`.
 *
 * @var \Cake\View\View $this
 * @var bool $providerEnabled
 * @var bool $passwordSignIn
 * @var string|null $redirect
 * @var string $email
 * @var array<string, mixed> $signInUrl
 */
$this->assign('title', __d('rock_software_users', 'Sign in'));
?>
<?php if ($providerEnabled) : ?>
    <?= $this->Html->link(
        __d('rock_software_users', 'Sign in with Rock Software ID'),
        $signInUrl,
        ['class' => 'rsu-button btn btn-primary btn-lg w-100'],
    ) ?>
<?php endif; ?>
<?php if ($passwordSignIn) : ?>
    <?php if ($providerEnabled) : ?>
        <p class="rsu-divider"><span><?= __d('rock_software_users', 'or with your email address and password') ?></span></p>
    <?php endif; ?>
    <?= $this->Form->create(null, [
        'url' => ['plugin' => 'RockSoftware/Users', 'controller' => 'SignIn', 'action' => 'password', 'prefix' => false],
        'class' => 'rsu-password-form',
    ]) ?>
    <?= $this->Form->hidden('redirect', ['value' => (string)$redirect]) ?>
    <?= $this->Form->control('email', [
        'type' => 'email',
        'label' => __d('rock_software_users', 'Email address'),
        'value' => $email,
        'required' => true,
        'autocomplete' => 'username',
        'class' => 'form-control',
    ]) ?>
    <?= $this->Form->control('password', [
        'type' => 'password',
        'label' => __d('rock_software_users', 'Password'),
        'value' => '',
        'required' => true,
        'autocomplete' => 'current-password',
        'class' => 'form-control',
    ]) ?>
    <?= $this->Form->button(__d('rock_software_users', 'Sign in'), [
        'class' => ($providerEnabled ? 'rsu-button rsu-button-secondary btn btn-outline-secondary' : 'rsu-button btn btn-primary') . ' w-100',
    ]) ?>
    <?= $this->Form->end() ?>
    <p class="rsu-hint">
        <?= $providerEnabled
            ? __d('rock_software_users', 'Forgot your password? Ask an administrator to set a new one, or sign in with Rock Software ID.')
            : __d('rock_software_users', 'Forgot your password? Ask an administrator to set a new one.') ?>
    </p>
<?php endif; ?>
<?php if (!$providerEnabled && !$passwordSignIn) : ?>
    <p class="rsu-notice alert alert-warning" role="alert">
        <?= __d('rock_software_users', 'Sign-in is not configured yet. Connect this deployment to Rock Software ID with {0} in the application container.', '<code>bin/cake rock_software_users configure</code>') ?>
    </p>
<?php endif; ?>
