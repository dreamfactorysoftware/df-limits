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
     * @param  \Illuminate\Cache\RateLimiter $limiter
     *
     * @return void
     */
    public function __construct()
    {
        $this->limiter = new LimitCache(app('cache')->store('limit'));
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

        $token = Session::getSessionToken();

        if ($isAdmin) {
            return $next($request);
        }

        $routeService = Route::getCurrentRoute()->parameter('service');
        $routeResource = Route::getcurrentRoute()->parameter('resource');
        $method = $request->method();

        $service = Service::where('name', $routeService)->first();

        /* Important - only evaluate against active limits */
        $limits = Limit::where('is_active', 1)->get();
        $overLimit = [];

        /* check for user overrides */
        $overrides = ['user' => [], 'service' => []];
        foreach ($limits as $limit) {
            if (!is_null($limit->user_id)) {
                switch ($limit->type) {
                    case 'instance.user':
                        $overrides['user'][] = $limit->user_id;
                        break;
                    case 'instance.user.service':
                        $overrides['service'][] = $limit->user_id;
                        break;
                }
            }
        }

        foreach ($limits as $limit) {
            $dbUser = $limit->user_id;

            /** Process all verbs unless it is specified in the db for that limit. */
            $dbVerb = (!is_null($limit->verb)) ?: null;
            $derivedVerb = (!is_null($limit->verb)) ?: null;

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

            /* $checkKey key built from the database - these are the conditions we're checking for */
            $checkKey   = $limitModel->resolveCheckKey($limit->type, $dbUser, $limit->role_id, $limit->service_id, $limit->endpoint, $dbVerb, $limit->period);
            /* $derivedKey key built from the current request - to check and match against the limit from $checkKey */
            $derivedKey = $limitModel->resolveCheckKey($limit->type, $userId, $roleId, $service->id, $routeResource, $derivedVerb, $limit->period);

            if ($checkKey == $derivedKey) {

                if (!$isUserLimit || ($isUserLimit && !is_null($token))) {

                    if ($this->limiter->tooManyAttempts($checkKey, $limit->rate, Limit::$limitIntervals[$limit->period])
                    ) {
                        /**
                         * Checks that the current user is not in the override structures for user and service. This would override
                            a specific user condition against an each_user condition. However, counters will get ticked regardless.
                         * This will skip the overLimit condition for the each_user evaluation.
                         */

                        if($limit->type == 'instance.each_user' && in_array($userId, $overrides['user']) ||
                            $limit->type == 'instance.each_user.service' && in_array($userId, $overrides['service'])){
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
