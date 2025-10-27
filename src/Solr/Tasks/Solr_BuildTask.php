<?php
namespace SilverStripe\FullTextSearch\Solr\Tasks;

use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\FullTextSearch\Utils\Logging\SearchLogFactory;

/**
 * Abstract class for build tasks
 */
class Solr_BuildTask extends Command
{
    protected $enabled = false;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setName('solr:build')
            ->setDescription('Abstract Solr build task')
            ->addArgument('verbose', InputArgument::OPTIONAL, 'Enable verbose output', false);
    }

    /**
     * Get the monolog logger
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Assign a new logger
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function getLoggerFactory()
    {
        return Injector::inst()->get(SearchLogFactory::class);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->run($input, $output);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $name = get_class($this);
        $verbose = $input->getArgument('verbose') ?? false;

        // Set new logger
        $logger = $this
            ->getLoggerFactory()
            ->getOutputLogger($name, $verbose);
        $this->setLogger($logger);

        return Command::SUCCESS;    
    }
}
