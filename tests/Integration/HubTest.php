<?php

declare(strict_types=1);

namespace Freddie\Tests\Integration;

use Clue\React\EventSource\EventSource;
use Clue\React\EventSource\MessageEvent;
use React\EventLoop\Loop;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

use function Freddie\Tests\publish_and_receive;
use function dirname;
use function explode;
use function Freddie\Tests\access_token;
use function Freddie\Tests\create_jwt;
use function Freddie\Tests\detail;
use function sprintf;

use const Freddie\Tests\ISSUER;
use const Freddie\Tests\RESOURCE_IDENTIFIER;

it('works 🎉🥳', function () {
    $messages = publish_and_receive(
        ['JWT_ISSUER' => ISSUER, 'RESOURCE_IDENTIFIER' => RESOURCE_IDENTIFIER],
        'match=*',
        'topic=/foo&data=itworks',
        access_token([detail(['publish'], ['*'])]),
    );

    expect($messages[0] ?? null)->toBe('itworks');
});

it('still works with Mercure 0.x clients in compatibility mode 🧓', function () {
    $messages = publish_and_receive(
        ['PROTOCOL_COMPATIBILITY' => '8'],
        'topic=' . urlencode('/foo/{id}'),
        'topic=/foo/1&data=itstillworks',
        create_jwt(['mercure' => ['publish' => ['*']]]),
    );

    expect($messages[0] ?? null)->toBe('itstillworks');
});
