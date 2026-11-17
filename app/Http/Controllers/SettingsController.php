<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Services\PasswordRecovery;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $organisation = $request->user()->organisation;

        return view('settings', [
            'organisation' => $organisation,
            'securityQuestions' => $request->user()->securityAnswers()->orderBy('id')->pluck('question')->all(),
            'currencies' => config('currencies'),
            'currencyLocked' => $this->currencyLocked($organisation),
        ]);
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        // Pas de SVG : il peut contenir du code exécuté par le navigateur.
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [], ['logo' => 'logo']);

        $organisation = $request->user()->organisation;
        $previous = $organisation->logo_path;

        $organisation->update(['logo_path' => $request->file('logo')->store('logos', 'local')]);

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return back()->with('success', __('Logo enregistré.'));
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        $organisation = $request->user()->organisation;

        if ($organisation->logo_path) {
            Storage::disk('local')->delete($organisation->logo_path);
            $organisation->update(['logo_path' => null]);
        }

        return back()->with('success', __('Logo retiré.'));
    }

    // Toujours le logo de l'organisation connectée : l'adresse ne désigne aucun fichier.
    public function showLogo(Request $request): StreamedResponse
    {
        $path = $request->user()->organisation->logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    // Les montants déjà facturés sont exprimés dans la devise d'origine.
    private function currencyLocked(Organisation $organisation): bool
    {
        return $organisation->invoices()->exists();
    }

    public function update(Request $request): RedirectResponse
    {
        $organisation = $request->user()->organisation;

        $currencyRule = $this->currencyLocked($organisation)
            ? Rule::in([$organisation->currency])
            : Rule::in(array_keys(config('currencies')));

        $previousCountry = $organisation->phone_country;
        $previousReminderDays = (int) $organisation->reminder_days;

        $organisation->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_country' => ['nullable', Rule::in(array_keys(config('phone_countries')))],
            'address' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', $currencyRule],
            'reminder_days' => ['required', 'integer', 'min:1', 'max:15'],
            'deposit_months' => ['sometimes', 'required', 'integer', 'min:0', 'max:24'],
            'legal_notice_after' => ['nullable', 'integer', 'min:1', 'max:365'],
            'legal_notice_unit' => ['sometimes', 'required', Rule::in(['months', 'days'])],
        ], [], ['phone_country' => __('pays'), 'reminder_days' => __('délai du rappel'), 'deposit_months' => __('mois de caution'), 'legal_notice_after' => __('délai avant la procédure')]));

        // Un délai plus long doit faire apparaître tout de suite les loyers du mois suivant.
        if ((int) $organisation->reminder_days !== $previousReminderDays) {
            $organisation->forceFill(['invoices_synced_on' => null])->save();
        }

        $response = back()->with('success', __('Paramètres enregistrés.'));

        if (! $organisation->phone_country || $organisation->phone_country === $previousCountry) {
            return $response;
        }

        // Les numéros déjà complets ne changent pas : seuls ceux sans indicatif le reçoivent.
        $result = PhoneNumber::normalizeOrganisation($organisation->id, $organisation->phone_country);

        return $result['invalid'] > 0
            ? $response->with('error', __(':v1 numéro(s) de locataire illisible(s) : ils sont marqués « À corriger » dans la liste des locataires.', ['v1' => $result['invalid']]))
            : $response;
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->forceFill(['password' => $validated['password'], 'must_change_password' => false])->save();

        return back()->with('success', __('Mot de passe modifié.'));
    }

    public function updateSecurityQuestions(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $validated = $request->validateWithBag('security', [
            'security_current_password' => ['required', 'current_password'],
        ] + $recovery->rules(), $recovery->messages(), $recovery->attributes());

        $recovery->saveAnswers($request->user(), $validated['security']);

        return redirect()->to(route('settings.edit').'#section-questions')->with('success', __('Questions de sécurité enregistrées.'));
    }
}
