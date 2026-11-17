<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceMessageController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\LeaseStatementController;
use App\Http\Controllers\LegalNoticeController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StayController;
use App\Http\Controllers\Support\AccountController as SupportAccountController;
use App\Http\Controllers\Support\RequestController as SupportRequestAdminController;
use App\Http\Controllers\Support\SessionController as SupportSessionController;
use App\Http\Controllers\Support\SubscriptionController as SupportSubscriptionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantHistoryController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnpaidController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AuthenticateSupport;
use App\Http\Middleware\EnsureAccountAdmin;
use App\Http\Middleware\EnsureMessagesEnabled;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

Route::get('langue/{locale}', fn (string $locale) => back(fallback: '/')->withCookie(cookie()->forever('locale', $locale)))
    ->whereIn('locale', SetLocale::LOCALES)->name('locale.switch');

Route::get('theme/{theme}', fn (string $theme) => back(fallback: '/')->withCookie(cookie()->forever('theme', $theme)))
    ->whereIn('theme', ['light', 'dark'])->name('theme.switch');

Route::get('csrf/refresh', fn () => response()->json(['token' => csrf_token()]))->name('csrf.refresh');

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('tableau-de-bord', DashboardController::class)->name('dashboard');

    Route::resource('biens', PropertyController::class)->names('properties')->parameters(['biens' => 'property']);
    Route::resource('logements', UnitController::class)->names('units')->parameters(['logements' => 'unit']);
    Route::resource('locataires', TenantController::class)->names('tenants')->parameters(['locataires' => 'tenant']);
    Route::get('historiques', [TenantHistoryController::class, 'index'])->name('history.index');
    Route::get('locataires/{tenant}/historique', [TenantHistoryController::class, 'show'])->name('tenants.history');
    Route::get('locataires/{tenant}/historique/pdf', [TenantHistoryController::class, 'pdf'])->name('tenants.history.pdf');

    Route::get('carte', [MapController::class, 'index'])->name('map.index');

    Route::get('logements/{unit}/entree', [LeaseController::class, 'create'])->name('leases.create');
    Route::post('logements/{unit}/entree', [LeaseController::class, 'store'])->name('leases.store');
    Route::get('baux/{lease}', [LeaseController::class, 'show'])->name('leases.show');
    Route::get('baux/{lease}/etat', [LeaseStatementController::class, 'show'])->name('leases.statement');
    Route::get('baux/{lease}/etat/pdf', [LeaseStatementController::class, 'pdf'])->name('leases.statement.pdf');
    Route::get('baux/{lease}/modifier', [LeaseController::class, 'edit'])->name('leases.edit');
    Route::put('baux/{lease}', [LeaseController::class, 'update'])->name('leases.update');
    Route::get('baux/{lease}/sortie', [LeaseController::class, 'exit'])->name('leases.exit');
    Route::post('baux/{lease}/sortie', [LeaseController::class, 'end'])->name('leases.end');
    Route::post('baux/{lease}/annuler-sortie', [LeaseController::class, 'reopen'])->name('leases.reopen');
    Route::post('baux/{lease}/preavis', [LeaseController::class, 'notice'])->name('leases.notice');
    Route::post('baux/{lease}/annuler-preavis', [LeaseController::class, 'cancelNotice'])->name('leases.notice.cancel');
    Route::post('baux/{lease}/etat-des-lieux', [LeaseController::class, 'inspection'])->name('leases.inspection');
    Route::post('baux/{lease}/preavis/message/{channel}', [LegalNoticeController::class, 'openNotice'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.notice');
    Route::post('baux/{lease}/etat-des-lieux/message/{channel}', [LegalNoticeController::class, 'openInspection'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.inspection');

    Route::get('factures', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('factures/imprimer', [InvoiceController::class, 'month'])->name('invoices.month');
    Route::get('factures/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('factures/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('factures/{invoice}/modifier', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::put('factures/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');

    Route::get('impayes', [UnpaidController::class, 'index'])->name('unpaid.index');

    Route::get('paiements', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('baux/{lease}/paiements', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('paiements/{payment}/modifier', [PaymentController::class, 'edit'])->name('payments.edit');
    Route::put('paiements/{payment}', [PaymentController::class, 'update'])->name('payments.update');
    Route::delete('paiements/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    // Location à la nuit des logements meublés.
    Route::get('sejours', [StayController::class, 'index'])->name('stays.index');
    Route::get('sejours/nouveau', [StayController::class, 'create'])->name('stays.create');
    Route::post('sejours', [StayController::class, 'store'])->name('stays.store');
    Route::get('sejours/historique', [StayController::class, 'report'])->name('stays.report');
    Route::get('sejours/historique/pdf', [StayController::class, 'reportPdf'])->name('stays.report.pdf');
    Route::get('sejours/{stay}', [StayController::class, 'show'])->whereNumber('stay')->name('stays.show');
    Route::post('sejours/{stay}/paiement', [StayController::class, 'pay'])->whereNumber('stay')->name('stays.pay');
    Route::get('sejours/{stay}/facture', [StayController::class, 'invoice'])->whereNumber('stay')->name('stays.invoice');
    Route::get('sejours/{stay}/facture/pdf', [StayController::class, 'invoicePdf'])->whereNumber('stay')->name('stays.invoice.pdf');
    Route::post('sejours/{stay}/message/{channel}', [StayController::class, 'message'])->whereNumber('stay')->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('stays.message');

    Route::get('messages', [InvoiceMessageController::class, 'index'])->name('messages.index');
    Route::post('factures/{invoice}/message/{channel}', [InvoiceMessageController::class, 'open'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.open');
    Route::post('factures/{invoice}/rappel/{channel}', [InvoiceMessageController::class, 'openReminder'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.reminder');
    Route::post('paiements/{payment}/message/{channel}', [InvoiceMessageController::class, 'openPayment'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.payment');
    Route::post('baux/{lease}/relance/{channel}', [InvoiceMessageController::class, 'openLateNotice'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.late');
    Route::post('baux/{lease}/procedure/{channel}', [LegalNoticeController::class, 'open'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.legal');
    Route::post('baux/{lease}/sortie/message/{channel}', [LegalNoticeController::class, 'openExit'])->whereIn('channel', ['whatsapp', 'sms'])->middleware(EnsureMessagesEnabled::class)->name('messages.exit');
    Route::get('baux/{lease}/procedure/lettre', [LegalNoticeController::class, 'letter'])->name('messages.legal.letter');

    Route::get('abonnement', [SubscriptionController::class, 'index'])->name('subscription.index');
    Route::post('abonnement', [SubscriptionController::class, 'store'])->middleware(['throttle:10,1', EnsureAccountAdmin::class])->name('subscription.store');

    Route::resource('utilisateurs', UserController::class)->except('show')->names('users')->parameters(['utilisateurs' => 'member'])->whereNumber('member')->middleware(EnsureAccountAdmin::class);

    Route::get('parametres', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('parametres', [SettingsController::class, 'update'])->middleware(EnsureAccountAdmin::class)->name('settings.update');
    Route::get('parametres/logo', [SettingsController::class, 'showLogo'])->name('settings.logo');
    Route::post('parametres/logo', [SettingsController::class, 'updateLogo'])->middleware(EnsureAccountAdmin::class)->name('settings.logo.update');
    Route::delete('parametres/logo', [SettingsController::class, 'destroyLogo'])->middleware(EnsureAccountAdmin::class)->name('settings.logo.destroy');
    Route::put('parametres/mot-de-passe', [SettingsController::class, 'updatePassword'])->name('settings.password');
    Route::put('parametres/questions-securite', [SettingsController::class, 'updateSecurityQuestions'])->name('settings.security');
});

// Poste de support : comptes séparés de ceux des propriétaires.
Route::prefix('support')->name('support.')->group(function () {
    Route::get('/', fn () => redirect()->route(Auth::guard('support')->check() ? 'support.accounts.index' : 'support.login'))->name('home');

    Route::middleware('guest:support')->group(function () {
        Route::get('connexion', [SupportSessionController::class, 'create'])->name('login');
        Route::post('connexion', [SupportSessionController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware(AuthenticateSupport::class)->group(function () {
        Route::post('deconnexion', [SupportSessionController::class, 'destroy'])->name('logout');
        Route::get('demandes', [SupportRequestAdminController::class, 'index'])->name('requests.index');
        Route::get('demandes/{supportRequest}', [SupportRequestAdminController::class, 'show'])->name('requests.show');
        Route::post('demandes/{supportRequest}/reinitialiser', [SupportRequestAdminController::class, 'reset'])->name('requests.reset');
        Route::post('demandes/{supportRequest}/cloturer', [SupportRequestAdminController::class, 'close'])->name('requests.close');

        Route::get('comptes', [SupportAccountController::class, 'index'])->name('accounts.index');
        Route::get('comptes/{organisation}', [SupportAccountController::class, 'show'])->whereNumber('organisation')->name('accounts.show');
        Route::post('comptes/{organisation}/utilisateurs/{user}/reinitialiser', [SupportAccountController::class, 'resetPassword'])->whereNumber('user')->name('accounts.reset');
        Route::post('comptes/{organisation}/suspendre', [SupportAccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('comptes/{organisation}/reactiver', [SupportAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('comptes/{organisation}/prolonger', [SupportSubscriptionController::class, 'extend'])->name('subscriptions.extend');

        Route::get('abonnements', [SupportSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('abonnements/plans', [SupportSubscriptionController::class, 'settings'])->name('subscriptions.settings');
        Route::put('abonnements/plans', [SupportSubscriptionController::class, 'updatePlans'])->name('subscriptions.plans');
        Route::put('abonnements/modes', [SupportSubscriptionController::class, 'updateMethods'])->name('subscriptions.methods');
        Route::post('abonnements/{payment}/valider', [SupportSubscriptionController::class, 'approve'])->name('subscriptions.approve');
        Route::post('abonnements/{payment}/refuser', [SupportSubscriptionController::class, 'reject'])->name('subscriptions.reject');
    });
});
