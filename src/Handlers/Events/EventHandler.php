<?php
namespace DreamFactory\Core\Limit\Handlers\Events;

use DreamFactory\Core\Events\BaseRoleEvent;
use DreamFactory\Core\Events\BaseServiceEvent;
use DreamFactory\Core\Events\RoleDeletedEvent;
use DreamFactory\Core\Events\UserDeletedEvent;
use DreamFactory\Core\Events\RoleModifiedEvent;
use DreamFactory\Core\Events\ServiceDeletedEvent;
use DreamFactory\Core\Events\ServiceModifiedEvent;
use DreamFactory\Core\Limit\Events\LimitExceeded;
use Illuminate\Contracts\Events\Dispatcher;
use DreamFactory\Core\Limit\Models\Limit;
use DreamFactory\Core\Limit\Resources\System\LimitCache;

class EventHandler
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param  Dispatcher $events
     */
    public function subscribe($events)
    {
        $events->listen(
            [
                UserDeletedEvent::class,
            ],
            static::class . '@handleUserDeletedEvent'
        );
        $events->listen(
            [
                RoleDeletedEvent::class,
            ],
            static::class . '@handleRoleDeletedEvent'
        );
        $events->listen(
            [
                ServiceDeletedEvent::class,
            ],
            static::class . '@handleServiceDeletedEvent'
        );
        /** Uses its own handler, local to the limits package. */
        $events->listen(
            [
                LimitExceeded::class
            ],
        static::class . '@handleLimitExceededEvent'
        );

    }

    /**
     * Handle User deleted events.
     *
     * @param BaseRoleEvent $event
     *
     * @return void
     */
    public function handleUserDeletedEvent($event)
    {
        $userId = $event->user->id;
        $limits = Limit::where('user_id', $userId)->get();
        if(!$limits->isEmpty()){
            $this->wipeLimits($limits);
        }
    }

    public function handleServiceDeletedEvent($event)
    {
        $serviceId = $event->service->id;
        $limits = Limit::where('service_id', $serviceId)->get();
        if(!$limits->isEmpty()){
            $this->wipeLimits($limits);
        }
    }

    public function handleRoleDeletedEvent($event)
    {
        $roleId = $event->role->id;
        $limits = Limit::where('role_id', $roleId)->get();
        if(!$limits->isEmpty()){
            $this->wipeLimits($limits);
        }
    }

    public function handleLimitExceededEvent($event){
        /** Do limit exceeded stuff */
    }

    protected function wipeLimits($limits)
    {
        foreach($limits as $limit){
            $limitCache = new LimitCache();
            /** Clear the cache for the limit we're disposing of */
            $limitCache->clearById($limit->id);
            /** Remove the Limit from the DB */
            Limit::deleteById($limit->id);
        }
    }


}
