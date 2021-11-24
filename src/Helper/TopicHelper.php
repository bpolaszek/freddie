<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Helper;

use Rize\UriTemplate\UriTemplate;

use function in_array;

final class TopicHelper
{
    private string $topic;
    private static self $instance;

    public function __construct(
        private UriTemplate $uriTemplate,
    ) {
    }

    /**
     * @param array<string> $topics
     */
    public function match(array $topics): bool
    {
        if (in_array($this->topic, $topics, true)) {
            return true;
        }

        if (in_array('*', $topics, true)) {
            return true;
        }

        foreach ($topics as $topic) {
            if ($this->matchUriTemplate($topic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $allowedTopics
     */
    public function canBeSubscribed(?array $allowedTopics, bool $allowAnonymous): bool
    {
        if (true === $allowAnonymous) {
            return true;
        }

        return $this->match($allowedTopics ?? []);
    }

    private function matchUriTemplate(string $template): bool
    {
        return str_contains($template, '{')
            && null !== $this->uriTemplate->extract($template, $this->topic, true);
    }

    public function with(string $topic): self
    {
        $clone = clone $this;
        $clone->topic = $topic;

        return $clone;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(new UriTemplate());
    }
}
