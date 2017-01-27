<?php
namespace DreamFactory\Core\Limit\Resources\System;

use DreamFactory\Core\Resources\System\BaseSystemResource;
use DreamFactory\Core\Limit\Models\Limit as LimitsModel;
use Illuminate\Cache\RateLimiter;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Enums\ApiOptions;

use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Exceptions\BadRequestException;

class LimitCache extends BaseSystemResource
{
    /**
     * @var string DreamFactory\Core\Models\BaseSystemModel Model Class name.
     */
    protected static $model = LimitsModel::class;

    /**
     * The rate limiter instance.
     *
     * @var \Illuminate\Cache\RateLimiter
     */
    protected $limiter;

    /**
     * The limiter cache store.
     *
     * @var \Illuminate\Cache\
     */
    protected $cache;

    /**
     * Create a new request throttler.
     *
     * @param  \Illuminate\Cache\RateLimiter $limiter
     *
     * @return void
     */
    public function __construct()
    {
        $this->cache = app('cache')->store('limit');
        $this->limiter = new RateLimiter($this->cache);
    }

    public function getResources($only_handlers = false)
    {
    }

    protected function handleGET()
    {

        $limit = new static::$model;
        $limits = $limit::where('active_ind', 1)->get();
        $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();

        $checkKeys = [];
        foreach ($limits as $limitData) {

            /* Check for each user condition */
            $limit_period_nbr = array_search($limitData->limit_period, LimitsModel::$limitPeriods);

            if (strpos($limitData->limit_type, 'user') && is_null($limitData->user_id)) {

                foreach ($users as $user) {

                    /* need to generate a key for each user to check */
                    $key = $limit->resolveCheckKey(
                        $limitData->limit_type,
                        $user->id,
                        $limitData->role_id,
                        $limitData->service_id,
                        $limit_period_nbr
                    );

                    $checkKeys[] = [
                        'name' => $limitData->label_text,
                        'key'  => $key,
                        'max'  => $limitData->limit_rate
                    ];
                }
            } else { /* Normal key checks */

                $key = $limit->resolveCheckKey(
                    $limitData->limit_type,
                    $limitData->user_id,
                    $limitData->role_id,
                    $limitData->service_id,
                    $limit_period_nbr
                );

                $checkKeys[] = [
                    'name' => $limitData->label_text,
                    'key'  => $key,
                    'max'  => $limitData->limit_rate
                ];
            }
        }

        foreach ($checkKeys as &$keyCheck) {
            $keyCheck['attempts'] = $this->limiter->attempts($keyCheck['key']);
            $keyCheck['remaining'] = $this->limiter->retriesLeft($keyCheck['key'], $keyCheck['max']);
        }

        return ResourcesWrapper::wrapResources($checkKeys);
    }

    protected function handleDELETE()
    {

        if (!empty($this->resource)) {
            $result = $this->clearById($this->resource, $this->request->getParameters());
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $result = $this->clearByIds($ids, $this->request->getParameters());
        } elseif ($records = ResourcesWrapper::unwrapResources($this->getPayloadData())) {
            $result = $this->clearByIds($records, $this->request->getParameters());
        } else {
            throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
        }

        return $result;
    }

    protected function clearById($id, $params)
    {
        $limitModel = new static::$model;
        $limit = $limitModel::where('id', $id)->get();
        $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();

        foreach ($limit as $limit_data) {

            /* Handles clearing for Each User scenario */
            if (strpos($limit_data->limit_type, 'user') && is_null($limit_data->user_id)) {

                foreach ($users as $user) {
                    /* need to generate a key for each user to check */
                    $usrKey = $limitModel->resolveCheckKey(
                        $limit_data->limit_type,
                        $user->id,
                        $limit_data->role_id,
                        $limit_data->service_id,
                        $limit_data->limit_period
                    );
                    $this->clearKey($usrKey);
                }
            }

            /* build the key to check */
            $keyCheck = $limitModel->resolveCheckKey(
                $limit_data->limit_type,
                $limit_data->user_id,
                $limit_data->role_id,
                $limit_data->service_id,
                $limit_data->limit_period
            );

            $this->clearKey($keyCheck);
        }
    }

    protected function clearByIds($ids, $params)
    {
    }

    protected function clearKey($key)
    {
        /* Clears for locked-out conditions */
        $this->limiter->clear($key);
        /* Clears for non-lockout conditions */
        $this->cache->forget($key);
    }

    /*public static function getApiDocInfo($service, array $resource = [])
    {
        $serviceName = strtolower($service);
        $class = trim(strrchr(static::class, '\\'), '\\');
        $resourceName = strtolower(array_get($resource, 'name', $class));
        $path = '/' . $serviceName . '/' . $resourceName;

        $apis = [
            $path                => [
                'delete' => [
                    'tags'              => [$serviceName],
                    'summary'           => 'deleteAllLimitCache() - Delete all Limits cache.',
                    'operationId'       => 'deleteAllLimitCache',
                    'parameters'        => [],
                    'responses'         => [
                        '200'     => [
                            'description' => 'Success',
                            'schema'      => ['$ref' => '#/definitions/Success']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                    'consumes'          => ['application/json', 'application/xml'],
                    'produces'          => ['application/json', 'application/xml'],
                    'description'       => 'This clears and resets all limits cache counters in the system.',
                ],
            ],
            $path . '/{id}' => [
                'delete' => [
                    'tags'              => [$serviceName],
                    'summary'           => 'deleteLimitCache() - Reset limit counter for a specific limit Id.',
                    'operationId'       => 'deleteServiceCache',
                    'consumes'          => ['application/json', 'application/xml'],
                    'produces'          => ['application/json', 'application/xml'],
                    'parameters'        => [
                        [
                            'name'        => 'id',
                            'description' => 'Identifier of the limit to reset the counter.',
                            'type'        => 'string',
                            'in'          => 'path',
                            'required'    => true,
                        ],
                    ],
                    'responses'         => [
                        '200'     => [
                            'description' => 'Success',
                            'schema'      => ['$ref' => '#/definitions/Success']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                    'description'       => 'This will reset the limit counter for a specific limit Id.',
                ],
            ],
        ];

        return ['paths' => $apis, 'definitions' => []];
    }*/

}