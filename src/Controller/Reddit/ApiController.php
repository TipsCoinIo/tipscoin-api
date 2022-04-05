<?php

namespace App\Controller\Reddit;

use App\Service\RedditClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/reddit", name="reddit_api")
 */
class ApiController extends AbstractController
{
    /** @var RedditClient */
    protected $redditClient;

    /**
     * @required
     * @param RedditClient $redditClient
     */
    public function setRedditClient(RedditClient $redditClient): void
    {
        $this->redditClient = $redditClient;
    }

    /**
     * @Route("/user/search/{q}", name="_user_search")
     */
    public function userSearch($q): Response
    {
        if (!preg_match(RedditClient::USERNAME_REGEX, $q)) {
            return $this->json(["message" => "user not found"], 400);
        }

        try {
            $res = $this->redditClient->request(
                "https://oauth.reddit.com/user/" . $q . "/about"
            )["data"];
        } catch (\Exception $e) {
            return $this->json(["message" => "user not found"], 400);
        }

        return $this->json([
            "id" => $res["id"],
            "name" => $res["name"],
            "icon_img" => !empty($res["snoovatar_img"])
                ? $res["snoovatar_img"]
                : $res["icon_img"] ?? null,
        ]);
    }

    /**
     * @Route("/link/search/{q}", name="_link_search", requirements={"q":"[A-Za-z0-9]+"})
     */
    public function linkSearch($q): Response
    {
        $res = $this->redditClient->request(
            "https://oauth.reddit.com/api/info",
            [
                "id" => "t3_" . $q,
            ]
        );

        if (
            isset($res["data"]["children"][0]["kind"]) &&
            isset($res["data"]["children"][0]["data"]["id"]) &&
            isset($res["data"]["children"][0]["kind"]) == "t3" &&
            $res["data"]["children"][0]["data"]["id"] == $q
        ) {
            $p = $res["data"]["children"][0]["data"];
            return $this->userSearch($p["author"]);
        }

        return $this->json(["message" => "link not found"], 400);
    }

    /**
     * @Route("/comment/search/{q}", name="_comment_search", requirements={"q":"[A-Za-z0-9]+"})
     */
    public function commentSearch($q): Response
    {
        $res = $this->redditClient->request(
            "https://oauth.reddit.com/api/info",
            [
                "id" => "t1_" . $q,
            ]
        );

        if (
            isset($res["data"]["children"][0]["kind"]) &&
            isset($res["data"]["children"][0]["data"]["id"]) &&
            isset($res["data"]["children"][0]["kind"]) == "t1" &&
            $res["data"]["children"][0]["data"]["id"] == $q
        ) {
            $p = $res["data"]["children"][0]["data"];

            return $this->userSearch($p["author"]);
        }

        return $this->json(["message" => "comment not found"], 400);
    }
}
