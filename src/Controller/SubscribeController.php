<?php

declare(strict_types=1);

namespace Freddie\Controller;

use BenTools\QueryString\Parser\FlatParser;
use BenTools\QueryString\Parser\QueryStringParserInterface;
use Freddie\Hub\HubInterface;
use Freddie\Security\JWT\TokenHydrator;
use Freddie\SSE\ServerEventFactory;
use Freddie\SSE\ServerEventFactoryInterface;
use Freddie\Subscription\Subscriber;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function BenTools\QueryString\query_string;
use function BenTools\UriFactory\Helper\uri;
use function flush;
use function set_time_limit;

#[AsController]
final readonly class SubscribeController
{
    public function __construct(
        private HubInterface $hub,
        private QueryStringParserInterface $queryStringParser = new FlatParser(),
        private ServerEventFactoryInterface $factory = new ServerEventFactory(),
        private TokenHydrator $tokenHydrator = new TokenHydrator(),
        private bool $debug = false,
    ) {
    }

    #[Route('/.well-known/mercure', name: 'freddie.subscribe', methods: ['GET'])]
    public function subscribe(Request $request): StreamedResponse
    {
        $subscribedTopics = $this->extractSubscribedTopics($request);
        $allowedTopics = $this->extractAllowedTopics($request);
        $lastEventId = $this->extractLastEventID($request);
        $userPaylod = $this->extractUserPayload($request);
        $subscriber = new Subscriber($subscribedTopics, $allowedTopics, $lastEventId, $userPaylod);

        $response = new StreamedResponse(function () use ($subscriber) {
            set_time_limit(0);
            $this->hub->subscribe($subscriber);
            foreach ($this->hub->getUpdates($subscriber) as $update) {
                $eventStream = $this->factory->createServerEvent($update);
                foreach ($eventStream as $output) {
                    echo $output;
                }
                if (!$this->debug) {
                    StreamedResponse::closeOutputBuffers(0, true); // @codeCoverageIgnore
                }
                flush();

                if ($this->hub->isConnectionAborted()) {
                    $this->hub->unsubscribe($subscriber);

                    return;
                }
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @return string[]
     */
    private function extractSubscribedTopics(Request $request): array
    {
        $qs = query_string(uri($request->getRequestUri()), $this->queryStringParser);
        if (!$qs->hasParam('topic')) {
            throw new BadRequestHttpException('Missing topic parameter.');
        }

        return (array) $qs->getParam('topic');
    }

    /**
     * @return string[]|null
     */
    private function extractAllowedTopics(Request $request): ?array
    {
        try {
            /** @var UnencryptedToken|null $jwt */
            $jwt = $this->tokenHydrator->getToken($request);
            if (null === $jwt) {
                if (!$this->hub->options['allow_anonymous']) {
                    throw new RequiredConstraintsViolated('Anonymous subscriptions are not allowed on this hub.');
                }

                return null;
            }
        } catch (RequiredConstraintsViolated $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $jwt->claims()->get('mercure')['subscribe'] ?? null;
    }

    private function extractUserPayload(Request $request): mixed
    {
        /** @var UnencryptedToken|null $jwt */
        $jwt = $this->tokenHydrator->getToken($request);
        if (null === $jwt) {
            return null;
        }

        return $jwt->claims()->get('mercure')['payload'] ?? null;
    }

    private function extractLastEventID(Request $request): ?string
    {
        return $request->headers->get('Last-Event-ID')
            ?? $request->query->get('lastEventID');
    }
}
