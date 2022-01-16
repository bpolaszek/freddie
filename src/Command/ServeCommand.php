<?php

declare(strict_types=1);

namespace Freddie\Command;

use Freddie\Hub\Hub;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function sprintf;

#[AsCommand(
    name: 'freddie:serve',
    description: 'Start Freddie server, the PHP Mercure Hub.',
    aliases: ['serve'],
)]
final class ServeCommand extends Command
{
    public function __construct(
        private Hub $hub,
        private ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // @phpstan-ignore-next-line
        $output->writeln(sprintf('Using transport: %s.', $this->params->get('transport_dsn')));
        $this->hub->run();

        return self::SUCCESS;
    }
}
