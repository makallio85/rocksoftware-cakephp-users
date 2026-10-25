<?php
declare(strict_types=1);

namespace RockSoftware\Users\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\EntityInterface;
use RockSoftware\Users\Service\UserProvisioner;

/**
 * Before Rock Software ID starts pushing people to an application that already
 * has users, shows what SCIM will do with each, with the same matching rules:
 * by the Rock Software ID link, else by email address to a row nobody is linked
 * to, else a new row. It changes nothing.
 *
 * The people come as JSON exported from Rock Software ID, on stdin or from
 * `--file`.
 *
 * What to fix before switching SCIM on:
 * - **would duplicate**: a new row would be made while the person already has a
 *   row here under another of their addresses.
 * - **address taken**: the person's address belongs to a row linked to
 *   another account, so the push is refused.
 * - **would lose access**: a row linked to an account Rock Software ID will not
 *   push here, which its full sync then removes.
 * And for information: rows not in Rock Software ID, which stay without a link.
 */
class ScimPrecheckCommand extends Command
{
    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'rock_software_users scim_precheck';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Show what switching on SCIM would do to this application\'s users. Changes nothing.')
            ->addOption('file', ['help' => 'The people JSON from Rock Software ID; read from stdin when left out.']);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments.
     * @param \Cake\Console\ConsoleIo $io IO.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $file = $args->getOption('file');
        $json = is_string($file) && $file !== ''
            ? (is_readable($file) ? (string)file_get_contents($file) : '')
            : (string)stream_get_contents(STDIN);
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['people'] ?? null)) {
            $io->error('Expected the people JSON from Rock Software ID (with a "people" list).');

            return static::CODE_ERROR;
        }

        $report = $this->compare($data['people']);
        $io->out(sprintf(
            'SCIM pre-check for %s: %d people from Rock Software ID, %d users here.',
            (string)($data['application'] ?? 'this application'),
            count($data['people']),
            $report['userCount'],
        ));
        $sections = [
            'would_duplicate' => 'Would duplicate (fix first: give the existing row the address '
                . 'Rock Software ID sends, or link it)',
            'address_taken' => 'Address taken by another linked user (fix first)',
            'would_lose_access' => 'Linked, but Rock Software ID will not push them here: '
                . 'they would lose access (fix first if wrong)',
            'link_by_email' => 'Will be linked to their existing row by email address',
            'linked' => 'Already linked',
            'create' => 'Will be created as new users',
            'not_in_id' => 'Users here who are not in Rock Software ID (stay without a link)',
        ];
        foreach ($sections as $key => $title) {
            $io->out('');
            $io->out(sprintf('%s: %d', $title, count($report[$key])));
            foreach ($report[$key] as $line) {
                $io->out('  - ' . $line);
            }
        }
        $toFix = count($report['would_duplicate'])
            + count($report['address_taken'])
            + count($report['would_lose_access']);
        $io->out('');
        if ($toFix === 0) {
            $io->success('Nothing to fix: SCIM can be switched on.');
        } else {
            $io->warning(sprintf('%d to fix before SCIM is switched on.', $toFix));
        }

        return static::CODE_SUCCESS;
    }

    /**
     * @param array<mixed> $people The people Rock Software ID would push.
     * @return array<string, mixed>
     */
    public function compare(array $people): array
    {
        $provisioner = new UserProvisioner();
        $users = $provisioner->users();
        $byId = [];
        $byEmail = [];
        foreach ($users->find()->all() as $user) {
            /** @var \Cake\Datasource\EntityInterface $user */
            $byId[(int)$user->get('id')] = $user;
            $byEmail[mb_strtolower((string)$user->get(UserProvisioner::EMAIL))] = $user;
        }
        $linkByUser = [];
        $linkByExternal = [];
        foreach ($provisioner->identities()->find()->all() as $identity) {
            /** @var \RockSoftware\Users\Model\Entity\Identity $identity */
            $linkByUser[$identity->user_id] = $identity->external_id;
            $linkByExternal[$identity->external_id] = $identity->user_id;
        }
        $system = UserProvisioner::systemUserIds();
        $describe = fn(EntityInterface $user): string => sprintf(
            '#%d %s (%s)',
            (int)$user->get('id'),
            (string)$user->get(UserProvisioner::EMAIL),
            UserProvisioner::displayName($user),
        );

        $report = array_fill_keys(
            ['would_duplicate', 'address_taken', 'would_lose_access', 'link_by_email', 'linked', 'create', 'not_in_id'],
            [],
        );
        $covered = [];
        $pushed = [];
        foreach ($people as $person) {
            if (
                !is_array($person)
                || !is_string($person['externalId'] ?? null)
                || !is_string($person['email'] ?? null)
            ) {
                continue;
            }
            $externalId = $person['externalId'];
            $email = mb_strtolower($person['email']);
            $label = sprintf('%s (%s)', $email, (string)($person['name'] ?? $person['userName'] ?? ''));
            $pushed[$externalId] = true;

            if (isset($linkByExternal[$externalId], $byId[$linkByExternal[$externalId]])) {
                $covered[$linkByExternal[$externalId]] = true;
                $report['linked'][] = $describe($byId[$linkByExternal[$externalId]]);
                continue;
            }
            $match = $byEmail[$email] ?? null;
            if ($match !== null && !isset($linkByUser[(int)$match->get('id')])) {
                $covered[(int)$match->get('id')] = true;
                $report['link_by_email'][] = $label . ' → ' . $describe($match);
                continue;
            }
            if ($match !== null) {
                $report['address_taken'][] = $label . ': ' . $describe($match) . ' is linked to another account';
                continue;
            }
            $existing = null;
            foreach ((array)($person['otherEmails'] ?? []) as $other) {
                $candidate = is_string($other) ? ($byEmail[mb_strtolower($other)] ?? null) : null;
                if ($candidate !== null && !isset($linkByUser[(int)$candidate->get('id')])) {
                    $existing = $candidate;
                    break;
                }
            }
            if ($existing !== null) {
                $covered[(int)$existing->get('id')] = true;
                $report['would_duplicate'][] = $label . ' would be new, but is already ' . $describe($existing);
                continue;
            }
            $report['create'][] = $label;
        }

        foreach ($byId as $id => $user) {
            if (isset($covered[$id]) || in_array($id, $system, true)) {
                continue;
            }
            $linkedElsewhere = isset($linkByUser[$id]) && !isset($pushed[$linkByUser[$id]]);
            if ($linkedElsewhere && (bool)$user->get(UserProvisioner::IS_ACTIVE)) {
                $report['would_lose_access'][] = $describe($user);
            } elseif (!isset($linkByUser[$id])) {
                $report['not_in_id'][] = $describe($user);
            }
        }
        $report['userCount'] = count($byId);

        return $report;
    }
}
