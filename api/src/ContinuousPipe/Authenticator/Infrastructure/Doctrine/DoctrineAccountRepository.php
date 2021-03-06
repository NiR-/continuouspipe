<?php

namespace ContinuousPipe\Authenticator\Infrastructure\Doctrine;

use ContinuousPipe\Authenticator\Infrastructure\Doctrine\Entity\AccountLink;
use ContinuousPipe\Security\Account\Account;
use ContinuousPipe\Security\Account\AccountNotFound;
use ContinuousPipe\Security\Account\AccountRepository;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;

class DoctrineAccountRepository implements AccountRepository
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritdoc}
     */
    public function find(string $uuid)
    {
        if (null === ($account = $this->getAccountRepository()->find($uuid))) {
            throw new AccountNotFound(sprintf('Account "%s" is not found', $uuid));
        }

        return $account;
    }

    /**
     * {@inheritdoc}
     */
    public function findByUsername(string $username)
    {
        $accountLinks = $this->getLinkRepository()
            ->createQueryBuilder('link')
            ->addSelect('account')
            ->join('link.account', 'account')
            ->where('link.username = :username')
            ->setParameter('username', $username)
        ;

        $links = $accountLinks->getQuery()->getResult();

        return array_map(function (AccountLink $link) {
            return $link->account;
        }, $links);
    }

    /**
     * {@inheritdoc}
     */
    public function link(string $username, Account $account)
    {
        $similarAccounts = $this->getAccountRepository()->findBy([
            'username' => $account->getUsername(),
            'identifier' => $account->getIdentifier(),
        ]);

        foreach ($similarAccounts as $similarAccount) {
            if (ClassUtils::getClass($similarAccount) != ClassUtils::getClass($account)) {
                // Actually, they might not be the same type.
                continue;
            }

            $similarLink = $this->getLinkRepository()->findOneBy([
                'username' => $username,
                'account' => $similarAccount,
            ]);

            if ($similarLink !== null) {
                $this->entityManager->remove($similarLink);
            }
        }

        $link = new AccountLink();
        $link->username = $username;
        $link->account = $account;

        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function unlink(string $username, Account $account)
    {
        $links = $this->getLinkRepository()->findBy([
            'account' => $account,
            'username' => $username,
        ]);

        foreach ($links as $link) {
            $this->entityManager->remove($link);
        }

        $this->entityManager->flush();
    }

    /**
     * @return \Doctrine\ORM\EntityRepository
     */
    private function getLinkRepository()
    {
        return $this->entityManager->getRepository(AccountLink::class);
    }

    /**
     * @return \Doctrine\Common\Persistence\ObjectRepository|\Doctrine\ORM\EntityRepository
     */
    private function getAccountRepository()
    {
        return $this->entityManager->getRepository(Account::class);
    }
}
