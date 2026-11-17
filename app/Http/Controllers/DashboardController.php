<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Services\PropertyFigures;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PropertyFigures $figures): View
    {
        $period = now()->format('Y-m');
        $month = Invoice::where('period', $period)->selectRaw('COALESCE(SUM(amount), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid')->first();
        $units = Unit::count();
        $occupied = Unit::occupied()->count();

        return view('dashboard', [
            'period' => $period,
            'properties' => Property::count(),
            'units' => $units,
            'occupied' => $occupied,
            'vacant' => $units - $occupied,
            'billed' => (float) $month->billed,
            'income' => $figures->totals(),
            'collected' => (float) Payment::where('paid_at', '>=', now()->startOfMonth()->toDateString())
                ->where('paid_at', '<', now()->startOfMonth()->addMonthNoOverflow()->toDateString())->sum('amount'),
            'monthRemaining' => max(0, (float) $month->billed - (float) $month->paid),
            'overdueTotal' => (float) Invoice::overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'depositsHeld' => (float) Lease::selectRaw('COALESCE(SUM(deposit_amount - deposit_retained - deposit_refunded), 0) as total')->value('total'),
            'remindersWaiting' => InvoiceMessageController::remindersToSend()->count(),
            'upcoming' => Invoice::with(['lease.tenant', 'lease.unit.property'])->dueWithin(7)->orderBy('due_date')->limit(10)->get(),
            'late' => Invoice::with(['lease.tenant', 'lease.unit.property'])->overdue()->orderBy('due_date')->limit(10)->get(),
            // Les départs annoncés, et ceux dont la sortie reste à enregistrer.
            'notices' => Lease::with(['tenant', 'unit.property'])->whereNull('end_date')->whereNotNull('notice_end_date')->orderBy('notice_end_date')->get(),
        ]);
    }
}
