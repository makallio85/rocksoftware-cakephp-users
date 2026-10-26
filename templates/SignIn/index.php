<?php
/**
 * The sign-in page: "Sign in with Rock Software ID", the email and password
 * form while password sign-in is on, and a notice while neither is set up.
 * A failed or refused sign-in comes back here with its reason as a flash
 * message, which the layout renders above this content.
 *
 * Bootstrap markup, rendered in the sign-in card of the layout
 * `UsersPlugin::signInLayout()` picks: the UI plugin's `auth` layout, which
 * shows the brand mark, the settings plugin's `application.name` and
 * `application.organization_name`, or the plugin's own `sign_in` layout with
 * the same two settings. Every application shows this same page;
 * none keeps a copy of it.
 *
 * @var \Cake\View\View $this
 * @var bool $providerEnabled
 * @var bool $passwordSignIn
 * @var string|null $redirect
 * @var string $email
 * @var array<string, mixed> $signInUrl
 */
$this->assign('title', __d('rock_software_users', 'Sign in'));
$field = [
    'templates' => [
        'inputContainer' => '<div class="mb-3">{{content}}</div>',
        'inputContainerError' => '<div class="mb-3">{{content}}{{error}}</div>',
    ],
    'class' => 'form-control',
    'required' => true,
];
?>
<?php if ($providerEnabled) : ?>
<div class="d-grid">
    <?= $this->Html->link(
        '<i class="bi bi-shield-lock me-1"></i> ' . h(__d('rock_software_users', 'Sign in with Rock Software ID')),
        $signInUrl,
        ['class' => 'btn btn-primary btn-lg', 'escapeTitle' => false],
    ) ?>
</div>
<?php endif; ?>
<?php if ($passwordSignIn) : ?>
    <?php if ($providerEnabled) : ?>
<p class="text-body-secondary small text-center my-3"><?= __d('rock_software_users', 'or with your email address and password') ?></p>
    <?php endif; ?>
    <?= $this->Form->create(null, [
        'url' => ['plugin' => 'RockSoftware/Users', 'controller' => 'SignIn', 'action' => 'password', 'prefix' => false],
    ]) ?>
    <?= $this->Form->hidden('redirect', ['value' => (string)$redirect]) ?>
    <?= $this->Form->control('email', [
        'type' => 'email',
        'label' => ['text' => __d('rock_software_users', 'Email address'), 'class' => 'form-label'],
        'value' => $email,
        'autocomplete' => 'username',
    ] + $field) ?>
    <?= $this->Form->control('password', [
        'type' => 'password',
        'label' => ['text' => __d('rock_software_users', 'Password'), 'class' => 'form-label'],
        'value' => '',
        'autocomplete' => 'current-password',
    ] + $field) ?>
<div class="d-grid">
    <?= $this->Form->button(
        '<i class="bi bi-box-arrow-in-right me-1"></i> ' . h(__d('rock_software_users', 'Sign in')),
        ['class' => $providerEnabled ? 'btn btn-outline-secondary' : 'btn btn-primary btn-lg', 'escapeTitle' => false],
    ) ?>
</div>
    <?= $this->Form->end() ?>
<p class="text-body-secondary small mt-3 mb-0">
    <?= $providerEnabled
        ? __d('rock_software_users', 'Forgot your password? Ask an administrator to set a new one, or sign in with Rock Software ID.')
        : __d('rock_software_users', 'Forgot your password? Ask an administrator to set a new one.') ?>
</p>
<?php endif; ?>
<?php if (!$providerEnabled && !$passwordSignIn) : ?>
<div class="alert alert-warning mb-0" role="alert">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?= __d('rock_software_users', 'Sign-in is not configured yet. Connect this deployment to Rock Software ID with {0} in the application container.', '<code>bin/cake rock_software_users configure</code>') ?>
</div>
<?php endif; ?>
