<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\Routing\Router;
use RockSoftware\Users\Model\Entity\AccessChangeType;
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * Single-use links that sign people in without Rock Software ID, for when it
 * is down. Only someone with a shell on the application can run it, so a link
 * carries that person's authority.
 *
 * `emergency_sign_in <email>` makes one link; `emergency_sign_in --all` one for
 * every user with access now. **A link is never printed**: the command asks
 * where to write them (or takes `--file`), creates the file with mode 0600,
 * never overwrites one, and prints only how many it wrote. Every link issued is
 * recorded in the person's access history. People without access (revoked,
 * not started, ended) get none.
 *
 * `--create` also creates the user when none has the address, for an empty
 * database such as a pull request preview. It is refused unless the
 * application runs with `debug` on: a production deployment never gets a user
 * from a shell command.
 */
class EmergencySignInCommand extends Command
{
    public const DEFAULT_VALID = '4h';
    private const MAX_MINUTES = 24 * 60;

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users emergency_sign_in';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(
                'Write single-use sign-in links to a file, for when Rock Software ID is unavailable. '
                . 'Links are never printed.',
            )
            ->addArgument('email', ['help' => 'Email address of the user to sign in (or use --all).'])
            ->addOption('all', [
                'boolean' => true,
                'help' => 'A link for every user with access now.',
            ])
            ->addOption('file', [
                'help' => 'Where to write the links; asked for when left out. Must not exist yet.',
            ])
            ->addOption('valid', [
                'help' => 'How long the links stay valid, e.g. 30m or 4h; at most 24h.',
                'default' => self::DEFAULT_VALID,
            ])
            ->addOption('create', [
                'boolean' => true,
                'help' => 'Create the user when none has the address (only with debug on, never in production).',
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $minutes = self::minutes((string)$args->getOption('valid'));
        if ($minutes === null) {
            $io->error('--valid takes minutes or hours, e.g. 30m or 4h, at most 24h.');

            return static::CODE_ERROR;
        }
        $email = $args->getArgument('email');
        $all = (bool)$args->getOption('all');
        if ($all === ($email !== null)) {
            $io->error('Give one email address, or --all.');

            return static::CODE_ERROR;
        }

        $users = $all
            ? $this->usersWithAccess()
            : $this->oneUser((string)$email, (bool)$args->getOption('create'), $io);
        if ($users === null) {
            return static::CODE_ERROR;
        }
        if ($users === []) {
            $io->error('No user has access now, so no link was made.');

            return static::CODE_ERROR;
        }

        $path = $args->getOption('file');
        if (!is_string($path) || $path === '') {
            $path = trim((string)$io->ask('File to write the links to (it must not exist yet)'));
        }
        if ($path === '') {
            $io->error('No file given, so no link was made.');

            return static::CODE_ERROR;
        }
        $handle = $this->createFile($path, $io);
        if ($handle === null) {
            return static::CODE_ERROR;
        }

        /** @var \RockSoftware\Users\Model\Table\EmergencySignInsTable $links */
        $links = $this->fetchTable('RockSoftware/Users.EmergencySignIns');
        /** @var \RockSoftware\Users\Model\Table\AccessChangesTable $changes */
        $changes = $this->fetchTable('RockSoftware/Users.AccessChanges');
        $until = UserAccess::format(DateTime::now()->addMinutes($minutes));
        fwrite($handle, sprintf("# Emergency sign-in links, valid until %s, each for one sign-in.\n", $until));
        fwrite($handle, "# Give each person only their own link, then delete this file.\n");
        foreach ($users as $user) {
            $token = $links->issue((int)$user->get('id'), $minutes);
            $changes->record((int)$user->get('id'), AccessChangeType::EMERGENCY_LINK_ISSUED, [
                'detail' => 'command line, valid ' . $minutes . ' min',
            ]);
            fwrite($handle, sprintf(
                "%s\t%s\t%s\n",
                (string)$user->get(UserProvisioner::EMAIL),
                UserProvisioner::displayName($user),
                self::linkFor($token),
            ));
        }
        fclose($handle);
        Log::warning(sprintf('Emergency sign-in links issued for %d users, written to a file.', count($users)));

        $io->out(sprintf(
            'Wrote %d link(s) to %s, valid until %s. Delete the file once they are handed out.',
            count($users),
            $path,
            $until,
        ));

        return static::CODE_SUCCESS;
    }

    /**
     * The sign-in address for a token.
     *
     * @param string $token The raw token.
     * @return string
     */
    public static function linkFor(string $token): string
    {
        return Router::url([
            'plugin' => 'RockSoftware/Users',
            'controller' => 'SignIn',
            'action' => 'emergency',
            'prefix' => false,
            $token,
        ], true);
    }

    /**
     * Minutes from `30m`, `4h` or a bare number of minutes.
     *
     * @param string $valid The option.
     * @return int|null
     */
    public static function minutes(string $valid): ?int
    {
        if (!preg_match('/^(\d+)\s*([mh]?)$/i', trim($valid), $match)) {
            return null;
        }
        $minutes = (int)$match[1] * (strtolower($match[2]) === 'h' ? 60 : 1);

        return $minutes >= 1 && $minutes <= self::MAX_MINUTES ? $minutes : null;
    }

    /**
     * Every user with access now, system users left out.
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    private function usersWithAccess(): array
    {
        $table = (new UserProvisioner())->users();
        $query = $table->find()
            ->where([$table->aliasField(UserProvisioner::IS_ACTIVE) => true])
            ->orderBy([$table->aliasField(UserProvisioner::EMAIL) => 'ASC']);
        $system = UserProvisioner::systemUserIds();
        if ($system !== []) {
            $query->where([$table->aliasField('id') . ' NOT IN' => $system]);
        }

        return array_values(array_filter(
            $query->all()->toList(),
            fn(EntityInterface $user): bool => UserAccess::allows($user),
        ));
    }

    /**
     * @param string $email The address.
     * @param bool $create Whether to create a missing user.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return array<\Cake\Datasource\EntityInterface>|null Null after an error.
     */
    private function oneUser(string $email, bool $create, ConsoleIo $io): ?array
    {
        $provisioner = new UserProvisioner();
        $user = $provisioner->findByEmail($email);
        if ($user === null && $create) {
            if (Configure::read('debug') !== true) {
                $io->error('--create is refused: the application runs with debug off (production).');

                return null;
            }
            $user = $provisioner->createUser($email);
            Log::warning(sprintf('Emergency sign-in created the user %s.', $email));
        }
        if ($user === null) {
            $io->error(sprintf('No user has the email address %s.', $email));

            return null;
        }
        $state = UserAccess::state($user);
        if ($state !== UserAccess::ACTIVE) {
            $io->error(sprintf('%s has no access now (%s), so no link was made.', $email, $state));

            return null;
        }

        return [$user];
    }

    /**
     * Creates the file for writing, readable by its owner only; never an
     * existing one.
     *
     * @param string $path The path.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return resource|null
     */
    private function createFile(string $path, ConsoleIo $io)
    {
        if (file_exists($path) || is_link($path)) {
            $io->error(sprintf('%s already exists; it is never overwritten.', $path));

            return null;
        }
        if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
            $io->error(sprintf('%s could not be created: its folder is missing or not writable.', $path));

            return null;
        }
        $previous = umask(0077);
        try {
            // 'x' fails rather than overwrite, even if the file appeared meanwhile.
            $handle = fopen($path, 'x');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            $io->error(sprintf('%s could not be created.', $path));

            return null;
        }
        chmod($path, 0600);

        return $handle;
    }
}
