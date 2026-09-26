<?php

namespace App\Services;

use App\Models\TellerShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drives the teller POS shift lifecycle: a teller must open a shift with a
 * counted starting cash float before processing any cash transactions, and
 * closes it by counting the drawer again — the gap between that count and
 * the system-expected cash (starting float + approved deposits - approved
 * withdrawals) is the shift's variance.
 */
class TellerShiftService
{
    public function currentShift(User $teller): ?TellerShift
    {
        return TellerShift::where('teller_id', $teller->id)->where('status', 'open')->first();
    }

    public function startShift(User $teller, float $startingCash): TellerShift
    {
        if ($startingCash < 0) {
            throw new \InvalidArgumentException(__('Starting cash cannot be negative.'));
        }

        if ($this->currentShift($teller)) {
            throw new \InvalidArgumentException(__('You already have an open shift. End it before starting a new one.'));
        }

        return TellerShift::create([
            'teller_id' => $teller->id,
            'starting_cash' => $startingCash,
            'status' => 'open',
            'started_at' => now(),
        ]);
    }

    /**
     * Close a shift: snapshot the system-expected cash and the teller's
     * physical count, and lock in the variance between them.
     */
    public function endShift(TellerShift $shift, float $actualCash): TellerShift
    {
        if ($actualCash < 0) {
            throw new \InvalidArgumentException(__('Counted cash cannot be negative.'));
        }

        if (! $shift->isOpen()) {
            throw new \InvalidArgumentException(__('This shift has already been closed.'));
        }

        return DB::transaction(function () use ($shift, $actualCash) {
            $locked = TellerShift::lockForUpdate()->findOrFail($shift->id);
            $totals = $locked->totals();

            $locked->update([
                'status' => 'closed',
                'ended_at' => now(),
                'ending_cash' => $actualCash,
                'expected_cash' => $totals['expected_cash'],
                'variance' => $actualCash - $totals['expected_cash'],
            ]);

            return $locked->fresh();
        });
    }
}
