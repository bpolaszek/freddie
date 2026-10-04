<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

const VALID_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

it('extracts token either from the authorization header or the cookie', function (
    ServerRequestInterface $request,
    ?string $expected
) {
    expect((new ChainTokenExtractor())->extract($request))->toBe($expected)
        ->and(ChainTokenExtractor::create()->extract($request))->toBe($expected);
})->with(function () {
    yield 'cookie' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => '__Secure-mercure_access_token=' . VALID_TOKEN,
        ]),
        'expected' => VALID_TOKEN,
    ];
    yield 'header takes precedence over cookie' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => '__Secure-mercure_access_token=foo',
            'Authorization' => 'Bearer ' . VALID_TOKEN,
        ]),
        'expected' => VALID_TOKEN,
    ];
    yield 'invalid header falls back to cookie' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => '__Secure-mercure_access_token=' . VALID_TOKEN,
            'Authorization' => 'Bearer foo',
        ]),
        'expected' => VALID_TOKEN,
    ];
    yield 'header' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer ' . VALID_TOKEN,
        ]),
        'expected' => VALID_TOKEN,
    ];
    yield 'legacy cookie is ignored' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=' . VALID_TOKEN,
        ]),
        'expected' => null,
    ];
    yield 'query parameter is ignored' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure?authorization=' . VALID_TOKEN),
        'expected' => null,
    ];
    yield 'invalid header' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer foobar',
        ]),
        'expected' => null,
    ];
    yield 'nothing' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure'),
        'expected' => null,
    ];
});

it('extracts tokens the legacy way in compatibility mode', function (
    ServerRequestInterface $request,
    ?string $expected
) {
    expect(ChainTokenExtractor::create(legacy: true)->extract($request))->toBe($expected);
})->with(function () {
    yield 'legacy cookie' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => 'mercureAuthorization=' . VALID_TOKEN,
        ]),
        'expected' => VALID_TOKEN,
    ];
    yield 'query parameter' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure?authorization=' . VALID_TOKEN),
        'expected' => VALID_TOKEN,
    ];
    yield 'header takes precedence' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure?authorization=foo', [
            'Cookie' => 'mercureAuthorization=foo',
            'Authorization' => 'Bearer ' . VALID_TOKEN,
        ]),
        'expected' => VALID_TOKEN,
    ];
    yield 'new cookie' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => '__Secure-mercure_access_token=' . VALID_TOKEN,
        ]),
        'expected' => VALID_TOKEN,
    ];
});

it('uses a custom cookie name', function () {
    $request = new ServerRequest('GET', '/.well-known/mercure', ['Cookie' => 'custom=' . VALID_TOKEN]);

    expect(ChainTokenExtractor::create(cookieName: 'custom')->extract($request))->toBe(VALID_TOKEN);
});
