<?php

declare(strict_types=1);

namespace Freddie\Hub\Transport\Redis;

use Freddie\Message\Message;
use Freddie\Message\Update;
use UnexpectedValueException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Hand-rolled (de)serializer for the Redis transport.
 *
 * The wire shape is intentionally identical to the previous Symfony
 * ObjectNormalizer output so mixed hub versions stay interoperable during a
 * rolling deploy; only the reflection-heavy normalizer is dropped, since this
 * runs on every message on every worker.
 */
final readonly class RedisSerializer
{
    public function serialize(Update $update): string
    {
        $message = $update->message;

        return json_encode([
            'topics' => $update->topics,
            'message' => [
                'id' => $message->id,
                'data' => $message->data,
                'private' => $message->private,
                'event' => $message->event,
                'retry' => $message->retry,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $payload): Update
    {
        /** @var array{topics?: string[], message?: array{id?: string|null, data?: string|null, private?: bool, event?: string|null, retry?: int|null}} $data */
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        // Required keys throw (as the previous ObjectNormalizer did); optional
        // message fields fall back to their defaults (a missing id is regenerated).
        $topics = $data['topics'] ?? throw new UnexpectedValueException('Malformed Mercure update: missing "topics".');
        $message = $data['message'] ?? throw new UnexpectedValueException('Malformed Mercure update: missing "message".');

        return new Update(
            $topics,
            new Message(
                id: $message['id'] ?? null,
                data: $message['data'] ?? null,
                private: $message['private'] ?? false,
                event: $message['event'] ?? null,
                retry: $message['retry'] ?? null,
            ),
        );
    }
}
