<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Staff;
use App\Models\User;

class OrderPolicy
{
    /**
     * Strict customer ownership check (IDOR protection) and staff RBAC check.
     */
    public function view(User $user, Order $order): bool
    {
        if ($user instanceof Staff) {
            return $user->hasPermission('orders.view');
        }

        return $user instanceof Customer && (int) $order->user_id === (int) $user->id;
    }

    public function pay(Customer $customer, Order $order): bool
    {
        return (int) $order->user_id === (int) $customer->id;
    }

    public function updateStatus(Staff $staff, Order $order): bool
    {
        return $staff->hasPermission('orders.update_status');
    }

    public function cancel(Staff $staff, Order $order): bool
    {
        return $staff->hasPermission('orders.cancel');
    }
}
