<?php

namespace Freddie\Tests\Mock;

use Redis;
use SensitiveParameter;

use function func_get_args;

class RedisStub extends Redis
{
    /**
     * @var array<mixed>
     */
    public private(set) array $streams = [];

    /**
     * @var array<string, mixed>
     */
    public private(set) array $storage = [];

    /**
     * @param array<string, mixed>|null $context
     */
    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null,
    ): false {
        return false;
    }

    public function auth(#[SensitiveParameter] mixed $credentials): false
    {
        return false;
    }

    /**
     * @param string[] $values
     */
    public function xadd(
        string $key,
        string $id,
        array $values,
        int $maxlen = 0,
        bool $approx = false,
        bool $nomkstream = false,
    ): false {
        $this->streams[$key][$id] = $values;

        return false;
    }

    /**
     * @param array<string, int> $streams
     * @return array<string, array<string, array<int, string>>>
     */
    public function xread(array $streams, int $count = -1, int $block = -1): array
    {
        return $this->streams;
    }

    /**
     * @param array<string, mixed> $fieldvals
     */
    public function hMset(string $key, array $fieldvals): bool
    {
        $this->storage[$key] = $fieldvals;

        return true;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function hGetAll(string $key): array|false
    {
        return $this->storage[$key] ?? false;
    }

    public function sAdd(string $key, mixed $value, ...$other_values): int
    {
        $this->storage[$key] ??= [];
        $allValues = [$value, ...$other_values];
        $this->storage[$key][] = $value;

        return count($allValues);
    }

    public function srem(string $key, mixed $value, ...$other_values): false
    {
        return false;
    }

    /**
     * @param string|string[] $key
     */
    public function del(array|string $key, string ...$other_keys): false
    {
        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $this->storage[$key] = $value;

        return true;
    }

    public function get(string $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function pipeline(): bool|Redis
    {
        return $this;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function sMembers(string $key): array|false
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        return $this->storage[$key];
    }
}
