<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\BearerTokenException;
use Freddie\Security\JWT\Extractor\AuthorizationHeaderTokenExtractor;
use React\Http\Message\ServerRequest;

it('extracts token from authorization header', function (array $headers, ?string $expected) {
    $extractor = new AuthorizationHeaderTokenExtractor();
    $request = new ServerRequest('GET', '/.well-known/mercure', $headers);
    expect($extractor->extract($request))->toBe($expected);
})->with(function () {
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    yield 'bearer' => [['Authorization' => 'Bearer ' . $validToken], $validToken];
    yield 'case-insensitive scheme' => [['Authorization' => 'bearer ' . $validToken], $validToken];
    yield 'invalid token, rejected later' => [['Authorization' => 'Bearer foo'], 'foo'];
    yield 'no header' => [[], null];
});

it('rejects a malformed authorization header', function (string $header) {
    $request = new ServerRequest('GET', '/.well-known/mercure', ['Authorization' => $header]);

    try {
        (new AuthorizationHeaderTokenExtractor())->extract($request);
        $this->fail('An exception should have been thrown.');
    } catch (BearerTokenException $e) {
        expect($e->getStatusCode())->toBe(400)
            ->and($e->error)->toBe(BearerTokenException::INVALID_REQUEST)
            ->and($e->getHeaders())->toBe(['WWW-Authenticate' => 'Bearer error="invalid_request"']);
    }
})->with([
    'other scheme' => ['Basic Zm9vOmJhcg=='],
    'no token' => ['Bearer '],
    'no scheme' => ['foobar'],
]);
