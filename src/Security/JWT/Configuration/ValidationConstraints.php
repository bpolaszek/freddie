<?php

declare(strict_types=1);

namespace Freddie\Security\JWT\Configuration;

use IteratorAggregate;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint;
use Traversable;

/**
 * @implements IteratorAggregate<Constraint>
 */
final class ValidationConstraints implements IteratorAggregate
{
    /**
     * @var iterable<Constraint>
     * @codingStandardsIgnoreStart
     */
    private iterable $validationConstraints {
        get {
            if (!$this->resolved) {
                $this->validationConstraints = [
                    new class implements Constraint {
                        public function assert(Token $token): void
                        {
                        }
                    },
                    ...$this->validationConstraints,
                ];
                $this->resolved = true;
            }

            return $this->validationConstraints;
        }
    }
    // @codingStandardsIgnoreEnd

    private bool $resolved = false;

    /**
     * @param iterable<Constraint> $validationConstraints
     */
    public function __construct(
        iterable $validationConstraints = [
            new Constraint\HasClaim('mercure')
        ],
    ) {
        $this->validationConstraints = $validationConstraints;
    }


    public function getIterator(): Traversable
    {
        yield from $this->validationConstraints;
    }
}
