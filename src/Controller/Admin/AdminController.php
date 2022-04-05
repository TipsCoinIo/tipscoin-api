<?php

namespace App\Controller\Admin;

use App\Entity\Config;
use App\Entity\ReferralProgramRewardLevel;
use App\Entity\ReferralProgramTelegramLink;
use App\Entity\ReferralProgramUser;
use App\Service\TelegramClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin")
 */
class AdminController extends AbstractController
{
    /** @var EntityManagerInterface  */
    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/reward-levels", methods={"GET"})
     */
    public function rewardLevels(Request $request)
    {
        $u = $this->entityManager
            ->getRepository(ReferralProgramUser::class)
            ->find($request->query->getInt("user"));

        $levels = $this->entityManager
            ->getRepository(ReferralProgramRewardLevel::class)
            ->findBy(["user" => $u], ["required" => "ASC"]);

        return $this->json(array_map([$this, "mapRewardLevel"], $levels));
    }

    public function mapRewardLevel(ReferralProgramRewardLevel $level)
    {
        return [
            "id" => $level->getId(),
            "user" => $level->getUser()
                ? ["id" => $level->getUser()->getId()]
                : null,
            "required" => $level->getRequired(),
            "reward" => $level->getReward(),
        ];
    }

    /**
     * @Route("/reward-levels", methods={"POST"})
     */
    public function rewardLevelsPost(Request $request)
    {
        foreach ($request->request->get("levels") as $level) {
            if (empty($level["id"])) {
                $l = new ReferralProgramRewardLevel();
            } else {
                $l = $this->entityManager
                    ->getRepository(ReferralProgramRewardLevel::class)
                    ->find($level["id"]);
            }

            if (array_key_exists("_delete", $level)) {
                $this->entityManager->remove($l);
                continue;
            }

            $l->setRequired($level["required"]);
            $l->setUser(
                empty($level["user"]["id"])
                    ? null
                    : $this->entityManager
                        ->getRepository(ReferralProgramUser::class)
                        ->find($level["user"]["id"])
            );
            $l->setReward($level["reward"]);
            $this->entityManager->persist($l);
        }
        $this->entityManager->flush();

        return $this->json([]);
    }

    /**
     * @Route("/referral-users", methods={"GET"})
     */
    public function referralUsers()
    {
        $offset = 0;
        $limit = 100;

        $users = $this->entityManager
            ->getRepository(ReferralProgramUser::class)
            ->createQueryBuilder("ru")
            ->orderBy("ru.id", "ASC")
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $result = new Paginator($users);

        return $this->json([
            "count" => count($result),
            "offset" => $offset,
            "limit" => $limit,
            "result" => array_map(
                [$this, "mapReferralUser"],
                $result->getIterator()->getArrayCopy()
            ),
        ]);
    }

    /**
     * @Route("/referral-users", methods={"POST"})
     */
    public function createReferralUser(
        Request $request,
        TelegramClient $telegramClient
    ) {
        $u = new ReferralProgramUser();
        $u->setUsername(trim($request->request->get("username")));
        $this->entityManager->persist($u);

        $res = $telegramClient->request("createChatInviteLink", [
            "chat_id" => -1001711928517,
        ]);

        $tgl = new ReferralProgramTelegramLink();
        $u->addTelegramLink($tgl);
        $tgl->setLink($res["result"]["invite_link"]);
        $this->entityManager->persist($tgl);
        $this->entityManager->flush();

        return $this->json($this->mapReferralUser($u));
    }

    /**
     * @Route("/apConfig", methods={"POST"})
     */
    public function apConfig(Request $request, TelegramClient $telegramClient)
    {
        $arr = ["text_homepage", "text_userpage", "text_info"];

        foreach ($arr as $v) {
            $c = $this->entityManager
                ->getRepository(Config::class)
                ->findOneBy(["name" => $v]);
            if (!$c) {
                $c = new Config();
                $c->setName($v);
            }
            $c->setValue($request->request->get($v));
            $this->entityManager->persist($c);
        }
        $this->entityManager->flush();

        return $this->json([]);
    }

    public function mapReferralUser(ReferralProgramUser $user)
    {
        return [
            "id" => $user->getId(),
            "username" => $user->getUsername(),
            "privateHash" => $user->getPrivateHash(),
            "customLevels" => $user
                ->getCustomLevels()
                ->map(function (ReferralProgramRewardLevel $level) {
                    return $this->mapRewardLevel($level);
                })
                ->toArray(),
        ];
    }
}
