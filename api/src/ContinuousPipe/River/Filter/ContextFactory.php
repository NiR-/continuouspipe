<?php

namespace ContinuousPipe\River\Filter;

use ContinuousPipe\River\CodeRepository;
use ContinuousPipe\River\CodeRepository\PullRequestResolver;
use ContinuousPipe\River\Event\GitHub\PullRequestEvent;
use ContinuousPipe\River\Filter\View\TaskListView;
use ContinuousPipe\River\GitHub\UserCredentialsNotFound;
use ContinuousPipe\River\Repository\FlowRepository;
use ContinuousPipe\River\Task\Task;
use ContinuousPipe\River\Tide;
use ContinuousPipe\River\Tide\Configuration\ArrayObject;
use ContinuousPipe\River\GitHub\ClientFactory;
use ContinuousPipe\River\TideContext;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

class ContextFactory
{
    /**
     * @var ClientFactory
     */
    private $gitHubClientFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PullRequestResolver
     */
    private $pullRequestResolver;

    /**
     * @var FlowRepository
     */
    private $flowRepository;

    /**
     * @param ClientFactory       $gitHubClientFactory
     * @param LoggerInterface     $logger
     * @param PullRequestResolver $pullRequestResolver
     * @param FlowRepository      $flowRepository
     */
    public function __construct(ClientFactory $gitHubClientFactory, LoggerInterface $logger, PullRequestResolver $pullRequestResolver, FlowRepository $flowRepository)
    {
        $this->gitHubClientFactory = $gitHubClientFactory;
        $this->logger = $logger;
        $this->pullRequestResolver = $pullRequestResolver;
        $this->flowRepository = $flowRepository;
    }

    /**
     * Create the context available in tasks' filters.
     *
     * @param Tide $tide
     *
     * @return ArrayObject
     */
    public function create(Tide $tide)
    {
        $tideContext = $tide->getContext();

        return new ArrayObject([
            'code_reference' => new ArrayObject([
                'branch' => $tideContext->getCodeReference()->getBranch(),
                'sha' => $tideContext->getCodeReference()->getCommitSha(),
            ]),
            'tide' => new ArrayObject([
                'uuid' => (string) $tideContext->getTideUuid(),
            ]),
            'flow' => new ArrayObject([
                'uuid' => (string) $tideContext->getFlowUuid(),
            ]),
            'tasks' => $this->createTasksView($tide->getTasks()->getTasks()),
            'pull_request' => $this->getPullRequestContext($tide),
        ]);
    }

    /**
     * @param Task[] $tasks
     *
     * @return object
     */
    private function createTasksView(array $tasks)
    {
        $view = new TaskListView();

        foreach ($tasks as $task) {
            $taskId = $task->getIdentifier();

            $view->add($taskId, $task->getExposedContext());
        }

        return $view;
    }

    /**
     * @param Tide $tide
     *
     * @return ArrayObject
     */
    private function getPullRequestContext(Tide $tide)
    {
        $context = $tide->getContext();
        $repository = $context->getCodeRepository();

        if (null !== ($event = $context->getCodeRepositoryEvent()) && $event instanceof PullRequestEvent) {
            $pullRequest = $event->getPullRequest();
        } else {
            $matchingPullRequests = $this->pullRequestResolver->findPullRequestWithHeadReference(
                \ContinuousPipe\River\View\Tide::create(
                    $tide->getUuid(),
                    $tide->getFlowUuid(),
                    $tide->getCodeReference(),
                    $tide->getLog(),
                    $tide->getTeam(),
                    $tide->getUser(),
                    $tide->getConfiguration(),
                    new \DateTime(),
                    $tide->getGenerationUuid(),
                    $tide->getPipeline()
                )
            );

            $pullRequest = count($matchingPullRequests) > 0 ? current($matchingPullRequests) : null;
        }

        if (null === $pullRequest) {
            return new ArrayObject([
                'number' => 0,
                'state' => '',
                'labels' => [],
            ]);
        }

        return new ArrayObject([
            'number' => $pullRequest->getIdentifier(),
            'labels' => $this->getPullRequestLabelNames($context->getFlowUuid(), $context, $repository, $pullRequest),
        ]);
    }

    /**
     * @param UuidInterface              $flowUuid
     * @param TideContext                $context
     * @param CodeRepository             $codeRepository
     * @param CodeRepository\PullRequest $pullRequest
     *
     * @return array
     */
    private function getPullRequestLabelNames(UuidInterface $flowUuid, TideContext $context, CodeRepository $codeRepository, CodeRepository\PullRequest $pullRequest)
    {
        if (!$codeRepository instanceof CodeRepository\GitHub\GitHubCodeRepository) {
            return [];
        }

        try {
            $client = $this->gitHubClientFactory->createClientForFlow($flowUuid);
        } catch (UserCredentialsNotFound $e) {
            $this->logger->warning('Unable to get pull-request labels, credentials not found', [
                'exception' => $e,
                'user' => $context->getUser(),
            ]);

            return [];
        }

        try {
            $labels = $client->issue()->labels()->all(
                $codeRepository->getOrganisation(),
                $codeRepository->getName(),
                $pullRequest->getIdentifier()
            );
        } catch (\Exception $e) {
            $this->logger->error('Unable to get pull-request labels, the communication with the GH API wasn\'t successful', [
                'exception' => $e,
            ]);

            return [];
        }

        if (!is_array($labels)) {
            $this->logger->error('Received a non-array response from GH API', [
                'response' => $labels,
            ]);

            return [];
        }

        return array_map(function (array $label) {
            return $label['name'];
        }, $labels);
    }
}
