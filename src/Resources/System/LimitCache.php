<?php
namespace DreamFactory\Core\Limit\Resources\System;

use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\System\BaseSystemResource;
use DreamFactory\Core\Limit\Models\Limit as LimitsModel;
use DreamFactory\Core\Resources\System\Cache;
use DreamFactory\Core\Utility\ResponseFactory;
use Illuminate\Cache\RateLimiter;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Enums\ApiOptions;

use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Exceptions\BadRequestException;
use Illuminate\Contracts\Cache\Repository;

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

    protected function handleGET($id = null)
    {
        $limit = new static::$model;

        /* check if passing a single limit id */
        if( !empty($this->resource)){
            $id = $this->resource;
            $limits = $limit::where('active_ind', 1)->where('id', $id)->get();
        } else {
            /* Get all limits */
            $limits = $limit::where('active_ind', 1)->get();
        }
        $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();

        $checkKeys = [];
        foreach ($limits as $limitData) {

            /* Check for each user condition */
            if (strpos($limitData->limit_type, 'user') && is_null($limitData->user_id)) {

                foreach ($users as $user) {

                    /* need to generate a key for each user to check */
                    $key = $limit->resolveCheckKey(
                        $limitData->limit_type,
                        $user->id,
                        $limitData->role_id,
                        $limitData->service_id,
                        $limitData->limit_period
                    );

                    $checkKeys[] = [
                        'limit_id' => $limitData->id,
                        'name' => $limitData->name,
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
                    $limitData->limit_period
                );

                $checkKeys[] = [
                    'limit_id' => $limitData->id,
                    'name' => $limitData->name,
                    'key'  => $key,
                    'max'  => $limitData->limit_rate
                ];
            }
        }

        foreach ($checkKeys as &$keyCheck) {
            $keyCheck['attempts'] = $this->getAttempts($keyCheck['key'], $keyCheck['max']);
            $keyCheck['remaining'] = $this->retriesLeft($keyCheck['key'], $keyCheck['max']);
        }

        return ResourcesWrapper::wrapResources($checkKeys);
    }

    protected function getAttempts($key, $max){

        if( $this->cache->has($key)){
            return $this->cache->get($key, 0);
        } else if ($this->cache->has($key.':lockout')) {
            return $max;
        } else {
            return 0;
        }

    }

    public function retriesLeft($key, $maxAttempts)
    {
        $attempts = $this->limiter->attempts($key);
        if ($this->cache->has($key.':lockout') || $attempts > $maxAttempts) {
            return 0;
        }
        return $attempts === 0 ? $maxAttempts : $maxAttempts - $attempts;

    }

    protected function handleDELETE()
    {
        $params = $this->request->getParameters();
        if(isset($params['allow_delete']) && filter_var($params['allow_delete'], FILTER_VALIDATE_BOOLEAN)){
            $this->cache->flush();
            $result = [
                'success' => true
            ];
            return ResponseFactory::create($result);

        }

        if (!empty($this->resource)) {
            $result = $this->clearById($this->resource, $params);
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $result = $this->clearByIds($ids, $params);
        } elseif ($records = ResourcesWrapper::unwrapResources($this->getPayloadData())) {
            $result = $this->clearByIds($records, $params);
        } else {
            throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
        }

        return ResponseFactory::create($result);

    }

    public function clearById($id)
    {
        $limitModel = new static::$model;
        $limitData = $limitModel::where('id', $id)->get();
        $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();

        foreach($limitData as $limit){
            /* Handles clearing for Each User scenario */
            if (strpos($limit->limit_type, 'user') && is_null($limit->user_id)) {

                foreach ($users as $user) {
                    /* need to generate a key for each user to check */
                    $usrKey = $limitModel->resolveCheckKey(
                        $limit->limit_type,
                        $user->id,
                        $limit->role_id,
                        $limit->service_id,
                        $limit->limit_period
                    );
                    $this->clearKey($usrKey);
                }

            } else {

                /* build the key to check */
                $keyCheck = $limitModel->resolveCheckKey(
                    $limit->limit_type,
                    $limit->user_id,
                    $limit->role_id,
                    $limit->service_id,
                    $limit->limit_period
                );

                $this->clearKey($keyCheck);
            }
        }

    }

    protected function clearByIds($records = array(), $params)
    {
        if(isset($params['ids']) && !empty($params['ids'])){
            $idParts = explode(',', $params['ids']);
            $records = array();
            foreach($idParts as $idPart){
                $records[] = ['id' => $idPart];
            }
        }
        $invalidIds = $validIds = [];
        $limitModel = new static::$model;

        foreach ($records as $idRecord) {
            if( ! $limitModel::where('id', $idRecord['id'])->exists()){
                $invalidIds[]['id'] = $idRecord['id'];
            } else {
                $validIds[] = $idRecord['id'];
            }
        }

        foreach($validIds as $validId){
            $this->clearById($validId);
        }

        if( ! empty($invalidIds)){
            throw new BadRequestException('Failed to clear all or some limits: One or more Ids are invalid.', null, null, $invalidIds);
        }
    }

    protected function clearKey($key)
    {
        /* Clears for locked-out conditions */
        $this->limiter->clear($key);
        /* Clears for non-lockout conditions */
        $this->cache->forget($key);
    }

    /**
     * Increment the counter for a given key for a given decay time.
     *
     * @param  string  $key
     * @param  int  $decayMinutes
     * @return int
     */
    public function hit($key, $decayMinutes = 1)
    {
        $this->cache->add($key, 0, $decayMinutes);

        return (int) $this->cache->increment($key);
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @param  int  $decayMinutes
     * @return bool
     */
    public function tooManyAttempts($key, $maxAttempts, $decayMinutes = 1)
    {
        if ($this->cache->has($key.':lockout')) {
            return true;
        }

        if ($this->attempts($key) >= $maxAttempts) {
            $this->cache->add($key.':lockout', time() + ($decayMinutes * 60), $decayMinutes);

            return $this->cache->forget($key);

            return true;
        }

        return false;
    }

    /**
     * Get the number of attempts for the given key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function attempts($key)
    {
        return $this->cache->get($key, 0);
    }

    public static function getApiDocInfo($service, array $resource = [])
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
    }

}