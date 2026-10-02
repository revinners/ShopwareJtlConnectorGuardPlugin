<?php

declare(strict_types=1);

namespace Revinners\ShopwareJtlConnectorGuardPlugin\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\ConnectorSource;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerRerouter;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerState;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\CustomerStateLoader;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogEntry;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\GuardLogger;
use Revinners\ShopwareJtlConnectorGuardPlugin\Service\SamePersonGuardConfig;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\KernelEvents;

final class CustomerRerouterTest extends TestCase
{
    private const GROUP_RETAIL = '0123456789abcdef0123456789abcdef';
    private const GROUP_DEALER = 'fedcba9876543210fedcba9876543210';

    private Connection&MockObject $connection;
    private EntityRepository&MockObject $repository;
    private CustomerStateLoader&MockObject $stateLoader;
    private GuardLogger&MockObject $guardLogger;
    private LoggerInterface&MockObject $logger;
    private CustomerRerouter $rerouter;
    private ConnectorSource $connector;
    private SamePersonGuardConfig $config;
    /** @var list<array{string, string, string|null, string|null, string|null, string|null}> action, field, customerId, current, attempted, assigned */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->stateLoader = $this->createMock(CustomerStateLoader::class);
        $this->guardLogger = $this->createMock(GuardLogger::class);
        $this->guardLogger->method('log')->willReturnCallback(function (GuardLogEntry $e): void {
            $this->logged[] = [$e->action, $e->field, $e->customerId, $e->currentValue, $e->attemptedValue, $e->assignedValue];
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rerouter = new CustomerRerouter($this->connection, $this->repository, $this->stateLoader, $this->guardLogger, $this->logger, $this->createMock(LoggerInterface::class));
        $this->connector = new ConnectorSource('2103c0f8ba934cbdb291287aaa3b5ce8', 'JTL-Connector');
        $this->config = new SamePersonGuardConfig(true, true, true);
    }

    /** The foreign account the connector addressed. */
    private function hit(string $idHex): CustomerState
    {
        return new CustomerState($idHex, ['id' => Uuid::fromHexToBytes($idHex), 'email' => 'retail@example.com']);
    }

    /** The dealer's own account, found by the e-mail the write carried. */
    private function target(string $idHex, array $extra = []): CustomerState
    {
        return new CustomerState($idHex, $extra + [
            'id' => Uuid::fromHexToBytes($idHex),
            'email' => 'dealer@example.com',
            'first_name' => 'Dora',
            'last_name' => 'Dealer',
            'company' => 'Motorrad Dealer',
            'customer_group_id' => Uuid::fromHexToBytes(self::GROUP_RETAIL),
            'vat_ids' => '["DE1"]',
            'customer_number' => '20001',
        ]);
    }

    /** @return array<string, mixed> */
    private function dealerPush(): array
    {
        return [
            'customer_number' => 'C20001',
            'email' => ' dealer@example.com ',
            'first_name' => 'Dora',
            'last_name' => 'Dealer',
            'company' => 'Motorrad Dealer GmbH',
            'customer_group_id' => Uuid::fromHexToBytes(self::GROUP_DEALER),
            'vat_ids' => '["DE1","DE2"]',
            'updated_at' => '2026-10-01 11:01:24.547',
        ];
    }

    public function testFlushesAfterTheControllerAndOnTerminate(): void
    {
        self::assertSame(
            [KernelEvents::RESPONSE => ['flush', -1000], KernelEvents::TERMINATE => 'flush'],
            CustomerRerouter::getSubscribedEvents()
        );
    }

    public function testSubRequestsDoNotFlush(): void
    {
        $this->connection->expects(self::never())->method('fetchAllAssociative');
        $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent(
            $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class),
            new \Symfony\Component\HttpFoundation\Request(),
            \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST,
            new \Symfony\Component\HttpFoundation\Response(),
        );

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush($event);
    }

    public function testNothingIsWrittenBeforeFlush(): void
    {
        $this->connection->expects(self::never())->method('fetchAllAssociative');
        $this->repository->expects(self::never())->method('update');

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
    }

    public function testAppliesTheChangedFieldsToTheSingleRegisteredAccountWithThatEmail(): void
    {
        $hit = Uuid::randomHex();
        $target = Uuid::randomHex();
        $this->connection->expects(self::once())->method('fetchAllAssociative')->with(
            self::stringContains('`guest` = 0'),
            ['email' => 'dealer@example.com', 'hit' => Uuid::fromHexToBytes($hit)],
        )->willReturn([['id' => Uuid::fromHexToBytes($target), 'email' => 'Dealer@Example.com']]);
        $this->stateLoader->method('load')->with([Uuid::fromHexToBytes($target)])->willReturn([$target => $this->target($target)]);

        $this->repository->expects(self::once())->method('update')->with(
            [['id' => $target, 'groupId' => self::GROUP_DEALER, 'company' => 'Motorrad Dealer GmbH', 'vatIds' => ['DE1', 'DE2']]],
            self::callback(static fn (Context $c): bool => $c->getSource() instanceof SystemSource),
        );

        $this->rerouter->queue($this->hit($hit), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();

        self::assertSame([
            [GuardLogEntry::ACTION_REROUTED, 'customer_group_id', $target, self::GROUP_RETAIL, self::GROUP_DEALER, $hit],
            [GuardLogEntry::ACTION_REROUTED, 'company', $target, 'Motorrad Dealer', 'Motorrad Dealer GmbH', $hit],
            [GuardLogEntry::ACTION_REROUTED, 'vat_ids', $target, '["DE1"]', '["DE1","DE2"]', $hit],
        ], $this->logged, 'unchanged name, the number and the e-mail are neither written nor logged');
    }

    public function testFlushRunsEachQueuedWriteOnlyOnce(): void
    {
        $target = Uuid::randomHex();
        $this->connection->expects(self::once())->method('fetchAllAssociative')->willReturn([['id' => Uuid::fromHexToBytes($target), 'email' => 'Dealer@Example.com']]);
        $this->stateLoader->method('load')->willReturn([$target => $this->target($target)]);
        $this->repository->expects(self::once())->method('update');

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();
        $this->rerouter->flush();
    }

    public function testNothingToChangeMeansNoWriteAndNoLog(): void
    {
        $target = Uuid::randomHex();
        $this->connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::fromHexToBytes($target), 'email' => 'Dealer@Example.com']]);
        $this->stateLoader->method('load')->willReturn([$target => $this->target($target, ['company' => 'Motorrad Dealer GmbH', 'customer_group_id' => Uuid::fromHexToBytes(self::GROUP_DEALER), 'vat_ids' => '["DE1", "DE2"]'])]);
        $this->repository->expects(self::never())->method('update');

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();

        self::assertSame([], $this->logged);
    }

    public function testNoRegisteredAccountIsRecordedAndNothingIsWritten(): void
    {
        $hit = Uuid::randomHex();
        $this->connection->method('fetchAllAssociative')->willReturn([]);
        $this->repository->expects(self::never())->method('update');

        $this->rerouter->queue($this->hit($hit), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();

        self::assertSame([[GuardLogEntry::ACTION_REROUTE_SKIPPED, '*', $hit, null, 'dealer@example.com', CustomerRerouter::REASON_NO_ACCOUNT]], $this->logged);
    }

    public function testSeveralRegisteredAccountsAreAmbiguousAndNothingIsWritten(): void
    {
        $hit = Uuid::randomHex();
        $this->connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::randomBytes(), 'email' => 'dealer@example.com'], ['id' => Uuid::randomBytes(), 'email' => 'DEALER@example.com']]);
        $this->repository->expects(self::never())->method('update');

        $this->rerouter->queue($this->hit($hit), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();

        self::assertSame([[GuardLogEntry::ACTION_REROUTE_SKIPPED, '*', $hit, null, 'dealer@example.com', CustomerRerouter::REASON_AMBIGUOUS]], $this->logged);
    }

    public function testOnlyConfiguredColumnsAreRerouted(): void
    {
        $target = Uuid::randomHex();
        $this->connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::fromHexToBytes($target), 'email' => 'Dealer@Example.com']]);
        $this->stateLoader->method('load')->willReturn([$target => $this->target($target)]);
        $this->repository->expects(self::once())->method('update')->with([['id' => $target, 'groupId' => self::GROUP_DEALER]], self::anything());

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), new SamePersonGuardConfig(true, true, true, ['customer_group_id', 'customer_number', 'email']), $this->connector);
        $this->rerouter->flush();
    }

    public function testAWriteWithoutAnEmailIsNotQueued(): void
    {
        $this->connection->expects(self::never())->method('fetchAllAssociative');

        $this->rerouter->queue($this->hit(Uuid::randomHex()), ['company' => 'x'], $this->config, $this->connector);
        $this->rerouter->flush();
    }

    public function testAFailingWriteIsLoggedAndDoesNotStopTheNextOne(): void
    {
        $target = Uuid::randomHex();
        $this->connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::fromHexToBytes($target), 'email' => 'Dealer@Example.com']]);
        $this->stateLoader->method('load')->willReturn([$target => $this->target($target)]);
        $calls = 0;
        $this->repository->expects(self::exactly(2))->method('update')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('boom');
            }

            return $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
        });
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('reroute'), self::anything());

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();

        self::assertSame(GuardLogEntry::ACTION_REROUTE_SKIPPED, $this->logged[0][0], 'the failed write leaves a row in the table');
        self::assertSame(CustomerRerouter::REASON_WRITE_FAILED, $this->logged[0][5]);
        self::assertCount(4, $this->logged, 'one skipped row, then the three rows of the successful write');
    }

    public function testResetDropsTheQueue(): void
    {
        $this->connection->expects(self::never())->method('fetchAllAssociative');

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->reset();
        $this->rerouter->flush();
    }

    public function testAMerelySimilarEmailTheDatabaseCollationMatchesIsNotATarget(): void
    {
        $hit = Uuid::randomHex();
        // utf8mb4_unicode_ci treats é as e: the query returns the row, the guard must not use it
        $this->connection->method('fetchAllAssociative')->willReturn([['id' => Uuid::randomBytes(), 'email' => 'déaler@example.com']]);
        $this->repository->expects(self::never())->method('update');

        $this->rerouter->queue($this->hit($hit), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();

        self::assertSame([[GuardLogEntry::ACTION_REROUTE_SKIPPED, '*', $hit, null, 'dealer@example.com', CustomerRerouter::REASON_NO_ACCOUNT]], $this->logged);
    }

    public function testASimilarAddressDoesNotMakeTheRealMatchAmbiguous(): void
    {
        $target = Uuid::randomHex();
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id' => Uuid::randomBytes(), 'email' => 'déaler@example.com'],
            ['id' => Uuid::fromHexToBytes($target), 'email' => 'dealer@example.com'],
        ]);
        $this->stateLoader->method('load')->with([Uuid::fromHexToBytes($target)])->willReturn([$target => $this->target($target)]);
        $this->repository->expects(self::once())->method('update');

        $this->rerouter->queue($this->hit(Uuid::randomHex()), $this->dealerPush(), $this->config, $this->connector);
        $this->rerouter->flush();
    }
}
