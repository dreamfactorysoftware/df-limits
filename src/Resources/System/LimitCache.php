<?php
namespace DreamFactory\Core\Limit\Resources\System;

use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\System\BaseSystemResource;
use DreamFactory\Core\Limit\Models\Limit as LimitsModel;
use DreamFactory\Core\Resources\System\Cache;
use DreamFactory\Core\Utility\ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

use Illuminate\Cache\RateLimiter;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Exceptions\NotFoundException;

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

    protected function handleGET()
    {
        $limit  = new static::$model;
        $limits = null;
        $params = $this->request->getParameters();
        if (!empty($this->resource)) {
            /* Single Resource ID */
            $id = $this->resource;
            $limits = $limit::where('active_ind', 1)->where('id', $id)->get();
        } else if (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $limits = $limit::where('active_ind', 1)->find($ids);
        } else if (!empty($records = ResourcesWrapper::unwrapResources($this->getPayloadData()))) {
            $limits = $limit::where('active_ind', 1)->find($records);
        } else {
            /* No id passed, get all limit cache entries */
            $limits = $limit::where('active_ind', 1)->get();
        }

        if($limits && !($limits->isEmpty())){
            $checkKeys = [];
            $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();
            foreach ($limits as $limitData) {

                /* Check for each user condition */
                if (strpos($limitData->type, 'user') && is_null($limitData->user_id)) {

                    foreach ($users as $user) {

                        /* need to generate a key for each user to check */
                        $key = $limit->resolveCheckKey(
                            $limitData->type,
                            $user->id,
                            $limitData->role_id,
                            $limitData->service_id,
                            $limitData->period
                        );

                        $checkKeys[] = [
                            'limit_id' => $limitData->id,
                            'key'      => $key,
                            'max'      => $limitData->limit_rate
                        ];
                    }
                } else { /* Normal key checks */

                    $key = $limit->resolveCheckKey(
                        $limitData->type,
                        $limitData->user_id,
                        $limitData->role_id,
                        $limitData->service_id,
                        $limitData->period
                    );

                    $checkKeys[] = [
                        'limit_id' => $limitData->id,
                        'key'      => $key,
                        'max'      => $limitData->rate
                    ];
                }
            }

            foreach ($checkKeys as &$keyCheck) {
                $keyCheck['attempts'] = $this->getAttempts($keyCheck['key'], $keyCheck['max']);
                $keyCheck['remaining'] = $this->retriesLeft($keyCheck['key'], $keyCheck['max']);
            }

            return ResourcesWrapper::wrapResources($checkKeys);
        } else {
            if(!is_null($id)){
                throw new NotFoundException(sprintf('Record with identifier %s not found', $id));

            } else {
                return ResourcesWrapper::wrapResources([]);
            }
        }
    }

    protected function getAttempts($key, $max)
    {

        if ($this->cache->has($key)) {
            return $this->cache->get($key, 0);
        } else if ($this->cache->has($key . ':lockout')) {
            return $max;
        } else {
            return 0;
        }
    }

    public function retriesLeft($key, $maxAttempts)
    {
        $attempts = $this->limiter->attempts($key);
        if ($this->cache->has($key . ':lockout') || $attempts > $maxAttempts) {
            return 0;
        }

        return $attempts === 0 ? $maxAttempts : $maxAttempts - $attempts;
    }

    protected function handleDELETE()
    {
        $params = $this->request->getParameters();
        $payload = $this->getPayloadData();
        if (isset($params['allow_delete']) && filter_var($params['allow_delete'], FILTER_VALIDATE_BOOLEAN)) {
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

        $asList = $this->request->getParameterAsBool(ApiOptions::AS_LIST);
        $id = $this->request->getParameter(ApiOptions::ID_FIELD, static::getResourceIdentifier());
        $result = ResourcesWrapper::cleanResources($result, $asList, $id, ApiOptions::FIELDS_ALL);
        return $result;
    }

    public function clearById($id)
    {
        $limitModel = new static::$model;
        $limitData = $limitModel::where('id', $id)->get();
        $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();

        if($limitData && !($limitData->isEmpty())){
            foreach ($limitData as $limit) {
                /* Handles clearing for Each User scenario */
                if (strpos($limit->type, 'user') && is_null($limit->user_id)) {

                    foreach ($users as $user) {
                        /* need to generate a key for each user to check */
                        $usrKey = $limitModel->resolveCheckKey(
                            $limit->type,
                            $user->id,
                            $limit->role_id,
                            $limit->service_id,
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
                        $limit->period
                    );

                    $this->clearKey($keyCheck);
                }
            }
        } else {
            if(!is_null($id)){
                throw new NotFoundException(sprintf('Record with identifier %s not found', $id));

            } else {
                return ResourcesWrapper::wrapResources([]);
            }
        }


    }

    public function clearByIds($records = array(), $params)
    {
        $key = 'id';
        $continue = (isset($params['continue']) && !filter_var($params['continue'], FILTER_VALIDATE_BOOLEAN)) ? false : true;
        if (isset($params['ids']) && !empty($params['ids'])) {
            //$key = 'ids';
            $idParts = explode(',', $params['ids']);
            $records = array();
            foreach ($idParts as $idPart) {
                $records[] = ['id' => $idPart];
            }
        }
        $invalidIds = $validIds = [];
        $limitModel = new static::$model;
        $errors = [];
        foreach ($records as $k=>$idRecord) {
            if (!$limitModel::where('id', $idRecord['id'])->exists()) {
                $errors[] = $k;
                $invalidIds[$k] = sprintf("Record with identifier '%s' not found.", $idRecord['id']);
                if(!$continue){
                    break;
                }
            } else {
                $validIds[$k] = [$key => $idRecord['id']];
                $this->clearById($idRecord['id']);
            }
        }

        if (!empty($invalidIds)) {
            /* Build a proper response array -> order is indeed important here <- need to
            /* preserve the keys to get the proper resource array order on batch operations */
            $errors = ['error' => $errors];
            /* Merge back in the two -> good and bad */
            $records = array_replace($validIds, $invalidIds);
            /* sort by keys */
            ksort($records);
            /* remove the keys */
            $records = array_values($records);
            /* wrap up the resources */
            $resources = ResourcesWrapper::wrapResources($records);
            /* build the context */
            $context = $errors + $resources;
            throw new BadRequestException('Batch Error: Not all records could be deleted.', Response::HTTP_INTERNAL_SERVER_ERROR,
                null, $context);
        } else {
            return $validIds;
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
     * @param  string $key
     * @param  int    $decayMinutes
     *
     * @return int
     */
    public function hit($key, $decayMinutes = 1)
    {
        $this->cache->add($key, 0, $decayMinutes);

        return (int)$this->cache->increment($key);
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string $key
     * @param  int    $maxAttempts
     * @param  int    $decayMinutes
     *
     * @return bool
     */
    public function tooManyAttempts($key, $maxAttempts, $decayMinutes = 1)
    {
        if ($this->cache->has($key . ':lockout')) {
            return true;
        }

        if ($this->attempts($key) >= $maxAttempts) {
            $this->cache->add($key . ':lockout', time() + ($decayMinutes * 60), $decayMinutes);

            return $this->cache->forget($key);

            return true;
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

    public static function getApiDocInfo($service, array $resource = [])
    {
        $serviceName = strtolower($service);
        $class = trim(strrchr(static::class, '\\'), '\\');
        $resourceName = strtolower(array_get($resource, 'name', $class));
        $path = '/' . $serviceName . '/' . $resourceName;

        $apis = [
            $path           => [
                'delete' => [
                    'tags'        => [$serviceName],
                    'summary'     => 'deleteAllLimitCache() - Delete all Limits cache.',
                    'operationId' => 'deleteAllLimitCache',
                    'parameters'  => [],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Success',
                            'schema'      => ['$ref' => '#/definitions/Success']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                    'consumes'    => ['application/json', 'application/xml'],
                    'produces'    => ['application/json', 'application/xml'],
                    'description' => 'This clears and resets all limits cache counters in the system.',
                ],
            ],
            $path . '/{id}' => [
                'delete' => [
                    'tags'        => [$serviceName],
                    'summary'     => 'deleteLimitCache() - Reset limit counter for a specific limit Id.',
                    'operationId' => 'deleteServiceCache',
                    'consumes'    => ['application/json', 'application/xml'],
                    'produces'    => ['application/json', 'application/xml'],
                    'parameters'  => [
                        [
                            'name'        => 'id',
                            'description' => 'Identifier of the limit to reset the counter.',
                            'type'        => 'string',
                            'in'          => 'path',
                            'required'    => true,
                        ],
                    ],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Success',
                            'schema'      => ['$ref' => '#/definitions/Success']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                    'description' => 'This will reset the limit counter for a specific limit Id.',
                ],
            ],
        ];

        return ['paths' => $apis, 'definitions' => []];
    }

}