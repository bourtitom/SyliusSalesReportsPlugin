# Report performance (MBIZ-29009)

Measured on 2026-10-28. Compact durable evidence: `docs/performance-results.json`.
Raw run logs are local files named `final-3g-*`; they are not committed.

## Final source design

The optimized repository uses two new collaborators:

- `ReportAggregateQueryBuilder`
- `ReportSummaryAssembler`

The aggregate path builds DQL with the Doctrine ORM `QueryBuilder` API. It does
not use the native SQL API. It runs 8 queries and returns small per-group
summaries instead of physical sales rows:

- Separate base-sum and adjustment-sum expressions, so join fan-out cannot
  double-count.
- `SIZE(item.units)` compiles to a correlated physical-unit count. The count is
  multiplied by the item price. Zero units contributes one placeholder price
  through `COALESCE(NULLIF(SIZE(item.units), 0), 1)`. The code does not assume
  a stored quantity.
- Adjustment-only INNER joins to physical owners. This is safe for zero
  metrics, because the base groups are created first.
- Distinct product order counts, independent of variant counts.
- Snapshot labels taken from the latest item IDs.
- A representative `MAX(o.id)` for the legacy option element counts.

The design removes the physical-owner SQL `GROUP BY`. The remaining queries
still group by product and variant IDs. MySQL may still use temporary tables
for those groups.

The repository picks the path by reflection. A method counts as overridden when
its declaring class is not `AbstractReportRepository` or `ReportRepository`:

- No relevant override and no enabled SQL filter: aggregate path, 8 queries.
- Other protected query-method overrides, or enabled Doctrine SQL filters:
  array bulk, normally 4 queries.
- Override of a public report method or of `initResult`, `addResults`,
  `averageResult`, `addResultsByElement` or `populateOptions`:
  the 6 legacy public methods run in
  order, which defaults to 16 queries. A custom method can issue a different
  number of queries.

The legacy fallback keeps the old SQL arithmetic unchanged, on purpose, to
protect application output behavior. The fan-out correction exists only in the
aggregate path.

## Query budget

| Path | Queries |
|------|---------|
| Old baseline | 16 (budget) |
| Aggregate (final design) | 8 |
| Protected query-hook override or enabled SQL filter | 4 (array bulk) |
| Public report-method or protected transformation override | 6 legacy methods = 16 (budget) |

"Budget" is the number of queries the path issues when it runs to completion.
Both baseline runs failed before all reads finished, so no baseline run
completed its 16-query budget. No baseline elapsed time or speedup ratio
exists, and this document does not invent one.

## Benchmark design

`tests/Performance` is a standalone synthetic structural SQL stress test. It is
**not** a production HTTP or Sylius workflow proof. It uses no kernel, no
`.env` and no `tests/Application`. No production or staging system was
accessed. See `tests/Performance/README.md` for exact commands.

- Real MySQL 8; minimal Doctrine mappings; Sylius `core` 1.14.21,
  Doctrine ORM 2.20.13, DBAL 3.10.6, PHP 8.2.29.
- Real plugin repository classes, real DQL joins and hydration of small
  summaries.
- Bulk-inserted structural fixtures; orders are not created through the Sylius
  workflow.
- Report limit 180 seconds; external watchdog kills the process group at 185
  seconds. The production source has no SQL timeout hard limit.
- Financial formulas in the checks are literal in the benchmark code, not read
  from the plugin repository.
- `--explain` prints the plans of the 6 aggregate query shapes: order base,
  item base, unit adjustments, item adjustments, order adjustments and product
  order counts.
- `--catalogue=2` (default) or `--catalogue=1000`. The 1000-catalogue profile
  has 100 products and 1000 variants, about 90% of orders on one popular
  variant and 10% on a rare variant in a round-robin spread, over all 365 days.

## Final profile

| Setting | Value |
|---------|-------|
| Docker VM | 6 CPU, about 32 GiB, on Apple M1 Max host |
| MySQL container limit | 3 GiB (3221225472 bytes, verified with `docker inspect`) |
| Actual InnoDB pool | 2 GiB (2147483648 bytes), 1 instance |
| Temp / heap tables | 64 MiB each |
| Binary logging | Off |
| PHP memory limit | 2 GiB (2147483648 bytes) |

The compose file configures this pool and cap. Cache state on the shared VM is
not controlled. Runs are separate PHP processes; a run soon after a service
recreate is not proof of a cold cache.

An earlier, smaller profile (2 GiB container cap, actual 1.5 GiB pool with
one instance) failed the 180-second budget on one million-order run and
passed another at 149.053988916 seconds. It does not reliably meet the
million-order budget. That limitation is retained; the profile changed to 3 GiB
for this reason.

## Measured results, final profile

Largest loaded dataset, queried exact in SQL: 1,000,004 orders, 1,000,004
items, 2,800,012 units, 12,400,052 adjustments, 100 products, 1000 variants
(2025). Max measured scale is 1 million accepted orders and about 17.2 million
order, item, unit and adjustment rows.

| Run | Orders / catalogue | Result | Time (s) | Queries | Peak PHP memory | Checks |
|-----|--------------------|--------|----------|---------|-----------------|--------|
| Aggregate | 365,000 / 2 | Pass | 31.60607025 | 8 | 12,582,912 B (12 MiB) | All 6 |
| Aggregate, repeat 1 | 1,000,000 / 1000 | Pass | 151.084807833 | 8 | 18,874,368 B (18 MiB) | All 6, plus 100 product and 1000 variant group formulas |
| Aggregate, repeat 2 | 1,000,000 / 1000 | Pass | 125.91545475 | 8 | 18,874,368 B | Same |
| Aggregate, repeat 3 | 1,000,000 / 1000 | Pass | 105.645155458 | 8 | 18,874,368 B | Same |
| Baseline | 365,000 / 2 | Fail | Not completed | 16 budget | — | External watchdog at 185 s, exit 1 |
| Baseline | 1,000,000 / 1000 | Fail | Not completed | 16 budget | — | PHP exhausted 2147483648 B in `ArrayHydrator.php:213`, exit 255 |

After the baseline failures, `SHOW PROCESSLIST` showed no active report query.
The final 3 runs produced no trace logs.

## Deliberate financial controls

The fixture exposes join fan-out on purpose. The aggregate path passes all
controls; all six outputs match the literal formulas:

| Control | Net | Promotions | Tax | Total | Item total | Quantity |
|---------|-----|------------|-----|-------|------------|----------|
| Standard | 28000 | -920 | 5600 | 37680 | 32880 | 28 |
| Fan-out | 28000 | -952 | 5600 | 37648 | 32848 | 28 |
| Multi-item | 29000 | -965 | 5800 | 38835 | 34035 | 29 |
| Unitless | 26200 | -875 | 5000 | 37680 | 32880 | 28 |

The multi-item control has 10 distinct orders for the product count; variant 1
appears in 9 orders and variant 2 in 2 orders. The unitless control passes on
both the 8-query aggregate path and the 16-query baseline, which validates the
zero-units placeholder behavior. An external artifact, `filter-control.json`,
adds a real-MySQL SQL filter control: the 4-query fallback passes, and all six
outputs equal the filtered legacy methods. Stored totals are unchanged, with 4
reads.

The baseline produces the same finance numbers on the unitless control
(`baseline-unitless.json`). The old single-unit fan-out case without the
correction added an extra -20 and -7 (promotions -989 instead of -947); the
aggregate path removes that error.

## Disk space incident

One catalogue load failed because the shared Docker disk was full. With the
user's explicit consent, `docker builder prune -af` freed 25.66 GB of build
cache; the load then ran with about 20 GiB free. No image, container or volume
was pruned. No further maximum-scale load ran, to avoid filling the shared
disk again.

## Reproducibility

1. `composer install --working-dir=tests/Performance`
2. Start the database: `docker compose -p sales-reports-benchmark -f tests/Performance/compose.yaml up -d`,
   and point `SALES_REPORT_BENCHMARK_DATABASE_URL` at its loopback port. The
   compose file configures the final profile: 3 GiB container cap, 2 GiB pool
   with 1 instance, 64 MiB temp and heap, binary logging off.
3. Load the large profile:
   `make benchmark.fixtures BENCHMARK_ORDERS=1000000 BENCHMARK_CATALOGUE=1000`.
4. Measure it:
   `make test.performance BENCHMARK_ORDERS=1000000 BENCHMARK_CATALOGUE=1000`.
   Use the README's `--source` command for the baseline in a separate process.
5. Keep the JSON output of every run.

The database service binds `127.0.0.1` on an ephemeral port, uses Docker
Compose project `sales-reports-benchmark`, and the database
`sales_reports_benchmark`. The runner accepts only loopback MySQL URLs with
that database name (optional `_[a-z0-9]+` suffix) and no query parameters.
Fixture loading drops and recreates only the mapped `bench_*` tables.

The isolated install allows one advisory exception in
`tests/Performance/composer.json`: `PKSA-w9tt-7782-78jx` (Flysystem 2),
user-approved for the local benchmark only. The plugin's production
dependencies and policy are unchanged.

## Quality checks

- Unit tests: `make test.unit.isolated`, 30 tests, 344 assertions, primary run
  passed.
- PHPStan: passed. PHPMD: 0 violations. Coding standards: passed.
- Independent audit of the filter guard: no blockers.
- `make test.all` at the root fails on a known pre-existing problem: a stale
  root `composer.lock` (Sylius 2.2.2 against a 1.x constraint). This work does
  not cause it. Upgrading the plugin to Sylius 2 is out of scope here.

## Limits

- No production or staging access; no HTTP or full-application verification;
  no non-MySQL runtime verification.
- Custom overrides and enabled SQL filters use compatibility paths. They do
  not get the 8-query performance guarantee.
- VM cache is not controlled; runtimes vary. These are benchmark observations,
  not production latency guarantees.
- The smaller 2 GiB-cap profile had a failed million-order run; the budget is
  not reliable below the final profile.

## Files changed

| File | Role |
|------|------|
| `src/Repository/ReportRepository.php` | Modified: reflection-based path selection and aggregate orchestration |
| `src/Repository/AbstractReportRepository.php` | Modified: shared query helpers |
| `src/Controller/Admin/ReportsController.php` | Modified: report-wide entry point |
| `src/Repository/ReportAggregateQueryBuilder.php` | New: DQL aggregate queries |
| `src/Repository/ReportSummaryAssembler.php` | New: PHP summary assembly |
| `tests/Unit/ReportAggregateQueryBuilderTest.php` | New: unit tests |
| `tests/Unit/ReportRepositoryTest.php` | New: unit tests |
| `tests/Performance/README.md` | New: benchmark commands and scope |
| `tests/Performance/run.php` | New: benchmark runner |
| `tests/Performance/Fixtures.php` | New: bulk fixture loader |
| `tests/Performance/Mapping.php` | New: minimal Doctrine mappings |
| `tests/Performance/watchdog.py` | New: external 185-second watchdog |
| `tests/Performance/compose.yaml` | New: MySQL 8 service with the final profile |
| `tests/Performance/composer.json` | New: isolated benchmark dependencies |
| `tests/Performance/.gitignore` | New: excludes local dependencies, lock and PHPUnit cache |
| `Makefile` | Modified: test and benchmark targets |
| `.php-cs-fixer.dist.php` | Modified: coding-standard config |
| `docs/performance.md` | New: this document |
| `docs/performance-results.json` | New: compact durable measurement evidence |

## Appendix: historical observations, superseded profile

Earlier runs on a 2 GiB-cap container with an actual 2 GiB pool (default
instances rounded the configured 1.5 GiB up) measured 117.479429959 s and
157.927019584 s at 1,000,000 orders with a 2-variant catalogue, 8 queries,
12,582,912 B peak, all checks passed. These come from a superseded profile and
are not part of the final comparison.
