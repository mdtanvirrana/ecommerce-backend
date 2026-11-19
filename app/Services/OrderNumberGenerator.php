<?php

namespace App\Services;

use App\Models\OrderSequence;
use Illuminate\Database\QueryException;

class OrderNumberGenerator
{
    /**
     * Generate concurrency-safe sequential order numbers like ORD-2026-000001.
     * Must be invoked inside an active database transaction.
     */
    public function next(): string
    {
        $year = (int) now()->format('Y');

        $sequence = OrderSequence::query()
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            try {
                OrderSequence::query()->create([
                    'year' => $year,
                    'last_number' => 0,
                ]);
            } catch (QueryException) {
                // Another concurrent transaction inserted the year row first.
            }

            $sequence = OrderSequence::query()
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $sequence->last_number++;
        $sequence->save();

        return sprintf('ORD-%d-%06d', $year, $sequence->last_number);
    }
}
