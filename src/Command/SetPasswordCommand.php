<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Log\Log;
use RockSoftware\Users\Service\PasswordSignIn;
use RockSoftware\Users\Service\SignInException;

/**
 * Sets a user's password for the sign-in page's password form. There is no
 * self-service reset: an administrator with a shell sets a new one, or the
 * person signs in through Rock Software ID.
 *
 * The password is read from standard input, never from the command line, so
 * it stays out of the shell history and the process list.
 */
class SetPasswordCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users set_password';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(sprintf(
                'Set a user\'s password (at least %d characters), read from standard input.',
                PasswordSignIn::MIN_LENGTH,
            ))
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
        $passwords = new PasswordSignIn();
        $user = $passwords->findUser($email);
        if ($user === null) {
            $io->error(sprintf('No user has the email address %s.', $email));

            return static::CODE_ERROR;
        }
        if (Configure::read('RockSoftwareUsers.passwordSignIn') !== true) {
            $io->warning('Password sign-in is not offered by this application (RockSoftwareUsers.passwordSignIn).');
        }

        $password = $this->readPassword($io);
        try {
            $passwords->setPassword($user, $password);
        } catch (SignInException $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }
        Log::warning(sprintf('Password set from the command line for %s.', $email));
        $io->out(sprintf('Password set for %s.', $email));

        return static::CODE_SUCCESS;
    }

    /**
     * One line from standard input, not echoed on a terminal.
     *
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return string
     */
    private function readPassword(ConsoleIo $io): string
    {
        $terminal = defined('STDIN') && stream_isatty(STDIN);
        if ($terminal) {
            shell_exec('stty -echo');
        }
        try {
            $password = $io->ask('New password');
        } finally {
            if ($terminal) {
                shell_exec('stty echo');
                $io->out('');
            }
        }

        return rtrim($password, "\r\n");
    }
}
