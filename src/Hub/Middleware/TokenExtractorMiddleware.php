<?php

declare(strict_types=1);

namespace Freddie\Hub\Middleware;

use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Freddie\Security\JWT\Extractor\PSR7TokenExtractorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class TokenExtractorMiddleware
{
    public function __construct(
        private JWTEncoderInterface $JWTEncoder,
        private PSR7TokenExtractorInterface $tokenExtractor = new ChainTokenExtractor(),
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $token = $this->tokenExtractor->extract($request);

        return $next($this->withToken($request, $token));
    }

    private function withToken(ServerRequestInterface $request, ?string $token): ServerRequestInterface
    {
        if (null === $token) {
            return $request;
        }

        try {
            $jwt = $this->JWTEncoder->decode($token);
        } catch (JWTDecodeFailureException $e) {
            throw new AccessDeniedHttpException($e->getMessage());
        }

        return $request->withAttribute('token', $jwt);
    }
}
