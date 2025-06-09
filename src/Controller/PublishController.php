<?php

declare(strict_types=1);

namespace Freddie\Controller;

use BenTools\QueryString\Parser\FlatParser;
use Freddie\Hub\HubInterface;
use Freddie\Message\Message;
use Freddie\Message\Update;
use Freddie\Security\JWT\TokenHydrator;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;
use Throwable;

use function BenTools\QueryString\query_string;
use function Freddie\is_truthy;
use function Freddie\nullify;
use function Freddie\urn;

#[AsController]
final readonly class PublishController
{
    public function __construct(
        private HubInterface $hub,
        private TokenHydrator $tokenHydrator = new TokenHydrator(),
    ) {
    }

    #[Route('/.well-known/mercure', name: 'freddie.publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        $input = query_string((string) $request->getContent(), new FlatParser());
        if (!$input->hasParam('topic')) {
            throw new BadRequestHttpException('Missing topic parameter.');
        }

        $providedId = nullify($input->getParam('id'), 'string');

        $id = match (true) {
            null === $providedId => new Ulid(),
            Ulid::isValid($providedId) => new Ulid($providedId),
            default => throw new BadRequestHttpException('Invalid ID.'),
        };

        $message = new Message(
            id: $id,
            data: nullify($input->getParam('data'), 'string'),
            private: is_truthy($input->getParam('private')),
            event: nullify($input->getParam('event'), 'string'),
            retry: nullify($input->getParam('retry'), 'int'),
        );
        $update = new Update((array) $input->getParam('topic'), $message);

        $allowedTopics = $this->extractAllowedTopics($request);
        if (!$update->canBePublished($allowedTopics)) {
            throw new AccessDeniedHttpException('Your rights are not sufficient to publish this update.');
        }

        try {
            $this->hub->publish($update);
        } catch (Throwable) {
            throw new ServiceUnavailableHttpException();
        }

        return new Response(urn($id), Response::HTTP_CREATED);
    }

    /**
     * @param Request $request
     * @return string[]
     */
    private function extractAllowedTopics(Request $request): array
    {
        try {
            /** @var UnencryptedToken $jwt */
            $jwt = $this->tokenHydrator->getToken($request)
                ?? throw new RequiredConstraintsViolated('You must be authenticated to publish on this hub.');

            return $jwt->claims()->get('mercure')['publish']
                ?? throw new RequiredConstraintsViolated('Missing mercure.publish claim.');
        } catch (RequiredConstraintsViolated $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
    }
}
