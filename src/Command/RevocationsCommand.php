<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use RockSoftware\Users\Model\Entity\RevocationType;
use RockSoftware\Users\Service\AccessManager;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * The removals the mass-removal stop held, on the command line: list them,
 * approve them all (access goes) or reject them all (access stays). The same
 * as on the users page, for when nobody who may open it can sign in.
 */
class RevocationsCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users revocations';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('List, approve or reject the removals the mass-removal stop held.')
            ->addArgument('action', [
                'help' => 'list (default), approve or reject.',
                'choices' => ['list', 'approve', 'reject'],
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \RockSoftware\Users\Model\Table\HeldRevocationsTable $table */
        $table = $this->fetchTable('RockSoftware/Users.HeldRevocations');
        $pending = $table->pending();
        $action = (string)($args->getArgument('action') ?? 'list');
        if ($pending === []) {
            $io->out('No removal is on hold.');

            return static::CODE_SUCCESS;
        }

        $provisioner = new UserProvisioner();
        if ($action === 'list') {
            foreach ($pending as $held) {
                $user = $provisioner->findById($held->user_id);
                $io->out(sprintf(
                    '%s  %s  %s',
                    $held->created->format('Y-m-d H:i'),
                    $user === null ? '#' . $held->user_id : (string)$user->get(UserProvisioner::EMAIL),
                    RevocationType::label($held->revocation_type->value ?? ''),
                ));
            }
            $io->out(sprintf(
                '%d on hold. Approve or reject them all with `%s approve|reject`.',
                count($pending),
                self::defaultName(),
            ));

            return static::CODE_SUCCESS;
        }

        $access = new AccessManager();
        foreach ($pending as $held) {
            $action === 'approve' ? $access->approve($held, null) : $access->reject($held, null);
        }
        $io->success(sprintf(
            $action === 'approve' ? 'Access removed for %d people.' : '%d people keep their access.',
            count($pending),
        ));

        return static::CODE_SUCCESS;
    }
}
