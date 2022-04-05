<?php

namespace App\Controller\Api;

use App\Entity\Config;
use App\Entity\ReferralProgramTelegramLink;
use App\Entity\ReferralProgramUser;
use App\Entity\ReferralProgramView;
use App\Entity\User;
use App\Service\BscScan;
use App\Service\Mappers\ReferralProgramUserMap;
use App\Service\TelegramClient;
use App\Service\WalletAddressValidator;
use Decimal\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
/**
 *
 * @Route("/ap", name="api_ap")
 */
class ApController extends AbstractController
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
     * @Route("/ref/{hash}")
     */
    public function ref($hash, TelegramClient $telegramClient, Request $request)
    {
        list($id, $hash) = explode(".", $hash, 2);
        $ap = $this->entityManager
            ->getRepository(ReferralProgramUser::class)
            ->findOneBy([
                "id" => $id,
                "publicHash" => $hash,
            ]);

        /** @var ReferralProgramTelegramLink $tgl */
        $tgl = $ap->getTelegramLinks()->first();

        $ips = explode(",", $request->server->get("HTTP_X_FORWARDED_FOR"));
        $ips = array_map("trim", $ips);

        $longIp = ip2long($ips[0]);

        if (
            !$this->entityManager
                ->getRepository(ReferralProgramView::class)
                ->findOneBy(["link" => $tgl, "ip" => $longIp])
        ) {
            $view = new ReferralProgramView();
            $view->setIp($longIp);
            $view->setLink($tgl);
            $view->setViewAt(new \DateTime());
            $this->entityManager->persist($view);
            $this->entityManager->flush();
        }

        return $this->redirect($tgl->getLink());
    }

    /**
     * @Route("/show/{hash}")
     */
    public function index(
        $hash,
        ReferralProgramUserMap $referralProgramUserMap,
        TelegramClient $telegramClient
    ) {
        list($id, $hash) = explode(".", $hash, 2);
        $ap = $this->entityManager
            ->getRepository(ReferralProgramUser::class)
            ->findOneBy([
                "id" => $id,
                "privateHash" => $hash,
            ]);

        return $this->json($referralProgramUserMap->map($ap));
    }

    /**
     * @Route("/config")
     */
    public function config()
    {
        $arr = ["text_homepage", "text_userpage", "text_info"];

        $d = array_combine($arr, array_fill(0, count($arr), null));

        foreach (
            $this->entityManager
                ->getRepository(Config::class)
                ->findBy(["name" => $arr])
            as $c
        ) {
            $d[$c->getName()] = $c->getValue();
        }

        return $this->json($d);
    }
}
