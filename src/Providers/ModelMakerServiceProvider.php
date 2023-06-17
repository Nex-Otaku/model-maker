<?php

namespace NexOtaku\ModelMaker\Providers;

use Illuminate\Support\ServiceProvider;
use NexOtaku\ModelMaker\Commands\ModelMakerCommand;

class ModelMakerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->commands([
                            ModelMakerCommand::class,
                        ]);
    }
}
