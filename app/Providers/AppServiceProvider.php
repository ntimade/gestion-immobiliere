<?php

namespace App\Providers;

use App\Http\Controllers\InvoiceMessageController;
use App\Models\Invoice;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::resourceVerbs(['create' => 'nouveau', 'edit' => 'modifier']);
        Paginator::defaultView('pagination.app');
        Paginator::defaultSimpleView('pagination.app');
        View::composer('layouts.app', function ($view) {
            $view->with('unpaidCount', Invoice::overdue()->count())
                ->with('messagesCount', InvoiceMessageController::waitingCount());
        });
    }
}
