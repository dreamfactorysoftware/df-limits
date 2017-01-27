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
            'label_text'     => 'test instance',
            'limit_period'   => 0

        ],
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
            try {
                Limit::insert($limit);

            } catch (\Exception $e){
                $this->fail($e->getMessage());
            }

        }

    }

    protected function deleteLimits($limitsArray)
    {

    }


}