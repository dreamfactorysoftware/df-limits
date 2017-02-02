<?php
namespace DreamFactory\Core\Limit\Resources\System;

use DreamFactory\Core\Resources\System\BaseSystemResource;
use DreamFactory\Core\Limit\Models\Limit as LimitsModel;
use DreamFactory\Library\Utility\Enums\DateTimeIntervals;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Limit\Resources\System\LimitCache as Cache;

class Limit extends BaseSystemResource
{
    /**
     * @var string DreamFactory\Core\Models\BaseSystemModel Model Class name.
     */
    protected static $model = LimitsModel::class;

    protected $periods = [
        'Minute'  => DateTimeIntervals::MINUTES_PER_MINUTE,
        'Hour'    => DateTimeIntervals::MINUTES_PER_HOUR,
        'Day'     => DateTimeIntervals::MINUTES_PER_DAY,
        '7 Days'  => DateTimeIntervals::MINUTES_PER_WEEK,
        '30 Days' => DateTimeIntervals::MINUTES_PER_MONTH,
    ];

    /**
     * The limiter cache store.
     *
     * @var \Illuminate\Cache\
     */
    protected $cache;

    public function __construct()
    {
        $this->cache = new LimitCache();
        $this->limitModel = new static::$model;
    }

    /**
     * {@inheritdoc}
     */
    protected function handleGET()
    {
        $response = parent::handleGET();
        if (isset($response['resource']) && !empty($response['resource'])) {
            foreach ($response['resource'] as &$resourceLimit) {
                $resourceLimit['period'] = $this->resolveLimitPeriod($resourceLimit['period']);
            }
        } else {
            if (isset($response['period']) && !empty($response['period'])) {
                $response['period'] = $this->resolveLimitPeriod($response['period']);
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
     * {@inheritdoc}
     */
    protected function handlePOST()
    {

        try {
            /* First, enrich our payload with some conversions and a unique key */
            $records = ResourcesWrapper::unwrapResources($this->getPayloadData());

            /* enrich the records (and validate) */
            foreach ($records as &$record) {
                $record = $this->enrichAndValidateRecordData($record);
            }

            $this->request->setPayloadData(ResourcesWrapper::wrapResources($records));
            /* For bulk create, rollback the transaction if a record fails. */
            $this->request->setParameter('rollback', true);

            $response = parent::handlePOST();
            $returnData = $response->getContent();
            if (is_array($returnData['resource'])) {
                foreach ($returnData['resource'] as &$return) {
                    if (isset($return['period'])) {
                        $return['period'] = LimitsModel::$limitPeriods[$return['period']];
                    }
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (preg_match('/Duplicate entry (.*) for key \'limits_key_text_unique\'/', $message)) {
                throw new BadRequestException('A limit already exists with those parameters. No records added.', 0, $e);
            }
            throw new BadRequestException('An error occurred when inserting Limits: ' . $message);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function handleDELETE()
    {
        $params = $this->request->getParameters();

        if (!empty($this->resource)) {
            $result = $this->cache->clearById($this->resource, $params);
        } elseif (!empty($ids = $this->request->getParameter(ApiOptions::IDS))) {
            $result = $this->cache->clearByIds($ids, $params);
        } elseif ($records = ResourcesWrapper::unwrapResources($this->getPayloadData())) {
            $result = $this->cache->clearByIds($records, $params);
        } else {
            throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
        }

        return parent::handleDELETE(); // TODO: Change the autogenerated stub

    }

    /**
     * {@inheritdoc}
     */
    protected function handlePATCH()
    {

        $params = $this->request->getParameters();
        $payload = $this->getPayloadData();

        if (!empty($this->resource)) {

            $id = $this->resource;
            /* Call the model with the ID to merge */
            $limitRecord = LimitsModel::where('id', $id)->first()->toArray();
            /* Merge the delta */
            $record = array_merge($limitRecord, $payload);
            $record = $this->enrichAndValidateRecordData($record);
            /* If nothing that affects the key has changed, unset the key to prevent a duplicate false positive */
            if ($record['key_text'] == $limitRecord['key_text']) {
                unset($record['key_text']);
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
                $tmpRecord = array_merge(LimitsModel::where('id', $idBuild)->first()->toArray(), $records[0]);
                $return = $this->enrichAndValidateRecordData($tmpRecord);
                /* If nothing that affects the key has changed, unset the key to prevent a duplicate false positive */
                if ($tmpRecord['key_text'] == $return['key_text']) {
                    unset($return['key_text']);
                }
                $updateRecords[] = $return;
            }

            $this->request->setParameter('rollback', true);
            $this->request->setPayloadData(ResourcesWrapper::wrapResources($updateRecords));
        } elseif (!empty($records = ResourcesWrapper::unwrapResources($payload))) {

            foreach ($records as &$record) {
                $limitRecord = LimitsModel::where('id', $record['id'])->first()->toArray();
                $tmpRecord = array_merge($limitRecord, $record);
                $record = $this->enrichAndValidateRecordData($tmpRecord);
                /* If nothing that affects the key has changed, unset the key to prevent a duplicate false positive */
                if ($record['key_text'] == $tmpRecord['key_text']) {
                    unset($record['key_text']);
                }
            }
            $this->request->setParameter('rollback', true);

            $this->request->setPayloadData(ResourcesWrapper::wrapResources($records));
        } else {
            throw new BadRequestException('No record(s) detected in request.' . ResourcesWrapper::getWrapperMsg());
        }

        try {

            return parent::handlePATCH();
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (preg_match('/Duplicate entry (.*) for key \'limits_key_text_unique\'/', $message)) {
                throw new BadRequestException('A limit already exists with those parameters. No records added.', 0, $e);
            }
            throw new BadRequestException('An error occurred when inserting Limits: ' . $message);
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
            /* check for an "each user" condition by a *, set it to null, bypassing validation */
            if (strpos($record['type'], 'user') && $record['user_id'] == '*') {
                $record['user_id'] = null;
            }
            $key =
                $this->limitModel->resolveCheckKey($record['type'], $record['user_id'], $record['role_id'],
                    $record['service_id'], $limitPeriodNumber);
            $record['key_text'] = $key;

            /* If record_label is not set, set it to name */
            if (!isset($record['label']) || is_null($record['label'])) {
                $record['label'] = $record['name'];
            }

            /* limits are active by default, but in case of deactivation, set the limit inactive */
            if (isset($record['active']) && !filter_var($record['active'], FILTER_VALIDATE_BOOLEAN)) {
                $record['active_ind'] = 0;
            }
        }

        return $record;
    }

    protected function validateLimitPayload(&$record)
    {

        /* Default service id enriched value - will get set from name if exists in database in _resolveServiceName(). */
        $record['service_id'] = null;

        switch ($record['type']) {
            case 'instance':
                break;

            case 'instance.user':

                if (!isset($record['user_id']) || is_null($record['user_id'])) {
                    throw new BadRequestException('user_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkUser($record['user_id']) && $record['user_id'] !== '*') {
                    throw new BadRequestException('user_id does not exist for ' . $record['name'] . ' limit.');
                }

                break;

            case 'instance.role':

                if (!isset($record['role_id']) || is_null($record['role_id'])) {
                    throw new BadRequestException('role_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkRole($record['role_id'])) {
                    throw new BadRequestException('No role_id exists for ' . $record['name'] . ' limit.');
                }

                break;

            case 'instance.user.service':

                if (!isset($record['user_id']) || is_null($record['user_id'])) {
                    throw new BadRequestException('user_id must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->checkUser($record['user_id']) && $record['user_id'] !== '*') {
                    throw new BadRequestException('No user_id exists for ' . $record['name'] . ' limit.');
                }

                if (!isset($record['service_name']) || is_null($record['service_name'])) {
                    throw new BadRequestException('service_name must be specified with this limit type. Limit: ' .
                        $record['name']);
                }

                if (!$this->resolveServiceName($record)) {
                    throw new BadRequestException('No service_name exists for ' . $record['name'] . ' limit.');
                }

                break;

            case 'instance.service':

                if (!isset($record['service_name']) || is_null($record['service_name'])) {
                    throw new BadRequestException('service_name must be specified with this limit type.');
                }

                if (!$this->resolveServiceName($record)) {
                    throw new BadRequestException('No service_name exists for ' . $record['name'] . ' limit.');
                }

                break;
        }

        return true;
    }

    protected function checkUser($id)
    {
        return User::where('id', $id)->exists();
    }

    protected function checkRole($id)
    {
        return Role::where('id', $id)->exists();
    }

    protected function resolveServiceName(&$record)
    {

        if ($service = Service::where('name', $record['service_name'])->first()) {
            $record['service_id'] = $service->id;

            return true;
        }

        return false;
    }

}