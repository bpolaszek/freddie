<?php

declare(strict_types=1);

namespace BenTools\MercurePHP\Tests\Unit\Command;

use BenTools\MercurePHP\Command\ServeCommand;
use BenTools\MercurePHP\Hub\Hub;
use React\EventLoop\Loop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

it('works', function () {
    Loop::futureTick(fn () => Loop::stop());
    $params = new ParameterBag(['transport_dsn' => 'php://localhost?size=10000']);
    $command = new ServeCommand(new Hub(), $params);
    $commandTester = new CommandTester($command);
    $commandTester->execute([]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
});
