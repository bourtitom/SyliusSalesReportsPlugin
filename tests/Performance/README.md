# Isolated MySQL report benchmark

This is a **synthetic structural SQL stress test**, not a full Sylius business-workflow recreation. It uses actual report repository code, real Doctrine DQL/SQL joins and real Sylius 1.14 model classes with deliberately minimal explicit metadata. Repository-interface doubles forward query builders to real Doctrine repositories; report results and database access are not mocked. No application, kernel, `.env`, or `tests/Application` is loaded.

## Measured database profile

The supplied `compose.yaml` caps MySQL at 3 GiB and sets a 2 GiB InnoDB pool,
one buffer-pool instance, and 64 MiB temp/heap limits. Binary logging is disabled
for this disposable service. The runner records actual pool/temp/heap settings.
Check them: MySQL can round a requested pool up when several instances are used.
Use the same database settings and PHP limit for both sources.

The final million-order, 1000-variant runs passed in 105.65–151.08 seconds with
18 MiB PHP peak memory. A smaller 1.5 GiB pool had both a timeout and a passing
run. These observations do not guarantee latency on another machine or cache
state. Full results and limits are in `docs/performance.md` and
`docs/performance-results.json`.

Keep at least 5 GiB free before the million-order load. Shared Docker disk
usage can grow during loading. Check available space; do not prune other
projects' images, containers, caches or volumes without the user's consent.

## Run

1. Install the isolated dependencies: `composer install --working-dir=tests/Performance`.
2. Start a dedicated local MySQL 8 service and create its empty benchmark database. Set the URL explicitly, for example:

   ```sh
   export SALES_REPORT_BENCHMARK_DATABASE_URL='mysql://benchmark:password@127.0.0.1:3306/sales_reports_benchmark'
   ```

   Only loopback MySQL URLs and database names `sales_reports_benchmark` or `sales_reports_benchmark_[a-z0-9]+` are accepted. URL query parameters are rejected. Credentials with special characters must be URL-encoded. `--load`/`--load-only` creates the validated database if absent (requires CREATE permission); otherwise create it beforehand. Fixture loading replaces only mapped `bench_*` tables, not the database. Use a dedicated database and a user restricted to it.

3. Load and verify the literal ten-order reference case:

   ```sh
   php -d memory_limit=2G tests/Performance/run.php --orders=10 --load
   ```

4. Load a requested scale separately, outside measurement and watchdog timing:

   ```sh
   php -d memory_limit=2G tests/Performance/run.php --orders=36500 --load-only
   python3 tests/Performance/watchdog.py 185 php -d memory_limit=2G tests/Performance/run.php --orders=36500 > /path/to/optimized.json
   ```

   Other supported full scales: `365000`, `1000000`. There is no automatic downscale. The report itself fails above 180 seconds; the external 185-second watchdog includes bootstrap and kills a stuck process. The memory limit is explicit and should be identical for both versions. Disable Xdebug for meaningful timings.

5. Compare an untouched baseline in a **separate process**, against the same loaded data:

   ```sh
   python3 tests/Performance/watchdog.py 185 php -d memory_limit=2G tests/Performance/run.php --orders=36500 --source=/absolute/artifact/plugin-baseline/src > /path/to/baseline.json
   ```

   `--source` explicitly loads the two original repository classes before Composer can load their current versions. The current native controller entry point uses 8 data queries. Earlier report-wide source snapshots use 4; original source falls back to six public methods and uses 16. The harness asserts the corresponding query budget. All paths normalize all six outputs to the same keys and check the same financial expectations. `--limit=SECONDS` can lower, but cannot raise, the report's 180-second ceiling.

## Data and checks

The deterministic 2025 schedule (labelled seed 2025, no random generator) spreads orders over all 365 days, including midnight and 23:59:59 boundaries. Ninety percent use one popular variant with three units; ten percent use rare variants with one unit. Default `--catalogue=2` has one rare variant; `--catalogue=1000` distributes rare orders across 999 variants. Each unit has included tax and two distinct promotion types. Each item also has a promotion; each order has shipping, shipping tax and an order promotion. A single translated size option covers the catalogue. Four additional otherwise-valid orders independently exercise excluded channel, date, order state and payment state.

Prices are integer minor units: unit 1200, included tax 200, unit promotions -10 and -5; item promotion -30; shipping 500 with included shipping tax 20; order promotion -20. For ten accepted orders, the literal reference is net 28000, promotions -920, shipping net 4800, tax 5600, order total 37680, item total 32880, quantity 28. Every scale checks all six outputs with independent arithmetic formulas, per-group financial totals, distinct order counts and group quantities. Legacy metadata such as grouped `order_id` is not a deterministic business total and is not compared.

Legacy option `number_of_elements` counts distinct representative order IDs from the variant summaries, not underlying orders or necessarily the number of variant rows. In the ten-order controls the popular and rare representatives are orders 9 and 10, respectively, yielding 2 for the option and 1 for each value. The larger fixtures also have distinct popular and rare representatives. The harness asserts this existing behavior rather than silently changing it. Product and variant element counts assert underlying distinct orders.

JSON reports actual SQL query count (a counting-only DBAL logger), `hrtime` report duration, process peak memory, peak-minus-start memory, source path, scale, MySQL version and all six outputs. Setup, fixture loading, proxy initialization and assertions are outside measurement. Each process is a cold PHP/Doctrine run; MySQL buffer-cache state is not controlled. PHP versions before 8.2 cannot reset the process memory high-water mark, so use PHP 8.2+ for isolated peak measurements.

## Limits

Minimal mappings are intentionally not a full production schema or a production query-plan guarantee. The standard fixture does not cover repeated adjustments of the same type or multiple items per order; use the separate fan-out and multi-item regressions below. These scenarios do not cover customer records, currencies, multiple options, missing translations, nullable variants, refunds or custom configured state lists. Correctness tests for these belong in the normal test suite. Database loading can require substantial time and disk space (roughly 12 million adjustments at one million orders); there is no claimed performance result until a run completes. For a timed-out baseline, keep its watchdog failure as evidence rather than reporting a fabricated duration or totals.

## Query diagnostics

On already-loaded data, `--orders=365000 --explain` prints diagnostic-only JSON containing SQL shapes and MySQL `EXPLAIN FORMAT=JSON` plans for six financial/count summaries: order base, item base (including physical unit counts), unit adjustments, item adjustments, order adjustments and product order counts. It then exits before report execution and assertions. It does not use `ANALYZE` or execute those report queries. It reuses the selected source's aggregate builder and protected order-filter method; parameter positions come from Doctrine ORM 2.20 parser metadata. Bindings and credentials are not printed. Plans can contain synthetic filter literals. Do not combine with loading flags unless deliberately replacing fixtures. Supply `--catalogue=1000` when diagnosing that loaded profile.

Add `--trace` to a normal measured run to print only query-start ordinals and elapsed seconds to STDERR. The next query's start includes the preceding query's execution, result fetching and intervening PHP aggregation. Existing `query_execution_seconds` measures DBAL execute calls and may exclude unbuffered result fetching; it must not be interpreted as complete query-plus-hydration duration. Trace output contains no SQL or parameter values. Trace adds small logging overhead; keep it separate from final benchmark measurements.

Before every measured report query, native PDO sets the MySQL session `max_execution_time` to the remaining report budget in milliseconds (minimum 1 ms). The budget starts at the exact report timer origin and defaults to 180 seconds, including for baseline source. These additional `SET SESSION` control statements bypass the DBAL logger: the reported 8/4/16 counts remain **data-query counts**, not total server statements. Full report timing includes the control statements; per-query execute timings begin after them. A missing native PDO connection or failed control statement stops the benchmark rather than silently omitting protection. Session state disappears on connection close. Keep the external 185-second watchdog as protection for PHP/fetching/aggregation; the MySQL deadline additionally bounds server execution when a killed PHP process leaves a query running. Runs made before this protection may have overlapped orphan queries and must be repeated on a verified idle benchmark service before comparing timings.

## Same-type promotion fan-out regression

Use a separate control database, never the loaded high-volume benchmark database:

```sh
SALES_REPORT_BENCHMARK_DATABASE_URL='mysql://root@127.0.0.1:58326/sales_reports_benchmark_control' \
    XDEBUG_MODE=off php tests/Performance/run.php --orders=10 --load --fanout
```

`--fanout` requires both `--orders=10` and `--load`, and forbids `--load-only`. It adds an item-promotion adjustment of -20 and a unit-promotion adjustment of -7 to the first unit, which already has item promotion -5, unit promotion -10 and included tax 200. Its correct contribution remains net 1000 and tax 200, with promotions -42. The old independent adjustment joins instead multiply this unit into four rows: net 4000, tax 800 and promotions -84. The second unit receives another item-promotion adjustment of -5, identical to its existing -5 adjustment: both must count, yielding promotions -20 on that unit rather than the -15 produced by incorrectly deduplicating equal amounts with `SUM(DISTINCT ...)`. Item and order stored totals are reduced by 32 to remain consistent with all three extra promotions.

The regression flags `--fanout`, `--multiitem` and `--unitless` are mutually exclusive and require `--catalogue=2`; the default remains compatible with the original literal controls.

All six reports are checked. The fan-out literal reference is net 28000, promotions -952, shipping net 4800, tax 5600, order total 37648, item total 32848, quantity 28. Popular-containing groups receive exactly the -32 promotion and item-total difference; the rare variant and all quantities, net values and taxes remain unchanged. Average values use the existing rounding rules.

The manifest records `standard`, `fanout`, `multiitem` or `unitless`, and a normal run rejects regression-scenario data rather than silently interpreting it as the standard fixture. Loading upgrades an existing local manifest with a scenario column; old scenario-less manifests are accepted only as standard fixtures. Replacing regression data with `--orders=10 --load` restores the standard control. `--source=/path/to/plugin-baseline/src` is allowed with `--fanout --orders=10 --load` to demonstrate the baseline's expected financial assertion failure; this is a regression demonstration, not a passing performance comparison.

## Multiple items and distinct product orders

```sh
SALES_REPORT_BENCHMARK_DATABASE_URL='mysql://root@127.0.0.1:58326/sales_reports_benchmark_control' \
    XDEBUG_MODE=off php tests/Performance/run.php --orders=10 --load --multiitem
```

`--multiitem` requires `--orders=10 --load`, forbids `--load-only`, and cannot combine with `--fanout`. It starts from the standard ten accepted orders and four excluded orders. A second item on order 1 uses the rare variant with one unit: price 1200, included tax 200, unit promotions -10 and -5, and item promotion -30. Its stored item total is 1155, also added to order 1's total; shipping and order promotions are unchanged. Extra item, unit and adjustment identifiers are allocated after the excluded rows.

Literal totals are net 29000, promotions -965, shipping net 4800, tax 5800, order total 38835, item total 34035 and quantity 29. All six financial outputs and rounded per-order averages are checked. There are still 10 orders for the product but 11 items. Popular variant counts are 9 orders, 9 items and 27 units; rare variant counts are 2 orders, 2 items and 2 units. Summing variant order counts would incorrectly report 11 product orders; the product assertion requires 10. Group promotions and item totals use item counts, not order counts. Representative order IDs remain popular 9 and rare 10, so the option's legacy count remains 2 and each option value's remains 1. The manifest records `multiitem` to prevent later standard runs from accepting these fixtures.

## Finite large-catalogue stress profile

Only `--catalogue=2` (default) and `--catalogue=1000` are accepted. The latter preloads 100 products, 1000 variants, 1000 option values and their translations, including unsold entries. Variants 1–10 belong to product 1, variants 11–20 to product 2, and so on. Stored item product names match those product IDs. Variant 1 retains code `popular`; other option-value codes are `rare_<id>`. The two-variant profile retains its original names and `rare` code exactly.

```sh
# Choose a dedicated local database and check available disk before loading.
php -d memory_limit=2G tests/Performance/run.php --orders=1000000 --catalogue=1000 --load-only
python3 tests/Performance/watchdog.py 185 php -d memory_limit=2G tests/Performance/run.php --orders=1000000 --catalogue=1000 > /path/to/catalogue-1000.json
```

Every tenth order uses variant `((order_id / 10 - 1) % 999) + 2`, with integer division; all other orders use variant 1. Thus quantities, adjustments, overall financial totals and the four excluded controls remain unchanged: 2.8 units per accepted order at the supported scales. This profile tests substantially more report groups but remains synthetic structural stress, not a complete large-site business simulation.

Expected groups are derived independently from closed-form arithmetic, never from SQL results. With `R = floor(N / 10)`, each rare variant gets `floor(R / 999)` orders plus one for its position in the first `R % 999` variants. Only sold variants/products may appear in reports. Product orders, items and units aggregate those counts; the standard profile has one item per order, so summing variant order counts is valid here. The separate multi-item control still requires distinct product orders. At one million orders, variant 1 has 900000 orders/2700000 units; variants 2–101 each have 101 orders, and variants 102–1000 each have 100. All 100 products and 1000 variants are sold.

Each group's net, tax, promotions and item total are checked from its own unit and item counts. The option count is the number of distinct representative maximum order IDs of sold variants, equal to the sold-variant count in this one-item-per-order profile; every option value counts one representative. Exact group IDs prevent unsold catalogue entries from passing count checks. JSON includes `catalogue_size`, `sold_product_groups` and `sold_variant_groups`. The manifest records `catalogue_size` and rejects mismatches before measurement; old manifests without that column mean catalogue 2. Loading adds the column locally when necessary. Use the same catalogue option for loading, measuring and baseline comparison.

## Unitless-item compatibility control

```sh
SALES_REPORT_BENCHMARK_DATABASE_URL='mysql://root@127.0.0.1:58326/sales_reports_benchmark_control' \
    XDEBUG_MODE=off php tests/Performance/run.php --orders=10 --load --unitless
```

`--unitless` requires `--orders=10 --load --catalogue=2` (catalogue 2 is the default), forbids `--load-only`, and cannot combine with `--fanout` or `--multiitem`. After loading the standard control it deletes all adjustments attached to item 1's three physical units, then deletes those units. It deliberately leaves item quantity 3 and both stored item/order totals unchanged. This is a **stale/corrupt denormalized-data compatibility fixture**, not proof of a valid business workflow. The fixture loader also rejects unitless requests outside this finite scenario before making changes.

The old unit `LEFT JOIN` contributes one price of 1200 for the now-unitless item, with no unit tax or promotions, instead of the prior net 3000, tax 600 and promotions -45. The native physical-unit count must preserve that zero-unit placeholder behavior; using stored item quantity would be incorrect. Independent corrections are net -1800, tax -600 and promotions +45, without changing quantities, item rows or order totals.

Literal totals are net 26200, promotions -875, shipping net 4800, tax 5000, order total 37680, item total 32880 and quantity 28 across 10 orders. Popular-variant expectations are net 25200, promotions -630, tax 4800, item total 31725 and quantity 27 across 9 orders; the rare variant retains net 1000, promotions -45, tax 200, item total 1155 and quantity 1 across 1 order. Product and option groups containing the popular variant receive the same independent financial corrections. Product order count remains 10; the option's legacy count remains 2 and each option value's remains 1. All six financial outputs and rounded averages are asserted. The manifest records `unitless` so later standard runs cannot accidentally accept this data.

Add `--source=/absolute/artifact/plugin-baseline/src` to the same finite control command to verify old-source compatibility in a separate process. Both original 16-query and current 8-query implementations must pass the same expectations. Use only the separate control database; loading replaces its fixture tables.
