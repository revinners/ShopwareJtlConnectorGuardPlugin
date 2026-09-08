<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerAddressStateLoader;
use Shopware\Core\Framework\Uuid\Uuid;

final class CustomerAddressStateLoaderTest extends TestCase
{
    public function testLoadsRowsKeyedByHexId(): void
    {
        $id = Uuid::randomHex();
        $customerId = Uuid::randomHex();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains('FROM `customer_address` WHERE `id` IN (:ids)'),
                ['ids' => [Uuid::fromHexToBytes($id)]],
                ['ids' => ArrayParameterType::BINARY]
            )
            ->willReturn([[
                'id' => Uuid::fromHexToBytes($id),
                'customer_id' => Uuid::fromHexToBytes($customerId),
                'street' => 'Nelkenweg 12',
                'zipcode' => '63814',
                'city' => 'Mainaschaff',
            ]]);

        $states = (new CustomerAddressStateLoader($connection))->load([Uuid::fromHexToBytes($id)]);

        self::assertArrayHasKey($id, $states);
        $state = $states[$id];
        self::assertSame($id, $state->id);
        self::assertSame($customerId, $state->getCustomerId());
        self::assertSame('Nelkenweg 12', $state->get('street'));
        self::assertNull($state->get('does_not_exist'));
        self::assertSame(['id', 'customer_id', 'street', 'zipcode', 'city'], array_keys($state->columns()));
    }

    public function testEmptyInputSkipsTheQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        self::assertSame([], (new CustomerAddressStateLoader($connection))->load([]));
    }

    public function testNullCustomerIsTolerated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::randomBytes(), 'customer_id' => null]]);

        $state = array_values((new CustomerAddressStateLoader($connection))->load([Uuid::randomBytes()]))[0];

        self::assertNull($state->getCustomerId());
    }
}
