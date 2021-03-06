<?php

namespace ContinuousPipe\River\Task;

class TaskRunnerException extends \Exception
{
    /**
     * @var Task
     */
    private $task;

    /**
     * @param string     $message
     * @param int        $code
     * @param \Exception $previous
     * @param Task       $task
     */
    public function __construct($message, $code, \Exception $previous = null, Task $task = null)
    {
        parent::__construct($message, $code, $previous);

        $this->task = $task;
    }

    /**
     * @return Task|null
     */
    public function getTask()
    {
        return $this->task;
    }
}
