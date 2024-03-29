<?php

namespace DreamFactory\Core\Limit\Resources\System;

use DreamFactory\Core\Events\ServiceModifiedEvent;
use DreamFactory\Core\System\Resources\BaseSystemResource;
use DreamFactory\Core\Limit\Models\Limit as LimitsModel;
use DreamFactory\Core\Enums\DateTimeIntervals;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Enums\ApiOptions;

class Limit extends BaseSystemResource
{
    /**
     * @var string DreamFactory\Core\Models\BaseSystemModel Model Class name.
     */
    protected static $model = LimitsModel::class;

    protected $allowedVerbs = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE'
    ];

    /**
     * The limiter cache store.
     *
     * @var \Illuminate\Cache\
     */
    protected $cache;

    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $this->cache = new LimitCache();
        $this->cache->setThrowErrors(false);
        $this->limitModel = new static::$model;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleGET()
    {
        $getLimitCache = $this->extractCacheRelated();
        $response = parent::handleGET();

        if (isset($response['resource']) && !empty($response['resource'])) {
            foreach ($response['resource'] as &$resourceLimit) {
                if (isset($resourceLimit['period'])) {
                    $resourceLimit['period'] = $this->resolveLimitPeriod($resourceLimit['period']);
                }
            }
        } else {
            if (isset($response['period']) && !empty($response['period'])) {
                $response['period'] = $this->resolveLimitPeriod($response['period']);
            }
        }

        /** Enrich records with limit_cache if requested. */
        if ($getLimitCache === true) {
            foreach ($response['resource'] as &$limitResource) {
                $cacheData = $this->cache->getLimitsById($limitResource['id']);
                $limitResource['limit_cache_by_limit_id'] = $cacheData;
            }
        }

        return $response;
    }

    /**
     * @param $periodNbr
     *
     * @return mixed
     */
    protected function resolveLimitPeriod($periodNbr)
    {
        return LimitsModel::$limitPeriods[$periodNbr];
    }

    /**
     * Since limit_cache isn't really a model, we have to look for this in the related param
     * and pull it out, since there is not a natural join there. We'll handle it separate from the
     * limit_cache system resource if so..
     *
     * @return bool
     */
    protected function extractCacheRelated()
    {

        $related = $this->request->getParameter('related');
        if ($related !== null && is_string($related)) {
            /** parse the related string */
            $relations = explode(',', $related);
            /** look for our limit_cache entry */
            $keyPos = array_search('limit_cache_by_limit_id', $relations);
            if ($keyPos !== false) {
                /** Remove the offensive relation */
                unset($relations[$keyPos]);
                /** Put it all back for the parent to handle. */
                $setData = (empty($relations)) ? [] : implode(',', $relations);
                $this->request->setParameter('related', $setData);

                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function handlePOST()
    {
        $getLimitCache = $this->extractCacheRelated();

        /** First, enrich our payload with some conversions and a unique key */
        $records = ResourcesWrapper::unwrapResources($this->getPayloadData());

        $isRollback = $this->request->getParameter('rollback');
        if (!isset($records[0])) {
            $tmpRecords[] = $records;
            $records = $tmpRecords;
        }
        /* enrich the records (and validate) */
        foreach ($records as &$record) {
            $record = $this->enrichAndValidateRecordData($record);
            /* Check that the key doesn't already exist (case of same limit type, etc) */
            if (LimitsModel::where('key_text', $record['key_text'])->exists() && !$isRollback) {
                throw new BadRequestException('A limit already exists with those parameters. No records added.', 0,
                    null, $record);
            }
        }
        $this->request->setPayloadData(ResourcesWrapper::wrapResources($records));
        $response = parent::handlePOST();
        $returnData = $response->getContent();

        // There could be no 'resource' wrapper because of DF_ALWAYS_WRAP_RESOURCES
        if(isset($returnData['resource'])) {
            $data = &$returnData['resource'];
        } else {
            $data = &$returnData;
        }

        if (is_array($data)) {
            foreach ($data as &$return) {
                if (isset($return['period'])) {
                    $return['period'] = LimitsModel::$limitPeriods[$return['period']];
                }

                /** Enrich records with limit_cache if requested. */
                if ($getLimitCache === true) {
                    $cacheData = $this->cache->getLimitsById($return['id']);
                    $return['limit_cache_by_limit_id'] = $cacheData;
                }
            }
        }

        // Use this event for now to clear event cache, which tracks limits for scripting
        event(new ServiceModifiedEvent(
            new Service([
                'id'   => $this->getServiceId(),
                'name' => $this->getServiceName()
            ])
        ));

        return $returnData;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleDELETE()
    {
        $params = $this->request->getParameters();

        if (!empty($this->resource)) {
            $result = $this->cache->clearById($this->resource, $params, true);
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $result = $this->cache->getOrClearLimits($ids, $params, true);
        } elseif ($records = ResourcesWrapper::unwrapResources($this->getPayloadData())) {
            $result = $this->cache->getOrClearLimits($records, $params, true);
        } else {
            throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
        }

        $result = parent::handleDELETE();

        /** Handle both resource bulk and single returns */
        if (isset($result['resource']) && is_array($result['resource'])) {
            foreach ($result['resource'] as &$return) {
                if (isset($return['period'])) {
                    $return['period'] = LimitsModel::$limitPeriods[$return['period']];
                }
            }
        } elseif (isset($result['period'])) {
            $result['period'] = LimitsModel::$limitPeriods[$result['period']];
        }

        // Use this event for now to clear event cache, which tracks limits for scripting
        event(new ServiceModifiedEvent(
            new Service([
                'id'   => $this->getServiceId(),
                'name' => $this->getServiceName()
            ])
        ));

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function handlePATCH()
    {
        $params = $this->request->getParameters();

        $payload = $this->getPayloadData();

        $getLimitCache = $this->extractCacheRelated();

        if (!empty($this->resource)) {

            $id = (int)$this->resource;
            /* Call the model with the ID to merge */
            $recordObj = LimitsModel::where('id', $id)->first();
            if (empty($recordObj)) {
                throw new BadRequestException(sprintf("Record with identifier '%s' not found.", $id), 404, null);
            }
            $limitRecord = $recordObj->toArray();
            /* Merge the delta */
            $record = array_merge($limitRecord, $payload);
            $record = $this->enrichAndValidateRecordData($record);

            /* If nothing that affects the key has changed, unset the key to prevent a duplicate false positive */
            if ($record['key_text'] == $limitRecord['key_text']) {
                unset($record['key_text']);

                $this->handleRateChanges($limitRecord, $record);
            } elseif (LimitsModel::where('key_text', $record['key_text'])->exists() &&
                !array_get_bool($params, 'rollback')
            ) {
                /* If a record exists in the DB that matches the key, throw it out */
                throw new BadRequestException('A limit already exists with those parameters. No records added.', 0,
                    null, $record);
            }

            $this->request->setPayloadData($record);
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $records = ResourcesWrapper::unwrapResources($payload);
            if (empty($records)) {
                throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
            }
            /* multiple Ids update */
            $updateRecords = [];
            $idParts = explode(',', $ids);
            foreach ($idParts as $idBuild) {
                $record = $records[0] ?? $records;
                $modelRecord = LimitsModel::where('id', $idBuild)->first();
                if (empty($modelRecord)) {
                    continue;
                }
                $modelRecord = $modelRecord->toArray();
                $tmpRecord = array_merge($modelRecord, $record);
                $return = $this->enrichAndValidateRecordData($tmpRecord);
                /* If nothing that affects the key has changed, unset the key to prevent a duplicate false positive */
                if ($modelRecord['key_text'] == $return['key_text']) {
                    unset($return['key_text']);

                    $this->handleRateChanges($modelRecord, $return);
                }
                $updateRecords[] = $return;
            }
            /* This test case should not occur often, bad id, but no resource. */
            if (empty($updateRecords)) {
                $updateRecords = $records;
            }

            $this->request->setPayloadData(ResourcesWrapper::wrapResources($updateRecords));
        } elseif (!empty($records = ResourcesWrapper::unwrapResources($payload))) {
            /* Do we have a single record? Make an array */
            if (!isset($records[0])) {
                $tmpRecords[] = $records;
                $records = $tmpRecords;
            }

            /* This is a batch request */
            foreach ($records as $k => &$record) {
                $recordObj = LimitsModel::where('id', $record['id'])->first();
                if (empty($recordObj)) {
                    continue;
                } else {
                    $limitRecord = $recordObj->toArray();
                    $tmpRecord = array_merge($limitRecord, $record);
                    $record = $this->enrichAndValidateRecordData($tmpRecord);

                    /* If nothing that affects the key has changed, unset the key to prevent a duplicate false positive */
                    if ($record['key_text'] == $tmpRecord['key_text']) {
                        unset($record['key_text']);
                        $this->handleRateChanges($limitRecord, $record);
                    }
                }
            }

            $this->request->setPayloadData(ResourcesWrapper::wrapResources($records));
        }

        $returnData = parent::handlePATCH();
        if (isset($returnData['resource']) && is_array($returnData['resource'])) {
            foreach ($returnData['resource'] as &$return) {
                if (isset($return['period'])) {
                    $return['period'] = LimitsModel::$limitPeriods[$return['period']];
                }
            }
        } else {
            if (isset($returnData['period'])) {
                $returnData['period'] = LimitsModel::$limitPeriods[$returnData['period']];
            }
        }

        /** Enrich records with limit_cache if requested. */
        if ($getLimitCache === true) {
            $cacheData = $this->cache->getLimitsById($returnData['id']);
            $returnData['limit_cache_by_limit_id'] = $cacheData;
        }

        return $returnData;
    }

    /**
     * @inheritdoc
     */
    public function handlePUT()
    {
        return $this->handlePATCH();
    }

    /**
     * If the key is locked out and the Admin changes the rate, need to unlock and reset the limit record. Handles
     * each_user conditions as well
     *
     * @param $dbRecord
     * @param $record
     */
    protected function handleRateChanges($dbRecord, $record)
    {
        /* If Admin is changing the rate, check for lockout condition */
        if ($dbRecord['rate'] <> $record['rate']) {

            /* Check for an each user condition */
            if (in_array($dbRecord['type'], LimitsModel::$eachUserTypes)) {
                $users = User::where('is_active', 1)->where('is_sys_admin', 0)->get();
                foreach ($users as $checkUser) {
                    $chkKey = $this->limitModel->resolveCheckKey($dbRecord['type'], $checkUser['id'],
                        $dbRecord['role_id'], $dbRecord['service_id'], $dbRecord['endpoint'], $dbRecord['verb'],
                        $dbRecord['period']);
                    if ($this->cache->hasLockout($chkKey)) {
                        $this->cache->clearById($dbRecord['id']);
                    }
                }
            } else {
                /* Regular condition */
                if ($this->cache->hasLockout($dbRecord['key_text'])) {
                    /* If It's locked out, let's reset the counter value to allow for rate  increase, etc. */
                    $this->cache->clearById($dbRecord['id']);
                }
            }
        }
    }

    /**
     * Enriches record data with key and hash for DB.
     */
    protected function enrichAndValidateRecordData($record)
    {
        $limitPeriodNumber =
            (!is_int($record['period'])) ? array_search($record['period'], LimitsModel::$limitPeriods)
                : $record['period'];

        if ($this->validateLimitPayload($record)) {
            /* set the resolved limit period number */
            $record['period'] = $limitPeriodNumber;
            /** Check for verb - default state is null for all verbs. */
            if (!isset($record['verb'])) {
                $record['verb'] = null;
            }

            $key =
                $this->limitModel->resolveCheckKey($record['type'], $record['user_id'], $record['role_id'],
                    $record['service_id'], $record['endpoint'], $record['verb'], $limitPeriodNumber);
            $record['key_text'] = $key;

            /* limits are active by default, but in case of deactivation, set the limit inactive */
            if (isset($record['is_active']) && !filter_var($record['is_active'], FILTER_VALIDATE_BOOLEAN)) {
                $record['is_active'] = 0;
            }
        }

        return $record;
    }

    protected function validateLimitPayload(&$record)
    {
        /** This applies to all limits */
        /* limits are active by default, but in case of deactivation, set the limit inactive */
        if (isset($record['rate']) && !filter_var($record['rate'], FILTER_VALIDATE_INT)) {
            throw new BadRequestException('Limit rate must be an integer. Limit not saved.');
        }

        switch ($record['type']) {
            case 'instance':
            case 'instance.each_user':
                $this->nullify($record, ['user_id', 'role_id', 'service_id', 'endpoint']);
                break;

            case 'instance.user':
                if (!isset($record['user_id']) || is_null($record['user_id'])) {
                    throw new BadRequestException('user_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkUser($record['user_id']) && $record['user_id'] !== '*') {
                    throw new BadRequestException('user_id does not exist for ' . $record['name'] . ' limit.');
                }
                $this->nullify($record, ['role_id', 'service_id', 'endpoint']);

                break;

            case 'instance.role':

                if (!isset($record['role_id']) || is_null($record['role_id'])) {
                    throw new BadRequestException('role_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkRole($record['role_id'])) {
                    throw new BadRequestException('No role_id exists for ' . $record['name'] . ' limit.');
                }
                $this->nullify($record, ['user_id', 'service_id', 'endpoint']);

                break;

            case 'instance.user.service':

                if (!isset($record['user_id']) || is_null($record['user_id'])) {
                    throw new BadRequestException('user_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkUser($record['user_id']) && $record['user_id'] !== '*') {
                    throw new BadRequestException('No user_id exists for ' . $record['name'] . ' limit.');
                }

                if (!$this->checkService($record['service_id'])) {
                    throw new BadRequestException('No service exists for ' . $record['name'] . ' limit.');
                }
                $this->nullify($record, ['role_id', 'endpoint']);

                break;

            case 'instance.service':
            case 'instance.each_user.service':

                if (!isset($record['service_id']) || is_null($record['service_id'])) {
                    throw new BadRequestException('service_id must be specified with this limit type.');
                }

                if (!$this->checkService($record['service_id'])) {
                    throw new BadRequestException('No service exists for ' . $record['name'] . ' limit.');
                }
                $this->nullify($record, ['user_id', 'role_id', 'endpoint']);
                break;

            case 'instance.service.endpoint':
            case 'instance.each_user.service.endpoint':

                if (!isset($record['service_id']) || is_null($record['service_id'])) {
                    throw new BadRequestException('service_id must be specified with this limit type.');
                }

                if (!$this->checkService($record['service_id'])) {
                    throw new BadRequestException('No service exists for ' . $record['name'] . ' limit.');
                }

                if (isset($record['verb']) && !in_array(mb_strtoupper($record['verb']), $this->allowedVerbs)) {
                    throw new BadRequestException('Verb is invalid or not allowed.');
                }

                $outcome = $this->validateEndpoint($record['endpoint'], $record['service_id']);
                if (!empty($outcome)) {
                    throw new BadRequestException(implode(' ', $outcome));
                }

                $this->nullify($record, ['user_id', 'role_id']);

                break;

            case 'instance.user.service.endpoint':

                if (!isset($record['user_id']) || is_null($record['user_id'])) {
                    throw new BadRequestException('user_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkUser($record['user_id']) && $record['user_id'] !== '*') {
                    throw new BadRequestException('No user_id exists for ' . $record['name'] . ' limit.');
                }

                if (!$this->checkService($record['service_id'])) {
                    throw new BadRequestException('No service exists for ' . $record['name'] . ' limit.');
                }

                if (!isset($record['endpoint']) || is_null($record['endpoint'])) {
                    throw new BadRequestException('endpoint must be specified with this limit type.');
                }

                if (isset($record['verb']) && !in_array(mb_strtoupper($record['verb']), $this->allowedVerbs)) {
                    throw new BadRequestException('Verb is invalid or not allowed.');
                }

                $outcome = $this->validateEndpoint($record['endpoint'], $record['service_id']);

                if (!empty($outcome)) {
                    throw new BadRequestException(implode(' ', $outcome));
                }

                $this->nullify($record, ['role_id']);

                break;
        }

        return true;
    }

    /**
     * Validates an enpoint against known events as well as sanitizes incoming endpoint.
     *
     * @param $endpoint
     * @param $serviceId
     *
     * @return bool
     */
    protected function validateEndpoint(&$endpoint, $serviceId)
    {

        $outcome = [];
        $endpoint = $this->sanitizeEndpoint($endpoint);

        /** Check for blank endpoints */
        if (empty($endpoint)) {
            return $outcome;
        }

        /** Need to pull system events to match any API Endpoint limits up with. $eventMap */
        /** Right now, no need to evaluate against the list of services, may need in the future. */

        /*$eventMap = Event::getEventMap();
        $service  = Service::where('id', $serviceId)->get();
        if(!$service->isEmpty()){
            $serviceName = $service[0]->name;
        }

        $eptParts = explode('/', $endpoint);*/

        /** Removed to allow any depth of endpoint to be posted.  */
        /*if(count($eptParts) > 2){
            $outcome[] = 'Endpoint cannot have extra depth. ie, _schema/contact NOT _schema/contact/name';
        }*/

        /* if(!isset($eventMap[$serviceName][$serviceName. '.' . $eptParts[0]])){
            $outcome[] = 'Endpoint does not exist for the service ' . $serviceName . ' Endpoint: ' .$endpoint;
        }*/

        return $outcome;
    }

    protected function checkUser($id)
    {
        return User::where('id', $id)->exists();
    }

    protected function checkRole($id)
    {
        return Role::where('id', $id)->exists();
    }

    protected function checkService($id)
    {
        return Service::where('id', $id)->exists();
    }

    protected function nullify(&$record, $nullable = [])
    {
        if (!empty($nullable)) {
            foreach ($nullable as $type) {
                $record[$type] = null;
            }
        }
    }

    /**
     * Sanitizes an endpoint from leading and trailing slashes
     *
     * @param $endpoint
     *
     * @return string Sanitized Endpoint.
     */
    protected function sanitizeEndpoint($endpoint)
    {
        if (!is_null($endpoint)) {
            return preg_replace('/(\/)+$/', '', preg_replace('/^(\/)+/', '', $endpoint));
        }

        return $endpoint;
    }

    public function getEventMap()
    {
        $limits = LimitsModel::where('is_active', 1)->get();

        $lids = [];
        foreach ($limits as $limit) {
            $lids[] = $limit->id;
        }
        if (!empty($lids)) {
            return ['system.limit.{id}.exceeded' => ['parameter' => ['id' => $lids]]];
        }

        return ['system.limit.{id}.exceeded' => ['parameter' => null]];
    }

}