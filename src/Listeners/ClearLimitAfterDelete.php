<?php

namespace DreamFactory\Listeners;

use DreamFactory\Events\LimitDeleted;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ClearLimitAfterDelete
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  LimitDeleted  $event
     * @return void
     */
    public function handle(LimitDeleted $event)
    {
        $stop = 1;
    }
}
