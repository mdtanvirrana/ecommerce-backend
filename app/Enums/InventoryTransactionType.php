<?php

namespace App\Enums;

enum InventoryTransactionType: string
{
    case SaleDeduction = 'sale_deduction';
    case CancellationRestoration = 'cancellation_restoration';
    case ManualAdjustment = 'manual_adjustment';
}
