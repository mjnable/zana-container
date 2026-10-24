<?php

declare(strict_types=1);

namespace Zana\Container\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Graph\DependencyEdge;
use Zana\Container\Graph\ServiceGraph;
use Zana\Container\Graph\ServiceNode;
use Zana\Container\Tests\Fixtures\ArraySettingsProvider;
use Zana\Container\Tests\Fixtures\AuditLogger;
use Zana\Container\Tests\Fixtures\Clock;
use Zana\Container\Tests\Fixtures\EmailNotifier;
use Zana\Container\Tests\Fixtures\FileLogger;
use Zana\Container\Tests\Fixtures\LoggerInterface;
use Zana\Container\Tests\Fixtures\Mailer;
use Zana\Container\Tests\Fixtures\NeedsUnregistered;
use Zana\Container\Tests\Fixtures\NotifierHub;
use Zana\Container\Tests\Fixtures\NotifierInterface;
use Zana\Container\Tests\Fixtures\PrefixLogger;
use Zana\Container\Tests\Fixtures\PushNotifier;
use Zana\Container\Tests\Fixtures\SmsNotifier;
use Zana\Container\Tests\Fixtures\UsesInjectedParameters;
use Zana\Container\Tests\Fixtures\WithInjectedMethods;
use Zana\Container\Tests\Fixtures\WithInjectedProperties;
use Zana\Container\Tests\Support\TestServiceCollection;

final class GraphTest extends TestCase
{
    public function testNodesDescribeEveryRegistrationAndAlias(): void
    {
        $graph = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class, origin: 'module:Core')
            ->transient('clock', static fn (): Clock => new Clock())
            ->instance(Clock::class, new Clock(), key: 'fixed')
            ->scoped(Mailer::class, lazy: true)
            ->alias('logger', LoggerInterface::class)
            ->tag('loggers', LoggerInterface::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->decorate(LoggerInterface::class, static fn (object $o): object => $o)
            ->provide('logging', 'module:Core')
            ->build()
            ->graph();

        $nodes = $graph->nodes();
        $this->assertSame(
            [0, LoggerInterface::class, null, 'singleton', 'class', FileLogger::class, 'module:Core', ['loggers'], [PrefixLogger::class, 'closure'], false],
            array_values($nodes[0]->toArray())
        );
        $this->assertSame(['factory', 'transient'], [$nodes[1]->strategy, $nodes[1]->lifetime]);
        $this->assertSame(['instance', 'fixed'], [$nodes[2]->strategy, $nodes[2]->key]);
        $this->assertTrue($nodes[3]->lazy);

        $alias = $this->nodeFor($graph, 'logger');
        $this->assertSame(['alias', null, LoggerInterface::class], [$alias->strategy, $alias->lifetime, $alias->implementation]);
        $this->assertSame(['logging' => ['module:Core']], $graph->capabilities());
    }

    public function testEdgesCoverEveryKindOfDependency(): void
    {
        $graph = (new TestServiceCollection())
            ->addParameterProvider(new ArraySettingsProvider([]))
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->addAttributed(AuditLogger::class)
            ->singleton('primary-logger', FileLogger::class)
            ->singleton(Clock::class)
            ->singleton('lazy-mailer', Mailer::class, lazy: true)
            ->alias('mailer', 'lazy-mailer')
            ->singleton(PushNotifier::class)
            ->transient(NotifierInterface::class, EmailNotifier::class)
            ->transient(NotifierInterface::class, SmsNotifier::class)
            ->tag('notifiers', PushNotifier::class)
            ->transient(Mailer::class)
            ->transient(NotifierHub::class)
            ->transient(UsesInjectedParameters::class)
            ->transient(WithInjectedProperties::class)
            ->transient(WithInjectedMethods::class)
            ->alias('logger', LoggerInterface::class)
            ->decorate(LoggerInterface::class, PrefixLogger::class)
            ->build()
            ->graph();

        $kinds = static fn (string $service): array => array_map(
            static fn (DependencyEdge $edge): string => "{$edge->kind}:{$edge->parameter}",
            $graph->dependenciesOf((int) array_search($service, array_map(static fn (ServiceNode $n): string => $n->service, $graph->nodes()), true))
        );

        $this->assertSame(['constructor:logger'], $kinds(Mailer::class));
        $this->assertSame(['constructor:logger', 'variadic:notifiers', 'variadic:notifiers'], $kinds(NotifierHub::class));
        $this->assertSame(['constructor:audit', 'constructor:primary', 'tagged:notifiers'], $kinds(UsesInjectedParameters::class));
        $this->assertSame(['property:logger', 'property:clock', 'tagged:notifiers'], $kinds(WithInjectedProperties::class));
        $this->assertSame(['method:logger', 'method:clock'], $kinds(WithInjectedMethods::class));
        $this->assertSame(['decorator:clock'], $kinds(LoggerInterface::class));
        $this->assertSame(['alias:'], $kinds('logger'));

        $aliasEdges = $graph->dependenciesOf($this->nodeFor($graph, 'mailer')->id);
        $this->assertCount(1, $aliasEdges);
        $this->assertTrue($aliasEdges[0]->lazy, 'edges into a lazy service are marked');
        $this->assertFalse($graph->dependenciesOf($this->nodeFor($graph, Mailer::class)->id)[0]->lazy);
    }

    public function testToArrayIsStableJson(): void
    {
        $graph = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->transient(Mailer::class)
            ->build()
            ->graph();

        $array = $graph->toArray();
        $this->assertSame(ServiceGraph::SCHEMA, $array['schema']);
        $this->assertSame(['schema', 'nodes', 'edges', 'capabilities'], array_keys($array));

        $roundTrip = ServiceGraph::fromArray(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertEquals($graph, $roundTrip);
    }

    public function testAnalyzeReportsErrorsAndStillReturnsTheGraph(): void
    {
        $services = (new TestServiceCollection())
            ->singleton(LoggerInterface::class, FileLogger::class)
            ->transient(NeedsUnregistered::class);

        $report = $services->analyze();

        $this->assertFalse($report->isValid());
        $this->assertSame(['B3'], array_map(static fn ($e) => $e->code, $report->errors()));
        $this->assertCount(3, $report->graph()->nodes(), 'two registrations plus CapabilitiesInterface');

        $valid = (new TestServiceCollection())->singleton(Clock::class)->analyze();
        $this->assertTrue($valid->isValid());
    }

    private function nodeFor(ServiceGraph $graph, string $service): ServiceNode
    {
        foreach ($graph->nodes() as $node) {
            if ($node->service === $service) {
                return $node;
            }
        }

        $this->fail("No node for {$service}.");
    }
}
