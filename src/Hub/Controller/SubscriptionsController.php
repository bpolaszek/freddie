<?php

declare(strict_types=1);

namespace Freddie\Hub\Controller;

use Freddie\Hub\HubControllerInterface;
use Freddie\Hub\HubInterface;
use Freddie\Hub\Transport\TransportInterface;
use Freddie\Matcher\MatcherType;
use Freddie\Matcher\TopicMatcherStore;
use Freddie\Security\BearerTokenException;
use Freddie\Security\Grants;
use Freddie\Subscription\Subscription;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function addcslashes;
use function json_encode;
use function rawurlencode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The subscription API: lists the active subscriptions of the subscribers connected to this process.
 */
final class SubscriptionsController implements HubControllerInterface
{
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
        return ['GET'];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getRoute(): string
    {
        return Subscription::PATH . '[/{match_type}[/{match}[/{subscriber}]]]';
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hub->getOption('subscriptions')) {
            throw new NotFoundHttpException('The subscription API is disabled.');
        }

        [$matchType, $match, $subscriberId] = $this->extractFilter($request);

        // The subscription resource is identified by its path, so the query string must not change
        // whether a subscribe grant matches.
        $path = $request->getUri()->getPath();
        $grants = Grants::fromRequest($request, $this->hub->getTopicMatcherStore(), $this->hub->isLegacyProtocol())
            ?? throw BearerTokenException::missingToken('You must be authenticated to use the subscription API.');
        if (!$grants->canSubscribe([$path])) {
            throw BearerTokenException::insufficientScope('Your rights are not sufficient to access this resource.');
        }

        $subscriptions = [];
        foreach ($this->hub->getSubscribers() as $subscriber) {
            if (null !== $subscriberId && $subscriber->id !== $subscriberId) {
                continue;
            }

            foreach ($subscriber->subscriptions as $subscription) {
                if (
                    (null === $matchType || $subscription->matcher->type === $matchType)
                    && (null === $match || $subscription->matcher->pattern === $match)
                ) {
                    $subscriptions[] = $subscription->toArray(true);
                }
            }
        }

        if (null !== $subscriberId) {
            $document = $subscriptions[0] ?? throw new NotFoundHttpException('Subscription not found.');
        } else {
            $document = ['id' => $path, 'type' => 'subscriptions', 'subscriptions' => $subscriptions];
        }

        $lastEventId = $this->getLastEventId();
        $etag = '"' . rawurlencode($lastEventId) . '"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, must-revalidate',
            'Vary' => 'Authorization, Cookie',
        ];
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return new Response(304, $headers);
        }

        $headers['Content-Type'] = 'application/json';
        // The reconciliation cursor subscribers pass back as the last_event_id query parameter.
        $headers['Link'] = '<' . TopicMatcherStore::RESERVED_PATH . '>; rel="mercure"'
            . '; last-event-id="' . addcslashes($lastEventId, '"\\') . '"'
            . '; type="' . SubscribeController::RESERVED_EVENT_TYPE . '"'
            . '; content-type="application/json"';

        return new Response(
            200,
            $headers,
            json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array{?MatcherType, ?string, ?string}
     */
    private function extractFilter(ServerRequestInterface $request): array
    {
        // Route attributes are already URL-decoded by the router.
        $attribute = fn (string $name): ?string => null === $request->getAttribute($name)
            ? null
            : (string) $request->getAttribute($name);

        $matchType = $attribute('match_type');
        $type = null === $matchType ? null : MatcherType::fromWire($matchType);
        if (null !== $matchType && null === $type) {
            throw new BadRequestHttpException('Unsupported topic matcher type.');
        }

        return [$type, $attribute('match'), $attribute('subscriber')];
    }

    private function getLastEventId(): string
    {
        $lastEventId = TransportInterface::EARLIEST;
        foreach ($this->hub->reconciliate(TransportInterface::EARLIEST) as $update) {
            $lastEventId = $update->message->id;
        }

        return $lastEventId;
    }
}
