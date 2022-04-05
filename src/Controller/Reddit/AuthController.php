<?php

namespace App\Controller\Reddit;

use App\Service\RedditClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/reddit/auth", name="reddit_auth_")
 */
class AuthController extends AbstractController
{
    /**
     * @var RedditClient
     */
    private $redditClient;

    public function __construct(RedditClient $redditClient)
    {
        $this->redditClient = $redditClient;
    }

    /**
     * @Route("/link", name="link")
     */
    public function link(Request $request)
    {
        return $this->json([
            "link" => $this->redditClient->authorizationUrl(
                $request->query->has("all") ? "default" : "identity",
                $request->query->has("all") ? "permanent" : "temporary"
            ),
        ]);
    }

    /**
     * @Route("/", name="_index")
     */
    public function index(Request $request): Response
    {
        $code = $request->query->get("code");
        return $this->redirect(
            $this->getParameter("app.front_url") .
                "/login?" .
                http_build_query([
                    "oauth" => "reddit",
                    "code" => is_string($code) ? $code : "",
                ])
        );
    }
}
