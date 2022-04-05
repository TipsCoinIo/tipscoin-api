<?php

namespace App\Controller\Api;

use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserNotification;
use App\Service\Mappers\TransactionMap;
use App\Service\Mappers\UserNotificationMap;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/user", name="api_user")
 */
class UserController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/transactions", name="_transactions")
     */
    public function index(
        Request $request,
        TransactionMap $transactionMap
    ): Response {
        /** @var User $u */
        $u = $this->getUser();
        $offset = $request->request->getInt("offset");
        if ($offset < 0 || $offset > 10000) {
            $offset = 0;
        }
        $limit = 10;

        $qb = $this->entityManager
            ->getRepository(Transaction::class)
            ->createQueryBuilder("t")
            ->andWhere("t.user = :user")
            ->setParameter("user", $u)
            ->addOrderBy("t.createdAt", "DESC")
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($request->request->has("provider")) {
            $provider = $request->request->get("provider");
            if (!empty($provider)) {
                $qb->andWhere("t.provider = :provider")->setParameter(
                    "provider",
                    $provider
                );
            }
        }

        $transactions = new Paginator($qb);

        return $this->json([
            "offset" => $offset,
            "limit" => $limit,
            "total" => count($transactions),
            "result" => array_map(
                [$transactionMap, "map"],
                $transactions->getIterator()->getArrayCopy()
            ),
        ]);
    }

    /**
     * @Route("/notifications", name="_notifications")
     */
    public function notifications(
        Request $request,
        UserNotificationMap $userNotificationMap
    ): Response {
        /** @var User $u */
        $u = $this->getUser();
        $limit = $request->request->getInt("limit", 10);
        if ($limit < 1 || $limit > 100) {
            $limit = 100;
        }

        if ($request->request->getBoolean("markAllAsRead")) {
            $this->entityManager
                ->getRepository(UserNotification::class)
                ->createQueryBuilder("un")
                ->update()
                ->set("un.unread", 0)
                ->andWhere("un.user = :user")
                ->setParameter("user", $u)
                ->getQuery()
                ->execute();
        }

        $qb = $this->entityManager
            ->getRepository(UserNotification::class)
            ->createQueryBuilder("n")
            ->andWhere("n.user = :user")
            ->setParameter("user", $u)
            ->addOrderBy("n.createdAt", "DESC")
            ->setMaxResults($limit);

        $notifications = new Paginator($qb);

        return $this->json([
            "limit" => $limit,
            "total" => count($notifications),
            "result" => array_map(
                [$userNotificationMap, "map"],
                $notifications->getIterator()->getArrayCopy()
            ),
        ]);
    }
}
