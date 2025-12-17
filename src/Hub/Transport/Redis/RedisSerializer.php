<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Freddie\Message\Update;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class RedisSerializer
{
    public function __construct(
        private SerializerInterface $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
    ) {
    }

    public function serialize(Update $update): string
    {
        return $this->serializer->serialize($update, 'json');
    }

    public function deserialize(string $payload): Update
    {
        return $this->serializer->deserialize($payload, Update::class, 'json');
    }
}
