<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Shopware\Core\Framework\Uuid\Uuid;

final class CustomerStateLoaderTest extends TestCase
{
    public function testLoadsRowsKeyedByHexId(): void
    {
        $id = Uuid::randomHex();
        $salesChannelId = Uuid::randomHex();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains('FROM `customer` WHERE `id` IN (:ids)'),
                ['ids' => [Uuid::fromHexToBytes($id)]],
                ['ids' => ArrayParameterType::BINARY]
            )
            ->willReturn([[
                'id' => Uuid::fromHexToBytes($id),
                'customer_number' => '100123',
                'email' => 'a@b.de',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
                'customer_group_id' => Uuid::randomBytes(),
            ]]);

        $states = (new CustomerStateLoader($connection))->load([Uuid::fromHexToBytes($id)]);

        self::assertArrayHasKey($id, $states);
        $state = $states[$id];
        self::assertSame($id, $state->id);
        self::assertSame('100123', $state->getCustomerNumber());
        self::assertSame('a@b.de', $state->getEmail());
        self::assertSame('Ada', $state->getFirstName());
        self::assertSame('Lovelace', $state->getLastName());
        self::assertSame($salesChannelId, $state->getSalesChannelId());
        self::assertSame('100123', $state->get('customer_number'));
        self::assertNull($state->get('does_not_exist'));
    }

    public function testEmptyInputSkipsTheQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame([], (new CustomerStateLoader($connection))->load([]));
    }

    public function testNullSalesChannelIsTolerated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'id' => Uuid::randomBytes(),
            'customer_number' => 'X',
            'sales_channel_id' => null,
        ]]);

        $state = array_values((new CustomerStateLoader($connection))->load([Uuid::randomBytes()]))[0];

        self::assertNull($state->getSalesChannelId());
        self::assertNull($state->getEmail());
    }
}
