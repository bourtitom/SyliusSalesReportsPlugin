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

namespace SalesReportsBenchmark;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Throwable;

final class Fixtures
{
    public static function load(Connection $db, int $orders, bool $fanout = false, bool $multiitem = false, int $catalogue = 2, bool $unitless = false): void
    {
        if ($unitless && (10 !== $orders || 2 !== $catalogue || $fanout || $multiitem)) {
            throw new InvalidArgumentException('Unitless fixtures require ten orders, catalogue 2 and no other regression scenario.');
        }
        foreach (['bench_channel' => [[1], [2]], 'bench_shipment' => [[1]]] as $table => $rows) {
            foreach ($rows as $row) {
                $db->insert($table, ['id' => $row[0]]);
            }
        }
        for ($product = 1; $product <= (2 === $catalogue ? 1 : 100); ++$product) {
            $db->insert('bench_product', ['id' => $product]);
        }
        $db->insert('bench_option', ['id' => 1, 'code' => 'size']);
        $db->insert('bench_option_translation', ['id' => 1, 'translatable_id' => 1, 'locale' => 'en_US', 'name' => 'Size']);
        for ($id = 1; $id <= $catalogue; ++$id) {
            $name = 1 === $id ? 'Popular' : (2 === $catalogue ? 'Rare' : 'Rare ' . $id);
            $db->insert('bench_variant', ['id' => $id, 'product_id' => self::productId($id, $catalogue)]);
            $db->insert('bench_option_value', ['id' => $id, 'code' => self::variantCode($id, $catalogue), 'option_id' => 1]);
            $db->insert('bench_option_value_translation', ['id' => $id, 'translatable_id' => $id, 'locale' => 'en_US', 'value' => $name]);
            $db->insert('bench_variant_option', ['variant_id' => $id, 'value_id' => $id]);
        }
        // Fixed arithmetic schedule (seed 2025), no randomness and no downscaling.
        $unit = 0;
        $adjustment = 0;
        $buffers = [];
        $append = static function (string $table, array $row) use (&$buffers): void {
            $buffers[$table][] = $row;
        };
        $flush = static function () use ($db, &$buffers): void {
            $db->beginTransaction();

            try {
                foreach (['bench_order', 'bench_item', 'bench_unit', 'bench_adjustment'] as $table) {
                    $rows = $buffers[$table] ?? [];
                    if ([] === $rows) {
                        continue;
                    }
                    $columns = array_keys($rows[0]);
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $params = [];
                        foreach ($chunk as $row) {
                            array_push($params, ...array_values($row));
                        }
                        $db->executeStatement('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ' .
                            implode(',', array_fill(0, \count($chunk), '(' . implode(',', array_fill(0, \count($columns), '?')) . ')')), $params);
                    }
                }
                $db->commit();
                $buffers = [];
            } catch (Throwable $error) {
                $db->rollBack();

                throw $error;
            }
        };
        for ($id = 1; $id <= $orders + 4; ++$id) {
            $sentinel = max(0, $id - $orders);
            $variant = 0 === $id % 10 ? (intdiv($id, 10) - 1) % ($catalogue - 1) + 2 : 1;
            $quantity = 1 === $variant ? 3 : 1;
            $extraPromotion = $fanout && 1 === $id ? 32 : 0;
            $extraItemTotal = $multiitem && 1 === $id ? 1155 : 0;
            $date = (new DateTimeImmutable('2025-01-01'))->modify('+' . (($id - 1) % 365) . ' days')->format('Y-m-d');
            $append('bench_order', ['id' => $id, 'channel_id' => 1 === $sentinel ? 2 : 1,
                'state' => 3 === $sentinel ? 'cancelled' : ($id % 2 ? 'new' : 'fulfilled'),
                'paymentState' => 4 === $sentinel ? 'refunded' : 'paid',
                'checkoutCompletedAt' => 2 === $sentinel ? '2024-12-31 23:59:59' : $date . ($id % 2 ? ' 00:00:00' : ' 23:59:59'),
                'total' => $quantity * 1185 - 30 + 480 - $extraPromotion + $extraItemTotal]);
            $productName = 2 === $catalogue ? 'Synthetic product' : 'Synthetic product ' . self::productId($variant, $catalogue);
            $variantName = 1 === $variant ? 'Popular' : (2 === $catalogue ? 'Rare' : 'Rare ' . $variant);
            $append('bench_item', ['id' => $id, 'order_id' => $id, 'variant_id' => $variant, 'productName' => $productName,
                'variantName' => $variantName, 'unitPrice' => 1200, 'quantity' => $quantity, 'total' => $quantity * 1185 - 30 - $extraPromotion]);
            $add = static function (string $type, int $amount, int $neutral, ?int $order, ?int $item, ?int $unitId, ?int $shipment = null) use (&$adjustment, $append): void {
                $append('bench_adjustment', ['id' => ++$adjustment, 'type' => $type, 'amount' => $amount, 'neutral' => $neutral,
                    'order_id' => $order, 'orderItem_id' => $item, 'orderItemUnit_id' => $unitId, 'shipment_id' => $shipment]);
            };
            $add('shipping', 500, 0, $id, null, null);
            $add('tax', 20, 1, $id, null, null, 1);
            $add('order_promotion', -20, 0, $id, null, null);
            $add('order_item_promotion', -30, 0, null, $id, null);
            for ($i = 0; $i < $quantity; ++$i) {
                $append('bench_unit', ['id' => ++$unit, 'orderItem_id' => $id]);
                $add('tax', 200, 1, null, null, $unit);
                $add('order_unit_promotion', -10, 0, null, null, $unit);
                $add('order_item_promotion', -5, 0, null, null, $unit);
                if ($fanout && 1 === $unit) {
                    $add('order_item_promotion', -20, 0, null, null, $unit);
                    $add('order_unit_promotion', -7, 0, null, null, $unit);
                }
                if ($fanout && 2 === $unit) {
                    $add('order_item_promotion', -5, 0, null, null, $unit);
                }
            }
            if (0 === $id % 500) {
                $flush();
            }
        }
        if ($multiitem) {
            // Allocate after every standard and excluded row to avoid identifier collisions.
            $itemId = $orders + 5;
            $append('bench_item', ['id' => $itemId, 'order_id' => 1, 'variant_id' => 2,
                'productName' => 'Synthetic product', 'variantName' => 'Rare',
                'unitPrice' => 1200, 'quantity' => 1, 'total' => 1155]);
            $append('bench_unit', ['id' => ++$unit, 'orderItem_id' => $itemId]);
            $add('tax', 200, 1, null, null, $unit);
            $add('order_unit_promotion', -10, 0, null, null, $unit);
            $add('order_item_promotion', -5, 0, null, null, $unit);
            $add('order_item_promotion', -30, 0, null, $itemId, null);
        }
        if ([] !== $buffers) {
            $flush();
        }
        if ($unitless) {
            // Deliberately retain stale item quantity and totals for LEFT JOIN compatibility.
            $db->executeStatement('DELETE FROM bench_adjustment WHERE orderItemUnit_id IN (SELECT id FROM bench_unit WHERE orderItem_id = 1)');
            $db->executeStatement('DELETE FROM bench_unit WHERE orderItem_id = 1');
        }
    }

    public static function productId(int $variant, int $catalogue = 2): int
    {
        return 2 === $catalogue ? 1 : intdiv($variant - 1, 10) + 1;
    }

    public static function variantCode(int $variant, int $catalogue = 2): string
    {
        return 1 === $variant ? 'popular' : (2 === $catalogue ? 'rare' : 'rare_' . $variant);
    }

    /** Closed-form accepted-order counts; excluded rows never enter this model. */
    public static function expectedVariants(int $orders, int $catalogue = 2, bool $multiitem = false): array
    {
        $rare = intdiv($orders, 10);
        $popular = $orders - $rare;
        $groups = [1 => ['orders' => $popular, 'items' => $popular, 'units' => $popular * 3,
            'representative' => $orders - (0 === $orders % 10 ? 1 : 0)]];
        $quotient = intdiv($rare, $catalogue - 1);
        $remainder = $rare % ($catalogue - 1);
        for ($variant = 2; $variant <= $catalogue; ++$variant) {
            $offset = $variant - 2;
            $count = $quotient + ($offset < $remainder ? 1 : 0);
            $extra = $multiitem && 2 === $variant ? 1 : 0;
            if (0 === $count + $extra) {
                continue;
            }
            $representative = $count > 0 ? 10 * ($offset + ($count - 1) * ($catalogue - 1) + 1) : 1;
            $groups[$variant] = ['orders' => $count + $extra, 'items' => $count + $extra,
                'units' => $count + $extra, 'representative' => $representative];
        }

        return $groups;
    }

    public static function expectedProducts(int $orders, int $catalogue = 2, bool $multiitem = false): array
    {
        $groups = [];
        foreach (self::expectedVariants($orders, $catalogue, $multiitem) as $variant => $counts) {
            $product = self::productId($variant, $catalogue);
            $groups[$product] ??= ['orders' => 0, 'items' => 0, 'units' => 0];
            foreach (['orders', 'items', 'units'] as $field) {
                $groups[$product][$field] += $counts[$field];
            }
        }
        if ($multiitem) {
            --$groups[1]['orders'];
        }

        return $groups;
    }

    public static function expected(int $orders, bool $fanout = false, bool $multiitem = false, int $catalogue = 2, bool $unitless = false): array
    {
        $extraItems = $multiitem ? 1 : 0;
        $units = array_sum(array_column(self::expectedVariants($orders, $catalogue, $multiitem), 'units'));
        $items = $orders + $extraItems;
        $extraPromotion = $fanout ? 32 : 0;

        return ['without_tax_total' => $units * 1000 - ($unitless ? 1800 : 0),
            'without_tax_promo_total' => -15 * $units - 30 * $items - 20 * $orders - $extraPromotion + ($unitless ? 45 : 0),
            'without_tax_shipping_total' => 480 * $orders, 'tax_total' => 200 * $units - ($unitless ? 600 : 0),
            'total' => $units * 1185 - 30 * $items + 480 * $orders - $extraPromotion,
            'item_row_total' => $units * 1185 - 30 * $items - $extraPromotion,
            'item_qty_total' => $units, 'number_of_elements' => 0];
    }
}
