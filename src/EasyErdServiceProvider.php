<?php

namespace Dgtlss\EasyErd;

use Illuminate\Support\ServiceProvider;
use Dgtlss\EasyErd\Console\GenerateErdCommand;
use Dgtlss\EasyErd\Services\ErdGenerator;

class EasyErdServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/easyerd.php', 'easyerd'
        );

        $this->app->singleton(ErdGenerator::class, function ($app) {
            return new ErdGenerator(config('easyerd') ?? []);
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateErdCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/Config/easyerd.php' => config_path('easyerd.php'),
            ], 'config');
        }
    }
}