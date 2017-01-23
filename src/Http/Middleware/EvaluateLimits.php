<?php
namespace DreamFactory\Core\Limit\Http\Middleware;

use DreamFactory\Core\Components\DfResponse;
use DreamFactory\Core\Limit\Models\Limit;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\Session;
use Illuminate\Cache\RateLimiter;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Models\App;
use Illuminate\Support\Facades\Cache;

use Carbon\Carbon;

use Request;
use Closure;
use ServiceManager;


class EvaluateLimits {

    /**
     * The rate limiter instance.
     *
     * @var \Illuminate\Cache\RateLimiter
     */
    protected $limiter;


    /**
     * Create a new request throttler.
     *
     * @param  \Illuminate\Cache\RateLimiter  $limiter
     * @return void
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = new RateLimiter(app('cache')->store('limit'));
    }


    /**
     * @param $request
     * @param Closure $next
     * @return mixed
     */
    function handle($request, Closure $next)
    {


        $limitModel = new Limit();
        $userId  = Session::getCurrentUserId();
        $roleId  = Session::getRoleId();
        $isAdmin = Session::isSysAdmin();

        //$appId   = App::getAppIdByApiKey(Session::getApiKey());

        $service = Service::where('name', $request->route()->getParameter('service'))->first();

        $limits = Limit::where('active_ind', 1)->get();
        $overLimit = [];

        foreach($limits as $limit){
            $db_user = $limit->user_id;

            /* This checks for an "Each User" condition, where the limit would apply to every user
            /* for this instance or service. The cache key will be based on each user in this case,
            /* so set the $db_user to the current $userId for matching keys */
            if(is_null($db_user) && strpos($limit->limit_type, 'user') && !is_null($userId) && !$isAdmin){
                $db_user = $userId;
            }

            /* $checkKey key built from the database - these are the conditions we're checking for */
            $checkKey   = $limitModel->resolveCheckKey($limit->limit_type, $db_user, $limit->role_id, $limit->service_id, $limit->limit_period);
            /* $derivedKey key built from the current request - to check and match against the limit from $checkKey */
            $derivedKey = $limitModel->resolveCheckKey($limit->limit_type, $userId, $roleId, $service->id, $limit->limit_period);

            if($checkKey == $derivedKey){
                if($this->limiter->tooManyAttempts($checkKey, $limit->limit_rate, Limit::$limitIntervals[$limit->limit_period])){
                    $overLimit[] = $limit->label_text;
                }

                $this->limiter->hit($checkKey, Limit::$limitIntervals[$limit->limit_period]);
            }
        }


        if(!empty($overLimit)){
            $response = ResponseFactory::sendException(new TooManyRequestsException('API limit(s) exceeded: ' . implode(', ', $overLimit)));

            return $this->addHeaders(
                $response, $limit->limit_rate,
                $this->calculateRemainingAttempts($checkKey, $limit->limit_rate),
                true
            );
        }


        return $next($request);


    }



    /**
     * Add the limit header information to the given response.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @param  int  $maxAttempts
     * @param  int  $remainingAttempts
     * @param  int|null  $retryAfter
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function addHeaders(DfResponse $response, $maxAttempts, $remainingAttempts, $retryAfter = null)
    {
        $headers = [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ];
        if (! is_null($retryAfter)) {
            $headers['Retry-After'] = $retryAfter;
            $headers['X-RateLimit-Reset'] = Carbon::now()->getTimestamp() + $retryAfter;
        }
        $response->headers->add($headers);
        return $response;
    }


    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @param  int|null  $retryAfter
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
