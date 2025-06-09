<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Extractor;

use Freddie\Security\JWT\Extractor\CookieTokenExtractor;

use function Freddie\Tests\createSfRequest;

it('extracts token from cookies', function () {
    $validToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30._esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';

    $extractor = new CookieTokenExtractor();
    $request = createSfRequest('GET', '/.well-known/mercure', [
    ], ['mercureAuthorization' => $validToken]);
    expect($extractor->extract($request))->toBe($validToken);
});

it('is compliant with the split-cookie strategy', function () {
    $jwt_hp = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30';
    $jwt_s = '_esyynAyo2Z6PyGe0mM_SuQ3c-C7sMQJ1YxVLvlj80A';
    $validToken = $jwt_hp . '.' . $jwt_s;

    $extractor = new CookieTokenExtractor(['jwt_hp', 'jwt_s']);
    $request = createSfRequest('GET', '/.well-known/mercure', [
    ], ['jwt_hp' => $jwt_hp, 'jwt_s' => $jwt_s]);
    expect($extractor->extract($request))->toBe($validToken);
});
