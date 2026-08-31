<?php

declare(strict_types=1);

namespace Frosh\Tools\Tests\Components\Health\Checker\HealthChecker;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Frosh\Tools\Components\Health\Checker\HealthChecker\QueueChecker;
use Frosh\Tools\Components\Health\HealthCollection;
use Frosh\Tools\Components\Health\SettingsResult;
use Frosh\Tools\Components\Queue\QueueRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Fast mock-based coverage for QueueChecker config/age edge cases.
 * Real DB/QueryBuilder behaviour is covered by QueueCheckerTest (integration).
 */
#[CoversClass(QueueChecker::class)]
class QueueCheckerUnitTest extends TestCase
{
    public function testEmptyQueueResultsInInfoState(): void
    {
        $result = $this->collect(
            connectionRows: [],
            config: [],
        );

        static::assertSame(SettingsResult::INFO, $result->state);
        static::assertSame('0 mins', $result->current);
    }

    public function testOldMessageResultsInWarningState(): void
    {
        $result = $this->collect(
            connectionRows: [
                [
                    'available_at' => (new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'async',
                ],
            ],
            config: ['FroshTools.config.monitorQueueGraceTime' => 15],
        );

        static::assertSame(SettingsResult::WARNING, $result->state);
        static::assertStringContainsString('async', $result->current);
    }

    public function testRecentMessageWithinGracePeriodResultsInOkState(): void
    {
        $result = $this->collect(
            connectionRows: [
                [
                    'available_at' => (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'async',
                ],
            ],
            config: ['FroshTools.config.monitorQueueGraceTime' => 15],
        );

        static::assertSame(SettingsResult::GREEN, $result->state);
    }

    public function testMessageJustOverGraceIsWarningNotDoubleGrace(): void
    {
        // Regression: previous formula effectively required age > 2 * grace.
        $result = $this->collect(
            connectionRows: [
                [
                    'available_at' => (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'async',
                ],
            ],
            config: ['FroshTools.config.monitorQueueGraceTime' => 15],
        );

        static::assertSame(SettingsResult::WARNING, $result->state);
    }

    public function testFailedQueuesAreExcludedByDefault(): void
    {
        $query = $this->createQueryBuilderMock([]);
        $query->expects(static::once())
            ->method('andWhere')
            ->with('queue_name NOT LIKE :failedPattern')
            ->willReturnSelf();
        $query->expects(static::once())
            ->method('setParameter')
            ->with('failedPattern', '%failed%')
            ->willReturnSelf();

        $result = $this->collectWith(
            connection: $this->connectionReturning($query),
            config: [],
        );

        static::assertSame(SettingsResult::INFO, $result->state);
    }

    public function testFailedQueuesCanBeIncluded(): void
    {
        $query = $this->createQueryBuilderMock([
            [
                'available_at' => (new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'queue_name' => 'async_failed',
            ],
        ]);
        $query->expects(static::never())->method('andWhere');

        $result = $this->collectWith(
            connection: $this->connectionReturning($query),
            config: ['FroshTools.config.monitorExcludeFailedQueues' => false],
        );

        static::assertSame(SettingsResult::WARNING, $result->state);
        static::assertStringContainsString('async_failed', $result->current);
    }

    public function testAllowlistRestrictsMonitoredQueues(): void
    {
        $query = $this->createQueryBuilderMock([
            [
                'available_at' => (new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'queue_name' => 'async',
            ],
        ]);

        $andWhere = [];
        $query->expects(static::exactly(2))
            ->method('andWhere')
            ->willReturnCallback(static function (string $predicate) use ($query, &$andWhere): QueryBuilder {
                $andWhere[] = $predicate;

                return $query;
            });

        $parameters = [];
        $query->expects(static::exactly(2))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value, mixed $type = null) use ($query, &$parameters): QueryBuilder {
                $parameters[$name] = ['value' => $value, 'type' => $type];

                return $query;
            });

        $result = $this->collectWith(
            connection: $this->connectionReturning($query),
            config: [
                'FroshTools.config.monitorQueues' => 'async, low_priority',
                'FroshTools.config.monitorQueueGraceTime' => 15,
            ],
        );

        static::assertContains('queue_name NOT LIKE :failedPattern', $andWhere);
        static::assertContains('queue_name IN (:queues)', $andWhere);
        static::assertSame('%failed%', $parameters['failedPattern']['value']);
        static::assertSame(['async', 'low_priority'], $parameters['queues']['value']);
        static::assertSame(ArrayParameterType::STRING, $parameters['queues']['type']);
        static::assertSame(SettingsResult::GREEN, $result->state);
    }

    public function testPerQueueGraceTimeOverridesDefault(): void
    {
        $result = $this->collect(
            connectionRows: [
                [
                    'available_at' => (new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'low_priority',
                ],
            ],
            config: [
                'FroshTools.config.monitorQueueGraceTime' => 15,
                'FroshTools.config.monitorQueueGraceTimes' => 'low_priority:60, async:10',
            ],
        );

        // 30 mins old with 60 min grace for low_priority → OK
        static::assertSame(SettingsResult::GREEN, $result->state);
        static::assertSame('max 60 mins', $result->recommended);
    }

    public function testPerQueueGraceTimeCanTightenDefault(): void
    {
        $result = $this->collect(
            connectionRows: [
                [
                    'available_at' => (new \DateTimeImmutable('-12 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'async',
                ],
            ],
            config: [
                'FroshTools.config.monitorQueueGraceTime' => 15,
                'FroshTools.config.monitorQueueGraceTimes' => 'async:10',
            ],
        );

        static::assertSame(SettingsResult::WARNING, $result->state);
        static::assertSame('max 10 mins', $result->recommended);
    }

    public function testShorterGraceQueueIsNotMaskedByOlderLooserQueue(): void
    {
        // Greptile P1: oldest global message was on low_priority (grace 120), which masked
        // a newer async message that already exceeded async's shorter grace (10).
        $result = $this->collect(
            connectionRows: [
                [
                    'available_at' => (new \DateTimeImmutable('-90 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'low_priority',
                ],
                [
                    'available_at' => (new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'queue_name' => 'async',
                ],
            ],
            config: [
                'FroshTools.config.monitorQueueGraceTime' => 15,
                'FroshTools.config.monitorQueueGraceTimes' => 'async:10, low_priority:120',
            ],
        );

        static::assertSame(SettingsResult::WARNING, $result->state);
        static::assertStringContainsString('async', $result->current);
        static::assertSame('max 10 mins', $result->recommended);
    }

    public function testRedisTransportWithOldMessageResultsInWarningState(): void
    {
        // A shop running purely on Redis never writes to messenger_messages, so the
        // Doctrine query alone would always report "0 mins" no matter how backed up the
        // Redis queue actually is.
        $queueRegistry = $this->createMock(QueueRegistry::class);
        $queueRegistry->method('all')->willReturn([
            'async' => $this->fakeQueueAdapter(ageSeconds: 2 * 60 * 60),
        ]);

        $result = $this->collectWith(
            connection: $this->connectionReturning($this->createQueryBuilderMock([])),
            config: ['FroshTools.config.monitorQueueGraceTime' => 15],
            queueRegistry: $queueRegistry,
        );

        static::assertSame(SettingsResult::WARNING, $result->state);
        static::assertStringContainsString('async', $result->current);
    }

    public function testRedisTransportWithRecentMessageResultsInOkState(): void
    {
        $queueRegistry = $this->createMock(QueueRegistry::class);
        $queueRegistry->method('all')->willReturn([
            'async' => $this->fakeQueueAdapter(ageSeconds: 60),
        ]);

        $result = $this->collectWith(
            connection: $this->connectionReturning($this->createQueryBuilderMock([])),
            config: ['FroshTools.config.monitorQueueGraceTime' => 15],
            queueRegistry: $queueRegistry,
        );

        static::assertSame(SettingsResult::GREEN, $result->state);
    }

    public function testAmqpCountOnlyTransportFallsBackToPendingCount(): void
    {
        // AMQP cannot report an age at all, only a count, so it can never win the
        // age-based comparison — it only surfaces when nothing else could be aged.
        $queueRegistry = $this->createMock(QueueRegistry::class);
        $queueRegistry->method('all')->willReturn([
            'async' => $this->fakeQueueAdapter(ageSeconds: null, messageCount: 7),
        ]);

        $result = $this->collectWith(
            connection: $this->connectionReturning($this->createQueryBuilderMock([])),
            config: [],
            queueRegistry: $queueRegistry,
        );

        static::assertSame(SettingsResult::INFO, $result->state);
        static::assertSame('7 pending', $result->current);
    }

    public function testFailedNonDoctrineTransportIsExcludedByDefault(): void
    {
        $queueRegistry = $this->createMock(QueueRegistry::class);
        $queueRegistry->method('all')->willReturn([
            'async_failed' => $this->fakeQueueAdapter(ageSeconds: 2 * 60 * 60),
        ]);

        $result = $this->collectWith(
            connection: $this->connectionReturning($this->createQueryBuilderMock([])),
            config: [],
            queueRegistry: $queueRegistry,
        );

        static::assertSame(SettingsResult::INFO, $result->state);
        static::assertSame('0 mins', $result->current);
    }

    private function fakeQueueAdapter(?int $ageSeconds, ?int $messageCount = null): \Frosh\Tools\Components\Queue\QueueAdapter
    {
        $adapter = $this->createMock(\Frosh\Tools\Components\Queue\QueueAdapter::class);
        $adapter->method('getOldestMessageAge')->willReturn($ageSeconds);
        $adapter->method('getMessageCount')->willReturn($messageCount);

        return $adapter;
    }

    /**
     * @param list<array{available_at: string, queue_name: string}> $connectionRows
     * @param array<string, mixed> $config
     */
    private function collect(array $connectionRows, array $config): SettingsResult
    {
        return $this->collectWith(
            connection: $this->connectionReturning($this->createQueryBuilderMock($connectionRows)),
            config: $config,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function collectWith(Connection $connection, array $config, ?QueueRegistry $queueRegistry = null): SettingsResult
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('getInt')->willReturnCallback(
            static fn (string $key): int => (int) ($config[$key] ?? 0),
        );
        $configService->method('getString')->willReturnCallback(
            static fn (string $key): string => (string) ($config[$key] ?? ''),
        );
        $configService->method('get')->willReturnCallback(
            static fn (string $key): mixed => $config[$key] ?? null,
        );

        if ($queueRegistry === null) {
            $queueRegistry = $this->createMock(QueueRegistry::class);
            $queueRegistry->method('all')->willReturn([]);
        }

        $collection = new HealthCollection();
        (new QueueChecker($connection, $configService, $queueRegistry))->collect($collection);

        foreach ($collection->getElements() as $element) {
            if ($element->id === 'queue') {
                return $element;
            }
        }

        static::fail('HealthCollection does not contain a result with id "queue"');
    }

    /**
     * @param list<array{available_at: string, queue_name: string}> $rows
     */
    private function createQueryBuilderMock(array $rows): QueryBuilder&MockObject
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('select')->willReturnSelf();
        $query->method('from')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('andWhere')->willReturnSelf();
        $query->method('groupBy')->willReturnSelf();
        $query->method('orderBy')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->method('setParameter')->willReturnSelf();
        $query->method('fetchAllAssociative')->willReturn($rows);

        return $query;
    }

    private function connectionReturning(QueryBuilder $query): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($query);

        return $connection;
    }
}
