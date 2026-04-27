<?php

namespace KsipTelnet;

use Illuminate\Support\ServiceProvider;

class KSIPServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                KSIPGen::class,
                KSIPRegisterUser::class,
            ]);
        }
    }
}
