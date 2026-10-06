<?php

declare(strict_types=1);

namespace Freddie\Hub\Middleware;

use Freddie\Security\BearerTokenException;
use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Freddie\Security\JWT\Extractor\ChainTokenExtractor;
use Freddie\Security\JWT\Extractor\PSR7TokenExtractorInterface;
use Freddie\Security\JWT\Validation\AccessTokenPolicy;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validator;
use Lcobucci\JWT\Validation\Validator as DefaultValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TokenExtractorMiddleware
{
    public function __construct(
        private Parser $parser = new Parser(new JoseEncoder()),
        private Validator $validator = new DefaultValidator(),
        private ValidationConstraints $validationConstraints = new ValidationConstraints([]),
        private PSR7TokenExtractorInterface $tokenExtractor = new ChainTokenExtractor(),
        private AccessTokenPolicy $accessTokenPolicy = new AccessTokenPolicy(),
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $token = $this->tokenExtractor->extract($request);

        return $next($this->withToken($request, $token));
    }

    private function withToken(ServerRequestInterface $request, ?string $token): ServerRequestInterface
    {
        if (empty($token)) {
            return $request;
        }

        try {
            $jwt = $this->parser->parse($token);
            $this->validator->assert(
                $jwt,
                ...$this->validationConstraints->constraints,
                ...$this->accessTokenPolicy->constraintsFor($request),
            );
        } catch (Exception $e) {
            throw BearerTokenException::invalidToken($e->getMessage());
        }

        return $request->withAttribute('token', $jwt);
    }
}
