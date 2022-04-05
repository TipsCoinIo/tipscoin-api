<?php

namespace App\Command;

use App\Entity\TokenTx;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserNotification;
use App\Service\BscScan;
use App\Service\LockFactory;
use Decimal\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

class TokenTxCommand extends Command
{
    use LockableTrait;

    protected static $defaultName = "app:token-tx";
    protected static $defaultDescription = "Add a short description for your command";
    /**
     * @var BscScan
     */
    private $bscScan;
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;
    /**
     * @var KernelInterface
     */
    private $kernel;

    /** @var LockFactory */
    private $lockFactory;

    public function __construct(
        KernelInterface $kernel,
        BscScan $bscScan,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->kernel = $kernel;
        $this->bscScan = $bscScan;
        $this->entityManager = $entityManager;
    }

    /**
     * @required
     * @param LockFactory $lockFactory
     */
    public function setLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $contractAddress = $this->kernel
            ->getContainer()
            ->getParameter("app:tips_contract_address");
        $depositAddress = $this->kernel
            ->getContainer()
            ->getParameter("app:tips_deposit_address");

        $blockNumber = $this->entityManager
            ->getRepository(TokenTx::class)
            ->createQueryBuilder("t")
            ->select("MAX(t.blockNumber) as blockNumber")
            ->getQuery()
            ->getOneOrNullResult()["blockNumber"];

        $i = 0;
        while ($i < 10) {
            $i++;
            $data = [
                "contractaddress" => $contractAddress,
                "address" => $depositAddress,
            ];
            if ($blockNumber) {
                $data["startblock"] = $blockNumber + 1;
            }
            $ret = $this->bscScan->request("account", "tokentx", $data)[
                "result"
            ];
            if (count($ret) == 0) {
                break;
            }
            foreach ($ret as $tx) {
                $t = new TokenTx();
                $t->setData($tx);
                $t->setBlockNumber($tx["blockNumber"]);
                $t->setValue($tx["value"]);
                $t->setFromAddress($tx["from"]);
                $t->setToAddress($tx["to"]);
                $this->entityManager->persist($t);
                $blockNumber = $tx["blockNumber"];
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        while (true) {
            /** @var TokenTx[] $txs */
            $txs = $this->entityManager
                ->getRepository(TokenTx::class)
                ->createQueryBuilder("t")
                ->andWhere("t.processed = 0")
                ->andWhere("t.toAddress = :toAddress")
                ->setParameter("toAddress", $depositAddress)
                ->setMaxResults(100)
                ->getQuery()
                ->getResult();

            if (count($txs) == 0) {
                break;
            }

            foreach ($txs as $tx) {
                $u = $this->entityManager
                    ->getRepository(User::class)
                    ->findOneBy(["walletAddress" => $tx->getFromAddress()]);
                if ($u) {
                    $lock = $this->lockFactory->createLock(
                        "u" . $u->getId(),
                        10
                    );
                    if ($lock->acquire()) {
                        try {
                            $u = $this->entityManager
                                ->getRepository(User::class)
                                ->findOneBy([
                                    "walletAddress" => $tx->getFromAddress(),
                                ]);
                            $value = new Decimal($tx->getValue());
                            $value = $value->div(
                                10 ** $tx->getData()["tokenDecimal"]
                            );
                            $u->setBalance(
                                (new Decimal($u->getBalance()))
                                    ->add($value)
                                    ->toString()
                            );
                            $this->entityManager->persist($u);

                            $trans = new Transaction();
                            $trans->setType("deposit");
                            $trans->setValue($value->toString());
                            $trans->setUser($u);
                            $trans->setBalanceAfter($u->getBalance());
                            $this->entityManager->persist($trans);

                            $notif = new UserNotification();
                            $notif->setUser($u);
                            $notif->setMessage(
                                "You deposit " .
                                    sprintf("%g", $value->toString()) .
                                    " Tips"
                            );
                            $this->entityManager->persist($notif);
                            $tx->setProcessed(true);
                            $this->entityManager->persist($tx);
                        } finally {
                            $lock->release();
                        }
                    }
                } else {
                    $tx->setProcessed(true);
                    $this->entityManager->persist($tx);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return Command::SUCCESS;
    }
}
