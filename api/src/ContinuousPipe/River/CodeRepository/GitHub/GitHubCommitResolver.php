<?php

namespace ContinuousPipe\River\CodeRepository\GitHub;

use ContinuousPipe\River\CodeRepository;
use ContinuousPipe\River\CodeRepository\CommitResolver;
use ContinuousPipe\River\CodeRepository\CommitResolverException;
use ContinuousPipe\River\GitHub\ClientFactory;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use Github\Client;
use GuzzleHttp\Exception\RequestException;

class GitHubCommitResolver implements CommitResolver
{
    /**
     * @var ClientFactory
     */
    private $clientFactory;

    /**
     * @var CodeRepository\RepositoryAddressDescriptor
     */
    private $addressDescriptor;

    /**
     * @param ClientFactory                              $clientFactory
     * @param CodeRepository\RepositoryAddressDescriptor $addressDescriptor
     */
    public function __construct(ClientFactory $clientFactory, CodeRepository\RepositoryAddressDescriptor $addressDescriptor)
    {
        $this->clientFactory = $clientFactory;
        $this->addressDescriptor = $addressDescriptor;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadCommitOfBranch(FlatFlow $flow, $branch)
    {
        $client = $this->clientFactory->createClientForFlow($flow->getUuid());

        return $this->_getHeadCommit($client, $flow->getRepository(), $branch);
    }

    /**
     * @param Client         $client
     * @param CodeRepository $repository
     * @param string$branch
     *
     * @return string
     *
     * @throws CommitResolverException
     */
    private function _getHeadCommit(Client $client, CodeRepository $repository, $branch)
    {
        try {
            $description = $this->addressDescriptor->getDescription($repository->getAddress());
        } catch (CodeRepository\InvalidRepositoryAddress $e) {
            throw new CommitResolverException('Invalid repository address', $e->getCode(), $e);
        }

        try {
            $branch = $client->repository()->branches($description->getUsername(), $description->getRepository(), $branch);
        } catch (RequestException $e) {
            if ($response = $e->getResponse()) {
                if ($response->getStatusCode() == 404) {
                    throw new CommitResolverException(sprintf(
                        'Branch "%s" not found in repository',
                        $branch
                    ), $e->getCode(), $e);
                }
            }

            throw new CommitResolverException($e->getMessage(), $e->getCode(), $e);
        }

        if (!isset($branch['commit']['sha'])) {
            throw new CommitResolverException(sprintf(
                'Unable to find the SHA1 of the branch "%s"',
                $branch
            ));
        }

        return $branch['commit']['sha'];
    }

    /**
     * {@inheritdoc}
     */
    public function supports(FlatFlow $flow): bool
    {
        return $flow->getRepository() instanceof GitHubCodeRepository;
    }
}
