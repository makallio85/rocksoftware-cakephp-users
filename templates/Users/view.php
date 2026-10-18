<?php
/**
 * One user: details from Rock Software ID, access, the access history and
 * the sign-in history. Bootstrap 5 markup; an application can restyle it by
 * overriding `templates/plugin/RockSoftware/Users/Users/view.php`.
 *
 * @var \Cake\View\View $this
 * @var \Cake\Datasource\EntityInterface $user
 * @var array{state: string, linked: bool, conflict: string|null, held: bool, system: bool} $status
 * @var array<\RockSoftware\Users\Model\Entity\UserEmail> $otherEmails
 * @var bool $canIssueLink
 * @var \RockSoftware\Users\Model\Entity\Identity|null $identity
 * @var \RockSoftware\Users\Model\Entity\SessionEvent|null $lastSignIn
 * @var array<\RockSoftware\Users\Model\Entity\SessionEvent> $history
 * @var array<\RockSoftware\Users\Model\Entity\AccessChange> $accessHistory
 * @var array<int, string> $actors
 * @var array<array{label: string, url: callable}> $actions
 * @var int $retentionDays
 * @var array<array{title: string, element: string, data?: array<string, mixed>}> $sections
 */
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Model\Entity\SignInMethodType;
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;

$name = UserProvisioner::displayName($user);
$this->assign('title', $name);
$id = (int)$user->get('id');
$format = fn($time): string => $time === null ? '' : $this->Time->format($time, 'd.M.yyyy HH.mm');
$from = $user->get(UserProvisioner::ACCESS_FROM);
$until = $user->get(UserProvisioner::ACCESS_UNTIL);
$phone = $user->get(UserProvisioner::PHONE);
$this->loadHelper('RockSoftware/Users.UserMenu');
$accountUrl = $this->UserMenu->peopleAdminUrl();
$actorName = fn(?int $actorId): string => $actorId === null
    ? __d('rock_software_users', 'Rock Software ID')
    : ($actors[$actorId] ?? __d('rock_software_users', 'a removed user'));
$buttons = [];
foreach ($actions as $i => $action) {
    $url = ($action['url'])($user);
    if ($url !== null) {
        $buttons[] = $this->Html->link($action['label'], $url, ['class' => $i === 0 ? 'btn btn-primary' : 'btn btn-outline-secondary']);
    }
}
?>
<div class="rsu-user" style="max-width: 1200px">
    <p class="mb-2"><?= $this->Html->link('‹ ' . __d('rock_software_users', 'Users'), ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'index']) ?></p>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= h($name) ?></h1>
            <?= $this->element('RockSoftware/Users.access_status', ['user' => $user, 'status' => $status]) ?>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <?= implode('', $buttons) ?>
            <?php if ($canIssueLink) : ?>
                <?= $this->Html->link(
                    __d('rock_software_users', 'Emergency sign-in link'),
                    ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'emergencyLink', $id],
                    ['class' => 'btn btn-outline-secondary'],
                ) ?>
            <?php else : ?>
                <button type="button" class="btn btn-outline-secondary" disabled aria-describedby="rsu-link-why"><?= __d('rock_software_users', 'Emergency sign-in link') ?></button>
            <?php endif; ?>
            <?= $this->Form->postButton(
                __d('rock_software_users', 'End sessions'),
                ['plugin' => 'RockSoftware/Users', 'controller' => 'Users', 'action' => 'endSessions', $id],
                ['class' => 'btn btn-outline-secondary'],
            ) ?>
            <?php if (!$canIssueLink) : ?>
                <div id="rsu-link-why" class="w-100 text-md-end small text-body-secondary">
                    <?= $status['state'] === UserAccess::NOT_STARTED && $from instanceof DateTimeInterface
                        ? __d('rock_software_users', 'Emergency links work only for people with access, from {0}.', UserAccess::format($from))
                        : __d('rock_software_users', 'Emergency links work only for people with access.') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold"><?= __d('rock_software_users', 'Details') ?></h2>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Email address') ?></dt>
                        <dd class="col-sm-7">
                            <?= h((string)$user->get(UserProvisioner::EMAIL)) ?>
                            <?php if ($otherEmails !== []) : ?>
                                <span class="badge rounded-pill ms-1" style="background:#eaeef2;color:#424a53"><?= __d('rock_software_users', 'used here') ?></span>
                            <?php endif; ?>
                        </dd>
                        <?php if ($otherEmails !== []) : ?>
                            <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Other addresses') ?></dt>
                            <dd class="col-sm-7">
                                <?php foreach ($otherEmails as $other) : ?>
                                    <div>
                                        <?= h($other->email) ?>
                                        <?php if ($other->label !== null) : ?>
                                            <span class="badge rounded-pill ms-1" style="background:#eaeef2;color:#424a53"><?= h($other->label) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Phone') ?></dt>
                        <dd class="col-sm-7"><?= is_string($phone) && $phone !== '' ? h($phone) : __d('rock_software_users', 'No phone') ?></dd>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Access from') ?></dt>
                        <dd class="col-sm-7"><?= $from instanceof DateTimeInterface ? h(UserAccess::format($from)) : __d('rock_software_users', 'No start date') ?></dd>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Access until') ?></dt>
                        <dd class="col-sm-7"><?= $until instanceof DateTimeInterface ? h(UserAccess::format($until)) : __d('rock_software_users', 'No end date') ?></dd>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Rock Software ID') ?></dt>
                        <dd class="col-sm-7"><?= $identity === null
                            ? __d('rock_software_users', 'Not linked to an account')
                            : h(__d('rock_software_users', 'Linked {0}', $format($identity->created))) ?></dd>
                        <dt class="col-sm-5 text-body-secondary fw-normal"><?= __d('rock_software_users', 'Last signed in') ?></dt>
                        <dd class="col-sm-7"><?= $lastSignIn === null
                            ? __d('rock_software_users', 'Never')
                            : h($format($lastSignIn->created) . ($lastSignIn->sign_in_method_type === null ? '' : ', ' . SignInMethodType::label($lastSignIn->sign_in_method_type->value))) ?></dd>
                    </dl>
                    <p class="small text-body-secondary border-top pt-3 mt-2 mb-0">
                        <?= __d('rock_software_users', 'Name, email addresses, phone and access dates are kept in Rock Software ID.') ?>
                        <?php if ($accountUrl !== null) : ?>
                            <?= $this->Html->link(__d('rock_software_users', 'Change them there'), $accountUrl, ['rel' => 'noopener']) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </section>
        </div>
        <?php foreach ($sections as $section) : ?>
            <div class="col-md-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 fw-semibold"><?= h($section['title']) ?></h2>
                        <?= $this->element($section['element'], ($section['data'] ?? []) + ['user' => $user]) ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent">
            <h2 class="h6 fw-semibold mb-0"><?= __d('rock_software_users', 'Access history') ?></h2>
        </div>
        <?php if ($accessHistory === []) : ?>
            <div class="card-body small text-body-secondary"><?= __d('rock_software_users', 'Nothing yet.') ?></div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead>
                        <tr class="text-body-secondary">
                            <th scope="col"><?= __d('rock_software_users', 'Time') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Event') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'By') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accessHistory as $change) : ?>
                            <?php
                            $type = (string)$change->access_change_type?->value;
                            $label = AccessChangeType::label($type);
                            if ($change->revocation_type !== null) {
                                $label .= ': ' . RevocationType::label($change->revocation_type->value);
                            }
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= h($format($change->created)) ?></td>
                                <td style="color: <?= $type === AccessChangeType::REVOKED ? '#a40e26' : ($type === AccessChangeType::GRANTED ? '#116329' : 'inherit') ?>"><?= h($label) ?></td>
                                <td><?= h($actorName($change->actor_user_id)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex flex-wrap justify-content-between gap-2">
            <h2 class="h6 fw-semibold mb-0"><?= __d('rock_software_users', 'Sign-in history') ?></h2>
            <span class="small text-body-secondary"><?= __d('rock_software_users', 'Kept for {0} days', $retentionDays) ?></span>
        </div>
        <?php if ($history === []) : ?>
            <div class="card-body small text-body-secondary"><?= __d('rock_software_users', 'Nothing yet.') ?></div>
        <?php else : ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead>
                        <tr class="text-body-secondary">
                            <th scope="col"><?= __d('rock_software_users', 'Time') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Method') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'Event') ?></th>
                            <th scope="col"><?= __d('rock_software_users', 'IP address') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $event) : ?>
                            <?php
                            $type = (string)$event->session_event_type?->value;
                            $refused = in_array($type, SessionEventType::REFUSALS, true);
                            $actor = $event->actor_user_id === null ? '' : ($actors[$event->actor_user_id] ?? __d('rock_software_users', 'a removed user'));
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= h($format($event->created)) ?></td>
                                <td><?= $event->sign_in_method_type === null ? '—' : h(SignInMethodType::label($event->sign_in_method_type->value)) ?></td>
                                <td style="color: <?= $refused ? '#a40e26' : ($type === SessionEventType::SIGNED_IN ? '#116329' : 'inherit') ?>"><?= h(SessionEventType::label($type, $actor)) ?></td>
                                <td class="text-body-secondary font-monospace"><?= h((string)$event->ip_address) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
