<?php
namespace Flownative\NodeRepair\InconsistentIdentifiers\Command;

/*
 * This file is part of the Flownative.NodeRepair.InconsistentIdentifiers package.
 *
 * (c) Flownative GmbH - www.flownative.com
 */

use Doctrine\ORM\QueryBuilder;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\ConsoleOutput;
use TYPO3\Flow\Persistence\PersistenceManagerInterface;
use TYPO3\Flow\Property\PropertyMapper;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\TYPO3CR\Command\NodeCommandControllerPluginInterface;
use TYPO3\TYPO3CR\Domain\Factory\NodeFactory;
use TYPO3\TYPO3CR\Domain\Model\NodeType;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;
use TYPO3\TYPO3CR\Domain\Service\ContentDimensionCombinator;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;

/**
 * Plugin for the TYPO3CR NodeCommandController which provides additional functionality for node:repair
 *
 * @Flow\Scope("singleton")
 */
class NodeCommandControllerPlugin implements NodeCommandControllerPluginInterface
{
    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @var ConsoleOutput
     */
    protected $output;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * Doctrine's Entity Manager. Note that "ObjectManager" is the name of the related
     * interface ...
     *
     * @Flow\Inject
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @var array
     */
    protected $pluginConfigurations = array();

    /**
     * @var ContentDimensionCombinator
     * @Flow\Inject
     */
    protected $contentDimensionCombinator;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Returns a short description
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @return string A piece of text to be included in the overall description of the node:xy command
     */
    public static function getSubCommandShortDescription($controllerCommandName)
    {
        switch ($controllerCommandName) {
            case 'repair':
                return 'Run checks for basic node integrity in the content repository';
        }
    }

    /**
     * Returns a piece of description for the specific task the plugin solves for the specified command
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @return string A piece of text to be included in the overall description of the node:xy command
     */
    public static function getSubCommandDescription($controllerCommandName)
    {
        switch ($controllerCommandName) {
            case 'repair':
                return <<<'HELPTEXT'
<u>Repair inconsistent node identifiers</u>
fixNodesWithInconsistentIdentifier

Will check for and optionally repair node identifiers which are out of sync with their
corresponding nodes in a live workspace.

HELPTEXT;
        }
    }

    /**
     * A method which runs the task implemented by the plugin for the given command
     *
     * @param string $controllerCommandName Name of the command in question, for example "repair"
     * @param ConsoleOutput $output An instance of ConsoleOutput which can be used for output or dialogues
     * @param NodeType $nodeType Only handle this node type (if specified)
     * @param string $workspaceName Only handle this workspace (if specified)
     * @param boolean $dryRun If TRUE, don't do any changes, just simulate what you would do
     * @param boolean $cleanup If FALSE, cleanup tasks are skipped
     * @param string $skip Skip the given check or checks (comma separated)
     * @param string $only Only execute the given check or checks (comma separated)
     * @return void
     */
    public function invokeSubCommand($controllerCommandName, ConsoleOutput $output, NodeType $nodeType = null, $workspaceName = 'live', $dryRun = false, $cleanup = true, $skip = null, $only = null)
    {
        $this->output = $output;
        $commandMethods = [
            'fixNodesWithInconsistentIdentifier' => [ 'cleanup' => true ]
        ];
        $skipCommandNames = Arrays::trimExplode(',', ($skip === null ? '' : $skip));
        $onlyCommandNames = Arrays::trimExplode(',', ($only === null ? '' : $only));

        switch ($controllerCommandName) {
            case 'repair':
                foreach ($commandMethods as $commandMethodName => $commandMethodConfiguration) {
                    if (in_array($commandMethodName, $skipCommandNames)) {
                        continue;
                    }
                    if ($onlyCommandNames !== [] && !in_array($commandMethodName, $onlyCommandNames)) {
                        continue;
                    }
                    if (!$cleanup && $commandMethodConfiguration['cleanup']) {
                        continue;
                    }
                    $this->$commandMethodName($workspaceName, $dryRun, $nodeType);
                }
        }
    }

    /**
     * @param string $question
     * @param \Closure $task
     * @return void
     */
    protected function askBeforeExecutingTask($question, \Closure $task)
    {
        $response = null;
        while (!in_array($response, array('y', 'n'))) {
            $response = strtolower($this->output->ask('<comment>' . $question . ' (y/n)</comment>'));
        }
        $this->output->outputLine();

        switch ($response) {
            case 'y':
                $task();
            break;
            case 'n':
                $this->output->outputLine('Skipping.');
            break;
        }
    }

    /**
     * ...
     *
     * @param string $workspaceName This argument will be ignored
     * @param boolean $dryRun Simulate?
     * @return void
     */
    public function fixNodesWithInconsistentIdentifier($workspaceName, $dryRun)
    {
        $this->output->outputLine('Checking for nodes with inconsistent identifier ...');

        $nodesArray = [];
        $liveWorkspaceNames = [];
        $nonLiveWorkspaceNames = [];
        foreach ($this->workspaceRepository->findAll() as $workspace) {
            /** @var Workspace $workspace */
            if ($workspace->getBaseWorkspace() !== null) {
                $nonLiveWorkspaceNames[] = $workspace->getName();
            } else {
                $liveWorkspaceNames[] = $workspace->getName();
            }
        }

        foreach ($nonLiveWorkspaceNames as $workspaceName) {
            /** @var QueryBuilder $queryBuilder */
            $queryBuilder = $this->entityManager->createQueryBuilder();
            $queryBuilder->select('nonlive.Persistence_Object_Identifier, nonlive.identifier, nonlive.path, live.identifier AS liveIdentifier')
                ->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'nonlive')
                ->join('TYPO3\TYPO3CR\Domain\Model\NodeData', 'live', 'WITH', 'live.path = nonlive.path AND live.dimensionsHash = nonlive.dimensionsHash AND live.identifier != nonlive.identifier')
                ->where('nonlive.workspace = ?1')
                ->andWhere($queryBuilder->expr()->in('live.workspace', $liveWorkspaceNames))
                ->andWhere('nonlive.path != \'/\'')
                ->setParameter(1, $workspaceName)
            ;

            foreach ($queryBuilder->getQuery()->getArrayResult() as $nodeDataArray) {
                $this->output->outputLine('Node %s in workspace %s has identifier %s but live node has identifier %s.', [$nodeDataArray['path'], $workspaceName, $nodeDataArray['identifier'], $nodeDataArray['liveIdentifier']]);
                $nodesArray[] = $nodeDataArray;
            }
        }

        if ($nodesArray === []) {
            return;
        }

        if (!$dryRun) {
            $self = $this;
            $this->output->outputLine();
            $this->output->outputLine('Nodes with inconsistent identifiers found.');
            $this->askBeforeExecutingTask(sprintf('Do you want to fix the identifiers of %s node%s now?', count($nodesArray), count($nodesArray) > 1 ? 's' : ''), function () use ($self, $nodesArray) {
                foreach ($nodesArray as $nodeArray) {
                    /** @var QueryBuilder $queryBuilder */
                    $queryBuilder = $this->entityManager->createQueryBuilder();
                    $queryBuilder->update('TYPO3\TYPO3CR\Domain\Model\NodeData', 'nonlive')
                        ->set('nonlive.identifier', $queryBuilder->expr()->literal($nodeArray['liveIdentifier']))
                        ->where('nonlive.Persistence_Object_Identifier = ?1')
                        ->setParameter(1, $nodeArray['Persistence_Object_Identifier']);
                    $result = $queryBuilder->getQuery()->getResult();
                    if ($result !== 1) {
                        $self->output->outputLine('<error>Error:</error> The update query returned an unexpected result!');
                        $self->output->outputLine('<b>Query:</b> ' . $queryBuilder->getQuery()->getSQL());
                        $self->output->outputLine('<b>Result:</b> %s', [ var_export($result, true)]);
                        exit(1);
                    }
                }
                $self->output->outputLine('Fixed inconsistent identifiers.');
            });
        } else {
            $this->output->outputLine('Found %s node%s with inconsistent identifiers which need to be fixed.', array(count($nodesArray), count($nodesArray) > 1 ? 's' : ''));
        }
        $this->output->outputLine();
    }
}
