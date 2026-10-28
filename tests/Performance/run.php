<?php

/*
 * This file is part of Monsieur Biz' Sales Reports plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

$processStart = hrtime(true);

// Deliberately no application bootstrap, dotenv, kernel, or tests/Application.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/Mapping.php';
require __DIR__ . '/Fixtures.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use SalesReportsBenchmark\Fixtures;
use SalesReportsBenchmark\Mapping;
use Sylius\Component\Core\Model;

$options = getopt('', ['orders:', 'load', 'load-only', 'source:', 'limit:', 'fanout', 'multiitem', 'unitless', 'catalogue:', 'explain', 'trace']);
$catalogueInput = $options['catalogue'] ?? '2';
if (!in_array($catalogueInput, ['2', '1000'], true)) {
    throw new RuntimeException('--catalogue must be 2 or 1000.');
}
$catalogue = (int) $catalogueInput;
$limit = (float) ($options['limit'] ?? 180);
if (!is_finite($limit) || $limit <= 0 || $limit > 180) {
    throw new RuntimeException('Limit must be > 0 and <= 180 seconds.');
}
$orders = (int) ($options['orders'] ?? 365000);
$fanout = isset($options['fanout']);
$multiitem = isset($options['multiitem']);
$unitless = isset($options['unitless']);
if (2 !== $catalogue && ($fanout || $multiitem || $unitless)) {
    throw new RuntimeException('--fanout, --multiitem and --unitless require --catalogue=2.');
}
if ($unitless && (10 !== $orders || !isset($options['load']) || isset($options['load-only']) || $fanout || $multiitem)) {
    throw new RuntimeException('--unitless requires --orders=10 --load and forbids --load-only, --fanout and --multiitem.');
}
if ($multiitem && (10 !== $orders || !isset($options['load']) || isset($options['load-only']) || $fanout)) {
    throw new RuntimeException('--multiitem requires --orders=10 --load and forbids --load-only and --fanout.');
}
if ($fanout && (10 !== $orders || !isset($options['load']) || isset($options['load-only']))) {
    throw new RuntimeException('--fanout requires --orders=10 --load and forbids --load-only.');
}
$scenario = $unitless ? 'unitless' : ($multiitem ? 'multiitem' : ($fanout ? 'fanout' : 'standard'));
if (!in_array($orders, [10, 36500, 365000, 1000000], true)) {
    throw new RuntimeException('Allowed orders: 10 (control only), 36500, 365000, 1000000.');
}
$url = getenv('SALES_REPORT_BENCHMARK_DATABASE_URL');
$parts = false === $url ? false : parse_url($url);
if (!$parts || !in_array($parts['scheme'] ?? '', ['mysql', 'pdo-mysql', 'pdo_mysql'], true)
    || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
    || !preg_match('~^/sales_reports_benchmark(?:_[a-z0-9]+)?$~D', $parts['path'] ?? '')
    || isset($parts['query']) || isset($parts['fragment'])) {
    throw new RuntimeException('Set explicit loopback MySQL SALES_REPORT_BENCHMARK_DATABASE_URL with database sales_reports_benchmark[_suffix], no query parameters.');
}
$source = realpath($options['source'] ?? dirname(__DIR__, 2) . '/src');
if (false === $source) {
    throw new RuntimeException('Source directory not found.');
}
require $source . '/Repository/AbstractReportRepository.php';
require $source . '/Repository/ReportRepository.php';

$config = ORMSetup::createConfiguration(true);
$config->setMetadataDriverImpl(new Mapping());
$parameters = ['driver' => 'pdo_mysql', 'host' => '[::1]' === $parts['host'] ? '::1' : $parts['host'],
    'port' => $parts['port'] ?? 3306, 'user' => rawurldecode($parts['user'] ?? ''), 'password' => rawurldecode($parts['pass'] ?? ''),
    'charset' => 'utf8mb4'];
$db = DriverManager::getConnection($parameters);
$version = (string) $db->fetchOne('SELECT VERSION()');
if (!preg_match('/^8\./', $version) || false !== stripos($version, 'mariadb')) {
    throw new RuntimeException('This benchmark requires real MySQL 8, got ' . $version);
}
$databaseSettings = array_map('intval', $db->fetchAssociative('SELECT @@innodb_buffer_pool_size AS buffer_pool_bytes, @@tmp_table_size AS temp_table_bytes, @@max_heap_table_size AS heap_table_bytes'));
if (isset($options['load']) || isset($options['load-only'])) {
    $db->executeStatement('CREATE DATABASE IF NOT EXISTS `' . substr($parts['path'], 1) . '` CHARACTER SET utf8mb4');
}
$db->close();
$parameters['dbname'] = substr($parts['path'], 1);
$db = DriverManager::getConnection($parameters);
$em = EntityManager::create($db, $config);
$metadata = $em->getMetadataFactory()->getAllMetadata();
if (isset($options['load']) || isset($options['load-only'])) {
    // Only these dedicated bench_* tables are dropped; never drop an entire database.
    $tool = new SchemaTool($em);
    $tool->dropSchema($metadata);
    $tool->createSchema($metadata);
    Fixtures::load($db, $orders, $fanout, $multiitem, $catalogue, $unitless);
    $db->executeStatement("CREATE TABLE IF NOT EXISTS bench_manifest (orders_count INT NOT NULL, scenario VARCHAR(16) NOT NULL DEFAULT 'standard', catalogue_size INT NOT NULL DEFAULT 2)");
    $manifestColumns = $db->createSchemaManager()->listTableColumns('bench_manifest');
    if (!isset($manifestColumns['scenario'])) {
        $db->executeStatement("ALTER TABLE bench_manifest ADD scenario VARCHAR(16) NOT NULL DEFAULT 'standard'");
    }
    if (!isset($manifestColumns['catalogue_size'])) {
        $db->executeStatement('ALTER TABLE bench_manifest ADD catalogue_size INT NOT NULL DEFAULT 2');
    }
    $db->executeStatement('DELETE FROM bench_manifest');
    $db->insert('bench_manifest', ['orders_count' => $orders, 'scenario' => $scenario, 'catalogue_size' => $catalogue]);
    if (isset($options['load-only'])) {
        echo json_encode(['loaded_orders' => $orders, 'excluded_orders' => 4, 'scenario' => $scenario,
            'catalogue_size' => $catalogue, 'mysql' => $version]) . \PHP_EOL;
        exit(0);
    }
}
$manifest = $db->fetchAssociative('SELECT * FROM bench_manifest');
// Manifests from the original harness predate scenarios and contain only standard fixtures.
if (!$manifest || (int) $manifest['orders_count'] !== $orders || ($manifest['scenario'] ?? 'standard') !== $scenario
    || (int) ($manifest['catalogue_size'] ?? 2) !== $catalogue
    || (int) $db->fetchOne('SELECT COUNT(*) FROM bench_order') !== $orders + 4
    || (int) $db->fetchOne('SELECT COUNT(*) FROM bench_variant') !== $catalogue
    || (int) $db->fetchOne('SELECT COUNT(*) FROM bench_product') !== (2 === $catalogue ? 1 : 100)) {
    throw new RuntimeException('Fixture count, scenario or catalogue mismatch; run --load for this exact profile.');
}

// Interface doubles ONLY forward createQueryBuilder to real Doctrine repositories.
// All reports, DQL, SQL joins, filtering, hydration and aggregation are real.
$factory = new class('benchmark') extends PHPUnit\Framework\TestCase {
    public function wrap(string $interface, Doctrine\ORM\EntityRepository $repository): object
    {
        $double = method_exists($interface, 'createQueryBuilder') ? $this->createMock($interface) :
            $this->getMockBuilder($interface)->onlyMethods(get_class_methods($interface))->addMethods(['createQueryBuilder'])->getMock();
        $double->method('createQueryBuilder')->willReturnCallback(static fn ($alias) => $repository->createQueryBuilder($alias));

        return $double;
    }
};
$report = new MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportRepository(
    $factory->wrap(Sylius\Component\Core\Repository\OrderRepositoryInterface::class, $em->getRepository(Model\Order::class)),
    $factory->wrap(Sylius\Component\Core\Repository\ProductVariantRepositoryInterface::class, $em->getRepository(Model\ProductVariant::class)),
    null,
    null,
);
$channel = $em->getReference(Model\Channel::class, 1);
$locale = new Sylius\Component\Locale\Model\Locale();
$locale->setCode('en_US');
// Initialize channel proxy before the measured interval.
$channel->getId();
$em->initializeObject($channel);
$channel->setDefaultLocale($locale);
if (isset($options['explain'])) {
    $builderFile = $source . '/Repository/ReportAggregateQueryBuilder.php';
    if (!is_file($builderFile) || !method_exists($report, 'appendOrderFilters')) {
        throw new RuntimeException('--explain requires the native data builder and shared order filters in the selected source.');
    }
    require_once $builderFile;
    $builder = new MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportAggregateQueryBuilder();
    $filters = new ReflectionMethod($report, 'appendOrderFilters');
    $parserProperty = new ReflectionProperty(Doctrine\ORM\Query::class, 'parserResult');
    $plans = [];
    foreach (['order_base' => 'createOrderBaseQuery', 'item_base' => 'createItemBaseQuery', 'unit_adjustments' => 'createUnitAdjustmentsQuery',
        'item_adjustments' => 'createItemAdjustmentsQuery', 'order_adjustments' => 'createOrderAdjustmentsQuery',
        'product_counts' => 'createProductOrderCountsQuery'] as $shape => $method) {
        $base = $em->getRepository(Model\Order::class)->createQueryBuilder('o');
        $filtered = $filters->invoke($report, $base, $channel, new DateTime('2025-01-01 00:00:00'), new DateTime('2025-12-31 23:59:59'));
        $query = $builder->{$method}($filtered)->getQuery();
        $sql = $query->getSQL();
        $mappings = $parserProperty->getValue($query)->getParameterMappings();
        $bindings = [];
        $types = [];
        foreach ($query->getParameters() as $parameter) {
            $value = $parameter->getValue();
            $type = $parameter->getType();
            if ($value instanceof Model\ChannelInterface) {
                $value = $value->getId();
                $type = Doctrine\DBAL\ParameterType::INTEGER;
            } else {
                $value = $query->processParameterValue($value);
            }
            foreach ($mappings[$parameter->getName()] ?? [] as $position) {
                $bindings[$position] = $value;
                $types[$position] = $type;
            }
        }
        ksort($bindings);
        ksort($types);
        $plan = $db->fetchOne('EXPLAIN FORMAT=JSON ' . $sql, array_values($bindings), array_values($types));
        $plans[$shape] = ['sql' => $sql, 'plan' => json_decode($plan, true, 512, \JSON_THROW_ON_ERROR)];
    }
    echo json_encode(['diagnostic_only' => true, 'orders' => $orders, 'catalogue_size' => $catalogue, 'source' => $source,
        'mysql' => $version, 'plans' => $plans], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . \PHP_EOL;
    exit(0);
}
$native = $db->getNativeConnection();
if (!$native instanceof PDO) {
    throw new RuntimeException('Native PDO connection required for server-side query deadlines.');
}
$logger = new class(isset($options['trace']), $native, $limit) implements SQLLogger {
    public int $count = 0;

    public array $seconds = [];

    private int $start = 0;

    public int $origin = 0;

    public function __construct(private bool $trace, private PDO $native, private float $limit)
    {
    }

    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        $deadline = $this->origin + (int) ($this->limit * 1e9);
        $remaining = (int) max(1, floor(($deadline - hrtime(true)) / 1e6));
        // Native PDO avoids recursively invoking this DBAL report-query logger.
        if (false === $this->native->exec('SET SESSION max_execution_time = ' . $remaining)) {
            throw new RuntimeException('Unable to enforce the server-side report query deadline.');
        }
        ++$this->count;
        $this->start = hrtime(true);
        if ($this->trace) {
            fwrite(\STDERR, json_encode(['event' => 'query_start', 'ordinal' => $this->count,
                'elapsed_seconds' => ($this->start - $this->origin) / 1e9]) . \PHP_EOL);
        }
    }

    public function stopQuery(): void
    {
        $this->seconds[] = (hrtime(true) - $this->start) / 1e9;
    }
};
$db->getConfiguration()->setSQLLogger($logger);
$from = new DateTimeImmutable('2025-01-01');
$to = new DateTimeImmutable('2025-12-31');
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$startMemory = memory_get_usage(true);
$start = hrtime(true);
$logger->origin = $start;
$outputs = [];
$bulk = method_exists($report, 'getReportsForChannelForDates');
if (!$bulk && !isset($options['source'])) {
    throw new RuntimeException('The current source must expose the optimized entry point. Use --source to measure a baseline.');
}
if ($bulk) {
    $results = $report->getReportsForChannelForDates($channel, $from, $to);
    foreach (['sales' => 'total_sales_result', 'average' => 'average_sales_result',
        'products' => 'product_sales_result', 'variants' => 'product_variant_sales_result',
        'options' => 'product_option_sales_result', 'option_values' => 'product_option_value_sales_result'] as $key => $resultKey) {
        $outputs[$key] = $results[$resultKey];
    }
} else {
    foreach ([
        'sales' => 'getSalesForChannelForDates',
        'average' => 'getAverageSalesForChannelForDates',
        'products' => 'getProductSalesForChannelForDates',
        'variants' => 'getProductVariantSalesForChannelForDates',
        'options' => 'getProductOptionSalesForChannelForDates',
        'option_values' => 'getProductOptionValueSalesForChannelForDates',
    ] as $key => $method) {
        $outputs[$key] = $report->{$method}($channel, $from, $to);
    }
}
$seconds = (hrtime(true) - $start) / 1e9;
$peak = memory_get_peak_usage(true);
$db->getConfiguration()->setSQLLogger(null);
$expected = Fixtures::expected($orders, $fanout, $multiitem, $catalogue, $unitless);
$assert = static function ($actual, $expected, string $label): void {
    if ($actual != $expected) {
        throw new RuntimeException($label . ' mismatch: ' . json_encode(['actual' => $actual, 'expected' => $expected]));
    }
};
$expectedQueries = !isset($options['source']) || method_exists($report, 'getAggregateReports') ? 8 : ($bulk ? 4 : 16);
$assert($logger->count, $expectedQueries, 'actual report data SQL query count');
if (10 === $orders) {
    $referenceTotals = $multiitem ? [
        'without_tax_total' => 29000, 'without_tax_promo_total' => -965,
        'without_tax_shipping_total' => 4800, 'tax_total' => 5800, 'total' => 38835,
        'item_row_total' => 34035, 'item_qty_total' => 29, 'number_of_elements' => 0,
    ] : [
        'without_tax_total' => 28000, 'without_tax_promo_total' => $fanout ? -952 : -920,
        'without_tax_shipping_total' => 4800, 'tax_total' => 5600, 'total' => $fanout ? 37648 : 37680,
        'item_row_total' => $fanout ? 32848 : 32880, 'item_qty_total' => 28, 'number_of_elements' => 0,
    ];
    if ($unitless) {
        $referenceTotals = ['without_tax_total' => 26200, 'without_tax_promo_total' => -875,
            'without_tax_shipping_total' => 4800, 'tax_total' => 5000, 'total' => 37680,
            'item_row_total' => 32880, 'item_qty_total' => 28, 'number_of_elements' => 0];
    }
    $assert($expected, $referenceTotals, 'literal reference fixture');
}
$assert($outputs['sales'], $expected, 'sales exact control totals');
$average = array_map(static fn ($value) => round($value / $orders), $expected);
$average['number_of_elements'] = $orders;
$assert($outputs['average'], $average, 'average');
$groupExpected = $expected;
$groupExpected['total'] = 0;
$groupExpected['without_tax_shipping_total'] = 0;
$groupExpected['without_tax_promo_total'] += 20 * $orders;
$variantCounts = Fixtures::expectedVariants($orders, $catalogue, $multiitem);
$productCounts = Fixtures::expectedProducts($orders, $catalogue, $multiitem);
$groupDefinitions = ['products' => [], 'variants' => [], 'options' => [], 'option_values' => []];
foreach (['products' => $productCounts, 'variants' => $variantCounts] as $key => $groups) {
    foreach ($groups as $id => $counts) {
        $counts['elements'] = $counts['orders'];
        $counts['extra_promotion'] = $fanout && 1 === $id ? 32 : 0;
        $counts['unitless'] = $unitless && 1 === $id;
        $groupDefinitions[$key][$id] = $counts;
        if ('variants' === $key) {
            $counts['elements'] = 1;
            $groupDefinitions['option_values'][Fixtures::variantCode($id, $catalogue)] = $counts;
        }
    }
}
$groupDefinitions['options']['size'] = ['items' => $orders + ($multiitem ? 1 : 0),
    'units' => $expected['item_qty_total'], 'elements' => count(array_unique(array_column($variantCounts, 'representative'))),
    'extra_promotion' => $fanout ? 32 : 0, 'unitless' => $unitless];
foreach (['products', 'variants', 'options', 'option_values'] as $key) {
    $totals = array_fill_keys(array_keys($groupExpected), 0);
    foreach ($outputs[$key] as $row) {
        foreach ($totals as $field => $_) {
            if ('number_of_elements' !== $field) {
                $totals[$field] += $row[$field];
            }
        }
    }
    $groupExpected['number_of_elements'] = 0;
    $assert($totals, $groupExpected, $key . ' exact financial totals');
    $actualIds = array_keys($outputs[$key]);
    $expectedIds = array_keys($groupDefinitions[$key]);
    sort($actualIds);
    sort($expectedIds);
    $assert($actualIds, $expectedIds, $key . ' exact sold group IDs');
    foreach ($outputs[$key] as $id => $row) {
        $counts = $groupDefinitions[$key][$id];
        $itemCount = $counts['items'];
        $units = $counts['units'];
        $extraPromotion = $counts['extra_promotion'];
        // Options count distinct representative order IDs from variant summaries, not underlying orders.
        $assert($row['number_of_elements'], $counts['elements'], $key . ' element count');
        $assert($row['total'], 0, $key . ' group order total');
        $assert($row['without_tax_shipping_total'], 0, $key . ' group shipping');
        $assert($row['item_qty_total'], $units, $key . ' group quantity');
        $assert($row['without_tax_total'], $units * 1000 - ($counts['unitless'] ? 1800 : 0), $key . ' group net');
        $assert($row['without_tax_promo_total'], -15 * $units - 30 * $itemCount - $extraPromotion + ($counts['unitless'] ? 45 : 0), $key . ' group promotions');
        $assert($row['tax_total'], 200 * $units - ($counts['unitless'] ? 600 : 0), $key . ' group tax');
        $assert($row['item_row_total'], 1185 * $units - 30 * $itemCount - $extraPromotion, $key . ' group item total');
    }
}
echo json_encode(['description' => 'Synthetic structural SQL stress test, not a full business workflow recreation',
    'source' => $source, 'orders' => $orders, 'excluded_orders' => 4, 'scenario' => $scenario, 'seed' => 2025, 'mysql' => $version,
    'catalogue_size' => $catalogue, 'sold_product_groups' => count($productCounts), 'sold_variant_groups' => count($variantCounts),
    'database_settings' => $databaseSettings, 'bootstrap_seconds' => ($start - $processStart) / 1e9,
    'strategy' => $bulk ? 'report-wide controller entry point' : 'six public methods (baseline)', 'queries' => $logger->count,
    'query_execution_seconds' => $logger->seconds,
    'seconds' => $seconds, 'limit_seconds' => $limit, 'memory_start_bytes' => $startMemory,
    'peak_memory_bytes' => $peak, 'peak_delta_bytes' => $peak - $startMemory,
    'checks' => 'all six outputs checked against deterministic integer formulas', 'outputs' => $outputs], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . \PHP_EOL;
if ($seconds > $limit) {
    fwrite(\STDERR, "FAIL: report exceeded {$limit} seconds\n");
    exit(1);
}
