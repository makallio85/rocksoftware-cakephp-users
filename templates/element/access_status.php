<?php
/**
 * A user's access, as one badge and an optional line under it.
 *
 * @var \Cake\View\View $this
 * @var \Cake\Datasource\EntityInterface $user
 * @var array{state: string, linked: bool, conflict: string|null, held: bool, system: bool} $status
 * @var bool $compact The badge only, without the line under it (phone cards).
 */
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;

$from = $user->get(UserProvisioner::ACCESS_FROM);
$until = $user->get(UserProvisioner::ACCESS_UNTIL);
$colors = [
    'green' => 'background:#dafbe1;color:#116329',
    'blue' => 'background:#ddf4ff;color:#0550ae',
    'grey' => 'background:#eaeef2;color:#424a53',
    'orange' => 'background:#fff1e5;color:#953800',
    'yellow' => 'background:#fff8c5;color:#7d4e00',
];
$note = null;
if ($status['held']) {
    [$color, $label] = ['orange', __d('rock_software_users', 'Removal on hold')];
    $note = __d('rock_software_users', 'Waiting for your decision');
} elseif ($status['conflict'] !== null) {
    [$color, $label] = ['orange', __d('rock_software_users', 'Email conflict')];
    $note = __d('rock_software_users', 'New address {0} is another user\'s', $status['conflict']);
} elseif ($status['state'] === UserAccess::NOT_STARTED) {
    [$color, $label] = ['blue', __d('rock_software_users', 'Starts {0}', UserAccess::format($from))];
} elseif ($status['state'] === UserAccess::ENDED) {
    [$color, $label] = ['grey', __d('rock_software_users', 'Ended {0}', UserAccess::format($until))];
} elseif ($status['state'] === UserAccess::REVOKED) {
    [$color, $label] = ['grey', __d('rock_software_users', 'Access removed')];
} elseif (!$status['linked'] && !$status['system']) {
    [$color, $label] = ['yellow', __d('rock_software_users', 'Not in Rock Software ID')];
    $note = __d('rock_software_users', 'Not linked to an account');
} else {
    [$color, $label] = ['green', __d('rock_software_users', 'Has access')];
    if ($until instanceof DateTimeInterface) {
        $note = __d('rock_software_users', 'Until {0}', UserAccess::format($until));
    }
}
?>
<span class="badge rounded-pill" style="<?= $colors[$color] ?>"><?= h($label) ?></span>
<?php if ($note !== null && empty($compact)) : ?>
    <div class="small text-body-secondary mt-1"><?= h($note) ?></div>
<?php endif; ?>
