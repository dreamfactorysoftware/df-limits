<?php
namespace DreamFactory\Core\Limit\Events;


use Illuminate\Queue\SerializesModels;
use DreamFactory\Core\Limit\Models\Limit;

class LimitExceeded
{

    use SerializesModels;

    public $limit;

    /**
     * Create a new event instance.
     *
     * @param  Limit  $limit
     * @return void
     */
    public function __construct(Limit $limit)
    {
        $this->limit = $limit;
    }

}
