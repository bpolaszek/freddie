<?php

namespace Freddie\Transport\Redis;

use BenTools\ReflectionPlus\Reflection;
use Nyholm\Dsn\DsnParser;
use Redis;

use function array_filter;
use function array_values;

final readonly class LazyRedis
{
    private const int DEFAULT_PORT = 6379;

    private function __construct(
        public Redis $redis,
    ) {
    }

    public static function factory(Redis $redis, string $dsn = 'redis://localhost:6379'): LazyRedis
    {
        $parsed = DsnParser::parse($dsn);
        /** @var LazyRedis $lazyRedis */
        $lazyRedis = Reflection::class(__CLASS__)->newLazyProxy(function () use ($redis, $parsed) {
            $lazyRedis = new LazyRedis($redis);
            $lazyRedis->redis->connect($parsed->getHost(), $parsed->getPort() ?? self::DEFAULT_PORT);
            $credentials = [$parsed->getUser(), $parsed->getPassword()];
            $credentials = array_values(array_filter($credentials, fn (mixed $value) => null !== $value));
            if ($credentials) {
                $lazyRedis->redis->auth($credentials);
            }

            return $lazyRedis;
        });

        return $lazyRedis;
    }
}
