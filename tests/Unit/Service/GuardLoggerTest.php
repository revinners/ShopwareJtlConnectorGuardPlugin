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
    private function entry(): GuardLogEntry
    {
        return new GuardLogEntry(
            action: GuardLogEntry::ACTION_BLOCKED_UPDATE,
            mode: 'enforce',
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

    public function testEntryToArrayIsFlat(): void
    {
        $array = $this->entry()->toArray();

        self::assertSame('blocked_update', $array['action']);
        self::assertSame('Adam', $array['firstName']);
        self::assertArrayHasKey('integrationLabel', $array);
    }
}
