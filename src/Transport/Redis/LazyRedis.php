<?php

namespace Freddie\Transport\Redis;

use BenTools\ReflectionPlus\Reflection;
use Nyholm\Dsn\Configuration\Dsn;
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

    public static function factory(?Dsn $parsedDsn = null, Redis $redis = new Redis()): LazyRedis
    {
        $parsedDsn ??= DsnParser::parse('redis://localhost:6379');
        /** @var LazyRedis $lazyRedis */
        $lazyRedis = Reflection::class(__CLASS__)->newLazyProxy(function () use ($redis, $parsedDsn) {
            $lazyRedis = new LazyRedis($redis);
            $lazyRedis->redis->connect($parsedDsn->getHost(), $parsedDsn->getPort() ?? self::DEFAULT_PORT);
            $credentials = [$parsedDsn->getUser(), $parsedDsn->getPassword()];
            $credentials = array_values(array_filter($credentials, fn (mixed $value) => null !== $value));
            if ($credentials) {
                $lazyRedis->redis->auth($credentials);
            }

            return $lazyRedis;
        });

        return $lazyRedis;
    }
}
