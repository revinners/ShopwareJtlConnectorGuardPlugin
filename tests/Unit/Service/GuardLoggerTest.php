<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
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

        (new GuardLogger($logger, $connection))->log($this->entry());
    }

    public function testDbFailureIsSwallowedAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not persist'), self::anything());

        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('table gone'));

        (new GuardLogger($logger, $connection))->log($this->entry());
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

        (new GuardLogger($logger, $this->createMock(Connection::class)))->log($this->entry(mode: 'log_only'));
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

        (new GuardLogger($logger, $this->createMock(Connection::class)))->log(new GuardLogEntry(
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

        (new GuardLogger($logger, $this->createMock(Connection::class)))->log(new GuardLogEntry(
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
}
