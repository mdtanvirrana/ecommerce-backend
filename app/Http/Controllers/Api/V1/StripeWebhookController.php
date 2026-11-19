<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = (string) $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        if ($signature === '') {
            return ApiResponse::error('Missing Stripe-Signature header.', 400);
        }

        $result = $this->paymentService->handleWebhook($payload, $signature);

        return ApiResponse::success($result, 'Webhook received.');
    }
}
