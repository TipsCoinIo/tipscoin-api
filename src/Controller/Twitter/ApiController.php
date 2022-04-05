<?php

namespace App\Controller\Twitter;

use App\Entity\TwitterTransactionHash;
use App\Service\RedditClient;
use App\Service\TwitterClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/twitter", name="twitter_api")
 */
class ApiController extends AbstractController
{
    /** @var TwitterClient */
    protected $twitterClient;

    /**
     * @required
     * @param TwitterClient $twitterClient
     */
    public function setTwitterClient(TwitterClient $twitterClient): void
    {
        $this->twitterClient = $twitterClient;
    }

    /**
     * @Route("/user/search/{q}", name="_user_search")
     */
    public function userSearch($q): Response
    {
        if (!preg_match(TwitterClient::USERNAME_REGEX, $q)) {
            return $this->json(["message" => "user not found"], 400);
        }

        try {
            $res = $this->twitterClient
                ->clientV1()
                ->get("users/show", ["screen_name" => $q]);
        } catch (\Exception $e) {
            return $this->json(["message" => "user not found"], 400);
        }

        return $this->json([
            "id" => $res->id_str,
            "name" => $res->screen_name,
            "icon_img" => $res->profile_image_url_https,
        ]);
    }

    /**
     * @Route("/txHash")
     */
    public function txHash(Request $request, EntityManagerInterface $entityManager)
    {
        $tx = new TwitterTransactionHash();
        $tx->setSender($request->request->get('sender'));
        $tx->setTarget($request->request->get('target') ?? null);
        $tx->setAmount($request->request->get('amount') ?? null);
        $entityManager->persist($tx);
        $entityManager->flush();

        return $this->json($tx->getHash());
    }

    /**
     * @Route("/link/search/{q}", name="_link_search", requirements={"q":"[0-9]+"})
     */
    public function linkSearch($q): Response
    {
        $res = $this->twitterClient->clientV1()->get("statuses/show/" . $q);

        if (!empty($res->id_str) && $res->id_str == $q) {
            return $this->json([
                "id" => $res->user->id_str,
                "name" => $res->user->screen_name,
                "icon_img" => $res->user->profile_image_url_https,
            ]);
        }

        return $this->json(["message" => "link not found"], 400);
    }
}
