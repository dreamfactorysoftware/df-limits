<?php
namespace DreamFactory\Core\Limit\Http\Middleware;

use DreamFactory\Core\Components\DfResponse;
use DreamFactory\Core\Limit\Models\Limit;
use DreamFactory\Core\Limit\Resources\System\LimitCache;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\Session;
use Illuminate\Cache\RateLimiter;
use DreamFactory\Core\Models\Service;

use Carbon\Carbon;
use Auth;
use Route;
use Closure;
use Log;


class EvaluateLimits
{

    /**
     * The rate limiter instance.
     *
     * @var \Illuminate\Cache\RateLimiter
     */
    protected $limiter;

    /**
     * Create a new request throttler.
     *
     */
    public function __construct()
    {
        $this->limiter = new LimitCache();
    }

    /**
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     */
    function handle($request, Closure $next)
    {
        $limitModel = new Limit();
        $userId = Session::getCurrentUserId();
        $roleId = Session::getRoleId();
        $isAdmin = Session::isSysAdmin();
        $isBasicAuth = false; // Will be checked later

        $token = Session::getSessionToken();

        /** Admins are immune to Limits... */
        if ($isAdmin) {
            return $next($request);
        }

        /** If we don't have a token and we have gotten this far, check for Basic Auth */
        if(!$token  && !is_null(Auth::user())){
            $isBasicAuth = true;
        }

        $routeService = Route::getCurrentRoute()->parameter('service');
        $routeResource = Route::getcurrentRoute()->parameter('resource');
        $method = $request->method();

        $service = Service::where('name', $routeService)->first();

        /** Important - only evaluate against active limits */
        $limits = Limit::where('is_active', 1)->get();
        $overLimit = [];

        /* check for user overrides */
        $overrides = ['user' => [], 'service' => [], 'endpoint' => [], 'verb'  => []];
        foreach ($limits as $limit) {
            /** User overrides each_user at these levels */
            if (!is_null($limit->user_id)) {
                switch ($limit->type) {
                    case 'instance.user':
                        $overrides['user'][] = $limit->user_id;
                        break;
                    case 'instance.user.service':
                        $overrides['service'][] = $limit->user_id;
                        break;
                    case 'instance.user.service.endpoint':
                        $overrides['endpoint'][] = $limit->user_id;
                        break;
                }
            }

            /** Verb overrides array, to be compared by type */
            if(!is_null($limit->verb)){
                $overrides['verb'][] = $limit;
            }
        }

        foreach ($limits as $limit) {
            $dbUser = $limit->user_id;

            /** Process all verbs unless it is specified in the db for that limit. */
            $dbVerb = (!is_null($limit->verb)) ? $limit->verb : null;
            $derivedVerb = (!is_null($limit->verb)) ? $method : null;
            $overrideVerb = false;
            /** Default state of derrived should be the route resource (only applies to endpoint with an override). */
            $derivedResource = ltrim(rtrim($routeResource, '/'), '/');

            $isUserLimit = (in_array($limit->type, $limitModel::$eachUserTypes));

            /* This checks for an "Each User" condition, where the limit would apply to every user
            /* for this instance or service. The cache key will be based on each user in this case,
            /* so set the $db_user to the current $userId for matching keys. $userOverrides is checked
            /* to ensure that a specific user limit overrides the each user limit. */
            if (is_null($dbUser) &&
                $isUserLimit &&
                !is_null($userId)
            ) {
                $dbUser = $userId;
            }

            /**
             * This is what actually makes the base endpoint stored in database
             * match against the route resource, so that none of the rest of the
             * route resource is counted, ie, _schema/table/field, etc only _schema/table.
             * Looks for a match and then overrides the resource as $derivedResource
             */
            if(!is_null($limit->endpoint) && !empty($limit->endpoint) && !empty($routeResource)){
                Log::debug('route resource: ' . $routeResource);
                $ep = $limit->endpoint; // You have to pull out endpoint due to model conversion stuff - won't work in substr_compare...
                $size = (strlen($ep) > 0) ?  strlen($ep) : 0;

                /** Check for a * in the endpoint for wildcard Eps */
                if(($pos = strrpos($ep, '*')) !== false){
                    /** Do we have a match up to the star? */
                    if(0 === substr_compare($ep, $routeResource, 0, $pos)){
                        $derivedResource = $ep;
                    }
                } else {
                    /** Evaluate the incoming literally */
                    if(0 === strcmp($ep, $routeResource)){
                        $derivedResource = $ep;
                    }
                }

            }

            /* $checkKey key built from the database - these are the conditions we're checking for */
            $checkKey   = $limitModel->resolveCheckKey($limit->type, $dbUser, $limit->role_id, $limit->service_id, $limit->endpoint, $dbVerb, $limit->period);
            /* $derivedKey key built from the current request - to check and match against the limit from $checkKey */
            $derivedKey = $limitModel->resolveCheckKey($limit->type, $userId, $roleId, $service->id, $derivedResource, $derivedVerb, $limit->period);

            if(!empty($overrides['verb'])){
                foreach($overrides['verb'] as $compareVerb){
                    /** First build a key without the verb to see if we get a match */
                    $verbKey = $limitModel->resolveCheckKey($compareVerb->type, $userId, $roleId, $service->id, $derivedResource, $derivedVerb, $compareVerb->period);
                    /** If the incoming key matches the verb key without the verb, and the verbs of the incoming request match the verb on the key,
                     * we have an override situation. */
                    if($verbKey == $checkKey && $verbKey == $derivedKey && $compareVerb->verb == $method && $compareVerb->id !== $limit->id){
                        $overrideVerb = true;
                    }
                }
            }

            if ($checkKey == $derivedKey) {

                if (!$isUserLimit || ($isUserLimit && !is_null($token)) || ($isUserLimit && $isBasicAuth)) {

                    if ($this->limiter->tooManyAttempts($checkKey, $limit->rate, Limit::$limitIntervals[$limit->period])
                    ) {
                        /**
                         * Checks that the current user is not in the override structures for user and service. This would override
                            a specific user condition against an each_user condition. However, counters will get ticked regardless.
                         * This will skip the overLimit condition for the each_user evaluation.
                         */

                        if($limit->type == 'instance.each_user' && in_array($userId, $overrides['user']) ||
                            $limit->type == 'instance.each_user.service' && in_array($userId, $overrides['service']) ||
                            $limit->type == 'instance.each_user.service.endpoint' && in_array($userId, $overrides['endpoint']) ){
                            continue;
                        }

                        if($overrideVerb === true ){
                            continue;
                        }

                        $overLimit[] = [
                            'id'    => $limit->id,
                            'name'  => $limit->name,
                        ];
                    } else {
                        $this->limiter->hit($checkKey, Limit::$limitIntervals[$limit->period]);
                    }
                }
            }
        }

        if (!empty($overLimit)) {
            $response = ResponseFactory::sendException(new TooManyRequestsException('API limit(s) exceeded. ', null, null,
                    $overLimit));

            return $this->addHeaders(
                $response, $limit->rate,
                $this->calculateRemainingAttempts($checkKey, $limit->rate),
                $this->limiter->availableIn($checkKey)
            );
        }

        return $next($request);
    }

    /**
     * Add the limit header information to the given response.
     *
     * @param  \Symfony\Component\HttpFoundation\Response $response
     * @param  int                                        $maxAttempts
     * @param  int                                        $remainingAttempts
     * @param  int|null                                   $retryAfter
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function addHeaders(DfResponse $response, $maxAttempts, $remainingAttempts, $retryAfter = null)
    {
        $headers = [
            'X-RateLimit-Limit'     => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ];
        if (!is_null($retryAfter)) {
            $headers['Retry-After'] = $retryAfter;
            $headers['X-RateLimit-Reset'] = Carbon::now()->getTimestamp() + $retryAfter;
        }
        $response->headers->add($headers);

        return $response;
    }

    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string   $key
     * @param  int      $maxAttempts
     * @param  int|null $retryAfter
     *
     * @return int
     */
    protected function calculateRemainingAttempts($key, $maxAttempts, $retryAfter = null)
    {
        if (is_null($retryAfter)) {
            return $this->limiter->retriesLeft($key, $maxAttempts);
        }

        return 0;
    }

}
