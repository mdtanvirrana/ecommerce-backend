<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note: Monetary fields (prices, totals, discounts, shipping) are deliberately
     * NOT accepted from the client; Laravel computes them exclusively from MySQL.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_full_name' => ['required', 'string', 'min:2', 'max:255'],
            'shipping_email' => ['required', 'string', 'email', 'max:255'],
            'shipping_phone' => ['required', 'string', 'min:6', 'max:50'],
            'shipping_address' => ['required', 'string', 'min:5', 'max:1000'],
            'shipping_city' => ['required', 'string', 'min:2', 'max:120'],
            'shipping_postal_code' => ['required', 'string', 'min:2', 'max:30'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
