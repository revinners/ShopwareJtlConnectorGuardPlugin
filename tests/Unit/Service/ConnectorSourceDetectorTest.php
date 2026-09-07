<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSourceDetector;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardConfig;
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

    private function config(array $labels = ['JTL-Connector'], array $ids = []): GuardConfig
    {
        return new GuardConfig(true, true, $labels, $ids, ['customer_number']);
    }

    public function testSystemSourceIsNotTheConnector(): void
    {
        $this->connection->expects(self::never())->method('fetchOne');

        self::assertNull($this->detector->resolve(Context::createDefaultContext(new SystemSource()), $this->config()));
    }

    public function testSalesChannelSourceIsNotTheConnector(): void
    {
        $context = Context::createDefaultContext(new SalesChannelApiSource(Uuid::randomHex()));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testAdminUserWriteIsNotTheConnectorEvenWithIntegration(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(Uuid::randomHex(), self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->config(ids: [self::INTEGRATION_ID])));
    }

    public function testAdminApiSourceWithoutIntegrationIsNotTheConnector(): void
    {
        $context = Context::createDefaultContext(new AdminApiSource(null, null));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testMatchesByConfiguredIdWithoutTouchingLabelsMatch(): void
    {
        $this->connection->method('fetchOne')->willReturn('Something else');
        $context = Context::createDefaultContext(new AdminApiSource(null, strtoupper(self::INTEGRATION_ID)));

        $source = $this->detector->resolve($context, $this->config(labels: ['Nope'], ids: [self::INTEGRATION_ID]));

        self::assertNotNull($source);
        self::assertSame(self::INTEGRATION_ID, $source->integrationId, 'id is normalised to lowercase');
        self::assertSame('Something else', $source->label);
    }

    public function testMatchesByLabelCaseInsensitively(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')
            ->with(
                self::stringContains('FROM `integration`'),
                ['id' => Uuid::fromHexToBytes(self::INTEGRATION_ID)]
            )
            ->willReturn('jtl-connector');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $source = $this->detector->resolve($context, $this->config(labels: ['JTL-Connector']));

        self::assertNotNull($source);
        self::assertSame('jtl-connector', $source->label);
    }

    #[DataProvider('provideEquivalentLabels')]
    public function testMatchesLabelIgnoringWhitespaceAndPunctuation(string $dbLabel): void
    {
        $this->connection->method('fetchOne')->willReturn($dbLabel);
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $source = $this->detector->resolve($context, $this->config(labels: ['JTL-Connector']));

        self::assertNotNull($source);
        self::assertSame($dbLabel, $source->label);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideEquivalentLabels(): iterable
    {
        yield 'space instead of hyphen' => ['JTL Connector'];
        yield 'underscore, lowercase' => ['jtl_connector'];
        yield 'extra spaces and dashes' => ['  JTL - connector '];
    }

    public function testEmptyLabelsNeverMatch(): void
    {
        $this->connection->method('fetchOne')->willReturn('---');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $source = $this->detector->resolve($context, $this->config(labels: ['---']));

        self::assertNull($source);
    }

    public function testUnknownIntegrationIsNotTheConnector(): void
    {
        $this->connection->method('fetchOne')->willReturn('Some other API client');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testDeletedOrMissingIntegrationIsNotTheConnector(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        self::assertNull($this->detector->resolve($context, $this->config()));
    }

    public function testLabelLookupIsMemoisedPerIntegration(): void
    {
        $this->connection->expects(self::once())->method('fetchOne')->willReturn('JTL-Connector');
        $context = Context::createDefaultContext(new AdminApiSource(null, self::INTEGRATION_ID));

        $this->detector->resolve($context, $this->config());
        $this->detector->resolve($context, $this->config());
    }
}
