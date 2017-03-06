<?php

namespace ContinuousPipe\DevelopmentEnvironment\Status;

use ContinuousPipe\Pipe\Client\PublicEndpoint;
use ContinuousPipe\River\View\Tide;
use JMS\Serializer\Annotation as JMS;

class DevelopmentEnvironmentStatus
{
    /**
     * @JMS\Type("string")
     * @JMS\Groups({"Default", "DevelopmentEnvironmentStatus"})
     *
     * @var string
     */
    private $status;

    /**
     * @JMS\Type("string")
     * @JMS\Groups({"Default", "DevelopmentEnvironmentStatus"})
     *
     * @var string
     */
    private $clusterIdentifier;

    /**
     * @JMS\Type("string")
     * @JMS\Groups({"Default", "DevelopmentEnvironmentStatus"})
     *
     * @var string
     */
    private $environmentName;

    /**
     * @JMS\Type("ContinuousPipe\River\View\Tide")
     * @JMS\Groups({"Default", "DevelopmentEnvironmentStatus"})
     *
     * @var Tide
     */
    private $lastTide;

    /**
     * @JMS\Type("array<ContinuousPipe\Pipe\Client\PublicEndpoint>")
     * @JMS\Groups({"Default", "DevelopmentEnvironmentStatus"})
     *
     * @var array|PublicEndpoint[]
     */
    private $publicEndpoints = [];

    public function __construct($status = null)
    {
        $this->status = $status;
    }

    /**
     * @return array|PublicEndpoint[]
     */
    public function getPublicEndpoints()
    {
        return $this->publicEndpoints;
    }

    public function withCluster(string $cluster) : self
    {
        $status = clone $this;
        $status->clusterIdentifier = $cluster;

        return $status;
    }

    public function withPublicEndpoints(array $endpoints) : self
    {
        $status = clone $this;
        $status->publicEndpoints = $endpoints;

        return $status;
    }

    public function withEnvironmentName(string $environmentName) : self
    {
        $status = clone $this;
        $status->environmentName = $environmentName;

        return $status;
    }

    public function withStatus(string $environmentStatus) : self
    {
        $status = clone $this;
        $status->status = $environmentStatus;

        return $status;
    }

    public function withLastTide(Tide $tide) : self
    {
        $status = clone $this;
        $status->lastTide = $tide;

        return $status;
    }
}
