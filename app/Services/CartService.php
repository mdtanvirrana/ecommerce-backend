<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(
        private readonly CouponService $couponService,
        private readonly ShippingCalculator $shippingCalculator,
    ) {}

    public function getOrCreateCart(Customer $customer): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $customer->id]);
    }

    public function addItem(
        Customer $customer,
        int $productId,
        int $quantity,
        string $selectedColor = '',
        string $selectedSize = '',
    ): Cart {
        $product = Product::query()->find($productId);

        if ($product === null || ! $product->is_active) {
            throw new DomainException('This product is no longer available.', 404);
        }

        if ($product->stock_quantity <= 0) {
            throw new DomainException('This product is currently out of stock.', 409);
        }

        $cart = $this->getOrCreateCart($customer);

        $existing = $cart->items()
            ->where('product_id', $product->id)
            ->where('selected_color', $selectedColor)
            ->where('selected_size', $selectedSize)
            ->first();

        $newQty = ($existing?->quantity ?? 0) + $quantity;

        if ($newQty > $product->stock_quantity) {
            throw new DomainException(
                "Only {$product->stock_quantity} unit(s) available for {$product->name}.",
                409,
                ['quantity' => ["Only {$product->stock_quantity} unit(s) available."]]
            );
        }

        if ($existing !== null) {
            $existing->update(['quantity' => $newQty]);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'selected_color' => $selectedColor,
                'selected_size' => $selectedSize,
            ]);
        }

        return $cart->fresh(['items.product.images', 'coupon']);
    }

    public function updateItemQuantity(Customer $customer, int $cartItemId, int $quantity): Cart
    {
        $cart = $this->getOrCreateCart($customer);

        /** @var CartItem|null $item */
        $item = $cart->items()->with('product')->whereKey($cartItemId)->first();

        if ($item === null) {
            throw new DomainException('Cart item not found.', 404);
        }

        if ($item->product === null || ! $item->product->is_active) {
            $item->delete();
            throw new DomainException('This product is no longer available and has been removed from your cart.', 409);
        }

        if ($quantity > $item->product->stock_quantity) {
            throw new DomainException(
                "Only {$item->product->stock_quantity} unit(s) available for {$item->product->name}.",
                409,
                ['quantity' => ["Only {$item->product->stock_quantity} unit(s) available."]]
            );
        }

        $item->update(['quantity' => $quantity]);

        return $cart->fresh(['items.product.images', 'coupon']);
    }

    public function removeItem(Customer $customer, int $cartItemId): Cart
    {
        $cart = $this->getOrCreateCart($customer);

        $deleted = $cart->items()->whereKey($cartItemId)->delete();
        if ($deleted === 0) {
            throw new DomainException('Cart item not found.', 404);
        }

        return $cart->fresh(['items.product.images', 'coupon']);
    }

    /**
     * Merge guest local-storage cart items into authenticated customer's MySQL cart after login.
     *
     * @param  list<array{product_id: int, quantity: int, selected_color?: string|null, selected_size?: string|null}>  $items
     */
    public function mergeGuestItems(Customer $customer, array $items): Cart
    {
        $cart = $this->getOrCreateCart($customer);

        DB::transaction(function () use ($cart, $items): void {
            foreach ($items as $entry) {
                $product = Product::query()->find((int) $entry['product_id']);
                if ($product === null || ! $product->is_active || $product->stock_quantity <= 0) {
                    continue;
                }

                $color = (string) ($entry['selected_color'] ?? '');
                $size = (string) ($entry['selected_size'] ?? '');
                $qty = max(1, (int) $entry['quantity']);

                $existing = $cart->items()
                    ->where('product_id', $product->id)
                    ->where('selected_color', $color)
                    ->where('selected_size', $size)
                    ->first();

                $mergedQty = min($product->stock_quantity, ($existing?->quantity ?? 0) + $qty);

                if ($existing !== null) {
                    $existing->update(['quantity' => $mergedQty]);
                } else {
                    $cart->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $mergedQty,
                        'selected_color' => $color,
                        'selected_size' => $size,
                    ]);
                }
            }
        });

        return $cart->fresh(['items.product.images', 'coupon']);
    }

    public function applyCoupon(Customer $customer, string $code): Cart
    {
        $cart = $this->getOrCreateCart($customer)->load(['items.product']);

        if ($cart->items->isEmpty()) {
            throw new DomainException('Cannot apply a coupon to an empty cart.', 422);
        }

        $subtotal = $this->computeRawSubtotal($cart);
        $validated = $this->couponService->validateAndCalculate($code, $subtotal, $customer);

        $cart->update(['coupon_id' => $validated['coupon']->id]);

        return $cart->fresh(['items.product.images', 'coupon']);
    }

    public function removeCoupon(Customer $customer): Cart
    {
        $cart = $this->getOrCreateCart($customer);
        $cart->update(['coupon_id' => null]);

        return $cart->fresh(['items.product.images', 'coupon']);
    }

    /**
     * Build authoritative server-side cart summary using live DB product prices.
     *
     * @return array<string, mixed>
     */
    public function summarize(Customer $customer): array
    {
        $cart = $this->getOrCreateCart($customer)->load(['items.product.images', 'items.product.category', 'coupon']);
        $subtotal = '0.00';
        $itemsPayload = [];
        $itemCount = 0;

        foreach ($cart->items as $item) {
            if ($item->product === null) {
                continue;
            }
            $unitPrice = $item->product->effectivePrice();
            $lineTotal = Money::mul($unitPrice, $item->quantity);
            $subtotal = Money::add($subtotal, $lineTotal);
            $itemCount += $item->quantity;

            $primaryImage = $item->product->images->first();

            $itemsPayload[] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'slug' => $item->product->slug,
                'sku' => $item->product->sku,
                'image_url' => $primaryImage?->fullUrl(),
                'regular_price' => Money::normalize((string) $item->product->regular_price),
                'discount_price' => $item->product->discount_price !== null ? Money::normalize((string) $item->product->discount_price) : null,
                'unit_price' => $unitPrice,
                'quantity' => $item->quantity,
                'line_total' => $lineTotal,
                'stock_quantity' => $item->product->stock_quantity,
                'is_available' => $item->product->isInStock($item->quantity),
                'selected_color' => $item->selected_color !== '' ? $item->selected_color : null,
                'selected_size' => $item->selected_size !== '' ? $item->selected_size : null,
            ];
        }

        $discountAmount = '0.00';
        $appliedCoupon = null;
        $couponNotice = null;

        if ($cart->coupon !== null && $itemsPayload !== []) {
            try {
                $result = $this->couponService->validateAndCalculate($cart->coupon->code, $subtotal, $customer);
                $discountAmount = $result['discount_amount'];
                $appliedCoupon = [
                    'id' => $cart->coupon->id,
                    'code' => $cart->coupon->code,
                    'type' => $cart->coupon->type->value,
                    'value' => Money::normalize((string) $cart->coupon->value),
                ];
            } catch (DomainException $e) {
                $couponNotice = $e->getMessage();
                $cart->update(['coupon_id' => null]);
            }
        } elseif ($cart->coupon_id !== null && $itemsPayload === []) {
            $cart->update(['coupon_id' => null]);
        }

        $afterDiscount = Money::max('0.00', Money::sub($subtotal, $discountAmount));
        $shippingAmount = $this->shippingCalculator->calculate($afterDiscount);
        $totalAmount = Money::add($afterDiscount, $shippingAmount);

        return [
            'id' => $cart->id,
            'items' => $itemsPayload,
            'item_count' => $itemCount,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'shipping_amount' => $shippingAmount,
            'total_amount' => $totalAmount,
            'coupon' => $appliedCoupon,
            'coupon_notice' => $couponNotice,
        ];
    }

    public function computeRawSubtotal(Cart $cart): string
    {
        $subtotal = '0.00';
        foreach ($cart->items as $item) {
            if ($item->product === null || ! $item->product->is_active) {
                continue;
            }
            $subtotal = Money::add($subtotal, Money::mul($item->product->effectivePrice(), $item->quantity));
        }

        return $subtotal;
    }
}
