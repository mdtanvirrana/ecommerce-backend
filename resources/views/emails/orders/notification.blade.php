<x-mail::message>
# {{ $headline }}

{{ $intro }}

**Order Number:** {{ $order->order_number }}
**Order Status:** {{ ucfirst($order->status->value) }}
**Payment Status:** {{ ucfirst($order->payment_status->value) }}

<x-mail::table>
| Item | SKU | Qty | Price | Total |
| :--- | :--- | :---: | :---: | :---: |
@foreach($order->items as $item)
| {{ $item->product_name }} | {{ $item->product_sku }} | {{ $item->quantity }} | ${{ $item->unit_price }} | ${{ $item->line_total }} |
@endforeach
</x-mail::table>

**Subtotal:** ${{ $order->subtotal }}
@if((float) $order->discount_amount > 0)
**Discount ({{ $order->coupon_code }}):** -${{ $order->discount_amount }}
@endif
**Shipping:** ${{ $order->shipping_amount }}
**Total:** ${{ $order->total_amount }}

<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
