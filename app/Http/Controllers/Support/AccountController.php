<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\PasswordSupportRequest;
use App\Models\SubscriptionPayment;
use App\Models\SupportAction;
use App\Services\PasswordRecovery;
use App\Support\SortedTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $term = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $suspendedOnly = $request->query('etat') === 'suspendus';

        return view('support.accounts.index', [
            'term' => $term,
            'suspendedOnly' => $suspendedOnly,
            'suspendedCount' => Organisation::whereNotNull('suspended_at')->count(),
            'accounts' => SortedTable::query(
                $this->withFigures(Organisation::query())
                    ->when($term !== '', function ($query) use ($term) {
                        $like = '%'.addcslashes($term, '%_\\').'%';

                        $query->where(fn ($where) => $where->where('name', 'like', $like)->orWhere('code', 'like', $like)
                            ->orWhereExists(fn ($users) => $users->from('users')->whereColumn('users.organisation_id', 'organisations.id')
                                ->where(fn ($match) => $match->where('users.email', 'like', $like)->orWhere('users.name', 'like', $like))));
                    })
                    ->when($suspendedOnly, fn ($query) => $query->whereNotNull('suspended_at'))
                    ->orderBy('name'),
                'comptes',
                [
                    'name' => 'name',
                    'code' => 'code',
                    'owner' => 'owner_email',
                    'tenants' => 'tenants_total',
                    'units' => 'units_total',
                    'invoices' => 'invoices_total',
                    'status' => 'suspended_at',
                    'subscription' => 'subscribed_until',
                    'created' => 'created_at',
                ],
            ),
        ]);
    }

    public function show(int $organisation): View
    {
        $account = $this->withFigures(Organisation::query())->findOrFail($organisation);
        $users = $account->users()->withCount('securityAnswers')->orderBy('id')->get();

        return view('support.accounts.show', [
            'account' => $account,
            'users' => $users,
            'payments' => SortedTable::query(
                SubscriptionPayment::where('organisation_id', $account->id)->latest()->latest('id'),
                'paiements',
                ['date' => 'created_at', 'months' => 'months', 'amount' => 'amount', 'method' => 'method', 'end' => 'period_end', 'status' => 'status'],
            ),
            'requests' => PasswordSupportRequest::whereIn('user_id', $users->pluck('id'))->latest()->limit(5)->get(),
            'actions' => SupportAction::with(['admin', 'user'])->where('organisation_id', $account->id)->latest()->latest('id')->limit(10)->get(),
        ]);
    }

    public function resetPassword(Request $request, Organisation $organisation, int $user, PasswordRecovery $recovery): RedirectResponse
    {
        $target = $organisation->users()->findOrFail($user);

        $request->validate(['identity_verified' => ['accepted']], [
            'identity_verified.accepted' => __('Confirmez d\'abord que vous avez vérifié l\'identité du propriétaire.'),
        ]);

        $temporary = Str::password(12, symbols: false);
        $admin = $request->user('support');

        DB::transaction(function () use ($recovery, $target, $temporary, $admin, $organisation) {
            $recovery->setPassword($target, $temporary, mustChange: true);

            // Une demande en attente pour ce compte est réglée par cette réinitialisation.
            PasswordSupportRequest::where('user_id', $target->id)->where('status', PasswordSupportRequest::PENDING)->update([
                'status' => PasswordSupportRequest::RESET,
                'handled_by' => $admin->id,
                'handled_at' => now(),
            ]);

            SupportAction::create([
                'support_admin_id' => $admin->id,
                'organisation_id' => $organisation->id,
                'user_id' => $target->id,
                'action' => SupportAction::PASSWORD_RESET,
            ]);
        });

        return redirect()->route('support.accounts.show', $organisation->id)
            ->with('temporary_password', $temporary)->with('temporary_login', $target->email);
    }

    public function suspend(Request $request, Organisation $organisation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('motif')]);

        if (! $organisation->isSuspended()) {
            DB::transaction(function () use ($request, $organisation, $data) {
                $organisation->forceFill(['suspended_at' => now(), 'suspension_reason' => $data['reason']])->save();

                // Déconnexion immédiate : les données du compte restent intactes.
                DB::table('sessions')->whereIn('user_id', $organisation->users()->pluck('id'))->delete();

                SupportAction::create([
                    'support_admin_id' => $request->user('support')->id,
                    'organisation_id' => $organisation->id,
                    'action' => SupportAction::SUSPENDED,
                    'note' => $data['reason'],
                ]);
            });
        }

        return redirect()->route('support.accounts.show', $organisation->id)->with('success', __('Compte suspendu. Ses utilisateurs ont été déconnectés.'));
    }

    public function reactivate(Request $request, Organisation $organisation): RedirectResponse
    {
        if ($organisation->isSuspended()) {
            DB::transaction(function () use ($request, $organisation) {
                $organisation->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

                SupportAction::create([
                    'support_admin_id' => $request->user('support')->id,
                    'organisation_id' => $organisation->id,
                    'action' => SupportAction::REACTIVATED,
                ]);
            });
        }

        return redirect()->route('support.accounts.show', $organisation->id)->with('success', __('Compte réactivé.'));
    }

    /**
     * Totaux lus directement dans les tables : le support voit tous les comptes, même si
     * ce navigateur est aussi connecté à une organisation. Il ne voit que des totaux.
     */
    private function withFigures(Builder $query): Builder
    {
        $total = fn (string $table) => DB::table($table)->selectRaw('COUNT(*)')->whereColumn($table.'.organisation_id', 'organisations.id');

        return $query->select('organisations.*')
            ->selectSub($total('tenants'), 'tenants_total')
            ->selectSub($total('units'), 'units_total')
            ->selectSub($total('invoices'), 'invoices_total')
            ->selectSub(DB::table('invoices')->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.organisation_id', 'organisations.id'), 'unpaid_total')
            ->selectSub(DB::table('users')->select('email')->whereColumn('users.organisation_id', 'organisations.id')->orderBy('id')->limit(1), 'owner_email');
    }
}
