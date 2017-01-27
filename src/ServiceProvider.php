<?php
namespace DreamFactory\Core\Limit;

use DreamFactory\Core\Components\ServiceDocBuilder;
use DreamFactory\Core\Resources\System\SystemResourceManager;
use DreamFactory\Core\Resources\System\SystemResourceType;
use DreamFactory\Core\Limit\Resources\System\Limit as LimitsResource;
use DreamFactory\Core\Limit\Resources\System\LimitCache;
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    use ServiceDocBuilder;

    public function register()
    {

        // Add our service types.
        $this->app->resolving('df.system.resource', function (SystemResourceManager $df){
            $df->addType(
                new SystemResourceType([
                    'name'        => 'limit',
                    'label'       => 'API limits Management',
                    'description' => 'Allows limits capability.',
                    'class_name'  => LimitsResource::class,
                    'singleton'   => false,
                    'read_only'   => false
                ])
            );
            $df->addType(
                new SystemResourceType([
                    'name'        => 'limit_cache',
                    'label'       => 'API limits Cache Management',
                    'description' => 'Allows for clearing and resetting Limit cache.',
                    'class_name'  => LimitCache::class,
                    'singleton'   => false,
                    'read_only'   => false
                ])
            );
        });
        /* // Add our table model mapping later
         $this->app->resolving('df.system.table_model_map', function (SystemTableModelMapper $df) {
             $df->addMapping('user_custom', UserCustom::class);
         });*/
    }

    /*/**
     * Add the Limits middleware to the Global Middleware Stack.
     * @param Request $request
     * @param Kernel  $kernel
     */
    public function boot(Request $request, Kernel $kernel)
    {
        /*if ($request->is('api/*'))
        {
            $kernel->pushMiddleware('DreamFactory\Core\Limit\Http\Middleware\EvaluateLimits');
        }*/
    }

}
