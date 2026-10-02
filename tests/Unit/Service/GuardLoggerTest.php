<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Shopware\Core\Framework\Uuid\Uuid;

final class GuardLoggerTest extends TestCase
{
    private function entry(string $mode = 'enforce'): GuardLogEntry
    {
        return new GuardLogEntry(
            action: GuardLogEntry::ACTION_BLOCKED_UPDATE,
            mode: $mode,
            field: 'customer_number',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'C10009',
            attemptedValue: '10009',
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
        );
    }

    public function testWritesToMonologAndToTheDbTable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::logicalAnd(
                self::stringContains('blocked_update'),
                self::stringContains('erdoesi@example.com'),
                self::stringContains('C10009'),
                self::stringContains('10009'),
            ),
            self::callback(static fn (array $ctx): bool => $ctx['action'] === 'blocked_update' && $ctx['attemptedValue'] === '10009')
        );
        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::never())->method('error');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static function (array $row): bool {
                return \strlen($row['id']) === 16
                    && $row['customer_id'] === Uuid::fromHexToBytes('019df771764772929f1136e52180ccf6')
                    && $row['integration_id'] === Uuid::fromHexToBytes('2103c0f8ba934cbdb291287aaa3b5ce8')
                    && $row['sales_channel_id'] === null
                    && $row['action'] === 'blocked_update'
                    && $row['mode'] === 'enforce'
                    && $row['field'] === 'customer_number'
                    && $row['current_value'] === 'C10009'
                    && $row['attempted_value'] === '10009'
                    && $row['assigned_value'] === null
                    && $row['email'] === 'erdoesi@example.com'
                    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $row['created_at']) === 1;
            })
        );

        (new GuardLogger($logger, $fallback, $connection))->log($this->entry());
    }

    public function testDbFailureIsSwallowedAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not persist'), self::anything());
        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::never())->method('error');

        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('table gone'));

        (new GuardLogger($logger, $fallback, $connection))->log($this->entry());
    }

    /**
     * F1 regression: the channel logger's info() call must not be able to prevent the DB
     * insert, and its own failure must be reported on the fallback logger instead of escaping.
     */
    public function testChannelLoggerInfoFailureDoesNotBlockTheDbInsertAndIsReportedOnFallback(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->willThrowException(new \RuntimeException('stream could not be opened'));
        $logger->expects(self::never())->method('error');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert');

        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::once())->method('error')->with(self::anything(), self::anything());

        (new GuardLogger($logger, $fallback, $connection))->log($this->entry());
    }

    /**
     * F1 regression: when the DB insert fails AND the channel logger's error() call also
     * throws (same broken sink), the fallback logger must still get the error report.
     */
    public function testDbFailureAndChannelLoggerErrorFailureBothReportedOnFallback(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::once())->method('error')->willThrowException(new \RuntimeException('stream could not be opened'));

        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('table gone'));

        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::once())->method('error')->with(self::stringContains('could not persist'), self::anything());

        (new GuardLogger($logger, $fallback, $connection))->log($this->entry());
    }

    /**
     * Regression (local verification on yam-shop 6.6.10.18): in log_only the connector's value
     * IS written, so a line claiming the old value was "kept" misreports the default mode.
     */
    public function testLogOnlyUpdateMessageDoesNotClaimTheValueWasKept(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::callback(static function (string $message): bool {
                return !str_contains($message, 'kept "C10009",')
                    && str_contains($message, 'connector sent "10009" over "C10009" and it was applied')
                    && str_contains($message, 'enforce mode would have kept "C10009"');
            }),
            self::anything(),
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))->log($this->entry(mode: 'log_only'));
    }

    /**
     * Regression: an insert has no current value, so the create line must name the assigned
     * number instead of printing an empty `kept ""`.
     */
    public function testRemappedCreateMessageNamesTheAssignedNumber(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::callback(static function (string $message): bool {
                return !str_contains($message, 'kept ""')
                    && str_contains($message, 'connector sent "51520", assigned "10011" from the shop number range');
            }),
            self::anything(),
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))->log(new GuardLogEntry(
            action: GuardLogEntry::ACTION_REMAPPED_CREATE,
            mode: 'enforce',
            field: 'customer_number',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'new@example.com',
            firstName: 'Guard',
            lastName: 'Created',
            currentValue: null,
            attemptedValue: '51520',
            assignedValue: '10011',
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL Connector',
            salesChannelId: null,
        ));
    }

    public function testLogOnlyCreateMessageSaysTheConnectorNumberWasApplied(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::callback(static fn (string $message): bool => str_contains(
                $message,
                'connector sent "51520" and it was applied; enforce mode would have assigned a number from the shop range'
            )),
            self::anything(),
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))->log(new GuardLogEntry(
            action: GuardLogEntry::ACTION_REMAPPED_CREATE,
            mode: 'log_only',
            field: 'customer_number',
            customerId: null,
            email: 'new@example.com',
            firstName: 'Guard',
            lastName: 'Created',
            currentValue: null,
            attemptedValue: '51520',
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL Connector',
            salesChannelId: null,
        ));
    }

    public function testEntryToArrayIsFlat(): void
    {
        $array = $this->entry()->toArray();

        self::assertSame('blocked_update', $array['action']);
        self::assertSame('Adam', $array['firstName']);
        self::assertArrayHasKey('integrationLabel', $array);
    }

    public function testAddressRowCarriesEntityAndEntityIdAndTruncatesLongValues(): void
    {
        $addressId = Uuid::randomHex();
        $long = str_repeat('x', 300);
        $entry = new GuardLogEntry(
            action: GuardLogEntry::ACTION_BLOCKED_MISMATCH,
            mode: 'enforce',
            field: 'street',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'Nelkenweg 12',
            attemptedValue: $long,
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
            entity: GuardLogEntry::ENTITY_CUSTOMER_ADDRESS,
            entityId: $addressId,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::logicalAnd(
                self::stringContains('blocked_mismatch'),
                self::stringContains('customer_address ' . $addressId . '.street'),
                self::stringContains('kept "Nelkenweg 12"'),
                self::stringContains($long),
            ),
            self::callback(static fn (array $ctx): bool => $ctx['entity'] === 'customer_address' && $ctx['entityId'] === $addressId && $ctx['attemptedValue'] === $long)
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static function (array $row) use ($addressId): bool {
                return $row['entity'] === 'customer_address'
                    && $row['entity_id'] === Uuid::fromHexToBytes($addressId)
                    && $row['field'] === 'street'
                    && mb_strlen($row['attempted_value']) === 255
                    && str_ends_with($row['attempted_value'], '…')
                    && $row['current_value'] === 'Nelkenweg 12';
            })
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $connection))->log($entry);
    }

    /**
     * F1 regression: `field` is VARCHAR(64); a long custom-field key (`custom_fields.<key>`) was
     * inserted untruncated and would fail the DB write once it exceeded the column width.
     */
    public function testFieldColumnIsTruncatedToItsOwnWidth(): void
    {
        $longField = 'custom_fields.' . str_repeat('x', 90);
        $entry = new GuardLogEntry(
            action: GuardLogEntry::ACTION_BLOCKED_MISMATCH,
            mode: 'enforce',
            field: $longField,
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'a',
            attemptedValue: 'b',
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
        );

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static function (array $row): bool {
                return mb_strlen($row['field']) === 64 && str_ends_with($row['field'], '…');
            })
        );

        (new GuardLogger($this->createMock(LoggerInterface::class), $this->createMock(LoggerInterface::class), $connection))->log($entry);
    }

    public function testCustomerRowDefaultsEntityToCustomerWithoutEntityId(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'revinners_jtl_guard_log',
            self::callback(static fn (array $row): bool => $row['entity'] === 'customer' && $row['entity_id'] === null)
        );

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $connection))->log($this->entry());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function actionMessages(): iterable
    {
        yield 'observed_address_create' => [GuardLogEntry::ACTION_OBSERVED_ADDRESS_CREATE, 'enforce', 'connector created it with "Grasiger Weg 20" (cannot be blocked, recorded)'];
        yield 'observed_address_delete' => [GuardLogEntry::ACTION_OBSERVED_ADDRESS_DELETE, 'enforce', 'connector deleted it, had "Nelkenweg 12" (cannot be blocked, recorded)'];
        yield 'blocked_mismatch' => [GuardLogEntry::ACTION_BLOCKED_MISMATCH, 'enforce', 'kept "Nelkenweg 12", connector sent "Grasiger Weg 20" (same-person check: the write carries a different e-mail)'];
        yield 'observed_mismatch' => [GuardLogEntry::ACTION_OBSERVED_MISMATCH, 'log_only', 'connector sent "Grasiger Weg 20" over "Nelkenweg 12" and it was applied (same-person check, observed only'];
        yield 'rejected_write' => [GuardLogEntry::ACTION_REJECTED_WRITE, 'enforce', 'whole connector write rejected (policy reject_write)'];
    }

    #[DataProvider('actionMessages')]
    public function testActionMessages(string $action, string $mode, string $expected): void
    {
        $entry = new GuardLogEntry(
            action: $action,
            mode: $mode,
            field: 'street',
            customerId: '019df771764772929f1136e52180ccf6',
            email: 'erdoesi@example.com',
            firstName: 'Adam',
            lastName: 'Erdösi',
            currentValue: 'Nelkenweg 12',
            attemptedValue: 'Grasiger Weg 20',
            assignedValue: null,
            integrationId: '2103c0f8ba934cbdb291287aaa3b5ce8',
            integrationLabel: 'JTL-Connector',
            salesChannelId: null,
            entity: GuardLogEntry::ENTITY_CUSTOMER_ADDRESS,
            entityId: 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4',
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains($expected), self::anything());

        (new GuardLogger($logger, $this->createMock(LoggerInterface::class), $this->createMock(Connection::class)))->log($entry);
    }
}
