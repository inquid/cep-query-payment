<?php

namespace Carlosupreme\CEPQueryPayment;

use Carlosupreme\CEPQueryPayment\CEPQueryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class CEPQueryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(CEPQueryService::class, function ($app) {
            return new CEPQueryService();
        });

        // Alias for backward compatibility
        $this->app->alias(CEPQueryService::class, 'cep-query');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
