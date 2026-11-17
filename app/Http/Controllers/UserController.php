<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\User;
use App\Services\PasswordRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

// L'administrateur gère l'équipe de sa société : uniquement les comptes de cette société.
class UserController extends Controller
{
    public function index(Request $request): View
    {
        return view('users.index', [
            'users' => $this->team($request)->orderBy('name')->get(),
            'code' => $request->user()->organisation->code,
        ]);
    }

    public function create(): View
    {
        return view('users.form', ['member' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $member = $request->user()->organisation->users()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        // Mot de passe provisoire : il choisit le sien à la première connexion.
        $member->forceFill(['role' => $data['role'], 'must_change_password' => true])->save();

        return redirect()->route('users.index')->with('success', __('Utilisateur ajouté. Donnez-lui le code du compte, son e-mail et son mot de passe provisoire : il devra le changer à sa première connexion.'));
    }

    public function edit(Request $request, int $member): View
    {
        return view('users.form', ['member' => $this->team($request)->findOrFail($member)]);
    }

    public function update(Request $request, int $member, PasswordRecovery $recovery): RedirectResponse
    {
        $target = $this->team($request)->findOrFail($member);
        $isSelf = $target->is($request->user());
        $data = $this->validated($request, $target);

        // Son propre rôle ne change pas ici : un compte garde toujours un administrateur.
        $target->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $isSelf ? $target->role : $data['role'],
        ])->save();

        if (! $isSelf && ! empty($data['password'])) {
            $recovery->setPassword($target, $data['password'], mustChange: true);
        }

        return redirect()->route('users.index')->with('success', __('Utilisateur modifié.'));
    }

    public function destroy(Request $request, int $member): RedirectResponse
    {
        $target = $this->team($request)->findOrFail($member);

        if ($target->is($request->user())) {
            return back()->with('error', __('Vous ne pouvez pas supprimer votre propre compte.'));
        }

        DB::transaction(function () use ($target) {
            Organisation::whereKey($target->organisation_id)->lockForUpdate()->first();

            if ($target->isAdmin() && User::where('organisation_id', $target->organisation_id)->where('role', User::ADMIN)->count() <= 1) {
                throw ValidationException::withMessages(['user' => __('Ce compte doit garder au moins un administrateur.')]);
            }

            DB::table('sessions')->where('user_id', $target->id)->delete();
            // Ses paiements restent enregistrés : ils appartiennent à la société.
            $target->delete();
        });

        return redirect()->route('users.index')->with('success', __('Utilisateur supprimé. Ses enregistrements sont conservés.'));
    }

    private function team(Request $request)
    {
        return $request->user()->organisation->users();
    }

    private function validated(Request $request, ?User $member): array
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member)],
            'role' => [$member?->is($request->user()) ? 'nullable' : 'required', Rule::in([User::ADMIN, User::MANAGER])],
            'password' => [$member ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => __('Cet e-mail est déjà utilisé par un autre compte.'),
        ], ['name' => __('nom'), 'role' => __('rôle')]);
    }
}
