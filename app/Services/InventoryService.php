<?php

namespace App\Services;

use App\Enums\InventoryTransactionType;
use App\Exceptions\DomainException;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Atomically lock ordered product rows (sorted ascending to prevent deadlocks),
     * verify stock availability for all items, decrement stock, and record inventory audit logs.
     *
     * MUST run inside an active database transaction.
     *
     * @throws DomainException if any product has insufficient stock.
     */
    public function deductForOrder(Order $order): void
    {
        if ($order->stock_deducted_at !== null) {
            return;
        }

        $items = $order->items()->orderBy('product_id')->get();
        if ($items->isEmpty()) {
            throw new DomainException('Order contains no items to fulfill.', 422);
        }

        // Aggregate required quantities per product_id in ascending lock order (prevents deadlocks).
        $requiredByProduct = [];
        foreach ($items as $item) {
            $pid = (int) $item->product_id;
            $requiredByProduct[$pid] = ($requiredByProduct[$pid] ?? 0) + (int) $item->quantity;
        }
        ksort($requiredByProduct);

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', array_keys($requiredByProduct))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Phase 1: Validate every item BEFORE mutating any product row.
        foreach ($requiredByProduct as $productId => $qtyNeeded) {
            /** @var Product|null $product */
            $product = $products->get($productId);

            if ($product === null || ! $product->is_active) {
                throw new DomainException("Product #{$productId} is no longer available.", 409);
            }

            if ($product->stock_quantity < $qtyNeeded) {
                throw new DomainException(
                    "Insufficient stock for {$product->name} (requested {$qtyNeeded}, available {$product->stock_quantity}).",
                    409
                );
            }
        }

        // Phase 2: Atomic decrement + audit log.
        foreach ($requiredByProduct as $productId => $qtyNeeded) {
            /** @var Product $product */
            $product = $products->get($productId);
            $before = (int) $product->stock_quantity;
            $after = $before - $qtyNeeded;

            $product->update(['stock_quantity' => $after]);

            InventoryTransaction::query()->create([
                'product_id' => $product->id,
                'order_id' => $order->id,
                'actor_id' => $order->user_id,
                'type' => InventoryTransactionType::SaleDeduction,
                'quantity_change' => -$qtyNeeded,
                'stock_before' => $before,
                'stock_after' => $after,
                'notes' => "Stock deducted for paid order {$order->order_number}",
            ]);
        }

        $order->forceFill(['stock_deducted_at' => now()])->save();
    }

    /**
     * Restore stock for a cancelled paid order EXACTLY ONCE.
     * Must be invoked inside a database transaction with the order row locked.
     */
    public function restoreForOrder(Order $order, ?int $actorId = null): bool
    {
        if ($order->stock_deducted_at === null || $order->stock_restored_at !== null) {
            return false;
        }

        $items = $order->items()->orderBy('product_id')->get();
        $restoreByProduct = [];
        foreach ($items as $item) {
            $pid = (int) $item->product_id;
            $restoreByProduct[$pid] = ($restoreByProduct[$pid] ?? 0) + (int) $item->quantity;
        }
        ksort($restoreByProduct);

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->withTrashed()
            ->whereIn('id', array_keys($restoreByProduct))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($restoreByProduct as $productId => $qtyToRestore) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if ($product === null) {
                continue;
            }

            $before = (int) $product->stock_quantity;
            $after = $before + $qtyToRestore;

            $product->update(['stock_quantity' => $after]);

            InventoryTransaction::query()->create([
                'product_id' => $product->id,
                'order_id' => $order->id,
                'actor_id' => $actorId,
                'type' => InventoryTransactionType::CancellationRestoration,
                'quantity_change' => $qtyToRestore,
                'stock_before' => $before,
                'stock_after' => $after,
                'notes' => "Stock restored on cancellation of {$order->order_number}",
            ]);
        }

        $order->forceFill(['stock_restored_at' => now()])->save();

        return true;
    }

    public function adjustStock(Product $product, int $newStockQuantity, int $actorId, string $notes = 'Manual staff adjustment'): Product
    {
        if ($newStockQuantity < 0) {
            throw new DomainException('Stock quantity cannot be negative.', 422);
        }

        return DB::transaction(function () use ($product, $newStockQuantity, $actorId, $notes): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = (int) $locked->stock_quantity;
            $delta = $newStockQuantity - $before;

            $locked->update(['stock_quantity' => $newStockQuantity]);

            if ($delta !== 0) {
                InventoryTransaction::query()->create([
                    'product_id' => $locked->id,
                    'actor_id' => $actorId,
                    'type' => InventoryTransactionType::ManualAdjustment,
                    'quantity_change' => $delta,
                    'stock_before' => $before,
                    'stock_after' => $newStockQuantity,
                    'notes' => $notes,
                ]);
            }

            return $locked->fresh();
        });
    }
}
