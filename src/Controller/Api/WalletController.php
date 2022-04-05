<?php

namespace App\Controller\Api;

use App\Entity\Transaction;
use App\Entity\User;
use App\Service\BscScan;
use App\Service\LockFactory;
use App\Service\Mappers\TransactionMap;
use App\Service\WalletAddressValidator;
use App\Service\Web3;
use Decimal\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
/**
 *
 * @Route("/wallet", name="api_wallet")
 */
class WalletController extends AbstractController
{
    use LockableTrait;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var KernelInterface
     */
    private $kernel;

    /**
     * @var LockFactory
     */
    private $lockFactory;

    public function __construct(
        EntityManagerInterface $entityManager,
        KernelInterface $kernel,
        LockFactory $lockFactory
    ) {
        $this->entityManager = $entityManager;
        $this->kernel = $kernel;
        $this->lockFactory = $lockFactory;
    }

    /**
     * @Route("/save", name="_save")
     */
    public function save(
        Request $request,
        WalletAddressValidator $validator
    ): Response {
        /** @var User $u */
        $u = $this->getUser();

        if ($request->request->has("address")) {
            $address = trim($request->request->get("address"));
            if ($validator->validate($address)) {
                $u->setWalletAddress($address);
                $this->entityManager->persist($u);
                $this->entityManager->flush();
                return $this->json([]);
            }
        }

        return $this->json(["message" => "Incorrect wallet address"], 400);
    }

    /**
     * @Route("/withdraw", name="_withdraw")
     */
    public function withdraw(
        Request $request,
        Web3 $web3,
        TransactionMap $transactionMap
    ) {
        /** @var User $u */
        $u = $this->getUser();
        $token = $request->request->get("token");

        try {
            $decoded = JWT::decode(
                $token,
                new Key(
                    $this->kernel
                        ->getContainer()
                        ->getParameter("app.jwt_secret"),
                    "HS256"
                )
            );
        } catch (\Throwable $e) {
            return $this->json(
                ["message" => "Transaction fees have changed."],
                400
            );
        }

        if (
            $decoded->data->user_id !== $u->getId() ||
            $decoded->data->to != $u->getWalletAddress()
        ) {
            return $this->json(
                ["message" => "An error occurred. Try again later"],
                400
            );
        }

        $l1 = $this->lockFactory->createLock("u" . $u->getId(), 60);

        if (!$l1->acquire()) {
            return $this->json(
                ["message" => "An error occurred. Try again later"],
                400
            );
        }

        $info = $web3->gasInfo($u->getWalletAddress(), $decoded->data->value);

        if ($info["tipsCost"] !== $decoded->data->tipsCost) {
            return $this->json(
                [
                    "message" => "Transaction fees have changed.",
                    "recalc" => true,
                ],
                400
            );
        }

        try {
            $u = $this->entityManager
                ->getRepository(User::class)
                ->find($u->getId());
            $balance = new Decimal($u->getBalance());
            $value = (new Decimal($decoded->data->value))->div(10 ** 9);

            if ($value > $balance) {
                throw new \Exception("tips:You don't have that much");
            }

            $txValue = $value
                ->mul(10 ** 9)
                ->sub((new Decimal($decoded->data->tipsCost))->mul(10 ** 9));

            if ($txValue <= new Decimal("0")) {
                throw new \Exception("tips:Transaction error.");
            }

            $web3->setGasPrice($decoded->data->gasPrice);
            $web3->setGasLimit($decoded->data->gasUsed);
            $ret = $web3->send($decoded->data->to, $txValue->toString());

            if (empty($ret["transactionHash"])) {
                throw new \Exception("tips:Transaction error.");
            }

            $u->setBalance(($balance - $value)->toString());
            $this->entityManager->persist($u);

            $t = new Transaction();
            $t->setUser($u);
            $t->setValue($value->mul("-1")->toString());
            $t->setType("withdraw");
            $t->setBalanceAfter($u->getBalance());
            $t->setData(["net" => $ret, "tipsFee" => $decoded->data->tipsCost]);
            $this->entityManager->persist($t);
            $this->entityManager->flush();

            return $this->json($transactionMap->map($t));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (strpos($message, "tips:") === 0) {
                $message = substr($message, 5);
            } else {
                $message = "An error occurred. Try again later.";
            }
            return $this->json(["message" => $message], 400);
        } finally {
            $l1->release();
        }
    }

    /**
     * @Route("/gasInfo", name="_gas_info")
     */
    public function gasInfo(Request $request, Web3 $web3)
    {
        /** @var User $u */
        $u = $this->getUser();

        if (!$u->getWalletAddress()) {
            return $this->json(
                ["message" => "You need add wallet address first"],
                400
            );
        }

        $d = new Decimal($request->request->get("value"));

        if ($d < new Decimal("500")) {
            return $this->json(
                ["message" => "The minimum withdrawal amount is 500 Tips."],
                400
            );
        }

        if ($d > new Decimal($u->getBalance())) {
            return $this->json(
                ["message" => "Oops. You don't have that much."],
                400
            );
        }

        $info = $web3->gasInfo(
            $u->getWalletAddress(),
            $d->mul(10 ** 9)->toString()
        );

        $info["user_id"] = $u->getId();

        $data = $info;

        $jwt = JWT::encode(
            [
                "iss" => "https://tipscoin.io",
                "iat" => time(),
                "exp" => time() + 300,
                "data" => $data,
            ],
            $this->kernel->getContainer()->getParameter("app.jwt_secret")
        );

        return $this->json($jwt);
    }

    /**
     * @Route("/clear", name="_clear")
     */
    public function clear()
    {
        /** @var User $u */
        $u = $this->getUser();

        if ($u && $u->getWalletAddress()) {
            $u->setWalletAddress(null);
            $this->entityManager->persist($u);
            $this->entityManager->flush();
        }

        return $this->json([]);
    }

    /**
     * @Route("/deposit", name="_deposit")
     */
    public function deposit(Request $request)
    {
        /** @var User $u */
        $u = $this->getUser();

        if ($request->request->has("balance")) {
            $current = new Decimal($u->getBalance());
            $old = new Decimal($request->request->get("balance"));

            if (!$current->equals($old)) {
                return $this->json([
                    "amount" => $current->sub($old)->toString(),
                ]);
            }
        }

        return $this->json(
            ["message" => "No transaction found. Try again later."],
            400
        );
    }
}
