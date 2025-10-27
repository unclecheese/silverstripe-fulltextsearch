<?php

namespace SilverStripe\FullTextSearch\Solr\Tasks;

use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\FullTextSearch\Search\Variants\SearchVariant;
use SilverStripe\ORM\DataList;
use SilverStripe\FullTextSearch\Solr\Reindex\Handlers\SolrReindexHandler;
use SilverStripe\FullTextSearch\Solr\SolrIndex;

/**
 * Task used for both initiating a new reindex, as well as for processing incremental batches
 * within a reindex.
 *
 * When running a complete reindex you can provide any of the following
 *  - class (to limit to a single class)
 *  - verbose (optional)
 *
 * When running with a single batch, provide the following arguments:
 *  - index
 *  - class
 *  - variantstate
 *  - verbose (optional)
 */
class Solr_Reindex extends Solr_BuildTask
{
    private static $segment = 'Solr_Reindex';

    protected $enabled = true;

    /**
     * Number of records to load and index per request
     *
     * @var int
     * @config
     */
    private static $recordsPerRequest = 200;

    /**
     * Configure the command
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('solr:reindex')
            ->setDescription('Reindex Solr indexes')
            ->addOption('class', 'c', InputOption::VALUE_OPTIONAL, 'Class to limit reindexing to')
            ->addOption('index', 'i', InputOption::VALUE_OPTIONAL, 'Index to reindex')
            ->addOption('variantstate', 'v', InputOption::VALUE_OPTIONAL, 'Variant state for reindexing')
            ->addOption('groups', 'g', InputOption::VALUE_OPTIONAL, 'Number of groups for batch processing')
            ->addOption('group', 'r', InputOption::VALUE_OPTIONAL, 'Group number for batch processing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->run($input, $output);
    }

    /**
     * Get the reindex handler
     *
     * @return SolrReindexHandler
     */
    protected function getHandler()
    {
        return Injector::inst()->get(SolrReindexHandler::class);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        parent::run($input, $output);

        $this->extend('updateBeforeSolrReindexTask', $input, $output);

        // Reset state
        $originalState = SearchVariant::current_state();
        $this->doReindex($input);
        SearchVariant::activate_state($originalState);

        $this->extend('updateAfterSolrReindexTask', $input, $output);
        
        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     */
    protected function doReindex(InputInterface $input)
    {
        $class = $input->getOption('class');
        $index = $input->getOption('index');

        //find the index classname by IndexName
        // this is for when index names do not match the class name (this can be done by overloading getIndexName() on
        // indexes
        if ($index && !ClassInfo::exists($index)) {
            foreach (ClassInfo::subclassesFor(SolrIndex::class) as $solrIndexClass) {
                $reflection = new ReflectionClass($solrIndexClass);
                //skip over abstract classes
                if (!$reflection->isInstantiable()) {
                    continue;
                }
                //check the indexname matches the index passed to the request
                if (!strcasecmp(singleton($solrIndexClass)->getIndexName() ?? '', $index ?? '')) {
                    //if we match, set the correct index name and move on
                    $index = $solrIndexClass;
                    break;
                }
            }
        }

        // Check if we are re-indexing a single group
        // If not using queuedjobs, we need to invoke Solr_Reindex as a separate process
        // Otherwise each group is processed via a SolrReindexGroupJob
        $groups = $input->getOption('groups');

        $handler = $this->getHandler();
        if ($groups) {
            // Run grouped batches (id % groups = group)
            $group = $input->getOption('group');
            $indexInstance = singleton($index);
            $state = json_decode($input->getOption('variantstate') ?? '', true);

            $handler->runGroup($this->getLogger(), $indexInstance, $state, $class, $groups, $group);
            return;
        }

        // If run at the top level, delegate to appropriate handler
        $taskName = $this->config()->segment ?: get_class($this);
        $handler->triggerReindex($this->getLogger(), $this->config()->recordsPerRequest, $taskName, $class);
    }
}
