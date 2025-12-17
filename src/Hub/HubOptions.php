<?php

declare(strict_types=1);

namespace Freddie\Hub;

final readonly class HubOptions
{
    public const string ALLOW_ANONYMOUS = 'allow_anonymous';
    public const string SUBSCRIPTIONS = 'subscriptions';
    public const array DEFAULTS = [
        self::ALLOW_ANONYMOUS => true,
        self::SUBSCRIPTIONS => true,
    ];
}
