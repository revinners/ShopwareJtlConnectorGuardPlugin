<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

final class ConnectorSourceDetectorTest extends TestCase
{
    private const INTEGRATION_ID = '019df771764772929f1136e52180ccf6';

    private Connection&MockObject $connection;
    private ConnectorSourceDetector $detector;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->detector = new ConnectorSourceDetector($this->connection);
    }

    /**
     * @return list<string>
     */
    private function ids(array $ids = [self::INTEGRATION_ID]): array
    {
        return $ids;
    }

    public function testSystemSourceIsNotTheConnector(): void
    {
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertNull($this->detector->resolve(Context::createDefaultContext(new SystemSource()), $this->ids()));
    }

    public function testSalesChannelSourceIsNotTheConnector(): void
    {
        $context = Context::createDefaultContext(new SalesChannelApiSource(Uuid::randomHex()));

        self::assertNull($this->detector->resolve($context, $this->ids()));
    }

    public function testAdminUserWriteIsNotTheConnectorEvenWithIntegration(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(Uuid::randomHex(), self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->ids()));
    }

    public function testAdminApiSourceWithoutIntegrationIsNotTheConnector(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(null, null));

        self::assertNull($this->detector->resolve($context, $this->ids()));
    }

    public function testMatchesTheSelectedIntegrationAndNormalisesTheId(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with(self::stringContains('FROM `integration`'), ['id' => Uuid::fromHexToBytes(self::INTEGRATION_ID)])
            ->willReturn('JTL Connector');
        $context = Context::createDefaultContext(new AdminApiSource(null, strtoupper(self::INTEGRATION_ID)));

        $source = $this->detector->resolve($context, $this->ids());

        self::assertNotNull($source);
        self::assertSame(self::INTEGRATION_ID, $source->integrationId, 'id is normalised to lowercase');
        self::assertSame('JTL Connector', $source->label, 'the label is only carried along for the audit log');
    }

    public function testAnIntegrationThatIsNotSelectedIsNotTheConnectorWhateverItIsCalled(): void
    {
        $this->connection->expects(self::never())->method('fetchOne');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->ids(ids: ['2103c0f8ba934cbdb291287aaa3b5ce8'])));
    }

    public function testNothingSelectedMeansNothingIsTheConnector(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->ids(ids: [])));
    }

    public function testAMissingOrUnreadableLabelDoesNotStopTheMatch(): void
    {
        $this->connection->method('fetchOne')->willThrowException(new \RuntimeException('db down'));
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $source = $this->detector->resolve($context, $this->ids());

        self::assertNotNull($source);
        self::assertNull($source->label);
    }

    public function testLabelLookupIsMemoisedPerIntegration(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')->willReturn('JTL Connector');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $this->detector->resolve($context, $this->ids());
        $this->detector->resolve($context, $this->ids());
    }
}
