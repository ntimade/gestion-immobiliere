<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SupportRequestController;
use App\Http\Controllers\Auth\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('inscription', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('inscription', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');

    Route::get('connexion', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('connexion', [AuthenticatedSessionController::class, 'store']);

    Route::get('mot-de-passe-oublie', [PasswordRecoveryController::class, 'create'])->name('password.request');
    Route::post('mot-de-passe-oublie', [PasswordRecoveryController::class, 'lookup'])->middleware('throttle:10,1')->name('password.lookup');
    Route::get('mot-de-passe-oublie/questions', [PasswordRecoveryController::class, 'questions'])->name('password.questions');
    Route::post('mot-de-passe-oublie/questions', [PasswordRecoveryController::class, 'verify'])->middleware('throttle:10,1')->name('password.verify');
    Route::get('mot-de-passe-oublie/nouveau', [PasswordRecoveryController::class, 'edit'])->name('password.new');
    Route::post('mot-de-passe-oublie/nouveau', [PasswordRecoveryController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

    Route::get('assistance', [SupportRequestController::class, 'create'])->name('support.request');
    Route::post('assistance', [SupportRequestController::class, 'store'])->middleware('throttle:5,10')->name('support.request.store');
});

Route::middleware('auth')->group(function () {
    Route::get('bienvenue', [WelcomeController::class, 'show'])->name('welcome');
    Route::get('bienvenue/identifiants', [WelcomeController::class, 'download'])->name('welcome.download');
    Route::post('bienvenue/terminer', [WelcomeController::class, 'finish'])->name('welcome.finish');

    Route::post('deconnexion', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
