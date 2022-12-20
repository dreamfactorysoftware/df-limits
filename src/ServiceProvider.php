<?php

namespace DreamFactory\Core\Limit;

use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;
use DreamFactory\Core\Limit\Resources\System\Limit as LimitsResource;
use DreamFactory\Core\Limit\Resources\System\LimitCache;
use DreamFactory\Core\Limit\Http\Middleware\EvaluateLimits;
use DreamFactory\Core\Limit\Handlers\Events\EventHandler;
use Illuminate\Routing\Router;
use Route;
use Event;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * @inheritdoc
     */
    public function boot()
    {
        // add our limit config
        $configPath = __DIR__ . '/../config/limit.php';
        if (function_exists('config_path')) {
            $publishPath = config_path('limit.php');
        } else {
            $publishPath = base_path('config/limit.php');
        }
        $this->publishes([$configPath => $publishPath], 'config');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->addMiddleware();

        // subscribe to all listened to events
        Event::subscribe(new EventHandler());
    }

    public function register()
    {
        // merge in limit config, https://laravel.com/docs/5.4/packages#resources
        $this->mergeConfigFrom(__DIR__ . '/../config/limit.php', 'limit');

        // Add our service types.
        $this->app->resolving('df.system.resource', function (SystemResourceManager $df) {
            $df->addType(
                new SystemResourceType([
                    'name'                  => 'limit',
                    'label'                 => 'API limits Management',
                    'description'           => 'Allows limits capability.',
                    'class_name'            => LimitsResource::class,
                    'subscription_required' => LicenseLevel::GOLD,
                    'singleton'             => false,
                    'read_only'             => false,
                ])
            );
            $df->addType(
                new SystemResourceType([
                    'name'                  => 'limit_cache',
                    'label'                 => 'API limits Cache Management',
                    'description'           => 'Allows for clearing and resetting Limit cache.',
                    'class_name'            => LimitCache::class,
                    'subscription_required' => LicenseLevel::GOLD,
                    'singleton'             => false,
                    'read_only'             => false
                ])
            );
        });
    }

    /**
     * Register any middleware aliases.
     *
     * @return void
     */
    protected function addMiddleware()
    {
        Route::aliasMiddleware('df.evaluate_limits', EvaluateLimits::class);
        Route::pushMiddlewareToGroup('df.api', 'df.evaluate_limits');
    }
}
