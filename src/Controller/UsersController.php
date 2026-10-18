<?php
declare(strict_types=1);

namespace RockSoftware\Users\Controller;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use RockSoftware\Users\Command\EmergencySignInCommand;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Model\Entity\SessionEventType;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\SignInHistory;
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * The users page. People come from Rock Software ID (SCIM), so it is
 * read-only: who they are, whether they have access, their access and
 * sign-in history. The application sets permissions on its own pages, linked
 * from here through `RockSoftwareUsers.userActions`.
 *
 *   GET  /auth/users                         — the list (`?q=`, `?access=`)
 *   GET  /auth/users/{id}                    — one user
 *   POST /auth/users/{id}/end-sessions       — sign the user out of this application
 *   GET|POST /auth/users/{id}/emergency-link — a single-use sign-in link, shown once
 *   GET  /auth/users/held-removals           — removals the mass-removal stop held
 *   POST /auth/users/held-removals/approve   — approve them all: access goes
 *   POST /auth/users/held-removals/reject    — reject them all: access stays
 *
 * An application adds to it through events, filling the `ArrayObject` in the
 * event data:
 *
 * - `RockSoftwareUsers.userListColumns` — `columns[] = ['label' => string, 'value' => fn(EntityInterface $user): string]`
 * - `RockSoftwareUsers.userViewSections` — `sections[] = ['title' => string, 'element' => string, 'data' => array]`,
 *   rendered with the application's element and `$user`
 * - `RockSoftwareUsers.userActions` — `actions[] = ['label' => string, 'url' => fn(EntityInterface $user): string|array|null]`,
 *   a button per user on the list and the user's page (null hides it), e.g. *Manage permissions*
 *
 * It tells of `RockSoftwareUsers.sessionsEnded` (data: user, actor).
 *
 * Who may open it: `RockSoftwareUsers.usersAccess`, else `RockSoftwareUsers.settingsAccess`.
 *
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class UsersController extends SettingsBaseController
{
    public const PAGE_SIZE = 50;

    public const EVENT_LIST_COLUMNS = 'RockSoftwareUsers.userListColumns';
    public const EVENT_VIEW_SECTIONS = 'RockSoftwareUsers.userViewSections';
    public const EVENT_USER_ACTIONS = 'RockSoftwareUsers.userActions';
    public const EVENT_SESSIONS_ENDED = 'RockSoftwareUsers.sessionsEnded';

    public const FILTER_ACCESS = 'access';
    public const FILTER_STARTING = 'starting';
    public const FILTER_NONE = 'none';
    public const FILTER_ATTENTION = 'attention';
    public const FILTER_ALL = 'all';

    /**
     * How long an email conflict counts without Rock Software ID repeating it.
     * A real conflict comes back with every full sync (every 4 hours); one
     * resolved by changing the address back never does, because Rock Software
     * ID then sees nothing to send.
     */
    public const EMAIL_CONFLICT_HOURS = 5;

    /** The validity choices for a link made on the page, in minutes. */
    public const EMERGENCY_LINK_MINUTES = [60, 240, 1440];

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        if (!$this->components()->has('Flash')) {
            $this->loadComponent('Flash');
        }
        if (!$this->components()->has('Authentication')) {
            $this->loadComponent('Authentication.Authentication');
        }
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event The event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        if ($event->isStopped()) {
            return;
        }

        $identity = $this->Authentication->getIdentity();
        if ($identity === null) {
            return;
        }
        $access = Configure::read('RockSoftwareUsers.usersAccess')
            ?? Configure::read('RockSoftwareUsers.settingsAccess');
        $allowed = $access === null || (is_callable($access) && $access($identity, $this->getRequest()) === true);
        if (!$allowed) {
            throw new ForbiddenException(__d('rock_software_users', 'You may not manage users.'));
        }
    }

    /**
     * @return void
     */
    public function index(): void
    {
        $users = $this->users();
        $search = trim((string)$this->getRequest()->getQuery('q'));
        $filter = (string)$this->getRequest()->getQuery('access', self::FILTER_ACCESS);
        if (!in_array($filter, array_keys(self::filters()), true)) {
            $filter = self::FILTER_ACCESS;
        }

        $query = $users->find();
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $or = [];
            foreach ([UserProvisioner::EMAIL, UserProvisioner::NAME, UserProvisioner::PHONE] as $column) {
                $or[$users->aliasField($column) . ' LIKE'] = $like;
            }
            $query->where(['OR' => $or]);
        }
        $this->applyFilter($query, $filter);
        $query->orderBy([
            $users->aliasField(UserProvisioner::NAME) => 'ASC',
            $users->aliasField(UserProvisioner::EMAIL) => 'ASC',
        ]);

        $page = $this->paginate($query, ['limit' => self::PAGE_SIZE, 'maxLimit' => self::PAGE_SIZE]);
        $ids = [];
        foreach ($page as $user) {
            /** @var \Cake\Datasource\EntityInterface $user */
            $ids[] = (int)$user->get('id');
        }

        $columns = new ArrayObject();
        EventManager::instance()->dispatch(new Event(self::EVENT_LIST_COLUMNS, $this, [
            'columns' => $columns,
            'users' => $page,
        ]));

        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable $held */
        $held = $this->fetchTable('RockSoftware/Users.HeldRevocations');
        $this->set([
            'users' => $page,
            'search' => $search,
            'filter' => $filter,
            'filters' => self::filters(),
            'statuses' => $this->statuses($page->toArray()),
            'lastSignIns' => $this->fetchTable('RockSoftware/Users.SessionEvents')->latestSignIns($ids),
            'columns' => $columns->getArrayCopy(),
            'actions' => $this->actions(),
            'heldCount' => count($held->pending()),
        ]);
    }

    /**
     * @param string $id The user.
     * @return void
     */
    public function view(string $id): void
    {
        $user = $this->user($id);
        $userId = (int)$user->get('id');
        /** @var \RockSoftware\Users\Model\Table\SessionEventsTable $events */
        $events = $this->fetchTable('RockSoftware/Users.SessionEvents');
        $history = $events->forUser($userId);
        /** @var \RockSoftware\Users\Model\Table\AccessChangesTable $changes */
        $changes = $this->fetchTable('RockSoftware/Users.AccessChanges');
        $accessHistory = $changes->forUser($userId);

        $actorIds = array_values(array_unique(array_filter([
            ...array_map(fn($event) => $event->actor_user_id, $history),
            ...array_map(fn($change) => $change->actor_user_id, $accessHistory),
        ])));
        $actors = [];
        if ($actorIds !== []) {
            foreach ($this->users()->find()->where([$this->users()->aliasField('id') . ' IN' => $actorIds]) as $actor) {
                /** @var \Cake\Datasource\EntityInterface $actor */
                $actors[(int)$actor->get('id')] = UserProvisioner::displayName($actor);
            }
        }

        $sections = new ArrayObject();
        EventManager::instance()->dispatch(new Event(self::EVENT_VIEW_SECTIONS, $this, [
            'sections' => $sections,
            'user' => $user,
        ]));

        $this->set([
            'user' => $user,
            'status' => $this->statuses([$user])[$userId],
            'canIssueLink' => UserAccess::allows($user),
            'identity' => (new UserProvisioner())->identities()->findForUser($userId),
            'lastSignIn' => $events->latestSignIns([$userId])[$userId] ?? null,
            'history' => $history,
            'accessHistory' => $accessHistory,
            'actors' => $actors,
            'actions' => $this->actions(),
            'retentionDays' => $this->fetchTable('RockSoftware/Users.Settings')->sessionLimits()['retention_days'],
            'sections' => $sections->getArrayCopy(),
        ]);
    }

    /**
     * Ends every session of the user in this application on its next request.
     *
     * @param string $id The user.
     * @return \Cake\Http\Response|null
     */
    public function endSessions(string $id): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        $user = $this->user($id);
        (new SignInHistory())->record(SessionEventType::SESSIONS_ENDED, [
            'user_id' => (int)$user->get('id'),
            'actor_user_id' => $this->actorId(),
        ], $this->getRequest());
        EventManager::instance()->dispatch(new Event(self::EVENT_SESSIONS_ENDED, $this, [
            'user' => $user,
            'actor' => $this->Authentication->getIdentity()?->getOriginalData(),
        ]));
        $name = UserProvisioner::displayName($user);
        $this->Flash->success(__d('rock_software_users', '{0} is signed out of this application.', $name));

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * A single-use link that signs the user in without Rock Software ID, for
     * when it is down: GET asks how long it stays valid, POST makes it and
     * shows it once. Only for a user with access now.
     *
     * @param string $id The user.
     * @return \Cake\Http\Response|null
     */
    public function emergencyLink(string $id): ?Response
    {
        $user = $this->user($id);
        if (!UserAccess::allows($user)) {
            $this->Flash->error(
                __d('rock_software_users', 'Emergency sign-in links work only for people with access.'),
            );

            return $this->redirect(['action' => 'view', $id]);
        }
        $link = null;
        $until = null;
        if ($this->getRequest()->is('post')) {
            $minutes = (int)$this->getRequest()->getData('minutes');
            if (!in_array($minutes, self::EMERGENCY_LINK_MINUTES, true)) {
                $minutes = 240;
            }
            /** @var \RockSoftware\Users\Model\Table\EmergencySignInsTable $links */
            $links = $this->fetchTable('RockSoftware/Users.EmergencySignIns');
            $token = $links->issue((int)$user->get('id'), $minutes);
            /** @var \RockSoftware\Users\Model\Table\AccessChangesTable $changes */
            $changes = $this->fetchTable('RockSoftware/Users.AccessChanges');
            $changes->record((int)$user->get('id'), AccessChangeType::EMERGENCY_LINK_ISSUED, [
                'actor_user_id' => $this->actorId(),
                'detail' => 'users page, valid ' . $minutes . ' min',
            ]);
            $link = EmergencySignInCommand::linkFor($token);
            $until = DateTime::now()->addMinutes($minutes);
            // The page holds a live credential: never cached anywhere.
            $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'no-store'));
        }

        $this->set([
            'user' => $user,
            'link' => $link,
            'until' => $until,
            'choices' => self::EMERGENCY_LINK_MINUTES,
        ]);

        return null;
    }

    /**
     * @return void
     */
    public function heldRevocations(): void
    {
        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable $table */
        $table = $this->fetchTable('RockSoftware/Users.HeldRevocations');
        $pending = $table->pending();
        $provisioner = new UserProvisioner();
        $rows = [];
        foreach ($pending as $held) {
            $rows[] = ['held' => $held, 'user' => $provisioner->findById($held->user_id)];
        }
        $this->set(['rows' => $rows, 'activeCount' => $this->activeCount()]);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function approveRevocations(): ?Response
    {
        return $this->decide(true);
    }

    /**
     * @return \Cake\Http\Response|null
     */
    public function rejectRevocations(): ?Response
    {
        return $this->decide(false);
    }

    /**
     * The access filters, in the order the page offers them.
     *
     * @return array<string, string>
     */
    public static function filters(): array
    {
        return [
            self::FILTER_ACCESS => __d('rock_software_users', 'Has access or starting'),
            self::FILTER_STARTING => __d('rock_software_users', 'Starting'),
            self::FILTER_NONE => __d('rock_software_users', 'No access'),
            self::FILTER_ATTENTION => __d('rock_software_users', 'Needs attention'),
            self::FILTER_ALL => __d('rock_software_users', 'All'),
        ];
    }

    /**
     * @param bool $approve Approve or reject.
     * @return \Cake\Http\Response|null
     */
    private function decide(bool $approve): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable $table */
        $table = $this->fetchTable('RockSoftware/Users.HeldRevocations');
        $pending = $table->pending();
        $access = new AccessManager();
        foreach ($pending as $held) {
            $approve ? $access->approve($held, $this->actorId()) : $access->reject($held, $this->actorId());
        }
        $this->Flash->success($approve
            ? __d('rock_software_users', 'Access removed for {0} people.', count($pending))
            : __d('rock_software_users', '{0} people keep their access.', count($pending)));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query The users query.
     * @param string $filter One of the `FILTER_*` constants.
     * @return void
     */
    private function applyFilter(SelectQuery $query, string $filter): void
    {
        $users = $this->users();
        $active = $users->aliasField(UserProvisioner::IS_ACTIVE);
        $from = $users->aliasField(UserProvisioner::ACCESS_FROM);
        $until = $users->aliasField(UserProvisioner::ACCESS_UNTIL);
        $now = DateTime::now();
        $id = $users->aliasField('id');

        switch ($filter) {
            case self::FILTER_ACCESS:
                $query->where([$active => true, 'OR' => [$until . ' IS' => null, $until . ' >' => $now]]);
                break;
            case self::FILTER_STARTING:
                $query->where([$active => true, $from . ' >' => $now]);
                break;
            case self::FILTER_NONE:
                $query->where(['OR' => [$active => false, $until . ' <=' => $now]]);
                break;
            case self::FILTER_ATTENTION:
                $provisioner = new UserProvisioner();
                $conflicts = $provisioner->identities()->find()
                    ->select(['user_id'])
                    ->where([
                        'pending_email IS NOT' => null,
                        'synced_at >' => DateTime::now()->subHours(self::EMAIL_CONFLICT_HOURS),
                    ]);
                $held = $this->fetchTable('RockSoftware/Users.HeldRevocations')->find()
                    ->select(['user_id'])
                    ->where(['resolved_at IS' => null]);
                $linked = $provisioner->identities()->find()->select(['user_id']);
                // Not in Rock Software ID, unless a system user.
                $missing = [$id . ' NOT IN' => $linked];
                $system = UserProvisioner::systemUserIds();
                if ($system !== []) {
                    $missing[] = [$id . ' NOT IN' => $system];
                }
                $query->where(['OR' => [[$id . ' IN' => $conflicts], [$id . ' IN' => $held], $missing]]);
                break;
        }
    }

    /**
     * What the page shows of each user's access.
     *
     * @param array<\Cake\Datasource\EntityInterface> $users The users.
     * @return array<int, array{state: string, linked: bool, conflict: string|null, held: bool, system: bool}>
     */
    private function statuses(array $users): array
    {
        $ids = array_map(fn(EntityInterface $user): int => (int)$user->get('id'), $users);
        if ($ids === []) {
            return [];
        }
        $identities = [];
        foreach ((new UserProvisioner())->identities()->find()->where(['user_id IN' => $ids]) as $identity) {
            /** @var \RockSoftware\Users\Model\Entity\Identity $identity */
            $identities[(int)$identity->user_id] = $identity;
        }
        $held = [];
        $heldRows = $this->fetchTable('RockSoftware/Users.HeldRevocations')->find()
            ->where(['user_id IN' => $ids, 'resolved_at IS' => null]);
        foreach ($heldRows as $row) {
            $held[(int)$row->get('user_id')] = true;
        }
        $system = UserProvisioner::systemUserIds();
        $statuses = [];
        foreach ($users as $user) {
            $id = (int)$user->get('id');
            $pending = isset($identities[$id]) ? $identities[$id]->get('pending_email') : null;
            $confirmed = isset($identities[$id]) ? $identities[$id]->synced_at : null;
            if (!$confirmed instanceof DateTime || $confirmed < DateTime::now()->subHours(self::EMAIL_CONFLICT_HOURS)) {
                $pending = null;
            }
            $statuses[$id] = [
                'state' => UserAccess::state($user),
                'linked' => isset($identities[$id]),
                'conflict' => is_string($pending) ? $pending : null,
                'held' => isset($held[$id]),
                'system' => in_array($id, $system, true),
            ];
        }

        return $statuses;
    }

    /**
     * The application's per-user buttons.
     *
     * @return array<array{label: string, url: callable}>
     */
    private function actions(): array
    {
        $actions = new ArrayObject();
        EventManager::instance()->dispatch(new Event(self::EVENT_USER_ACTIONS, $this, ['actions' => $actions]));

        return array_values(array_filter(
            $actions->getArrayCopy(),
            fn($action): bool => is_array($action)
                && is_string($action['label'] ?? null)
                && is_callable($action['url'] ?? null),
        ));
    }

    /**
     * @return int
     */
    private function activeCount(): int
    {
        $users = $this->users();

        return $users->find()->where([$users->aliasField(UserProvisioner::IS_ACTIVE) => true])->count();
    }

    /**
     * @return \Cake\ORM\Table
     */
    private function users(): Table
    {
        return (new UserProvisioner())->users();
    }

    /**
     * @param string $id The user.
     * @return \Cake\Datasource\EntityInterface
     */
    private function user(string $id): EntityInterface
    {
        $user = (new UserProvisioner())->findById((int)$id);
        if (!$user instanceof EntityInterface) {
            throw new NotFoundException();
        }

        return $user;
    }

    /**
     * @return int|null
     */
    private function actorId(): ?int
    {
        $id = $this->Authentication->getIdentity()?->getIdentifier();

        return $id === null ? null : (int)$id;
    }
}
