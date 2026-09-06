<?php

use Abigah\BotCopTrafficClient\Http\Middleware\NeverCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('marks a response as never cacheable', function () {
    $response = (new NeverCache)->handle(
        Request::create('/up'),
        fn () => new Response('OK')
    );

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store', 'no-cache', 'must-revalidate')
        ->and($response->headers->get('CDN-Cache-Control'))->toBe('no-store');
});

it('passes the response through untouched otherwise', function () {
    $response = (new NeverCache)->handle(
        Request::create('/up'),
        fn () => new Response('OK', 200, ['X-Custom' => 'kept'])
    );

    expect($response->getContent())->toBe('OK')
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('X-Custom'))->toBe('kept');
});
