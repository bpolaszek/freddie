<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Transport\Redis;

use BenTools\ReflectionPlus\Reflection;
use Freddie\Transport\Redis\LazyRedis;

use function expect;

it('creates a lazy object', function () {
    $lazyRedis = LazyRedis::factory();
    expect($lazyRedis)->toBeInstanceOf(LazyRedis::class)
        ->and(Reflection::class($lazyRedis)->isUninitializedLazyObject($lazyRedis))->toBeTrue();
});
