<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Hub\Transport\PHP;

use BenTools\MercurePHP\Hub\Transport\PHP\PHPTransport;
use BenTools\MercurePHP\Hub\Transport\PHP\PHPTransportFactory;

it('supports PHP DSNs', function (string $dsn, bool $expected) {
    $factory = new PHPTransportFactory();
    expect($factory->supports($dsn))->toBe($expected);
})->with(function () {
    yield ['php://whatever', true];
    yield ['notphp://shrug', false];
});

it('instantiates a PHP transport', function (string $dsn, PHPTransport $expected) {
    $factory = new PHPTransportFactory();
    expect($factory->create($dsn))->toEqual($expected);
})->with(function () {
    yield ['php://default', new PHPTransport()];
    yield ['php://default?size=10000', new PHPTransport(size: 10000)];
});
