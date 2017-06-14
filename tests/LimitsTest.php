<?php
namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Enums\DateTimeIntervals;
use DreamFactory\Core\Limit\Models\Limit;
use DreamFactory\Core\Models\AdminUser;

class LimitsTest extends TestCase
{

    protected $limits = [
        'type'     => 'instance',
        'key_text' => 'instance',
        'rate'     => 20,
        'name'     => 'test instance#',
        'period'   => 1
    ];

    protected $users = [
        "name"                => "Test User",
        "username"            => null,
        "first_name"          => "Test",
        "last_name"           => "User",
        "last_login_date"     => "2017-02-21 16:15:12",
        "email"               => "testeruser%s@dreamfactory.com",
        "is_active"           => true,
        "phone"               => null,
        "security_question"   => null,
        "confirm_code"        => "y",
        "default_app_id"      => null,
        "oauth_provider"      => null,
        "created_date"        => "2017-02-21 16:15:12",
        "last_modified_date"  => "2017-02-21 16:15:12",
        "created_by_id"       => 4,
        "last_modified_by_id" => 1
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
        //$this->createLimits();
        $this->createUsers();

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
        $newLimits = [];
        for ($i = 20; $i <= 40; $i++) {
            $newLimits[$i] = $this->limits;
            $newLimits[$i]['name'] = $this->limits['name'] . $i;
            $newLimits[$i]['key_text'] = $this->limits['key_text'] . bin2hex(random_bytes(6));
            $newLimits[$i]['rate'] = $i * $i;
        }
        try {
            Limit::insert($newLimits);

        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    protected function createUsers()
    {
        $newUsers = [];
        for ($i = 1; $i <= 100; $i++) {
            $newUsers[$i] = $this->users;
            $newUsers[$i]['email'] = sprintf($this->users['email'], $i);
            $newUsers[$i]['name'] = $this->users['name'] . $i;
        }
        try {
            AdminUser::insert($newUsers);

        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    protected function deleteLimits($limitsArray)
    {

    }
}