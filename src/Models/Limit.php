<?php

namespace DreamFactory\Core\Limit\Models;

use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;
use DreamFactory\Core\Exceptions\BadRequestException;


class Limit extends BaseSystemModel
{
    protected $table = 'limits';

    public static $limitTypes = [
        'instance'                   => 'instance',
        'instance.user'              => 'instance.user:%s',
        'instance.user.service'      => 'instance.user:%s.service:%s',
        'instance.each_user'         => 'instance.each_user',
        'instance.each_user.service' => 'instance.each_user.service:%s',
        'instance.service'           => 'instance.service:%s',
        'instance.role'              => 'instance.role:%s',
    ];

    public static $limitPeriods = [
        'minute',
        'hour',
        'day',
        '7-day',
        '30-day',
    ];

    protected $rules = [
        'limit_type'   => 'required',
        'limit_rate'   => 'required',
        'limit_period' => 'required',
        'label_text'   => 'required'
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
        'limit_key_text',
        'limit_key_hash',
        'active_ind'
    ];

    protected $fillable = [
        'limit_type',
        'limit_rate',
        'user_id',
        'role_id',
        'service_id',
        'label_text',
        'limit_period',
        'limit_key_hash',
        'limit_key_text'

    ];
    /**
     * Resolves and builds unique key based on limit type.
     * @param $limitType
     * @param $userId
     * @param $roleId
     * @param $service
     * @param $limitPeriod
     * @return string
     */
    public function resolveCheckKey($limitType, $userId, $roleId, $serviceId, $limitPeriod)
    {
        if (isset(self::$limitTypes[$limitType])) {

            switch ($limitType) {
                case 'instance':
                case 'instance.each_user':
                    $key = self::$limitTypes[$limitType];
                    break;

                case 'instance.user':
                    $key = sprintf(self::$limitTypes[$limitType], $userId);
                    break;

                case 'instance.role':
                    $key = sprintf(self::$limitTypes[$limitType], $roleId);
                    break;

                case 'instance.user.service':
                    $key = sprintf(self::$limitTypes[$limitType], $userId, $serviceId);
                    break;

                case 'instance.each_user.service':
                case 'instance.service':
                    $key = sprintf(self::$limitTypes[$limitType], $serviceId);
                    break;
            }
            /* Finally add the period to the string */
            return $key . '.' . self::$limitPeriods[$limitPeriod];
        }
    }

    public function validate(array $data = [], $throwException = true)
    {
        $this->rules['limit_period'] .= '|in:' . implode(',', self::$limitPeriods);

        return parent::validate($data, $throwException); // TODO: Change the autogenerated stub
    }





}