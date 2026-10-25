<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Prints the stored provider connection as JSON, without the secret: whether
 * one is configured and enabled, its issuer, client ID and required group.
 *
 * Read by deployment tooling through `docker exec` to tell whether a
 * development deployment or preview is connected to the client it should be.
 */
class StatusCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users status';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription('Print the identity provider connection as JSON, without the secret.');
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \RockSoftware\Users\Model\Table\SettingsTable $settings */
        $settings = $this->fetchTable('RockSoftware/Users.Settings');
        $setting = $settings->current();

        $io->out((string)json_encode([
            'configured' => $setting !== null,
            'enabled' => $setting !== null && $setting->is_enabled,
            'issuer' => $setting?->issuer_url,
            'client_id' => $setting?->client_id,
            'required_group' => $setting?->required_group,
        ], JSON_UNESCAPED_SLASHES));

        return static::CODE_SUCCESS;
    }
}
