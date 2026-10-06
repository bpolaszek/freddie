<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Middleware;

use DateTimeImmutable;
use FrameworkX\App;
use Freddie\Hub\Middleware\HttpExceptionConverterMiddleware;
use Freddie\Hub\Middleware\TokenExtractorMiddleware;
use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Validation\AccessTokenPolicy;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use RingCentral\Psr7\ServerRequest;
use Symfony\Component\Clock\Clock;

use function Freddie\Tests\token_app;
use function Freddie\Tests\access_token;
use function Freddie\Tests\access_token_policy;
use function Freddie\Tests\create_jwt;
use function Freddie\Tests\handle;
use function Freddie\Tests\jwt_config;

use const Freddie\Tests\ISSUER;
use const Freddie\Tests\RESOURCE_IDENTIFIER;

it('extracts the token and stores it in an attribute', function () {
    $token = null;
    $app = token_app(access_token_policy(), $token);

    // Given
    $request = new ServerRequest('GET', '/', ['Authorization' => 'Bearer ' . access_token()]);

    // When
    $response = handle($app, $request);

    // Then
    expect($response->getStatusCode())->toBe(204)
        ->and($token)->toBeInstanceOf(Token::class);
});

it('does nothing when JWT is not provided', function () {
    $token = null;
    $app = token_app(access_token_policy(), $token);

    // When
    handle($app, new ServerRequest('GET', '/'));

    // Then
    expect($token)->toBe('No token provided');
});

it('rejects tokens which are not valid access tokens', function (string $jwt, string $expectedMessage) {
    $token = null;
    $app = token_app(access_token_policy(), $token);

    // When
    $response = handle($app, new ServerRequest('GET', '/', ['Authorization' => "Bearer $jwt"]));

    // Then
    expect($response->getStatusCode())->toBe(401)
        ->and($response->getHeaderLine('WWW-Authenticate'))->toBe('Bearer error="invalid_token"')
        ->and((string) $response->getBody())->toContain($expectedMessage)
        ->and($token)->toBeNull();
})->with(function () {
    $claims = [
        'iss' => ISSUER,
        'aud' => RESOURCE_IDENTIFIER,
        'exp' => new DateTimeImmutable('+1 hour'),
    ];
    $typ = ['typ' => 'at+jwt'];

    yield 'malformed' => [access_token() . 'foo', 'Error while decoding from Base64Url'];
    yield 'missing typ' => [create_jwt($claims), 'typ must be "at+jwt"'];
    yield 'wrong typ' => [create_jwt($claims, ['typ' => 'JWT']), 'typ must be "at+jwt"'];
    yield 'missing iss' => [
        create_jwt(['aud' => RESOURCE_IDENTIFIER, 'exp' => new DateTimeImmutable('+1 hour')], $typ),
        'does not have the "iss" claim',
    ];
    yield 'missing exp' => [
        create_jwt(['iss' => ISSUER, 'aud' => RESOURCE_IDENTIFIER], $typ),
        'does not have the "exp" claim',
    ];
    yield 'expired' => [
        access_token(claims: ['exp' => new DateTimeImmutable('-1 hour')]),
        'The token is expired',
    ];
    yield 'wrong issuer' => [
        access_token(claims: ['iss' => 'https://evil.example']),
        'The token was not issued by the given issuers',
    ];
    yield 'wrong audience' => [
        access_token(claims: ['aud' => 'https://other.example/.well-known/mercure']),
        'The token is not allowed to be used by this audience',
    ];
});

it('accepts the application/at+jwt type, case-insensitively', function () {
    $token = null;
    $app = token_app(access_token_policy(), $token);
    $jwt = create_jwt(
        ['iss' => ISSUER, 'aud' => RESOURCE_IDENTIFIER, 'exp' => new DateTimeImmutable('+1 hour')],
        ['typ' => 'Application/AT+JWT'],
    );

    handle($app, new ServerRequest('GET', '/', ['Authorization' => "Bearer $jwt"]));

    expect($token)->toBeInstanceOf(Token::class);
});

it('accepts any issuer when none is configured', function () {
    $token = null;
    $app = token_app(new AccessTokenPolicy(resourceIdentifier: RESOURCE_IDENTIFIER), $token);
    $jwt = access_token(claims: ['iss' => 'https://whoever.example']);

    handle($app, new ServerRequest('GET', '/', ['Authorization' => "Bearer $jwt"]));

    expect($token)->toBeInstanceOf(Token::class);
});

it('accepts any signed token in compatibility mode', function () {
    $token = null;
    $app = token_app(access_token_policy(legacy: true), $token);
    $jwt = create_jwt(['mercure' => ['publish' => ['*']]]);

    handle($app, new ServerRequest('GET', '/', ['Authorization' => "Bearer $jwt"]));

    expect($token)->toBeInstanceOf(Token::class);
});

it('derives the resource identifier from the request', function (ServerRequest $request, string $expected) {
    expect((new AccessTokenPolicy())->resourceIdentifierFor($request))->toBe($expected);
})->with(function () {
    yield 'host' => [
        new ServerRequest('GET', 'http://example.com:8080/.well-known/mercure'),
        'http://example.com:8080/.well-known/mercure',
    ];
    yield 'forwarded' => [
        new ServerRequest('GET', 'http://127.0.0.1:8080/.well-known/mercure', [
            'X-Forwarded-Proto' => 'https, http',
            'X-Forwarded-Host' => 'example.com',
        ]),
        'https://example.com/.well-known/mercure',
    ];
    yield 'no scheme' => [
        new ServerRequest('GET', '/.well-known/mercure', ['Host' => 'example.com']),
        'http://example.com/.well-known/mercure',
    ];
});

it('uses the configured resource identifier', function () {
    $policy = new AccessTokenPolicy(resourceIdentifier: RESOURCE_IDENTIFIER);
    $request = new ServerRequest('GET', 'http://example.com/.well-known/mercure');

    expect($policy->resourceIdentifierFor($request))->toBe(RESOURCE_IDENTIFIER);
});

it('ignores the cookie when an Authorization header is present', function (string $header, int $expectedStatus) {
    $token = null;
    $app = token_app(access_token_policy(), $token);
    $request = new ServerRequest('GET', '/', [
        'Authorization' => $header,
        'Cookie' => '__Secure-mercure_access_token=' . access_token(),
    ]);

    $response = handle($app, $request);

    expect($response->getStatusCode())->toBe($expectedStatus)
        ->and($token)->toBeNull();
})->with([
    'invalid token' => ['Bearer invalid', 401],
    'invalid token, lowercase scheme' => ['bearer invalid', 401],
    'other scheme' => ['Basic Zm9vOmJhcg==', 400],
]);
