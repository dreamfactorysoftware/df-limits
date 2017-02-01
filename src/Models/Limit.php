<?php

namespace DreamFactory\Core\Limit\Models;

use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;

class Limit extends BaseSystemModel
{
    protected $table = 'limits';

    public static $limitTypes = [
        'instance'              => 'instance',
        'instance.user'         => 'instance.user:%s',
        'instance.user.service' => 'instance.user:%s.service:%s',
        'instance.service'      => 'instance.service:%s',
        'instance.role'         => 'instance.role:%s',
    ];

    public static $limitPeriods = [
        'minute',
        'hour',
        'day',
        '7-day',
        '30-day',
    ];

    protected $rules = [
        'type'   => 'required',
        'rate'   => 'required',
        'period' => 'required',
        'name'   => 'required'
    ];

    public static $limitIntervals = [
        DateTimeIntervals::MINUTES_PER_MINUTE,
        DateTimeIntervals::MINUTES_PER_HOUR,
        DateTimeIntervals::MINUTES_PER_DAY,
        DateTimeIntervals::MINUTES_PER_WEEK,
        DateTimeIntervals::MINUTES_PER_MONTH,
    ];

    protected $hidden = [
        'create_date',
        'active_ind'
    ];

    protected $fillable = [
        'type',
        'rate',
        'user_id',
        'role_id',
        'service_id',
        'name',
        'label',
        'period',
        'key_text',
        'active_ind'

    ];

    /**
     * Resolves and builds unique key based on limit type.
     *
     * @param $limitType
     * @param $userId
     * @param $roleId
     * @param $service
     * @param $limitPeriod
     *
     * @return string
     */
    public function resolveCheckKey($limitType, $userId, $roleId, $serviceId, $limitPeriod)
    {
        if (isset(self::$limitTypes[$limitType])) {

            switch ($limitType) {
                case 'instance':
                    $key = static::$limitTypes[$limitType];
                    break;

                case 'instance.user':
                    $key = sprintf(static::$limitTypes[$limitType], $userId);
                    break;

                case 'instance.role':
                    $key = sprintf(static::$limitTypes[$limitType], $roleId);
                    break;

                case 'instance.user.service':
                    $key = sprintf(static::$limitTypes[$limitType], $userId, $serviceId);
                    break;
                case 'instance.service':
                    $key = sprintf(static::$limitTypes[$limitType], $serviceId);
                    break;
            }

            /* Finally add the period to the string */

            return $key . '.' . static::$limitPeriods[$limitPeriod];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $data = [], $throwException = true)
    {
        $this->rules['period'] .= '|in:' . implode(',', range(0, (count(static::$limitPeriods)-1)));
        $this->rules['type']   .= '|in:' . implode(',', array_keys(static::$limitTypes));

        return parent::validate($data, $throwException);
    }

}