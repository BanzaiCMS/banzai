<?php
declare(strict_types=1);

namespace Banzai\Search;

use Flux\Console\Command\Command;
use Flux\Console\Command\CommandInterface;
use Flux\Logger\LoggerInterface;


class ElasticConsoleSearch extends Command implements CommandInterface
{
    public function __construct(protected LoggerInterface $logger, protected ElasticService $elastic)
    {
    }

    public function configure(): void
    {
        $this->addArgument('Query', self::ARGUMENT_REQUIRED, 'Search Query string');
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

        if (!$this->verifyInput())
            return 1;

        $query = $this->getArgumentValue('query');

        $response = $this->elastic->query($query, 10);

        if (empty($response))
            $this->writeln('no data found');
        else
            $this->writeln($this->elastic->processQueryResponse($response));

        return 0;
    }
}
