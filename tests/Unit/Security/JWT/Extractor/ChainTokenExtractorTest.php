<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\BearerTokenException;
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
    yield 'cookie is ignored when the header is present, even with an invalid token' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure', [
            'Cookie' => '__Secure-mercure_access_token=' . VALID_TOKEN,
            'Authorization' => 'Bearer foo',
        ]),
        'expected' => 'foo',
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
    yield 'query parameter takes precedence over cookies' => [
        'request' => new ServerRequest('GET', '/.well-known/mercure?authorization=' . VALID_TOKEN, [
            'Cookie' => 'mercureAuthorization=' . strrev(VALID_TOKEN),
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

it('does not fall back on the cookie when the header is malformed', function (bool $legacy) {
    $request = new ServerRequest('GET', '/.well-known/mercure?authorization=' . VALID_TOKEN, [
        'Cookie' => '__Secure-mercure_access_token=' . VALID_TOKEN,
        'Authorization' => 'Basic Zm9vOmJhcg==',
    ]);

    ChainTokenExtractor::create($legacy)->extract($request);
})->with(['1.0 mode' => [false], 'compatibility mode' => [true]])
    ->throws(BearerTokenException::class, 'Invalid "Authorization" header.');

it('uses a custom cookie name', function () {
    $request = new ServerRequest('GET', '/.well-known/mercure', ['Cookie' => 'custom=' . VALID_TOKEN]);

    expect(ChainTokenExtractor::create(cookieName: 'custom')->extract($request))->toBe(VALID_TOKEN);
});
