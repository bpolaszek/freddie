<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Security\JWT\Configuration;

use Freddie\Security\JWT\Configuration\ValidationConstraints;
use Lcobucci\JWT\Validation\Constraint\IdentifiedBy;

it('returns the validation constraints', function () {
    $constraint = new IdentifiedBy('foo');
    $validationConstraints = new ValidationConstraints((fn () => yield from [$constraint])());
    expect($validationConstraints->constraints)->toHaveCount(2);
    expect($validationConstraints->constraints)->toContain($constraint);
});
