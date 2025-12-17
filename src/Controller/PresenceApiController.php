<?php

declare(strict_types=1);

namespace Freddie\Controller;

use Freddie\Hub\HubInterface;
use Freddie\Security\JWT\TokenHydrator;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function Freddie\topic;
use function Freddie\urn;
use function urlencode;

#[AsController]
final readonly class PresenceApiController
{
    public function __construct(
        private HubInterface $hub,
        private TokenHydrator $tokenHydrator = new TokenHydrator(),
    ) {
    }

    #[Route(
        path: '/.well-known/mercure/subscriptions/{topic}',
        name: 'freddie.list_subscriptions',
        requirements: ['topic' => '.+'],
        methods: ['GET'],
    )]
    public function getSubscriptions(Request $request, ?string $topic = null): Response
    {
        $this->checkAuthorization($request);

        $id = $topic ? '/.well-known/mercure/subscriptions/' . urlencode($topic) : '/.well-known/mercure/subscriptions';
        $lastEventId = $this->hub->getLastEventId();

        return new StreamedJsonResponse([
            '@context' => 'https://mercure.rocks/',
            'id' => $id,
            'type' => 'Subscriptions',
            'lastEventID' => $lastEventId ? urn($lastEventId) : 'earliest',
            'subscriptions' => $this->hub->getSubscriptions($topic),
        ], headers: [
            'Content-Type' => 'application/ld+json',
            'ETag' => $lastEventId ? urn($lastEventId) : 'earliest',
            'Cache-Control' => 'must-revalidate',
        ]);
    }

    #[Route(
        path: '/.well-known/mercure/subscriptions/{topic}/{subscriber}',
        name: 'freddie.get_subscription',
        methods: ['GET'],
    )]
    public function getSubscription(Request $request): Response
    {
        $this->checkAuthorization($request);

        $lastEventId = $this->hub->getLastEventId();
        $subscription = $this->hub->getSubscription($request->getPathInfo())
            ?? throw new NotFoundHttpException();

        return new JsonResponse([
            '@context' => 'https://mercure.rocks/',
            ...$subscription->jsonSerialize(),
            'lastEventID' => $lastEventId ? urn($lastEventId) : 'earliest',
        ], headers: [
            'Content-Type' => 'application/ld+json',
            'ETag' => $lastEventId ? urn($lastEventId) : 'earliest',
            'Cache-Control' => 'must-revalidate',
        ]);
    }

    private function checkAuthorization(Request $request): void
    {
        if (!$this->hub->options['subscriptions']) {
            throw new BadRequestHttpException("Subscriptions are not enabled on this hub.");
        }

        $allowedTopics = $this->extractAllowedTopics($request);
        if (topic($request->getPathInfo())->match($allowedTopics)) {
            return;
        }

        throw new AccessDeniedHttpException("Your rights are not sufficient to access subscriptions.");
    }

    /**
     * @return string[]
     */
    private function extractAllowedTopics(Request $request): array
    {
        try {
            /** @var UnencryptedToken $jwt */
            $jwt = $this->tokenHydrator->getToken($request)
                ?? throw new RequiredConstraintsViolated('You must provide a valid JWT to access this endpoint.');

            return $jwt->claims()->get('mercure')['subscribe']
                ?? throw new RequiredConstraintsViolated('Missing mercure.subscribe claim.');
        } catch (RequiredConstraintsViolated $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
    }
}
