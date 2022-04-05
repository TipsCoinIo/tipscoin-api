<?php

namespace App\Controller\Twitter;

use App\Entity\Config;
use App\Service\RedditUserRegister;
use App\Service\TwitterClient;
use App\Service\UserToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/twitter/auth", name="twitter_auth_")
 */
class AuthController extends AbstractController
{
    /**
     * @var TwitterClient
     */
    private $twitterClient;

    public function __construct(TwitterClient $twitterClient)
    {
        $this->twitterClient = $twitterClient;
    }

    /**
     * @Route("/bot", name="bot")
     */
    public function linkbot()
    {
        return $this->redirect($this->twitterClient->botAuthorizationUrl());
    }

    /**
     * @Route("/link", name="link")
     */
    public function link()
    {
        return $this->json([
            "link" => $this->twitterClient->authorizationUrl(),
        ]);
    }

    /**
     * @Route("/", name="_index")
     */
    public function index(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if ($request->query->has("oauth_token")) {
            $res = $this->twitterClient->botAuthorize(
                $request->query->get("oauth_token"),
                $request->query->get("oauth_verifier")
            );
            if ($res["screen_name"] == "tipscoinbot") {
                $c = $entityManager
                    ->getRepository(Config::class)
                    ->findOneBy(["name" => "twitter_token"]);
                if (!$c) {
                    $c = new Config();
                    $c->setName("twitter_token");
                }
                $c->setValue($res);
                $entityManager->persist($c);
                $entityManager->flush();
            }
            return new Response("OK");
        }
        $code = $request->query->get("code");
        return $this->redirect(
            $this->getParameter("app.front_url") .
                "/login?" .
                http_build_query([
                    "oauth" => "twitter",
                    "code" => is_string($code) ? $code : "",
                ])
        );
    }
}
