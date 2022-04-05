<?php

namespace App\Controller;

use App\Entity\MessageToBot;
use App\Service\Bot\Providers\Twitter\Twitter;
use App\Service\RedditClient;
use App\Service\SmsActivate;
use App\Service\TelegramUserRegister;
use App\Service\TwitterClient;
use App\Service\Web3;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RootController extends AbstractController
{
    /**
     * @Route("/", name="root")
     */
    public function index(
        Twitter $twitter,
        TwitterClient $twitterClient,
        EntityManagerInterface $entityManager,
        TelegramUserRegister $telegramUserRegister,
        Web3 $web3,
        SmsActivate $smsActivate
    ): Response {
        /*$res = json_decode(
            $smsActivate->request("getTopCountriesByService", "POST", [
                "service" => "tg",
            ]),
            1
        );
        $res = array_filter($res, function ($v) {
            return $v["count"] > 0;
        });
        usort($res, function ($a, $b) {
            return $a["price"] <=> $b["price"];
        });

        $res = $smsActivate->request("getNumber", "POST", [
            "service" => "tg",
            "forward" => "0",
            "verification" => "0",
            "country" => $res[0]["country"],
        ]);

        if (strpos($res, "ACCESS_NUMBER") !== false) {
            list($type, $id, $number) = explode(":", $res);
        }*/
        $id = "822640575";
        $number = "8801313581196";

        return $this->json([
            "time" => intval(microtime(true) * 1000),
        ]);
    }
}
