<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PostWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Shopping Experience layouts of a limited user (see CmsPages):
 *
 * - a layout, its sections, blocks, slots and translations only change when
 *   the layout belongs to their channels only; shared and locked layouts are
 *   read-only, other channels' layouts hidden;
 * - new layouts are allowed and belong to the creator's channels until
 *   something uses them (also copies of shared layouts);
 * - categories, products and landing pages can only get layouts the user sees.
 */
class CmsGuardSubscriber implements EventSubscriberInterface
{
    /** Layout parts: entity => [column of the parent, parent entity]. */
    private const PARTS = [
        'cms_section' => ['cms_page_id', 'cms_page'],
        'cms_block' => ['cms_section_id', 'cms_section'],
        'cms_slot' => ['cms_block_id', 'cms_block'],
    ];

    /** Translations: entity => [primary key column of the parent, parent entity]. */
    private const TRANSLATIONS = [
        'cms_page_translation' => ['cms_page_id', 'cms_page'],
        'cms_slot_translation' => ['cms_slot_id', 'cms_slot'],
    ];

    private const LAYOUT_USERS = ['category', 'product', 'landing_page'];

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly CmsPages $pages,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'onPreWrite',
            PostWriteValidationEvent::class => 'onPostWrite',
        ];
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        $ids = $this->scope->salesChannelIds($event->getContext());
        if ($ids === null) {
            return;
        }

        $commands = $event->getCommands();
        $inserted = $this->inserted($commands);

        foreach ($commands as $command) {
            $reason = $this->deny($command, $ids, $inserted);
            if ($reason !== null) {
                $event->getExceptions()->add(ChannelScopeException::writeDenied($command->getEntityName(), $reason));
            }
        }
    }

    /**
     * A layout created by a limited user belongs to their channels. Runs in
     * the write's transaction, after the layout's row exists.
     */
    public function onPostWrite(PostWriteValidationEvent $event): void
    {
        $ids = $this->scope->salesChannelIds($event->getContext());
        if ($ids === null || $ids === []) {
            return;
        }

        foreach ($event->getCommands() as $command) {
            $id = $command->getPrimaryKey()['id'] ?? null;
            $version = $command->getPrimaryKey()['version_id'] ?? null;
            if ($command instanceof InsertCommand && $command->getEntityName() === 'cms_page' && \is_string($id)
                && ($version === null || Uuid::fromBytesToHex($version) === Defaults::LIVE_VERSION)) {
                $this->pages->addOwners(Uuid::fromBytesToHex($id), $ids);
            }
        }
    }

    /**
     * @param list<string> $ids
     * @param array<string, array<string, ?string>> $inserted entity => id => parent id (hex)
     */
    private function deny(WriteCommand $command, array $ids, array $inserted): ?string
    {
        $entity = $command->getEntityName();
        $payload = $command->getPayload();
        $primaryKey = $command->getPrimaryKey();

        if (\in_array($entity, self::LAYOUT_USERS, true)) {
            $page = $this->hex($payload['cms_page_id'] ?? null);
            if ($page !== null && !\array_key_exists($page, $inserted['cms_page'] ?? []) && !$this->pages->isVisible($page, $ids)) {
                return 'the layout belongs to another sales channel';
            }

            return null;
        }

        if ($entity === 'cms_page') {
            return $command instanceof InsertCommand ? null : $this->denyPage($this->hex($primaryKey['id'] ?? null), $ids, $inserted);
        }

        if (isset(self::TRANSLATIONS[$entity])) {
            [$column, $parent] = self::TRANSLATIONS[$entity];

            return $this->denyPage($this->pageOf($parent, $this->hex($primaryKey[$column] ?? null), $primaryKey, $inserted), $ids, $inserted);
        }

        if (!isset(self::PARTS[$entity])) {
            return null;
        }

        [$column, $parent] = self::PARTS[$entity];
        if (!$command instanceof InsertCommand) {
            $reason = $this->denyPage($this->pages->pageOf($entity, $primaryKey), $ids, $inserted);
            if ($reason !== null) {
                return $reason;
            }
        }
        if (\array_key_exists($column, $payload)) {
            return $this->denyPage($this->pageOf($parent, $this->hex($payload[$column]), $primaryKey, $inserted), $ids, $inserted);
        }

        return null;
    }

    /**
     * @param list<string> $ids
     * @param array<string, array<string, ?string>> $inserted
     */
    private function denyPage(?string $page, array $ids, array $inserted): ?string
    {
        if ($page !== null && \array_key_exists($page, $inserted['cms_page'] ?? [])) {
            return null;
        }
        if ($page === null || !$this->pages->isEditable($page, $ids)) {
            return 'the layout is also used by other sales channels, or it is one of Shopware\'s locked layouts';
        }

        return null;
    }

    /**
     * Layout of a record given by entity and id: inserted in this write, or stored.
     *
     * @param array<string, string> $primaryKey version of the record being written
     * @param array<string, array<string, ?string>> $inserted
     */
    private function pageOf(string $entity, ?string $id, array $primaryKey, array $inserted): ?string
    {
        while ($id !== null && $entity !== 'cms_page') {
            if (!\array_key_exists($id, $inserted[$entity] ?? [])) {
                return $this->pages->pageOf($entity, ['id' => Uuid::fromHexToBytes($id), 'version_id' => $primaryKey['version_id'] ?? Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]);
            }
            $id = $inserted[$entity][$id];
            $entity = self::PARTS[$entity][1];
        }

        return $id;
    }

    /**
     * Layouts and layout parts inserted in this write, with their parent.
     *
     * @param list<WriteCommand> $commands
     *
     * @return array<string, array<string, ?string>>
     */
    private function inserted(array $commands): array
    {
        $inserted = [];
        foreach ($commands as $command) {
            $entity = $command->getEntityName();
            $id = $this->hex($command->getPrimaryKey()['id'] ?? null);
            if (!$command instanceof InsertCommand || $id === null) {
                continue;
            }
            if ($entity === 'cms_page') {
                $inserted['cms_page'][$id] = null;
            } elseif (isset(self::PARTS[$entity])) {
                $inserted[$entity][$id] = $this->hex($command->getPayload()[self::PARTS[$entity][0]] ?? null);
            }
        }

        return $inserted;
    }

    private function hex(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }
}
