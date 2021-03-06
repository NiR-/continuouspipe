<?php

namespace ContinuousPipe\Pipe\Kubernetes\Handler;

use ContinuousPipe\Pipe\Kubernetes\Client\DeploymentClientFactory;
use ContinuousPipe\Pipe\Kubernetes\Component\ComponentCreationStatus;
use ContinuousPipe\Pipe\Kubernetes\Inspector\PodInspector;
use ContinuousPipe\Pipe\Command\WaitComponentsCommand;
use ContinuousPipe\Pipe\DeploymentContext;
use ContinuousPipe\Pipe\Event\ComponentsReady;
use ContinuousPipe\Pipe\Event\DeploymentFailed;
use ContinuousPipe\Pipe\Handler\Deployment\DeploymentHandler;
use ContinuousPipe\Pipe\Promise\PromiseBuilder;
use ContinuousPipe\Pipe\View\ComponentStatus;
use ContinuousPipe\Security\Credentials\Cluster\Kubernetes;
use Kubernetes\Client\Exception\DeploymentNotFound;
use Kubernetes\Client\Model\Container;
use Kubernetes\Client\Model\ContainerStatus;
use Kubernetes\Client\Model\Deployment;
use Kubernetes\Client\Model\KubernetesObject;
use Kubernetes\Client\Model\Pod;
use Kubernetes\Client\Model\PodSpecification;
use Kubernetes\Client\Model\PodStatus;
use Kubernetes\Client\Model\ReplicationController;
use Kubernetes\Client\NamespaceClient;
use LogStream\Log;
use LogStream\Logger;
use LogStream\LoggerFactory;
use LogStream\Node\Complex;
use LogStream\Node\Text;
use Psr\Log\LoggerInterface;
use SimpleBus\Message\Bus\MessageBus;
use React;

class WaitComponentsHandler implements DeploymentHandler
{
    /**
     * The internal to check to components.
     */
    const DEFAULT_COMPONENT_CHECK_INTERVAL = 2.5;

    /**
     * The default timeout is 30 minutes for a deployment.
     */
    const DEFAULT_COMPONENT_TIMEOUT = 1800;

    /**
     * @var DeploymentClientFactory
     */
    private $clientFactory;

    /**
     * @var MessageBus
     */
    private $eventBus;

    /**
     * @var float
     */
    private $checkInternal;

    /**
     * @var int
     */
    private $timeout;
    /**
     * @var LoggerFactory
     */
    private $loggerFactory;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var PodInspector
     */
    private $podInspector;

    public function __construct(
        DeploymentClientFactory $clientFactory,
        MessageBus $eventBus,
        LoggerFactory $loggerFactory,
        LoggerInterface $logger,
        PodInspector $podInspector,
        $checkInternal = self::DEFAULT_COMPONENT_CHECK_INTERVAL,
        $timeout = self::DEFAULT_COMPONENT_TIMEOUT
    ) {
        $this->clientFactory = $clientFactory;
        $this->eventBus = $eventBus;
        $this->loggerFactory = $loggerFactory;
        $this->logger = $logger;
        $this->podInspector = $podInspector;
        $this->checkInternal = $checkInternal;
        $this->timeout = $timeout;
    }

    /**
     * @param WaitComponentsCommand $command
     */
    public function handle(WaitComponentsCommand $command)
    {
        $objects = $this->getKubernetesObjectsToWait($command->getComponentStatuses());
        $deploymentContext = $command->getContext();
        $client = $this->clientFactory->get($deploymentContext);
        $logger = $this->loggerFactory->from($deploymentContext->getLog());

        $loop = React\EventLoop\Factory::create();
        $promises = [];

        foreach ($objects as $object) {
            if ($object instanceof ReplicationController) {
                $promises[] = $this->waitOneReplicationControllerPodRunning($loop, $client, $logger, $object);
            } elseif ($object instanceof Deployment) {
                $promises[] = $this->waitDeploymentFinished($loop, $deploymentContext, $client, $logger, $object);
            }
        }

        React\Promise\all($promises)->then(function () use ($deploymentContext) {
            $deploymentUuid = $deploymentContext->getDeployment()->getUuid();

            $this->eventBus->handle(new ComponentsReady($deploymentUuid));
        })->otherwise(function () use ($deploymentContext) {
            $this->eventBus->handle(new DeploymentFailed($deploymentContext));
        });

        try {
            $loop->run();
        } catch (DeploymentNotFound $e) {
            $this->eventBus->handle(new DeploymentFailed($deploymentContext));

            $this->logger->warning('The deployment was not found', [
                'exception' => $e,
                'environment' => $deploymentContext->getEnvironment()->getName(),
                'cluster' => $deploymentContext->getCluster()->getIdentifier(),
            ]);
        }
    }

    /**
     * @param NamespaceClient       $client
     * @param ReplicationController $replicationController
     *
     * @return React\Promise\PromiseInterface
     */
    private function waitOneReplicationControllerPodRunning(React\EventLoop\LoopInterface $loop, NamespaceClient $client, Logger $logger, ReplicationController $replicationController)
    {
        $logger = $logger->child(new Text(sprintf('Waiting at least one pod of RC "%s" to be running', $replicationController->getMetadata()->getName())));
        $logger->updateStatus(Log::RUNNING);

        $timeout = $this->getDeploymentTimeout(
            $replicationController->getSpecification()->getPodTemplateSpecification()->getPodSpecification()
        );

        return (new PromiseBuilder($loop))
            ->retry($this->checkInternal, function (React\Promise\Deferred $deferred) use ($client, $replicationController) {
                $pods = $client->getPodRepository()->findByReplicationController($replicationController);

                $runningPods = array_filter($pods->getPods(), function (Pod $pod) {
                    return $this->podInspector->isRunningAndReady($pod);
                });

                if (count($runningPods) > 0) {
                    $deferred->resolve($pods);
                }
            })
            ->withTimeout($timeout)
            ->getPromise()
            ->then(function () use ($logger) {
                $logger->updateStatus(Log::SUCCESS);
            }, function (\Exception $e) use ($logger, $replicationController) {
                $logger->updateStatus(Log::FAILURE);
                $logger->child(new Text($e->getMessage()));

                throw $e;
            })
        ;
    }

    /**
     * @param React\EventLoop\LoopInterface $loop
     * @param DeploymentContext             $deploymentContext
     * @param NamespaceClient               $client
     * @param Logger                        $logger
     * @param Deployment                    $deployment
     *
     * @return React\Promise\PromiseInterface
     */
    private function waitDeploymentFinished(React\EventLoop\LoopInterface $loop, DeploymentContext $deploymentContext, NamespaceClient $client, Logger $logger, Deployment $deployment)
    {
        $logger = $logger->child(new Text(sprintf('Rolling update of component "%s"', $deployment->getMetadata()->getName())));
        $logger->updateStatus(Log::RUNNING);

        $timeout = $this->getDeploymentTimeout($deployment->getSpecification()->getTemplate()->getPodSpecification());

        // Display status of the deployment
        $statusLogger = $logger->child(new Text('Deployment is starting'));
        $deploymentStatusPromise = (new PromiseBuilder($loop))
            ->retry($this->checkInternal, function (React\Promise\Deferred $deferred) use ($client, $statusLogger, $deployment) {
                try {
                    $foundDeployment = $client->getDeploymentRepository()->findOneByName($deployment->getMetadata()->getName());
                } catch (DeploymentNotFound $e) {
                    $deferred->reject($e);

                    return;
                }

                $status = $foundDeployment->getStatus();

                if (null === $status) {
                    $statusLogger->update(new Text('Deployment is not started yet'));

                    return;
                }

                $statusLogger->update(new Text(sprintf(
                    '%d/%d available replicas - %d updated',
                    $status->getAvailableReplicas(),
                    $status->getReplicas(),
                    $status->getUpdatedReplicas()
                )));

                if ($status->getAvailableReplicas() == $status->getReplicas()) {
                    $deferred->resolve($deployment);
                }
            })
            ->withTimeout($timeout)
            ->getPromise()
        ;

        // Display the status of the pods related to this deployment
        $podsLogger = $logger->child(new Complex('pods'));
        $updatePodStatuses = function () use ($client, $deployment, $podsLogger, $deploymentContext) {
            $podsFoundByLabel = $client->getPodRepository()->findByLabels($deployment->getSpecification()->getSelector()->getMatchLabels());

            $podsLogger->update(new Complex('pods', [
                'deployment' => $this->normalizeDeployment($deploymentContext, $deployment),
                'pods' => array_map(function (Pod $pod) {
                    return $this->normalizePod($pod);
                }, $podsFoundByLabel->getPods()),
            ]));
        };

        $updatePodStatusesTimer = $loop->addPeriodicTimer(1, $updatePodStatuses);

        return $deploymentStatusPromise
            ->then(function () use ($logger, $updatePodStatusesTimer, $updatePodStatuses) {
                $updatePodStatusesTimer->cancel();
                $updatePodStatuses();

                $logger->updateStatus(Log::SUCCESS);
            }, function (\Exception $e) use ($logger, $statusLogger, $updatePodStatusesTimer, $updatePodStatuses) {
                $updatePodStatusesTimer->cancel();
                $updatePodStatuses();

                $logger->updateStatus(Log::FAILURE);
                $statusLogger->update(new Text($e->getMessage()));

                throw $e;
            })
        ;
    }

    /**
     * @param ComponentStatus[] $componentStatuses
     *
     * @return KubernetesObject[]
     */
    private function getKubernetesObjectsToWait(array $componentStatuses)
    {
        $objects = [];

        foreach ($componentStatuses as $componentStatus) {
            if (!$componentStatus instanceof ComponentCreationStatus) {
                throw new \RuntimeException(sprintf(
                    'Expected a status of type `%s`, got %s',
                    ComponentCreationStatus::class,
                    get_class($componentStatus)
                ));
            }

            $objects = array_merge($objects, $componentStatus->getCreated(), $componentStatus->getUpdated());
        }

        return $objects;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(DeploymentContext $context)
    {
        return $context->getCluster() instanceof Kubernetes;
    }

    /**
     * @param DeploymentContext $deploymentContext
     * @param Deployment        $deployment
     *
     * @return array
     */
    private function normalizeDeployment(DeploymentContext $deploymentContext, Deployment $deployment)
    {
        $specification = $deployment->getSpecification();
        $containers = $specification->getTemplate()->getPodSpecification()->getContainers();

        return [
            'environment' => [
                'identifier' => $deploymentContext->getEnvironment()->getIdentifier(),
                'cluster' => $deploymentContext->getCluster()->getIdentifier(),
            ],
            'replicas' => $specification->getReplicas(),
            'containers' => array_map(function (Container $container) {
                return $this->normalizeContainer($container);
            }, $containers),
        ];
    }

    /**
     * @param Pod $pod
     *
     * @return array
     */
    private function normalizePod(Pod $pod)
    {
        return [
            'name' => $pod->getMetadata()->getName(),
            'creationTimestamp' => $pod->getMetadata()->getCreationTimestamp(),
            'deletionTimestamp' => $pod->getMetadata()->getDeletionTimestamp(),
            'containers' => array_map(function (Container $container) {
                return $this->normalizeContainer($container);
            }, $pod->getSpecification()->getContainers()),
            'status' => $this->normalizePodStatus($pod),
        ];
    }

    /**
     * @param Container $container
     *
     * @return array
     */
    private function normalizeContainer(Container $container)
    {
        return [
            'name' => $container->getName(),
            'image' => $container->getImage(),
        ];
    }

    /**
     * @param Pod $pod
     *
     * @return array|null
     */
    private function normalizePodStatus(Pod $pod)
    {
        if (null === ($status = $pod->getStatus())) {
            return null;
        }

        return [
            'phase' => $status->getPhase(),
            'ready' => $this->podInspector->isRunningAndReady($pod),
        ];
    }

    /**
     * @param PodSpecification $podSpecification
     *
     * @return int
     */
    private function getDeploymentTimeout(PodSpecification $podSpecification)
    {
        $containers = $podSpecification->getContainers();
        if (count($containers) == 0) {
            return $this->timeout;
        }

        $timeoutPerContainer = array_map(function (Container $container) {
            if (null === ($probe = $container->getReadinessProbe())) {
                return $this->timeout;
            }

            $initialDelay = $probe->getInitialDelaySeconds();
            $periodSeconds = $probe->getPeriodSeconds();
            $failureThreshold = $probe->getFailureThreshold();

            if (null === $initialDelay || null === $periodSeconds || null == $failureThreshold) {
                return $this->timeout;
            }

            return 60 + $initialDelay + $periodSeconds * $failureThreshold;
        }, $podSpecification->getContainers());

        return max($timeoutPerContainer);
    }
}
