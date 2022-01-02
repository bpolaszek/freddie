<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint;
use Traversable;

use function iterator_to_array;

final class ValidationConstraints
{
    public readonly array $constraints;

    public function __construct(
        iterable $validationConstraints,
    ) {
        $constraints = $validationConstraints instanceof Traversable ?
            iterator_to_array($validationConstraints)
            : (array) $validationConstraints;
        $this->constraints = [
            new class implements Constraint {
                public function assert(Token $token): void
                {
                }
            },
            ...$constraints,
        ];
    }


}
