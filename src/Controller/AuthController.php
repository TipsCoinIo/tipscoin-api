<?php

namespace App\Controller;

use App\Entity\Config;
use App\Entity\Transaction;
use App\Entity\TwitterTransactionHash;
use App\Entity\User;
use App\Entity\UserNotification;
use App\Service\Bot\Providers;
use App\Service\LockFactory;
use App\Service\RedditClient;
use App\Service\RedditUserRegister;
use App\Service\TelegramClient;
use App\Service\TelegramUserRegister;
use App\Service\TwitterClient;
use App\Service\TwitterUserRegister;
use App\Service\UserToken;
use Decimal\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/auth")
 */
class AuthController extends AbstractController
{
    /** @var RedditClient  */
    protected $redditClient;

    /** @var TwitterClient  */
    protected $twitterClient;

    /** @var TelegramClient  */
    protected $telegramClient;

    /** @var RedditUserRegister  */
    protected $redditUserRegister;

    /** @var TwitterUserRegister  */
    protected $twitterUserRegister;

    /** @var TelegramUserRegister  */
    protected $telegramUserRegister;

    /** @var UserToken  */
    protected $userToken;

    /** @var EntityManagerInterface  */
    protected $entityManager;

    public function __construct(
        RedditClient $redditClient,
        TwitterClient $twitterClient,
        TelegramClient $telegramClient,
        RedditUserRegister $redditUserRegister,
        TwitterUserRegister $twitterUserRegister,
        TelegramUserRegister $telegramUserRegister,
        UserToken $userToken,
        EntityManagerInterface $entityManager
    ) {
        $this->redditClient = $redditClient;
        $this->twitterClient = $twitterClient;
        $this->telegramClient = $telegramClient;
        $this->redditUserRegister = $redditUserRegister;
        $this->twitterUserRegister = $twitterUserRegister;
        $this->telegramUserRegister = $telegramUserRegister;
        $this->userToken = $userToken;
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/logout")
     */
    public function logout(): Response
    {
        /** @var User $u */
        $u = $this->getUser();
        if ($u) {
            $u->setAccessToken(null);
            $u->setRefreshToken(null);
            $u->setRefreshTokenValid(null);
            $this->entityManager->persist($u);
            $this->entityManager->flush();
            return $this->json([]);
        }
        return $this->json(["message" => "unknown error"], 401);
    }

    /**
     * @Route("/refresh")
     */
    public function refresh(Request $request): Response
    {
        if ($request->request->has("refresh_token")) {
            $rt = $request->request->get("refresh_token");
            if (is_string($rt) && preg_match("/^(\d+)\./", $rt, $out)) {
                $u = $this->entityManager
                    ->getRepository(User::class)
                    ->find($out[1]);
                if (
                    $u &&
                    $u->getRefreshTokenValid() &&
                    $u->getRefreshTokenValid()->getTimestamp() > time() &&
                    $u->getRefreshToken() &&
                    $u->getRefreshToken() === $rt
                ) {
                    $token = $this->userToken->create($u);
                    return $this->json([
                        "token" => $token,
                        "refresh_token" => $u->getRefreshToken(),
                    ]);
                }
            }
        }

        return $this->json(["message" => "unknown error"], 401);
    }

    /**
     * @Route("/login")
     */
    public function index(Request $request): Response
    {
        if ($request->request->has("authCode")) {
            $authCode = trim($request->request->get("authCode"));
            if (!preg_match('/^[A-Za-z0-9]{20}$/', $authCode)) {
                return $this->json(["message" => "incorrect auth code"], 401);
            }
            $user = $this->entityManager
                ->getRepository(User::class)
                ->findOneBy([
                    "authCode" => $authCode,
                ]);
            if (!$user) {
                return $this->json(["message" => "incorrect auth code"], 401);
            }
            if (
                $user->getAuthCodeExpires() === null ||
                $user->getAuthCodeExpires()->getTimestamp() < time()
            ) {
                return $this->json(["message" => "auth code expired"], 401);
            }
            $user->setAuthCodeExpires(new \DateTime("+1day"));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $token = $this->userToken->create($user);
            return $this->json([
                "token" => $token,
                "refresh_token" => $user->getRefreshToken(),
            ]);
        } elseif (
            $request->request->has("oauth") &&
            $request->request->has("code")
        ) {
            if ($request->request->get("oauth") === "telegram") {
                $data = $this->telegramClient->parseLoginCode(
                    $request->request->get("code")
                );
                if (empty($data["username"])) {
                    return $this->json(
                        [
                            "message" =>
                                "you must set telegram username before login",
                        ],
                        400
                    );
                }
                $user = $this->telegramUserRegister->register($data);

                $av = $data["photo_url"] ?? null;
                if ($av) {
                    $user->setAvatar("telegram", $av);
                }

                $token = $this->userToken->create($user);
                return $this->json([
                    "token" => $token,
                    "refresh_token" => $user->getRefreshToken(),
                ]);
            }
            if ($request->request->get("oauth") === "twitter") {
                $token = $this->twitterClient->accessToken(
                    $request->request->get("code")
                );
                $res = $this->twitterClient->request(
                    "users/me",
                    "GET",
                    [
                        "user.fields" => "profile_image_url",
                    ],
                    [
                        "Authorization" => "Bearer " . $token,
                    ]
                );
                if (!empty($res["data"]["username"])) {
                    $user = $this->twitterUserRegister->register($res);
                    $user->setAvatar(
                        "twitter",
                        $res["data"]["profile_image_url"]
                    );
                    $token = $this->userToken->create($user);
                    return $this->json([
                        "token" => $token,
                        "refresh_token" => $user->getRefreshToken(),
                    ]);
                }
            }
            if ($request->request->get("oauth") === "reddit") {
                $token = $this->redditClient->accessToken(
                    $request->request->get("code")
                );
                $token["expires"] = time() + 3600;
                $this->redditClient->setToken($token);
                $res = $this->redditClient->request(
                    "https://oauth.reddit.com/api/v1/me"
                );
                if (!empty($res["name"])) {
                    if (
                        $res["name"] === "tipscoinbot" &&
                        !empty($token["scope"]) &&
                        strpos($token["scope"], "privatemessages") !== false
                    ) {
                        $c = $this->entityManager
                            ->getRepository(Config::class)
                            ->findOneBy(["name" => "reddit_token"]);
                        if (!$c) {
                            $c = new Config();
                            $c->setName("reddit_token");
                        }
                        $c->setValue($token);
                        $this->entityManager->persist($c);
                        $this->entityManager->flush();
                    }

                    $user = $this->redditUserRegister->register($res);
                    $redditAvatar = !empty($res["snoovatar_img"])
                        ? $res["snoovatar_img"]
                        : $res["icon_img"] ?? null;
                    $user->setAvatar("reddit", $redditAvatar);
                    $token = $this->userToken->create($user);
                    return $this->json([
                        "token" => $token,
                        "refresh_token" => $user->getRefreshToken(),
                    ]);
                }
            }
        }
        return $this->json(["message" => "unknown error"], 401);
    }

    /**
     * @Route("/connect")
     */
    public function connect(Request $request, Providers $providers): Response
    {
        /** @var User $u */
        $u = $this->getUser();

        $connectData = [];

        $oauth = $request->request->get("oauth");
        if ($oauth === "reddit" && $u->getRedditId() === null) {
            $token = $this->redditClient->accessToken(
                $request->request->get("code")
            );
            $token["expires"] = time() + 3600;
            $this->redditClient->setToken($token);
            $res = $this->redditClient->request(
                "https://oauth.reddit.com/api/v1/me"
            );
            if (!empty($res["name"])) {
                $redditAvatar = !empty($res["snoovatar_img"])
                    ? $res["snoovatar_img"]
                    : $res["icon_img"] ?? null;
                $connectData = [
                    "provider" => "reddit",
                    "id" => $res["id"],
                    "name" => $res["name"],
                    "avatar" => $redditAvatar,
                ];
            }
        }
        if ($oauth === "twitter" && $u->getTwitterId() === null) {
            $token = $this->twitterClient->accessToken(
                $request->request->get("code")
            );
            $res = $this->twitterClient->request(
                "users/me",
                "GET",
                [
                    "user.fields" => "profile_image_url",
                ],
                [
                    "Authorization" => "Bearer " . $token,
                ]
            );
            if (!empty($res["data"]["username"])) {
                $connectData = [
                    "provider" => "twitter",
                    "id" => $res["data"]["id"],
                    "name" => $res["data"]["username"],
                    "avatar" => $res["data"]["profile_image_url"],
                ];
            }
        }
        if ($oauth === "telegram" && $u->getTelegramId() === null) {
            $data = $this->telegramClient->parseLoginCode(
                $request->request->get("code")
            );
            if (empty($data["username"])) {
                return $this->json(
                    [
                        "message" =>
                            "you must set telegram username before login",
                    ],
                    400
                );
            }
            $connectData = [
                "provider" => "telegram",
                "id" => $data["id"],
                "name" => $data["username"],
                "avatar" => $data["photo_url"] ?? null,
            ];
        }

        if (count($connectData) > 0) {
            $um = $this->entityManager->getRepository(User::class)->findOneBy([
                $connectData["provider"] . "Id" => $connectData["id"],
            ]);

            if ($um) {
                $providerNames = array_keys($providers->providers);
                foreach ($providerNames as $providerName) {
                    if ($providerName === $connectData["provider"]) {
                        continue;
                    }

                    $m = "get" . ucfirst($providerName) . "Id";
                    if ($um->$m() !== null) {
                        return $this->json(
                            ["message" => "user already exist in database"],
                            400
                        );
                    }
                }

                $mid = "set" . ucfirst($connectData["provider"]) . "Id";
                $mname = "set" . ucfirst($connectData["provider"]) . "Name";
                $um->$mid(null);
                $um->$mname(null);
                $balance = new Decimal($um->getBalance());
                $um->setBalance("0");
                $walletAddress = $um->getWalletAddress();
                $um->setWalletAddress(null);
                $this->entityManager->persist($um);
                $this->entityManager->flush();

                $mid = "set" . ucfirst($connectData["provider"]) . "Id";
                $mname = "set" . ucfirst($connectData["provider"]) . "Name";
                $u->$mid($connectData["id"]);
                $u->$mname($connectData["name"]);
                $u->setAvatar($connectData["provider"], $connectData["avatar"]);
                $u->setBalance(
                    (new Decimal($u->getBalance()))->add($balance)->toString()
                );
                if ($walletAddress && !$u->getWalletAddress()) {
                    $u->setWalletAddress($walletAddress);
                }
                $this->entityManager->persist($u);
                $this->entityManager->flush();

                $t = new Transaction();
                $t->setUser($u);
                $t->setType("account_merge");
                $t->setValue($balance->toString());
                $t->setBalanceAfter($u->getBalance());
                $this->entityManager->persist($t);
                $this->entityManager->flush();

                $sm = $this->entityManager
                    ->getConnection()
                    ->createSchemaManager();
                $rows = [];
                foreach ($sm->listTables() as $table) {
                    foreach ($table->getForeignKeys() as $foreignKey) {
                        if ($foreignKey->getForeignTableName() == "user") {
                            $rows[] = [
                                "table" => $table->getName(),
                                "column" => $foreignKey->getLocalColumns()[0],
                            ];
                        }
                    }
                }

                foreach ($rows as $row) {
                    $this->entityManager
                        ->createNativeQuery(
                            "DELETE FROM `" .
                                $row["table"] .
                                "` WHERE `" .
                                $row["column"] .
                                "`=" .
                                $um->getId(),
                            new ResultSetMapping()
                        )
                        ->execute();
                }

                $this->entityManager->remove($um);
                $this->entityManager->flush();

                return $this->json([]);
            } else {
                $mid = "set" . ucfirst($connectData["provider"]) . "Id";
                $mname = "set" . ucfirst($connectData["provider"]) . "Name";
                $u->$mid($connectData["id"]);
                $u->$mname($connectData["name"]);
                $u->setAvatar($connectData["provider"], $connectData["avatar"]);
                $this->entityManager->persist($u);
                $this->entityManager->flush();
                return $this->json([]);
            }
        }

        return $this->json([]);
    }

    /**
     * @Route("/user")
     */
    public function user(Request $request, LockFactory $lockFactory): Response
    {
        /** @var User $u */
        $u = $this->getUser();

        $lock = $lockFactory->createLock("u" . $u->getId(), 10);
        if ($lock->acquire()) {
            try {
                $u = $this->entityManager
                    ->getRepository(User::class)
                    ->find($u->getId());
                if ($u->getFirstLogin() === null) {
                    $u->setBalance(
                        (new Decimal($u->getBalance()))->add("1000")
                    );

                    $t = new Transaction();
                    $t->setUser($u);
                    $t->setBalanceAfter($u->getBalance());
                    $t->setValue("1000");
                    $t->setType("first_login");
                    $this->entityManager->persist($t);

                    $n = new UserNotification();
                    $n->setUser($u);
                    $n->setMessage("You get free 1000 Tips");
                    $this->entityManager->persist($n);

                    $u->setFirstLogin(true);
                    $this->entityManager->persist($u);
                    $this->entityManager->flush();
                }
            } finally {
                $lock->release();
            }
        }

        if ($request->query->has("disableFirstLogin")) {
            $u->setFirstLogin(false);
            $this->entityManager->persist($u);
            $this->entityManager->flush();
        }

        $unreadCount = intval(
            $this->entityManager
                ->getRepository(UserNotification::class)
                ->createQueryBuilder("un")
                ->addSelect("COUNT(un.id) as cnt")
                ->andWhere("un.user = :user")
                ->setParameter("user", $u)
                ->andWhere("un.unread = 1")
                ->getQuery()
                ->getOneOrNullResult()["cnt"]
        );

        if (!empty($u->getTwitterName())) {
            $this->entityManager
                ->getRepository(TwitterTransactionHash::class)
                ->createQueryBuilder("t")
                ->update()
                ->set("t.status", ":status")
                ->setParameter("status", TwitterTransactionHash::CLAIMED)
                ->andWhere("t.target = :target")
                ->setParameter("target", $u->getTwitterName())
                ->andWhere("t.status = :s1")
                ->setParameter("s1", TwitterTransactionHash::SENT)
                ->getQuery()
                ->execute();
        }

        return $this->json([
            "user" => [
                "id" => $u->getId(),
                "username" => $u->getUsername(),
                "avatar" => $u->getAvatar(),
                "avatars" => $u->getAvatars(),
                "redditName" => $u->getRedditName(),
                "twitterName" => $u->getTwitterName(),
                "telegramName" => $u->getTelegramName(),
                "balance" => $u->getBalance(),
                "firstLogin" => $u->getFirstLogin(),
                "walletAddress" => $u->getWalletAddress(),
                "unreadNotifications" => $unreadCount,
                "role" => $u->getRoles(),
            ],
        ]);
    }
}
