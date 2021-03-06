<?php

namespace ContinuousPipe\Pipe\Kubernetes\Listener\BeforeCreatingComponent;

use ContinuousPipe\Pipe\Kubernetes\Event\BeforeCreatingComponent;
use ContinuousPipe\Model\Component\Volume;
use Kubernetes\Client\Exception\PersistentVolumeClaimNotFound;
use Kubernetes\Client\Model\ObjectMetadata;
use Kubernetes\Client\Model\PersistentVolumeClaim;
use Kubernetes\Client\Model\PersistentVolumeClaimSpecification;
use Kubernetes\Client\Model\ResourceRequirements;
use Kubernetes\Client\Model\ResourceRequirementsRequests;
use Kubernetes\Client\NamespaceClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CreatePersistentVolumeClaim implements EventSubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            BeforeCreatingComponent::NAME => 'listen',
        ];
    }

    /**
     * @param BeforeCreatingComponent $event
     */
    public function listen(BeforeCreatingComponent $event)
    {
        $componentVolumes = $event->getComponent()->getSpecification()->getVolumes();
        $persistentVolumes = array_filter($componentVolumes, function (Volume $volume) {
            return $volume instanceof Volume\Persistent;
        });

        if (0 === count($persistentVolumes)) {
            return;
        }

        foreach ($persistentVolumes as $persistentVolume) {
            $this->createPersistentVolumeClaimIfNotExists($event->getClient(), $persistentVolume);
        }
    }

    /**
     * Create the asked persistent volume claim if it do not exists yet.
     *
     * @param NamespaceClient   $client
     * @param Volume\Persistent $persistentVolume
     */
    private function createPersistentVolumeClaimIfNotExists(NamespaceClient $client, Volume\Persistent $persistentVolume)
    {
        $persistentVolumeClaimRepository = $client->getPersistentVolumeClaimRepository();

        try {
            $persistentVolumeClaimRepository->findOneByName($persistentVolume->getName());
        } catch (PersistentVolumeClaimNotFound $e) {
            $persistentVolumeClaimRepository->create(new PersistentVolumeClaim(
                $this->getPersistentVolumeClaimMetadata($persistentVolume),
                new PersistentVolumeClaimSpecification(
                    [PersistentVolumeClaimSpecification::ACCESS_MODE_READ_WRITE_ONCE],
                    new ResourceRequirements(new ResourceRequirementsRequests(
                        $persistentVolume->getCapacity()
                    ))
                )
            ));
        }
    }

    /**
     * @param Volume\Persistent $persistentVolume
     *
     * @return ObjectMetadata
     */
    private function getPersistentVolumeClaimMetadata(Volume\Persistent $persistentVolume)
    {
        $metadata = new ObjectMetadata($persistentVolume->getName());

        if ($storageClass = $persistentVolume->getStorageClass()) {
            $metadata->setAnnotationsFromAssociativeArray([
                'volume.alpha.kubernetes.io/storage-class' => $storageClass,
                'volume.beta.kubernetes.io/storage-class' => $storageClass,
            ]);
        }

        return $metadata;
    }
}
