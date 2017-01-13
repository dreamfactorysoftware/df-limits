<?php
namespace DreamFactory\Core\Testing;

use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;
use DreamFactory\Core\Limit\Models\Limit;
use Illuminate\Support\Arr;

class LimitsTest extends TestCase
{

    protected $limits = [
        [
            'limit_type'     => 'instance',
            'limit_key_text' => 'instance',
            'limit_rate'     => 10,
        ],
        [
            'limit_type'     => 'instance.each_user',
            'limit_key_text' => 'instance.each_user',
            'limit_rate'     => 10,
        ],
        [
            'limit_type'     => 'instance.user',
            'limit_key_text' => 'instance.user:2',
            'limit_rate'     => 10,
            'user_id'        => 2,
        ],
        [
            'limit_type'     => 'instance.role',
            'limit_key_text' => 'instance.role:2',
            'limit_rate'     => 10,
            'role_id'        => 2,
        ],
        [
            'limit_type'     => 'instance.service',
            'limit_key_text' => 'instance.service:local_test',
            'limit_rate'     => 10,
            'service_name'   => 'local_test',
        ],
        [
            'limit_type'     => 'instance.each_user.service',
            'limit_key_text' => 'instance.each_user.service:local_test',
            'limit_rate'     => 10,
            'service_name'   => 'local_test',
        ],
        [
            'limit_type'     => 'instance.user.service',
            'limit_key_text' => 'instance.user:2.service:local_test',
            'limit_rate'     => 10,
            'service_name'   => 'local_test',
            'user_id'        => 2,
        ]

    ];

    protected $periods = [
        'Minute'  => DateTimeIntervals::MINUTES_PER_MINUTE,
        'Hour'    => DateTimeIntervals::MINUTES_PER_HOUR,
        'Day'     => DateTimeIntervals::MINUTES_PER_DAY,
        '7 Days'  => DateTimeIntervals::MINUTES_PER_WEEK,
        '30 Days' => DateTimeIntervals::MINUTES_PER_MONTH,
    ];


    public function setUp()
    {
        parent::setUp();
        $this->createLimits();

    }


    public function testTrue()
    {
        $this->assertEquals(1, 1);
    }

    /************************************************
     * Password sub-resource test
     ************************************************/



    /************************************************
     * Helper methods
     ************************************************/

    protected function createLimits()
    {

        foreach($this->limits as $limit){
            $limit['limit_key_hash'] = sha1($limit['limit_key_text']);
            $limit['limit_period'] = 0;
            Limit::insert($limit);

        }

    }

    protected function deleteLimits($limitsArray)
    {

    }


}