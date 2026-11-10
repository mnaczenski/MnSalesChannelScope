<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Command;

use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Preset\PermissionPresets;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds a preset's privileges to an ACL role (existing privileges are kept).
 */
#[AsCommand(name: 'mn:channel-scope:preset', description: 'Add a permission preset (e.g. "orders") to an ACL role')]
class ApplyPresetCommand extends Command
{
    public function __construct(
        private readonly PermissionPresets $presets,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('preset', InputArgument::REQUIRED, 'Preset name, e.g. orders');
        $this->addArgument('role', InputArgument::REQUIRED, 'Role name or id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $privileges = $this->presets->privileges((string) $input->getArgument('preset'));
        $role = (string) $input->getArgument('role');

        $row = $this->connection->fetchAssociative(
            'SELECT id, name, privileges FROM acl_role WHERE deleted_at IS NULL AND (name = :role OR id = :id)',
            ['role' => $role, 'id' => Uuid::isValid($role) ? Uuid::fromHexToBytes($role) : ''],
        );
        if ($row === false) {
            $output->writeln(\sprintf('<error>Role "%s" not found.</error>', $role));

            return self::FAILURE;
        }

        $current = json_decode((string) $row['privileges'], true) ?: [];
        $merged = array_values(array_unique([...$current, ...$privileges]));
        sort($merged);

        $this->connection->executeStatement(
            'UPDATE acl_role SET privileges = :privileges, updated_at = NOW(3) WHERE id = :id',
            ['privileges' => json_encode($merged, \JSON_THROW_ON_ERROR), 'id' => $row['id']],
        );

        $output->writeln(\sprintf('Role "%s": %d privileges added, %d in total.', $row['name'], \count($merged) - \count($current), \count($merged)));

        return self::SUCCESS;
    }
}
