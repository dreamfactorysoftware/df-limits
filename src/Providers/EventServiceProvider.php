<?php namespace DreamFactory\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{

    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        '\DreamFactory\Events\LimitDeleted' => [
            '\DreamFactory\Listeners\ClearLimitAfterDelete',
        ],
    ];
}
