<?php

namespace ContinuousPipe\River\Task\Build\Event;

use ContinuousPipe\Builder\Build;
use ContinuousPipe\River\Task\TaskEvent;
use Ramsey\Uuid\Uuid;

class BuildStarted extends BuildEvent implements TaskEvent
{
    /**
     * @var string
     */
    private $taskIdentifier;

    public function __construct(Uuid $tideUuid, string $taskIdentifier, Build $build)
    {
        parent::__construct($tideUuid, $build);

        $this->taskIdentifier = $taskIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getTaskId()
    {
        return $this->taskIdentifier;
    }
}
