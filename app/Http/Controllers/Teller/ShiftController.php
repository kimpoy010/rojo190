<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\TellerShift;
use App\Services\TellerShiftService;
use App\Support\CsvExport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShiftController extends Controller
{
    public function __construct(private TellerShiftService $shiftService) {}

    public function start(): View|RedirectResponse
    {
        if ($this->shiftService->currentShift(auth()->user())) {
            return redirect()->route('teller.dashboard');
        }

        return view('teller.shift.start');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'starting_cash' => 'required|numeric|min:0',
        ]);

        try {
            $this->shiftService->startShift(auth()->user(), (float) $data['starting_cash']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.shift.start')->with('error', $e->getMessage());
        }

        return redirect()->route('teller.dashboard')->with('success', __('Shift started. Good luck!'));
    }

    public function end(): View|RedirectResponse
    {
        $shift = $this->shiftService->currentShift(auth()->user());

        if (! $shift) {
            return redirect()->route('teller.dashboard')->with('error', __('You have no open shift.'));
        }

        $totals = $shift->totals();

        return view('teller.shift.end', compact('shift', 'totals'));
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
        ]);

        $shift = $this->shiftService->currentShift(auth()->user());

        if (! $shift) {
            return redirect()->route('teller.dashboard')->with('error', __('You have no open shift.'));
        }

        try {
            $shift = $this->shiftService->endShift($shift, (float) $data['actual_cash']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.shift.end')->with('error', $e->getMessage());
        }

        return redirect()->route('teller.shift.report', $shift)->with('success', __('Shift closed.'));
    }

    public function report(TellerShift $tellerShift): View
    {
        abort_if($tellerShift->teller_id !== auth()->id(), 403);

        // A closed shift's totals are always re-derived live from its own
        // completed transactions, which never change after close — the
        // frozen expected_cash/variance columns exist purely so the
        // reconciliation figures the teller saw at close time are preserved
        // verbatim even if this recomputation logic ever changes later.
        $totals = $tellerShift->totals();

        return view('teller.shift.report', compact('tellerShift', 'totals'));
    }

    public function export(TellerShift $tellerShift): StreamedResponse
    {
        abort_if($tellerShift->teller_id !== auth()->id(), 403);

        $transactions = $tellerShift->cashTransactions()
            ->where('status', 'completed')
            ->with('user:id,name,username')
            ->orderBy('completed_at')
            ->cursor()
            ->map(fn ($tx) => [
                $tx->completed_at?->format('Y-m-d H:i:s') ?? '',
                $tx->type === 'deposit' ? 'Deposit' : 'Withdrawal',
                $tx->user->displayName(),
                number_format((float) $tx->amount, 2, '.', ''),
            ]);

        return CsvExport::stream(
            "shift-{$tellerShift->id}-report.csv",
            ['Date', 'Type', 'Player', 'Amount'],
            $transactions
        );
    }

    public function history(): View
    {
        $shifts = auth()->user()->tellerShifts()->orderByDesc('started_at')->paginate(30);

        return view('teller.shift.history', compact('shifts'));
    }
}
