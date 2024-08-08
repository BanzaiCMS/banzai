<?php
declare(strict_types=1);

namespace Banzai\Search;

use Flux\Console\Command\Command;
use Flux\Console\Command\CommandInterface;
use Flux\Logger\LoggerInterface;


class ElasticConsoleIndex extends Command implements CommandInterface
{
    public function __construct(protected LoggerInterface $logger, protected ElasticService $elastic)
    {
    }

    public function configure(): void
    {
        $this->addOption('help', 'h', self::OPTION_IS_BOOL, 'show usage information');
    }

    public function showHelp(): void
    {
        echo $this->getUsage() . "\n";
    }

    public function execute(): int
    {

        if ($this->getOptionValue('help') === true) {
            $this->showHelp();
            return 0;
        }

        $this->elastic->indexAllArticles();

        return 0;
    }
}
