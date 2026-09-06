<?php

namespace Abigah\BotCopTrafficClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a response as never cacheable. Intended for the health route.
 *
 * A cached 200 on /up is a check that never reached the origin: the prober sees
 * a healthy site for as long as the cache holds, which is exactly the window in
 * which it was supposed to be raising the alarm. That is a worse failure than
 * no monitoring at all, because it is a confident one.
 *
 * This is the app's half of the rule. The other half is at the CDN, where a
 * "Cache Everything" rule can override what the origin asks for regardless of
 * these headers — see docs/faq.md. And the prober does its part too: it sends
 * no-cache with a cache-busting parameter, and records any response carrying
 * cf-cache-status: HIT as served-from-cache rather than believing it.
 *
 * This adds no health logic. Whether /up means anything beyond "the framework
 * booted" is the application's business, and the FAQ shows how to make it mean
 * more.
 *
 *     Route::get('/up', …)->middleware(NeverCache::class);
 */
class NeverCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        // Cloudflare and most CDNs will not cache a response carrying this,
        // even under a rule that ignores Cache-Control.
        $response->headers->set('CDN-Cache-Control', 'no-store');

        return $response;
    }
}
