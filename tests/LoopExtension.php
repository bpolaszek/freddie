<?php

declare(strict_types=1);

namespace Freddie\Tests;

use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;
use React\EventLoop\Factory;
use React\EventLoop\Loop;

final class LoopExtension implements BeforeTestHook, AfterTestHook
{
    public function executeBeforeTest(string $test): void
    {
        //Loop::set(Factory::create());
    }

    public function executeAfterTest(string $test, float $time): void
    {
        Loop::stop();
    }
}
