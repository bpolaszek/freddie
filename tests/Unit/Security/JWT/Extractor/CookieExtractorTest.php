<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\CookieTokenExtractor;
use React\Http\Message\ServerRequest;

it('extracts token from cookies', function () {
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    $extractor = new CookieTokenExtractor();
    $request = new ServerRequest('GET', '/.well-known/mercure', [
        'Cookie' => 'foo=bar; mercureAuthorization=' . $validToken . '; bar=foo',
    ]);
    expect($extractor->extract($request))->toBe($validToken);
});

it('is compliant with the split-cookie strategy', function () {
    $jwt_hp = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30';
    $jwt_s = '_esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';
    $validToken = $jwt_hp . '.' . $jwt_s;

    $extractor = new CookieTokenExtractor(['jwt_hp', 'jwt_s']);
    $request = new ServerRequest('GET', '/.well-known/mercure', [
        'Cookie' => 'foo=bar; jwt_hp=' . $jwt_hp . '; bar=foo; jwt_s=' . $jwt_s,
    ]);
    expect($extractor->extract($request))->toBe($validToken);
});
