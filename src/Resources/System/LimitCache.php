<?php

namespace DreamFactory\Core\Limit\Resources\System;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Events\ServiceEvent;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\BatchException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Limit\Models\Limit as LimitsModel;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\System\Resources\BaseSystemResource;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\ServiceRequest;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Redis\RedisManager;
use Cache;
use Event;

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
     * Model reference to Limits
     *
     * @var
     */
    protected $limitsModel;

    /**
     * The limiter cache store.
     *
     * @var \Illuminate\Cache\
     */
    protected $cache;

    /**
     * Standard not found string to use.
     *
     * @var string
     */
    protected $notFoundStr = "Record with identifier '%s' not found.";

    /**
     * Whether or not to trow exceptions or surpress
     *
     * @var bool
     */
    protected $isThrowable = true;

    /**
     * Create a new request throttler.
     * LimitCache constructor.
     *
     * @param array $settings
     *
     */
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        switch (config('limit.default')) {
            case 'file':
                $fileSystem = new Filesystem();
                $store = new FileStore($fileSystem, config('limit.stores.file.path'));

                break;

            case 'redis':
                $cacheConfig = config('limit.stores.redis');
                $server = [
                    'cluster' => false,
                    'default' => [
                        'host'     => array_get($cacheConfig, 'host'),
                        'port'     => array_get($cacheConfig, 'port'),
                        'database' => array_get($cacheConfig, 'database'),
                        'password' => array_get($cacheConfig, 'password'),
                    ]
                ];
                $redisDatabase = new RedisManager(array_get($cacheConfig, 'client'), $server);
                $redisPrefix = (getenv('LIMIT_CACHE_PREFIX')) ?: null;
                $store = new RedisStore($redisDatabase, $redisPrefix);

                break;
        }

        $this->cache = Cache::repository($store);
        $this->limiter = new RateLimiter($this->cache);

        $this->limitsModel = new static::$model;
    }

    /** Sets the throwable flag from Limits model.
     *
     * @param $throw
     */
    public function setThrowErrors($throw)
    {
        $this->isThrowable = $throw;
    }

    /**
     * @inheritdoc
     */
    protected function handleGET()
    {
        $id = null;
        $params = $this->request->getParameters();
        $result = [];
        if (!empty($this->resource)) {
            /* Single Resource ID */
            $result = $this->getLimitsById($this->resource);

            return $result[0];
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $result = $this->getOrClearLimits($ids, $params, false);
        } elseif (!empty($records = ResourcesWrapper::unwrapResources($this->getPayloadData()))) {
            $result = $this->getOrClearLimits($records, $params, false);
        } else {
            /* No id passed, get all limit cache entries */
            $dbLimits = LimitsModel::get(['id']);
            if (!empty($dbLimits)) {
                $records = $dbLimits->toArray();
                $result = $this->getOrClearLimits($records, $params, false);
            }
        }

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $id = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $id, ApiOptions::FIELDS_ALL);

        return $result;
    }

    /**
     * @inheritdoc
     */
    protected function handleDELETE()
    {
        $params = $this->request->getParameters();

        if (!empty($this->resource)) {
            $this->clearById($this->resource, $params);
            $result = ['id' => (int)$this->resource];
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $result = $this->clearByIds($ids, $params);
        } elseif ($records = ResourcesWrapper::unwrapResources($this->getPayloadData())) {
            $result = $this->clearByIds($records, $params);
        } else {
            // protect against accidentally clearing all limit caches
            if ($this->request->getParameterAsBool('allow_delete') || $this->request->getParameterAsBool('force')) {
                $this->cache->flush();
                $result = ['success' => true];

                return ResponseFactory::create($result);
            }
            throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
        }

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $id = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $id, ApiOptions::FIELDS_ALL);

        return $result;
    }

    /**
     * Multipurpose function to get or clear cache entries
     *
     * @param array $records - Records from GET or DELETE
     * @param array $params  - Any passed params, like continue
     * @param bool  $clear   - switches from get to clear a limit
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BatchException
     */
    public function getOrClearLimits($records = [], $params = [], $clear = false)
    {
        $continue =
            (isset($params['continue']) && filter_var($params['continue'], FILTER_VALIDATE_BOOLEAN)) ? true : false;

        if (isset($params['ids']) && !empty($params['ids'])) {
            $idParts = explode(',', $params['ids']);
            $records = [];
            foreach ($idParts as $idPart) {
                $records[] = ['id' => (int)$idPart];
            }
        }

        $output = [];
        $invalid = false;
        foreach ($records as $k => $idRecord) {
            try {
                if ($clear) {
                    $output[] = $this->clearById($idRecord['id']);
                } else {
                    $tmpArr = $this->getLimitsById($idRecord['id']);
                    if (count($tmpArr) > 1) {
                        foreach ($tmpArr as $item) {
                            $output[] = $item;
                        }
                    } else {
                        $output[] = $tmpArr[0];
                    }
                }
            } catch (\Exception $e) {
                $invalid = true;
                $output[] = $e;
                if (!$continue) {
                    break;
                }
            }
        }
        if ($invalid) {
            if ($this->isThrowable) {
                $errString =
                    sprintf('Batch Error: Not all requested records could be %s.', ($clear) ? 'deleted' : 'retrieved');
                throw new BatchException($output, $errString);
            }
        } else {
            return (isset($output[0][0]['id'])) ? $output[0] : $output;
        }
    }

    /**
     * Gets a limit cache count values by limit ID
     *
     * @param $id - LimitId
     *
     * @return array - Limit Cache entry
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     */
    public function getLimitsById($id)
    {
        $limits = limitsModel::where('id', $id)->get();

        if ($limits && !($limits->isEmpty())) {
            $checkKeys = [];
            $eachUsers = false;
            $users = [];

            foreach ($limits as $limitData) {
                if (in_array($limitData->type, LimitsModel::$eachUserTypes) && empty($users)) {
                    $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();
                    $eachUsers = true;
                }

                /* Check for each user condition */
                if ($eachUsers) {

                    foreach ($users as $user) {

                        /* need to generate a key for each user to check */
                        $key = $this->limitsModel->resolveCheckKey(
                            $limitData->type,
                            $user->id,
                            $limitData->role_id,
                            $limitData->service_id,
                            $limitData->endpoint,
                            $limitData->verb,
                            $limitData->period
                        );

                        $checkKeys[] = [
                            'id'  => $limitData->id,
                            'key' => $key,
                            'max' => $limitData->rate
                        ];
                    }
                } else { /* Normal key checks */

                    $key = $this->limitsModel->resolveCheckKey(
                        $limitData->type,
                        $limitData->user_id,
                        $limitData->role_id,
                        $limitData->service_id,
                        $limitData->endpoint,
                        $limitData->verb,
                        $limitData->period
                    );

                    $checkKeys[] = [
                        'id'  => $limitData->id,
                        'key' => $key,
                        'max' => $limitData->rate
                    ];
                }
            } //endforeach limits

            $checkKeys = $this->checkKeys($checkKeys);

            return $checkKeys;
        } else {
            if ($this->isThrowable) {
                throw new NotFoundException(sprintf($this->notFoundStr, $id));
            }
        }
    }

    /**
     * Gets attempts and retries left from a passed cache key.
     *
     * @param $keys - key to check
     *
     * @return array Enriched keys array
     */
    protected function checkKeys($keys)
    {
        foreach ($keys as &$key) {
            $key['attempts'] = (int)$this->getAttempts($key['key'], $key['max']);
            $key['remaining'] = $this->retriesLeft($key['key'], $key['max']);
        }

        return $keys;
    }

    /**
     * Calculates attempts for a given cache key.
     *
     * @param $key - unique cache key.
     * @param $max - max number of hits allowed for the limit.
     *
     * @return int
     */
    protected function getAttempts($key, $max)
    {
        if ($this->cache->has($key)) {
            return $this->cache->get($key, 0);
        } elseif ($this->cache->has($key . ':timer')) {
            return $max;
        } else {
            return 0;
        }
    }

    /**
     * @param $key         - unique cache key.
     * @param $maxAttempts - Max number of attempts allowed
     *
     * @return int
     */
    public function retriesLeft($key, $maxAttempts)
    {
        $attempts = $this->limiter->attempts($key);
        if ($this->cache->has($key . ':timer') || $attempts > $maxAttempts) {
            return 0;
        }

        return $attempts === 0 ? $maxAttempts : $maxAttempts - $attempts;
    }

    /**
     * Get the number of seconds until the "key" is accessible again.
     *
     * @param  string $key
     *
     * @return int
     */
    public function availableIn($key)
    {
        return $this->limiter->availableIn($key);
    }

    /**
     * Override function for Clearing limits
     *
     * @param array $records
     * @param array $params
     * @param bool  $clear
     *
     * @return array
     */
    public function clearByIds($records = [], $params = [], $clear = true)
    {
        return $this->getOrClearLimits($records, $params, $clear);
    }

    /**
     * Clears a limit cache entry by individual ID with options.
     *
     * @param       $id    limit to clear by id
     * @param array $params
     * @param bool  $throw - whether or not to throw an error. If being called
     *                     from the Limit Model, need to quietly try to delete
     *                     a limit from cache when a DELETE is called on the Limit
     *                     entry. Not throwing an error here allows the BaseModel to do
     *                     its job with invalid POSTS, etc.
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     */
    public function clearById($id, $params = [], $throw = true)
    {
        $limitModel = new static::$model;
        $limitData = $limitModel::where('id', $id)->get();
        $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();

        if ($limitData && !($limitData->isEmpty())) {
            foreach ($limitData as $limit) {
                /* Handles clearing for Each User scenario */
                if (in_array($limit->type, LimitsModel::$eachUserTypes)) {

                    foreach ($users as $user) {
                        /* need to generate a key for each user to check */
                        $usrKey = $limitModel->resolveCheckKey(
                            $limit->type,
                            $user->id,
                            $limit->role_id,
                            $limit->service_id,
                            $limit->endpoint,
                            $limit->verb,
                            $limit->period
                        );
                        $this->clearKey($usrKey);
                    }
                } else {

                    /* build the key to check */
                    $keyCheck = $limitModel->resolveCheckKey(
                        $limit->type,
                        $limit->user_id,
                        $limit->role_id,
                        $limit->service_id,
                        $limit->endpoint,
                        $limit->verb,
                        $limit->period
                    );

                    $this->clearKey($keyCheck);
                }
            }
        } else {
            if (!is_null($id) && $throw) {
                throw new NotFoundException(sprintf($this->notFoundStr, $id));
            } else {
                return ResourcesWrapper::wrapResources([]);
            }
        }

        return ['id' => $id];
    }

    /**
     * Clears and removes a key entry.
     *
     * @param $key limit cache unique key
     */
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
     * @param  string $key
     * @param  int    $decaySeconds
     *
     * @return int
     */
    public function hit($key, $decaySeconds = 60)
    {
        $this->cache->add($key, 0, $decaySeconds);

        return (int)$this->cache->increment($key);
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string $key
     * @param  int    $limit
     * @param  int    $decaySeconds
     *
     * @return bool
     */
    public function tooManyAttempts($key, $limit, $decaySeconds = 60)
    {
        if ($this->cache->has($key . ':timer')) {
            return true;
        }

        if ($this->attempts($key) >= $limit->rate) {
            $this->cache->add($key . ':timer', time() + ($decaySeconds * 60), $decaySeconds);

            /** Some conversion and enrichment */
            $data = [];
            $sendLimit = $limit->toArray();
            $sendLimit['period'] = limitsModel::$limitPeriods[$sendLimit['period']];
            $sendLimit['rate'] = (string)$sendLimit['rate'];
            $sendLimit['cache_key'] = $key;
            $data['limit'] = $sendLimit;
            $request = new ServiceRequest();
            $data['request'] = $request->toArray();

            /** Fire a generic event for the service */
            Event::dispatch(new ServiceEvent('system.limit.{id}.exceeded', $limit->id, $data));
            /** Fire the specific event */
            Event::dispatch(new ServiceEvent(sprintf('system.limit.%s.exceeded', $limit->id), null, $data));

            return $this->cache->forget($key);
        }

        return false;
    }

    /**
     * Get the number of attempts for the given key.
     *
     * @param  string $key
     *
     * @return mixed
     */
    public function attempts($key)
    {
        return $this->cache->get($key, 0);
    }

    public function hasLockout($key)
    {
        if ($this->cache->has($key . ':timer')) {
            return true;
        }

        return false;
    }

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);
        $resourceName = strtolower($this->name);
        $path = '/' . $resourceName;

        return [
            $path           => [
                'get'    => [
                    'summary'     => 'Retrieve one or more Limit Cache entries.',
                    'description' => 'This clears and resets all limits cache counters in the system.',
                    'operationId' => 'get' . $capitalized . 'LimitCaches',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/LimitCachesResponse']
                    ],
                ],
                'delete' => [
                    'summary'     => 'Delete all Limits cache.',
                    'description' => 'This clears and resets all limits cache counters in the system.',
                    'operationId' => 'delete' . $capitalized . 'LimitCaches',
                    'parameters'  => [
                        ApiOptions::documentOption(ApiOptions::IDS),
                        ApiOptions::documentOption(ApiOptions::FORCE),
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            $path . '/{id}' => [
                'parameters' => [
                    [
                        'name'        => 'id',
                        'description' => 'Identifier of the limit to reset the counter.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ]
                ],
                'get'        => [
                    'summary'     => 'Retrieve one Limit Cache entry.',
                    'description' => 'This will retrieve the limit counts for a specific limit Id.',
                    'operationId' => 'get' . $capitalized . 'LimitCache',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/LimitCacheResponse']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Reset limit counter for a specific limit Id.',
                    'description' => 'This will reset the limit counter for a specific limit Id.',
                    'operationId' => 'delete' . $capitalized . 'LimitCache',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/LimitCacheResponse']
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return []; // no requests
    }

    protected function getApiDocSchemas()
    {
        return [
            'LimitCachesResponse' => [
                'type'       => 'object',
                'properties' => [
                    'resource' => [
                        'type'        => 'array',
                        'description' => 'Array of accessible resources available to this path',
                        'items'       => [
                            '$ref' => '#/components/schemas/LimitCacheResponse',
                        ]
                    ]
                ]
            ],
            'LimitCacheResponse'  => [
                'type'       => 'object',
                'properties' => [
                    'id'        => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Limit identifier.'
                    ],
                    'key'       => [
                        'type'        => 'string',
                        'description' => 'Unique key that makes up a limit cache string (mostly internal).'
                    ],
                    'max'       => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Max number of hits for a given period.'
                    ],
                    'attempts'  => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Number of attempts already made.'
                    ],
                    'remaining' => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Number of attempts left before limit is reached.'
                    ]
                ]
            ],
        ];
    }
}