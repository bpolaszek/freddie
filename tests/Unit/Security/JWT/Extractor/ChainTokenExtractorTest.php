<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Symfony\Component\HttpFoundation\Request;

use function Freddie\Tests\createSfRequest;

it('extracts token either from cookies or authorization header', function (
    Request $request,
    ?string $expected,
) {
    $extractor = new ChainTokenExtractor();
    expect($extractor->extract($request))->toBe($expected);
})->with(function () {
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure', cookies: ['mercureAuthorization' => $validToken]),
        'expected' => $validToken,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure?authorization=' . $validToken),
        'expected' => $validToken,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer foo',
        ], ['mercureAuthorization' => $validToken]),
        'expected' => $validToken,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer ' . $validToken,
        ], ['mercureAuthorization' => $validToken]),
        'expected' => $validToken,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer ' . $validToken,
        ]),
        'expected' => $validToken,
    ];
    yield [
        'request' => createSfRequest(
            'GET',
            '/.well-known/mercure',
            cookies: ['mercureAuthorization' => 'foo'],
        ),
        'expected' => null,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer bar',
        ], ['mercureAuthorization' => 'foo']),
        'expected' => null,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure', [
            'Authorization' => 'Bearer foobar',
        ]),
        'expected' => null,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure?authorization=foobar'),
        'expected' => null,
    ];
    yield [
        'request' => createSfRequest('GET', '/.well-known/mercure'),
        'expected' => null,
    ];
});
