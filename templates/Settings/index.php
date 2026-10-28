<?php
/**
 * Settings › Identity provider. Bootstrap 5 markup; an application can restyle
 * it by overriding `templates/plugin/RockSoftware/Users/Settings/index.php`.
 *
 * @var \Cake\View\View $this
 * @var array<string, mixed> $values
 * @var bool $configured
 * @var string|null $problem
 * @var bool $issuerInvalid
 * @var string $callbackUrl
 * @var \RockSoftware\Users\Model\Entity\SettingChange|null $lastChange
 * @var string|null $lastChangedBy
 * @var \RockSoftware\Users\Model\Entity\Provisioning|null $lastProvisioning
 * @var bool $provisioningEnabled
 * @var \Cake\I18n\DateTime|null $provisioningOpenUntil
 * @var bool $passwordSignInOffered
 * @var bool $passwordSignInUnsupported
 * @var bool $passwordSignInEnabled
 * @var bool $canTurnPasswordSignInOff
 * @var string|null $passwordSignInChangedBy
 * @var \Cake\I18n\DateTime|null $passwordSignInChangedAt
 * @var array{max_hours: int, idle_minutes: int, retention_days: int} $sessionLimits
 * @var string|null $bearerAudience
 * @var string|null $bearerIssuerUrl
 */
$this->assign('title', __d('rock_software_users', 'Identity provider'));
?>
<div class="rsu-settings" style="max-width: 980px">
    <div class="mb-3">
        <h1 class="h4 mb-1"><?= __d('rock_software_users', 'Identity provider') ?></h1>
        <p class="text-body-secondary mb-0"><?= __d('rock_software_users', 'People sign in through Rock Software ID. These settings say which server and which client.') ?></p>
    </div>

    <?php if (!$configured) : ?>
        <div class="alert alert-warning" role="status">
            <?= __d('rock_software_users', 'Not configured yet. Enter the connection below; it is saved once the server answers.') ?>
        </div>
    <?php elseif ($problem === null) : ?>
        <div class="alert alert-success" role="status">
            <strong><?= __d('rock_software_users', 'Connected.') ?></strong> <?= __d('rock_software_users', 'The server answers its discovery document.') ?>
        </div>
    <?php elseif (!$issuerInvalid) : ?>
        <div class="alert alert-danger" role="status">
            <strong><?= __d('rock_software_users', 'The stored server does not answer.') ?></strong> <?= h($problem) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
            <?= $this->Form->create(null, ['url' => ['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'update']]) ?>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="issuer-url"><?= __d('rock_software_users', 'Identity server address') ?></label>
                <?= $this->Form->control('issuer_url', [
                    'label' => false,
                    'type' => 'url',
                    'id' => 'issuer-url',
                    'value' => $values['issuer_url'] ?? '',
                    'required' => true,
                    'placeholder' => 'https://id.rocksoftware.fi/application/o/<slug>/',
                    'class' => 'form-control' . ($issuerInvalid ? ' is-invalid' : ''),
                    'aria-describedby' => 'issuer-url-help',
                ]) ?>
                <div id="issuer-url-help" class="form-text<?= $issuerInvalid ? ' text-danger' : '' ?>">
                    <?= $issuerInvalid
                        ? __d('rock_software_users', 'This address did not answer as an identity server. Check that the path ends in the application\'s slug.')
                        : __d('rock_software_users', 'The issuer URL: https://id.rocksoftware.fi/application/o/ followed by the application\'s slug.') ?>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="client-id"><?= __d('rock_software_users', 'Client ID') ?></label>
                    <?php // `type` is explicit: a field ending in _id otherwise renders as an empty select. ?>
                    <?= $this->Form->control('client_id', [
                        'label' => false, 'type' => 'text', 'id' => 'client-id', 'class' => 'form-control',
                        'required' => true, 'value' => $values['client_id'] ?? '',
                    ]) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="required-group"><?= __d('rock_software_users', 'Required group') ?></label>
                    <?= $this->Form->control('required_group', [
                        'label' => false, 'type' => 'text', 'id' => 'required-group', 'class' => 'form-control',
                        'value' => $values['required_group'] ?? '',
                    ]) ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="client-secret"><?= __d('rock_software_users', 'Client secret') ?></label>
                <?= $this->Form->control('client_secret', [
                    'label' => false, 'type' => 'password', 'id' => 'client-secret', 'class' => 'form-control',
                    'value' => '', 'autocomplete' => 'new-password',
                    'placeholder' => $configured ? __d('rock_software_users', 'Stored — leave blank to keep it') : __d('rock_software_users', 'Required'),
                    'aria-describedby' => 'client-secret-help',
                ]) ?>
                <div id="client-secret-help" class="form-text"><?= __d('rock_software_users', 'Stored encrypted and never shown. In Authentik: Applications › Providers › the provider › Edit.') ?></div>
            </div>
            <div class="bg-light rounded p-3 mb-3">
                <div class="small fw-semibold text-secondary mb-1"><?= __d('rock_software_users', 'Redirect URI to register at the provider') ?></div>
                <code class="small text-break"><?= h($callbackUrl) ?></code>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?= $this->Form->button(__d('rock_software_users', 'Save'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->button(__d('rock_software_users', 'Test connection'), [
                    'class' => 'btn btn-outline-secondary',
                    'formaction' => $this->Url->build(['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'test']),
                ]) ?>
                <span class="small text-body-secondary"><?= __d('rock_software_users', 'Save checks the server first. Settings that cannot reach it are not saved, so nobody is locked out.') ?></span>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <?php if ($provisioningEnabled) : ?>
        <?php $windowOpen = $provisioningOpenUntil !== null && $provisioningOpenUntil->isFuture(); ?>
        <?php if (!$configured) : ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body p-3 p-md-4">
                    <div class="fw-semibold mb-1"><?= __d('rock_software_users', 'Connection by Rock Software ID') ?></div>
                    <div class="small text-body-secondary"><?= __d('rock_software_users', 'Waiting. Rock Software ID connects this application when its administrator runs the identity provider\'s registration step; nothing needs to be typed here.') ?></div>
                </div>
            </div>
        <?php elseif ($windowOpen) : ?>
            <div class="card border border-warning bg-warning-subtle mt-3">
                <div class="card-body p-3 p-md-4">
                    <div class="fw-semibold mb-1"><?= __d('rock_software_users', 'Connection by Rock Software ID') ?></div>
                    <div class="small"><?= __d('rock_software_users', 'Open until {0}. Run the identity provider\'s registration step now; the connection it sends closes this again.', h($this->Time->format($provisioningOpenUntil, 'HH.mm'))) ?></div>
                </div>
            </div>
        <?php else : ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div style="flex: 1 1 420px">
                        <div class="fw-semibold mb-1"><?= __d('rock_software_users', 'Connection by Rock Software ID') ?></div>
                        <div class="small text-body-secondary"><?= __d('rock_software_users', 'Closed. Rock Software ID cannot change this connection. Open it for an hour to set a new client secret or client; the next connection closes it again.') ?></div>
                    </div>
                    <?= $this->Form->postButton(
                        __d('rock_software_users', 'Allow Rock Software ID to connect again'),
                        ['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'allowProvisioning'],
                        ['class' => 'btn btn-outline-primary'],
                    ) ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($passwordSignInUnsupported) : ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body p-3 p-md-4">
                <div class="fw-semibold mb-1"><?= __d('rock_software_users', 'Password sign-in') ?></div>
                <div class="small text-body-secondary"><?= __d('rock_software_users', 'Not available. This application has no passwords of its own; people sign in only through Rock Software ID.') ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($passwordSignInOffered) : ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div style="flex: 1 1 420px">
                    <div class="fw-semibold mb-1"><?= __d('rock_software_users', 'Password sign-in') ?></div>
                    <div class="small text-body-secondary">
                        <?php if (!$passwordSignInEnabled) : ?>
                            <?= __d('rock_software_users', 'Off. Only Rock Software ID signs people in. Turning it back on lets everyone use their password again at once.') ?>
                        <?php elseif ($canTurnPasswordSignInOff) : ?>
                            <?= __d('rock_software_users', 'On. People can also sign in with their email address and password on the sign-in page. Turn it off once everyone has signed in through Rock Software ID once.') ?>
                        <?php else : ?>
                            <?= __d('rock_software_users', 'On. To turn it off, sign in through Rock Software ID first, so nobody can lock themselves out.') ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($passwordSignInEnabled) : ?>
                    <?= $this->Form->postButton(
                        __d('rock_software_users', 'Turn password sign-in off'),
                        ['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'passwordSignIn'],
                        [
                            'data' => ['enabled' => '0'],
                            'class' => 'btn btn-outline-danger',
                            'disabled' => !$canTurnPasswordSignInOff,
                        ],
                    ) ?>
                <?php else : ?>
                    <?= $this->Form->postButton(
                        __d('rock_software_users', 'Turn password sign-in on'),
                        ['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'passwordSignIn'],
                        ['data' => ['enabled' => '1'], 'class' => 'btn btn-outline-secondary'],
                    ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($passwordSignInChangedAt !== null) : ?>
            <p class="small text-body-secondary mt-2 mb-0">
                <?= h($passwordSignInEnabled
                    ? __d('rock_software_users', 'Password sign-in turned on by {0}, {1}.', $passwordSignInChangedBy ?? __d('rock_software_users', 'a removed user'), $this->Time->format($passwordSignInChangedAt, 'd.M.yyyy HH.mm'))
                    : __d('rock_software_users', 'Password sign-in turned off by {0}, {1}.', $passwordSignInChangedBy ?? __d('rock_software_users', 'a removed user'), $this->Time->format($passwordSignInChangedAt, 'd.M.yyyy HH.mm'))) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($configured) : ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-3"><?= __d('rock_software_users', 'Sessions') ?></h2>
                <?= $this->Form->create(null, ['url' => ['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'sessions']]) ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="session-max-hours"><?= __d('rock_software_users', 'Longest session') ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="session-max-hours" name="session_max_hours" min="0" value="<?= (int)$sessionLimits['max_hours'] ?>" aria-describedby="session-max-hours-help">
                            <span class="input-group-text"><?= __d('rock_software_users', 'hours') ?></span>
                        </div>
                        <div id="session-max-hours-help" class="form-text"><?= __d('rock_software_users', 'Then people sign in again. 0 = no limit.') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="session-idle-minutes"><?= __d('rock_software_users', 'Sign out when idle') ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="session-idle-minutes" name="session_idle_minutes" min="0" value="<?= (int)$sessionLimits['idle_minutes'] ?>" aria-describedby="session-idle-minutes-help">
                            <span class="input-group-text"><?= __d('rock_software_users', 'minutes') ?></span>
                        </div>
                        <div id="session-idle-minutes-help" class="form-text"><?= __d('rock_software_users', '0 = never.') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="session-event-retention-days"><?= __d('rock_software_users', 'Keep sign-in history') ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="session-event-retention-days" name="session_event_retention_days" min="1" value="<?= (int)$sessionLimits['retention_days'] ?>">
                            <span class="input-group-text"><?= __d('rock_software_users', 'days') ?></span>
                        </div>
                    </div>
                </div>
                <?= $this->Form->button(__d('rock_software_users', 'Save'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($configured) : ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-1"><?= __d('rock_software_users', 'Access tokens') ?></h2>
                <p class="small text-body-secondary mb-3"><?= __d('rock_software_users', 'For the application\'s API and MCP paths: tools sign in with an access token from Rock Software ID instead of a browser session. Empty audience refuses every token.') ?></p>
                <?= $this->Form->create(null, ['url' => ['plugin' => 'RockSoftware/Users', 'controller' => 'Settings', 'action' => 'accessTokens']]) ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bearer-audience"><?= __d('rock_software_users', 'Audience') ?></label>
                        <input type="text" class="form-control" id="bearer-audience" name="bearer_audience" maxlength="255" value="<?= h((string)$bearerAudience) ?>" aria-describedby="bearer-audience-help">
                        <div id="bearer-audience-help" class="form-text"><?= __d('rock_software_users', 'The client ID the tokens are issued for.') ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="bearer-issuer-url"><?= __d('rock_software_users', 'Token issuer') ?></label>
                        <input type="url" class="form-control" id="bearer-issuer-url" name="bearer_issuer_url" maxlength="255" value="<?= h((string)$bearerIssuerUrl) ?>" placeholder="<?= h((string)($values['issuer_url'] ?? '')) ?>" aria-describedby="bearer-issuer-url-help">
                        <div id="bearer-issuer-url-help" class="form-text"><?= __d('rock_software_users', 'When the client is another application in Rock Software ID. Empty: the identity server address above.') ?></div>
                    </div>
                </div>
                <?= $this->Form->button(__d('rock_software_users', 'Save'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($lastProvisioning !== null && ($lastChange === null || $lastProvisioning->created >= $lastChange->created)) : ?>
        <p class="small text-body-secondary mt-3 mb-0">
            <?= __d('rock_software_users', 'Connected by Rock Software ID, {0}.', h($this->Time->format($lastProvisioning->created, 'd.M.yyyy HH.mm'))) ?>
        </p>
    <?php elseif ($lastChange !== null) : ?>
        <p class="small text-body-secondary mt-3 mb-0">
            <?= __d('rock_software_users', 'Last changed by {0}, {1}.', h($lastChangedBy ?? __d('rock_software_users', 'a removed user')), h($this->Time->format($lastChange->created, 'd.M.yyyy HH.mm'))) ?>
        </p>
    <?php endif; ?>
</div>
