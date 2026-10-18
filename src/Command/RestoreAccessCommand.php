<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\UserAccess;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * Gives one person access back from the server, when a mistake in Rock
 * Software ID or a bug took it away: active again, inside their dates, any
 * held removal dropped. It is recorded in their access history, and the next
 * push from Rock Software ID overrules it, so fix the cause there too.
 */
class RestoreAccessCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users restore_access';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Give one person access back from the server. Rock Software ID\'s next push overrules it.')
            ->addArgument('email', ['help' => 'Email address of the user.', 'required' => true]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $email = (string)$args->getArgument('email');
        $user = (new UserProvisioner())->findByEmail($email);
        if ($user === null) {
            $io->error(sprintf('No user has the email address %s.', $email));

            return static::CODE_ERROR;
        }
        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable $held */
        $held = $this->fetchTable('RockSoftware/Users.HeldRevocations');
        if (UserAccess::allows($user) && $held->pendingFor((int)$user->get('id')) === null) {
            $io->out(sprintf('%s already has access.', $email));

            return static::CODE_SUCCESS;
        }

        (new AccessManager())->restore($user);
        Log::warning(sprintf('Access restored on the server for %s.', $email));
        $io->success(sprintf(
            '%s has access again. Fix the cause in Rock Software ID too: its next push overrules this.',
            $email,
        ));

        return static::CODE_SUCCESS;
    }
}
