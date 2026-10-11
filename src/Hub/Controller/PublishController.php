<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use Fig\Http\Message\StatusCodeInterface;
use Freddie\Helper\FlatQueryParser;
use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Matcher\TopicMatcher;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\BearerTokenException;
use Freddie\Security\Grants;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\Timer\TimeoutException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Uid\Ulid;
use Throwable;

use function array_key_exists;
use function array_map;
use function count;
use function ctype_digit;
use function Freddie\is_truthy;
use function Freddie\is_valid_protocol_string;
use function Freddie\nullify;
use function is_string;
use function mb_check_encoding;
use function React\Async\await;
use function sprintf;
use function str_starts_with;
use function strlen;

final class PublishController implements HubControllerInterface
{
    public const int MAX_TOPICS = 1000;
    public const int MAX_EVENT_ID_LENGTH = 1024;

    private HubInterface $hub;

    public function setHub(HubInterface $hub): self
    {
        $this->hub = $hub;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getMethods(): array
    {
        return ['POST'];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getRoute(): string
    {
        return '/.well-known/mercure';
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $legacy = $this->hub->isLegacyProtocol();
        $store = $this->hub->getTopicMatcherStore();
        $grants = Grants::fromRequest($request, $store, $legacy)
            ?? throw BearerTokenException::missingToken('You must be authenticated to publish on this hub.');

        $update = $this->createUpdate((new FlatQueryParser())->parse((string) $request->getBody()), $legacy);
        $this->validate($update, $store);

        if (!$this->canPublish($grants, $update, $legacy)) {
            throw BearerTokenException::insufficientScope('Your rights are not sufficient to publish this update.');
        }

        try {
            await($this->hub->publish($update));
        } catch (TimeoutException) {
            throw new HttpException(StatusCodeInterface::STATUS_GATEWAY_TIMEOUT);
        } catch (Throwable) {
            throw new ServiceUnavailableHttpException();
        }

        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $update->message->id);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createUpdate(array $params, bool $legacy): Update
    {
        $topics = (array) ($params['topic'] ?? throw new BadRequestHttpException('Missing "topic" parameter.'));
        if (count($topics) > self::MAX_TOPICS) {
            throw new BadRequestHttpException(sprintf('Too many topics (max %d).', self::MAX_TOPICS));
        }

        $retry = nullify($params['retry'] ?? null, 'string');
        if (null !== $retry && !ctype_digit($retry)) {
            throw new BadRequestHttpException('Invalid "retry" parameter.');
        }

        // Protocol version 8 clients of Freddie used to send the event type as "event".
        $type = $params['type'] ?? ($legacy ? $params['event'] ?? null : null);

        return new Update(
            array_map(strval(...), $topics),
            new Message(
                id: nullify($params['id'] ?? null, 'string') ?? Ulid::generate(),
                data: nullify($params['data'] ?? null, 'string'),
                // The presence of the field marks the update as private, regardless of its value.
                private: $legacy ? is_truthy($params['private'] ?? null) : array_key_exists('private', $params),
                event: nullify($type, 'string'),
                retry: null === $retry ? null : (int) $retry,
            ),
        );
    }

    private function validate(Update $update, TopicMatcherStore $store): void
    {
        foreach ($update->topics as $topic) {
            if (!is_valid_protocol_string($topic) || strlen($topic) > TopicMatcherStore::MAX_PATTERN_LENGTH) {
                throw new BadRequestHttpException(sprintf('Invalid topic "%s".', $topic));
            }

            if (TopicMatcher::WILDCARD === $topic) {
                throw new BadRequestHttpException('The "*" topic is reserved for the wildcard matcher.');
            }

            if ($store->addressesReservedNamespace($topic)) {
                throw new BadRequestHttpException(
                    sprintf('The "%s" topic resolves into the reserved "/.well-known/mercure" namespace.', $topic),
                );
            }
        }

        $message = $update->message;
        if (
            !is_valid_protocol_string($message->id)
            || strlen($message->id) > self::MAX_EVENT_ID_LENGTH
            || str_starts_with($message->id, '#')
            || TransportInterface::EARLIEST === $message->id
        ) {
            throw new BadRequestHttpException('Invalid "id" parameter.');
        }

        if (null !== $message->event && !is_valid_protocol_string($message->event)) {
            throw new BadRequestHttpException('Invalid "type" parameter.');
        }

        if (SubscribeController::RESERVED_EVENT_TYPE === $message->event) {
            throw new BadRequestHttpException(
                sprintf('The "%s" type is reserved to the hub.', SubscribeController::RESERVED_EVENT_TYPE),
            );
        }

        if (is_string($message->data) && !mb_check_encoding($message->data, 'UTF-8')) {
            throw new BadRequestHttpException('The "data" parameter is not valid UTF-8.');
        }
    }

    /**
     * The publish action is required on every topic of the update. Freddie used to only check private
     * updates, which compatibility mode preserves (provided the token has a mercure.publish claim).
     */
    private function canPublish(Grants $grants, Update $update, bool $legacy): bool
    {
        if ($legacy && !$update->message->private && $grants->hasLegacyPublishClaim()) {
            return true;
        }

        return $grants->canPublish($update->topics);
    }
}
