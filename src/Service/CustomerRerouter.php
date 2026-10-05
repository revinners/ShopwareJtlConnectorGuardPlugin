<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Feature 004, second half: a write the same-person check kept away from a foreign account is
 * applied to the account it was meant for. JTL-Wawi's data about the customer is right, only the
 * shop account it addresses is wrong, so the e-mail it sent identifies the intended account: the
 * single registered (non-guest) customer with that e-mail. None or several -> nothing is written,
 * the attempt is recorded.
 *
 * A write is queued only from the write event's success callback, i.e. once the connector's own
 * commands were executed without error; it is applied on kernel.response of the main request,
 * after the controller returned, through the customer repository with a system context — so it
 * cannot roll back the connector's write and is not mistaken for a connector write by the guard
 * itself. A write that was rolled back is never queued. kernel.terminate is subscribed as well, as a net for anything queued later; it is
 * not relied upon (it was observed not to reach this listener on the Apache/mod_php dev shop).
 */
final class CustomerRerouter implements EventSubscriberInterface, ResetInterface
{
    /**
     * Storage column => [DAL property, kind]. Only these columns can be rerouted; the number and
     * the e-mail never are (the number is the shop's, the e-mail is the key).
     */
    private const COLUMNS = [
        'customer_group_id' => ['groupId', 'id'],
        'salutation_id' => ['salutationId', 'id'],
        'first_name' => ['firstName', 'scalar'],
        'last_name' => ['lastName', 'scalar'],
        'title' => ['title', 'scalar'],
        'company' => ['company', 'scalar'],
        'account_type' => ['accountType', 'scalar'],
        'vat_ids' => ['vatIds', 'json'],
    ];

    public const REASON_NO_ACCOUNT = 'no_registered_account';

    public const REASON_AMBIGUOUS = 'several_registered_accounts';

    public const REASON_WRITE_FAILED = 'write_failed';

    /** @var list<array{hitId: string, email: string, sent: array<string, mixed>, config: SamePersonGuardConfig, connector: ConnectorSource, salesChannelId: ?string}> */
    private array $queue = [];

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Customer\CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $customerRepository,
        private readonly CustomerStateLoader $stateLoader,
        private readonly GuardLogger $guardLogger,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $fallbackLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['flush', -1000],
            KernelEvents::TERMINATE => 'flush',
        ];
    }

    /**
     * @return list<string> storage columns this class is able to reroute
     */
    public static function supportedColumns(): array
    {
        return array_keys(self::COLUMNS);
    }

    /**
     * Remembers a write that was kept away from $hit. Nothing is written here.
     *
     * @param array<string, mixed> $sent the payload exactly as the connector sent it (storage names, DB-encoded)
     */
    public function queue(CustomerState $hit, array $sent, SamePersonGuardConfig $config, ConnectorSource $connector): void
    {
        $email = trim((string) ($sent['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $this->queue[] = [
            'hitId' => $hit->id,
            'email' => $email,
            'sent' => $sent,
            'config' => $config,
            'connector' => $connector,
            'salesChannelId' => $hit->getSalesChannelId(),
        ];
    }

    public function flush(?KernelEvent $event = null): void
    {
        if ($event !== null && !$event->isMainRequest()) {
            return;
        }

        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $item) {
            try {
                $this->reroute($item);
            } catch (\Throwable $e) {
                $this->error(sprintf('jtl_connector_guard: reroute of the write kept away from customer %s failed: %s', $item['hitId'], $e->getMessage()), ['exception' => $e, 'customerId' => $item['hitId']]);
            }
        }
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    /**
     * @param array{hitId: string, email: string, sent: array<string, mixed>, config: SamePersonGuardConfig, connector: ConnectorSource, salesChannelId: ?string} $item
     */
    private function reroute(array $item): void
    {
        // MySQL's collation is looser than the same-person check (accent-insensitive, pads spaces):
        // `jose@` would match `josé@`. The candidates are therefore re-checked with the very
        // comparison the check uses, so a write can never reach a merely similar address.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT `id`, `email` FROM `customer` WHERE `email` = :email AND `guest` = 0 AND `id` != :hit LIMIT 20',
            ['email' => $item['email'], 'hit' => Uuid::fromHexToBytes($item['hitId'])]
        );
        $ids = [];
        foreach ($rows as $row) {
            if (Values::sameEmail($row['email'] ?? null, $item['email'])) {
                $ids[] = $row['id'];
            }
        }

        if (\count($ids) !== 1) {
            $this->guardLogger->log($this->entry(
                GuardLogEntry::ACTION_REROUTE_SKIPPED,
                $item,
                '*',
                $item['hitId'],
                null,
                $item['email'],
                $ids === [] ? self::REASON_NO_ACCOUNT : self::REASON_AMBIGUOUS,
                null,
            ));

            return;
        }

        $targetBytes = (string) $ids[0];
        $targetHex = Uuid::fromBytesToHex($targetBytes);
        $target = $this->stateLoader->load([$targetBytes])[$targetHex] ?? null;
        if ($target === null) {
            $this->guardLogger->log($this->entry(GuardLogEntry::ACTION_REROUTE_SKIPPED, $item, '*', $item['hitId'], null, $item['email'], self::REASON_NO_ACCOUNT, null));

            return;
        }

        $data = [];
        $entries = [];
        foreach ($item['config']->rerouteFields as $column) {
            if (!isset(self::COLUMNS[$column]) || !\array_key_exists($column, $item['sent'])) {
                continue;
            }
            $attempted = $item['sent'][$column];
            $current = $target->get($column);
            if (Values::sameStorage($attempted, $current)) {
                continue;
            }

            [$property, $kind] = self::COLUMNS[$column];
            // The connector does not send every field JTL-Wawi holds: an empty value in the write
            // is "not transferred", not "delete it". It is never rerouted — it must not erase
            // what the shop has, and writing empty over empty would only be noise.
            if (self::isEmpty($kind, $attempted)) {
                continue;
            }

            $data[$property] = self::decode($kind, $attempted);
            $entries[] = $this->entry(
                GuardLogEntry::ACTION_REROUTED,
                $item,
                $column,
                $targetHex,
                Values::render($column, $current),
                Values::render($column, $attempted),
                $item['hitId'],
                $target,
            );
        }

        if ($data === []) {
            return;
        }

        try {
            $this->customerRepository->update([['id' => $targetHex] + $data], Context::createDefaultContext());
        } catch (\Throwable $e) {
            // e.g. a group or salutation id the shop does not know: nothing was written. The
            // table must still say so, not only the log file.
            $this->guardLogger->log($this->entry(GuardLogEntry::ACTION_REROUTE_SKIPPED, $item, '*', $item['hitId'], null, $item['email'], self::REASON_WRITE_FAILED, null));

            throw $e;
        }

        foreach ($entries as $entry) {
            $this->guardLogger->log($entry);
        }
    }

    private static function isEmpty(string $kind, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if ($kind === 'json') {
            return (\is_array($value) ? $value : Values::decodeJson($value)) === [];
        }

        return \is_string($value) && trim($value) === '';
    }

    private static function decode(string $kind, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($kind) {
            'id' => \is_string($value) && \strlen($value) === 16 ? Uuid::fromBytesToHex($value) : $value,
            'json' => \is_string($value) ? array_values(Values::decodeJson($value)) : $value,
            default => $value,
        };
    }

    /**
     * `assigned_value` carries the id of the account the connector addressed (rerouted) or the
     * reason nothing was written (reroute_skipped).
     *
     * @param array{hitId: string, email: string, sent: array<string, mixed>, config: SamePersonGuardConfig, connector: ConnectorSource, salesChannelId: ?string} $item
     */
    private function entry(string $action, array $item, string $field, string $customerId, ?string $current, ?string $attempted, string $assigned, ?CustomerState $target): GuardLogEntry
    {
        return new GuardLogEntry(
            action: $action,
            mode: $item['config']->mode(),
            field: $field,
            customerId: $customerId,
            email: $target?->getEmail(),
            firstName: $target?->getFirstName(),
            lastName: $target?->getLastName(),
            currentValue: $current,
            attemptedValue: $attempted,
            assignedValue: $assigned,
            integrationId: $item['connector']->integrationId,
            integrationLabel: $item['connector']->label,
            salesChannelId: $target?->getSalesChannelId() ?? $item['salesChannelId'],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(string $message, array $context): void
    {
        try {
            $this->logger->error($message, $context);
        } catch (\Throwable) {
            try {
                $this->fallbackLogger->error($message, $context);
            } catch (\Throwable) {
                // both loggers broken; nothing left to do
            }
        }
    }
}
