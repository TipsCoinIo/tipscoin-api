<?php

namespace App\Controller\Telegram;

use App\Entity\ReferralProgramJoinedUser;
use App\Entity\ReferralProgramTelegramLink;
use App\Entity\TelegramCaptcha;
use App\Entity\TelegramWebhook;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserNotification;
use App\Service\LockFactory;
use App\Service\TelegramClient;
use App\Service\TelegramUserRegister;
use App\Service\TipsTransfer;
use App\Service\UserToken;
use Decimal\Decimal;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Gregwar\Captcha\CaptchaBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\ByteString;

/**
 * @Route("/telegram", name="telegram_api_")
 */
class BotController extends AbstractController
{
    /** @var TelegramClient */
    protected $telegramClient;
    /**
     * @var KernelInterface
     */
    private $kernel;
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;
    /**
     * @var TelegramUserRegister
     */
    private $telegramUserRegister;
    /**
     * @var UserToken
     */
    private $userToken;
    /**
     * @var TipsTransfer
     */
    private $tipsTransfer;

    public function __construct(
        KernelInterface $kernel,
        EntityManagerInterface $entityManager,
        TelegramUserRegister $telegramUserRegister,
        UserToken $userToken,
        TipsTransfer $tipsTransfer
    ) {
        $this->kernel = $kernel;
        $this->entityManager = $entityManager;
        $this->telegramUserRegister = $telegramUserRegister;
        $this->userToken = $userToken;
        $this->tipsTransfer = $tipsTransfer;
    }

    /**
     * @required
     * @param TelegramClient $telegramClient
     */
    public function setTelegramClient(TelegramClient $telegramClient): void
    {
        $this->telegramClient = $telegramClient;
    }

    public static function captchaPhrase()
    {
        return ByteString::fromRandom(
            5,
            "123456789ABCDEFGHJKMNPQRSTUVWXYZ"
        )->toString();
    }

    /**
     * @Route("/bot/captcha/{userId}.jpg", name="captcha_image")
     */
    public function captchaImage($userId, $return = false)
    {
        $tc = $this->entityManager
            ->getRepository(TelegramCaptcha::class)
            ->findOneBy(["telegramUserId" => $userId]);
        if (!$tc) {
            return new Response("", 404);
        }

        $builder = new CaptchaBuilder($tc->getPhrase());
        $builder->setTextColor(255, 0, 106);
        $builder->setMaxAngle(20);
        $builder->setIgnoreAllEffects(true);
        $builder->setBackgroundImages([
            $this->kernel->getProjectDir() . "/assets/img/captcha.jpg",
        ]);
        $builder->build(400, 200);
        if ($return) {
            return $builder->get();
        }

        return new Response($builder->get(), 200, [
            "Content-Type" => "image/jpeg",
        ]);
    }

    /**
     * @Route("/bot/{token}", name="bot")
     */
    public function bot($token, Request $request): Response
    {
        if (!$this->telegramClient->verifyWebhookToken($token)) {
            return $this->json(["message" => "invalid token"], 401);
        }

        file_put_contents(
            $this->kernel->getProjectDir() . "/public/telegram.txt",
            json_encode($request->request->all())
        );

        try {
            $this->processMessageToBot($request->request->all());
        } catch (\Exception $e) {
            file_put_contents(
                $this->kernel->getProjectDir() . "/public/telegram-error.txt",
                "error " .
                    $e->getMessage() .
                    " line " .
                    $e->getLine() .
                    " in " .
                    $e->getFile() .
                    "\n" .
                    $e->getTraceAsString() .
                    "\n" .
                    json_encode($request->request->all())
            );
        }

        return $this->json([]);
    }

    public function processMessageToBot($data)
    {
        $twh = new TelegramWebhook();
        $twh->setData($data);
        $this->entityManager->persist($twh);
        $this->entityManager->flush();

        $this->processCaptcha($data);

        if (!empty($data["message"]["left_chat_member"]["id"])) {
            $this->entityManager
                ->getRepository(ReferralProgramJoinedUser::class)
                ->createQueryBuilder("ru")
                ->delete()
                ->andWhere("ru.telegramId = :tid")
                ->setParameter(
                    "tid",
                    $data["message"]["left_chat_member"]["id"]
                )
                ->getQuery()
                ->execute();
        }

        if (!empty($data["chat_member"]["invite_link"]["invite_link"])) {
            $from = $data["chat_member"]["from"];

            if (
                $this->entityManager
                    ->getRepository(ReferralProgramJoinedUser::class)
                    ->findOneBy(["telegramId" => $from["id"]])
            ) {
                return;
            }

            $link = $this->entityManager
                ->getRepository(ReferralProgramTelegramLink::class)
                ->findOneBy([
                    "link" =>
                        $data["chat_member"]["invite_link"]["invite_link"],
                ]);

            if (!$link) {
                return;
            }

            $joinedUser = new ReferralProgramJoinedUser();
            $joinedUser->setLink($link);
            $joinedUser->setJoinAt(new \DateTime());
            $joinedUser->setTelegramId($from["id"]);
            $joinedUser->setData($from);
            $this->entityManager->persist($joinedUser);
            $this->entityManager->flush();

            return;
        }

        $message = $data["message"] ?? null;
        $callbackQuery = $data["callback_query"] ?? null;

        if (!$message && !$callbackQuery) {
            return;
        }

        $chatId =
            $callbackQuery["message"]["chat"]["id"] ?? $message["chat"]["id"];
        $sender = $callbackQuery["from"] ?? $message["from"];
        $senderUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(["telegramId" => $sender["id"]]);

        if ($chatId === $sender["id"]) {
            $this->processPrivate($chatId, $sender);
            return;
        }

        if (
            !empty($callbackQuery["data"]) &&
            $callbackQuery["data"] == "send_tips"
        ) {
            if (!$senderUser) {
                $mention = $this->mentionPart($callbackQuery["from"]);
                $this->telegramClient->request("sendMessage", [
                    "chat_id" => $chatId,
                    "text" =>
                        $mention["text"] .
                        " Before sending coins for the first time, you must set Telegram username and log in to the user panel.",
                    "entities" => [$mention["entity"]],
                    "reply_markup" => [
                        "inline_keyboard" => [[$this->buttons()["dashboard"]]],
                    ],
                ]);
                $this->telegramClient->request("deleteMessage", [
                    "chat_id" => $chatId,
                    "message_id" => $callbackQuery["message"]["message_id"],
                ]);
                return;
            }

            $senderUser->setTelegramDialog(["t" => time(), "s" => 0]);
            $this->entityManager->persist($senderUser);
            $this->entityManager->flush();

            $this->tipsSteps($senderUser, $callbackQuery["message"], true);
            return;
        }

        if (
            !empty($callbackQuery["data"]) &&
            $callbackQuery["data"] ===
                "cancel_tips_" . $callbackQuery["from"]["id"]
        ) {
            if ($senderUser) {
                $senderUser->setTelegramDialog(null);
                $this->entityManager->persist($senderUser);
                $this->entityManager->flush();
            }
            $this->telegramClient->request("deleteMessage", [
                "chat_id" => $chatId,
                "message_id" => $callbackQuery["message"]["message_id"],
            ]);
            return;
        }

        if ($message && array_key_exists("text", $message)) {
            $text = trim($message["text"]);

            if (preg_match("/^\/tip/i", $text)) {
                if (preg_match("/^\/tip[^\s]*\s+[^\s]+/i", $text)) {
                    if (!$senderUser) {
                        $this->telegramClient->request("sendMessage", [
                            "chat_id" => $chatId,
                            "text" =>
                                "Before sending coins for the first time, you must set Telegram username and log in to the user panel.",
                            "reply_markup" => [
                                "inline_keyboard" => [
                                    [$this->buttons()["dashboard"]],
                                ],
                            ],
                        ]);
                        return;
                    }

                    $p = $this->parseTipMessage($senderUser, $message);

                    if (is_string($p)) {
                        $this->telegramClient->request("sendMessage", [
                            "chat_id" => $chatId,
                            "reply_to_message_id" => $message["message_id"],
                            "text" => $p,
                            "parse_mode" => "html",
                        ]);
                    } else {
                        $done = false;
                        try {
                            $done = $this->tipsTransfer->transfer(
                                $senderUser,
                                $p["target"],
                                $p["amount"],
                                ["providerName" => "telegram"]
                            );
                        } catch (\Exception $e) {
                        }

                        if ($done) {
                            $this->telegramClient->request("sendMessage", [
                                "chat_id" => $chatId,
                                "reply_to_message_id" => $message["message_id"],
                                "text" =>
                                    "Congratulations! You have sent user @" .
                                    $p["target"]->getTelegramName() .
                                    " " .
                                    sprintf("%g", $p["amount"]->toString()) .
                                    " coins. @" .
                                    $p["target"]->getTelegramName() .
                                    " to claim your Tips, log in to the user dashboard",
                                "reply_markup" => [
                                    "inline_keyboard" => [
                                        [$this->buttons()["dashboard"]],
                                    ],
                                ],
                            ]);
                            $t = $this->entityManager
                                ->getRepository(Transaction::class)
                                ->findOneBy(
                                    [
                                        "user" => $p["target"],
                                        "provider" => "telegram",
                                        "type" => "transfer",
                                    ],
                                    ["id" => "DESC"]
                                );
                            $photo =
                                $this->kernel
                                    ->getContainer()
                                    ->getParameter("app.front_url") .
                                "/api/transaction-image/" .
                                $t->getId() .
                                "/" .
                                $t->getImageHash();
                            $this->telegramClient->request("sendPhoto", [
                                "chat_id" => $chatId,
                                "photo" => $photo,
                            ]);
                        } else {
                            $this->telegramClient->request("sendMessage", [
                                "chat_id" => $chatId,
                                "reply_to_message_id" => $message["message_id"],
                                "text" => "Error sending Tips",
                            ]);
                        }
                    }
                } else {
                    if ($senderUser) {
                        $senderUser->setTelegramDialog(null);
                        $this->entityManager->persist($senderUser);
                        $this->entityManager->flush();
                    }

                    $this->telegramClient->request("sendMessage", [
                        "chat_id" => $chatId,
                        "reply_to_message_id" => $message["message_id"],
                        "text" => $this->commands([
                            "name" => !empty($sender["username"])
                                ? "@" . $sender["username"]
                                : $sender["first_name"],
                        ]),
                        "reply_markup" => [
                            "inline_keyboard" => [
                                [
                                    $this->buttons()["send_tips"],
                                    $this->buttons()["dashboard"],
                                ],
                            ],
                        ],
                        "parse_mode" => "html",
                    ]);
                }
                return;
            }

            if ($senderUser) {
                $t = $senderUser->getTelegramDialog()["t"] ?? 0;

                if ($t > time() - 120) {
                    $this->tipsSteps($senderUser, $message);
                } else {
                    $senderUser->setTelegramDialog(null);
                    $this->entityManager->persist($senderUser);
                    $this->entityManager->flush();
                }
            }
        }
    }

    public function processPrivate($chatId, $sender)
    {
        $mention = $this->mentionPart($sender);
        $this->telegramClient->request("sendMessage", [
            "chat_id" => $chatId,
            "text" =>
                $mention["text"] .
                " " .
                "Sending coins is only possible in groups to which TipsCoinBot is added. If it is not in your group, ask the administrator to add it. Below the user panel. Log in to manage your account",
            "reply_markup" => [
                "inline_keyboard" => [[$this->buttons()["dashboard"]]],
            ],
            "parse_mode" => "html",
            "entities" => [$mention["entity"]],
        ]);
        return $this->json([]);
    }

    public function commands($opt)
    {
        $lines = [];
        $lines[] = "<b>Available Commands:</b>";
        $lines[] = "/tip [amount] Tips @username ";
        $lines[] = "";
        $lines[] = "<b>Example:</b>";
        $lines[] = "/tip 1000 Tips @user";
        $lines[] = "";
        $lines[] = "You can also use the commands in the form of buttons:";
        return implode("\n", $lines);
    }

    public function targetUserFromMessage($message, $rawUsername = null)
    {
        $username = null;

        foreach ($message["entities"] as $entity) {
            if ($entity["type"] === "text_mention") {
                $ret = [
                    "id" => $entity["user"]["id"],
                    "first_name" => $entity["user"]["first_name"],
                ];
                if (!empty($entity["user"]["username"])) {
                    $ret["username"] = $entity["user"]["username"];
                }
                return $ret;
            }
            if ($entity["type"] === "mention") {
                $username = substr(
                    $message["text"],
                    $entity["offset"] + 1,
                    $entity["length"] - 1
                );
                break;
            }
        }

        if (empty($username)) {
            if ($rawUsername) {
                $username = $rawUsername;
            } else {
                $username = preg_split("/\s+/", trim($message["text"]))[0];
            }
        }

        if (empty($username)) {
            throw new \Exception("invalid username");
        }

        try {
            return $this->telegramUserRegister->usernameExist($username);
        } catch (\Exception $e) {
            throw new \Exception("The given user doesn't exist.");
        }
    }

    public function tipsSteps(
        User $senderUser,
        $message,
        $disableProcessing = false
    ) {
        $steps = [
            [
                "text" =>
                    "Enter the login of the user to whom you want to send coins:",
                "cancelButton" => true,
                "validator" => function ($val) use ($senderUser) {
                    try {
                        $data = $this->targetUserFromMessage($val);
                    } catch (\Exception $e) {
                        return $e->getMessage();
                    }
                    $user = $this->entityManager
                        ->getRepository(User::class)
                        ->findOneBy(["telegramId" => $data["id"]]);
                    if ($user && $user->getId() == $senderUser->getId()) {
                        return "You can't send yourself Tips.";
                    }
                    return ["data" => $data];
                },
                "property" => "telegramId",
            ],
            [
                "text" => "Enter the number of coins you want to send:",
                "cancelButton" => true,
                "validator" => function ($val) use ($senderUser) {
                    if (!preg_match('/^[1-9]\d*$/s', $val["text"])) {
                        return "incorrect format (e.g. 1000)";
                    }

                    try {
                        $amount = new Decimal($val["text"]);
                    } catch (\Exception $e) {
                        return "incorrect format (e.g. 1000)";
                    }

                    if ($amount < new Decimal("500")) {
                        return "I'm sorry, but you need to send a minimum of 500 tips";
                    }

                    if ($amount > new Decimal($senderUser->getBalance())) {
                        return "I'm sorry but you don't have enough coins to do this.";
                    }

                    return ["data" => $amount->toString()];
                },
                "property" => "amount",
            ],
        ];

        $step = $steps[$senderUser->getTelegramDialog()["s"]];

        $req = [
            "chat_id" => $message["chat"]["id"],
        ];

        $photo = null;

        if ($disableProcessing) {
            $this->telegramClient->request("deleteMessage", [
                "chat_id" => $message["chat"]["id"],
                "message_id" => $message["message_id"],
            ]);

            $mention = $this->mentionPart($message["reply_to_message"]["from"]);
            $req["text"] = $mention["text"] . " " . $step["text"];
            $req["entities"] = [$mention["entity"]];
        } else {
            $this->telegramClient->request("deleteMessage", [
                "chat_id" => $message["chat"]["id"],
                "message_id" => $senderUser->getTelegramDialog()["m"],
            ]);

            $ret = $step["validator"]($message);

            if (is_string($ret)) {
                $td = $senderUser->getTelegramDialog();
                $td["t"] = time();
                $senderUser->setTelegramDialog($td);
                $this->entityManager->persist($senderUser);
                $this->entityManager->flush();

                $req["text"] = $ret . "\n\n" . $step["text"];
            } else {
                $td = $senderUser->getTelegramDialog();
                $td["t"] = time();
                $td["_" . $senderUser->getTelegramDialog()["s"]] = $ret["data"];
                $td["s"] = $senderUser->getTelegramDialog()["s"] + 1;
                $senderUser->setTelegramDialog($td);
                $this->entityManager->persist($senderUser);
                $this->entityManager->flush();

                $nextStep = $steps[$td["s"]] ?? null;

                if ($nextStep) {
                    $step = $nextStep;
                    $req["text"] = $step["text"];
                } else {
                    $step = null;

                    $data = [];
                    foreach ($steps as $k => $s) {
                        if (isset($td["_" . $k])) {
                            $data[$s["property"]] = $td["_" . $k];
                        }
                    }

                    $senderUser->setTelegramDialog(null);
                    $this->entityManager->persist($senderUser);
                    $this->entityManager->flush();

                    $toUser = $this->telegramUserRegister->register(
                        $data["telegramId"]
                    );
                    $done = false;
                    try {
                        $done = $this->tipsTransfer->transfer(
                            $senderUser,
                            $toUser,
                            new Decimal($data["amount"]),
                            [
                                "providerName" => "telegram",
                            ]
                        );
                    } catch (\Exception $e) {
                    }

                    if ($done) {
                        $mention = !empty($data["telegramId"]["username"])
                            ? "@" . $data["telegramId"]["username"]
                            : $data["telegramId"]["first_name"];

                        $req["text"] =
                            "Congratulations! You have sent user " .
                            $mention .
                            " " .
                            sprintf("%g", $data["amount"]) .
                            " coins. " .
                            $mention .
                            " to claim your Tips, log in to the user dashboard";
                        $req["reply_markup"] = [
                            "inline_keyboard" => [
                                [$this->buttons()["dashboard"]],
                            ],
                        ];
                        $t = $this->entityManager
                            ->getRepository(Transaction::class)
                            ->findOneBy(
                                [
                                    "user" => $toUser,
                                    "provider" => "telegram",
                                    "type" => "transfer",
                                ],
                                ["id" => "DESC"]
                            );
                        $photo =
                            $this->kernel
                                ->getContainer()
                                ->getParameter("app.front_url") .
                            "/api/transaction-image/" .
                            $t->getId() .
                            "/" .
                            $t->getImageHash();
                    } else {
                        $req["text"] = "Error sending tips";
                    }
                }
            }

            $mention = $this->mentionPart($message["from"]);
            $req["text"] = $mention["text"] . " " . $req["text"];
            $req["entities"] = [$mention["entity"]];
        }

        if ($step && $step["cancelButton"]) {
            $cancel = $this->buttons()["cancel_tips"];

            $cancel["callback_data"] .=
                "_" .
                ($disableProcessing ? $message["reply_to_message"] : $message)[
                    "from"
                ]["id"];

            $req["reply_markup"] = [
                "inline_keyboard" => [[$cancel]],
            ];
        }

        $res = $this->telegramClient->request("sendMessage", $req);
        $td = $senderUser->getTelegramDialog();
        $td["m"] = $res["result"]["message_id"];
        $senderUser->setTelegramDialog($td);
        $this->entityManager->persist($senderUser);
        $this->entityManager->flush();
        if ($photo) {
            $this->telegramClient->request("sendPhoto", [
                "chat_id" => $req["chat_id"],
                "photo" => $photo,
            ]);
        }
    }

    public function buttons()
    {
        return [
            "send_tips" => [
                "text" => "💰 Send Tips",
                "callback_data" => "send_tips",
            ],
            "cancel_tips" => [
                "text" => "❌ cancel",
                "callback_data" => "cancel_tips",
            ],
            "dashboard" => [
                "text" => "🎛️ User Dashboard",
                "login_url" => [
                    "url" => "https://bot.tipscoin.io/api/telegram/auth",
                ],
            ],
        ];
    }

    public function parseTipMessage(User $sender, $message)
    {
        $text = preg_split("/\s+/", $message["text"]);

        if (count($text) < 4) {
            return "Invalid command. Use e.g. /tip 1000 Tips @user";
        }

        if (strtolower($text[2]) !== "tips") {
            return "You can only send Tips.";
        }

        if (!preg_match('/^[1-9]\d*$/s', $text[1])) {
            return "incorrect format (e.g. 1000)";
        }

        try {
            $amount = new Decimal($text[1]);
        } catch (\Exception $e) {
            return "Invalid amount. Use e.g. /tip 1000 Tips @user";
        }

        if ($amount < new Decimal("500")) {
            return "I'm sorry, but you need to send a minimum of 500 tips";
        }

        try {
            $data = $this->targetUserFromMessage(
                $message,
                ltrim("@", $text[3])
            );
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        $targetUser = $this->telegramUserRegister->register($data);

        if ($sender->getId() == $targetUser->getId()) {
            return "You can't send yourself Tips.";
        }

        if (new Decimal($sender->getBalance()) < $amount) {
            return "I'm sorry but you don't have enough coins to do this.";
        }

        return [
            "amount" => $amount,
            "target" => $targetUser,
        ];
    }

    public function mentionPart($from)
    {
        if (empty($from["username"])) {
            return [
                "text" => $from["first_name"],
                "entity" => [
                    "type" => "text_mention",
                    "offset" => 0,
                    "length" => strlen($from["first_name"]),
                    "user" => $from,
                ],
            ];
        } else {
            return [
                "text" => "@" . $from["username"],
                "entity" => [
                    "type" => "mention",
                    "offset" => 0,
                    "length" => strlen($from["first_name"]) + 1,
                ],
            ];
        }
    }

    public function processCaptcha($data)
    {
        $chatId =
            $data["message"]["chat"]["id"] ??
            ($data["callback_query"]["message"]["chat"]["id"] ?? null);

        if ($chatId != -1001711928517) {
            return;
        }

        /** @var TelegramCaptcha[] $tcs */
        $tcs = $this->entityManager
            ->getRepository(TelegramCaptcha::class)
            ->createQueryBuilder("tc")
            ->andWhere("tc.createdAt < :dt")
            ->setParameter("dt", new \DateTime("-1minutes"))
            ->andWhere("tc.solved = 0")
            ->getQuery()
            ->getResult();

        foreach ($tcs as $tc) {
            foreach ($tc->getRelatedMessages() as $rm) {
                try {
                    $this->telegramClient->request("deleteMessage", [
                        "chat_id" => $chatId,
                        "message_id" => $rm,
                    ]);
                } catch (\Throwable $e) {
                }
            }

            try {
                $this->telegramClient->request("banChatMember", [
                    "chat_id" => $chatId,
                    "user_id" => $tc->getTelegramUserId(),
                    "until_date" => time() + 60,
                ]);
            } catch (\Exception $e) {
            }
            $this->entityManager->remove($tc);
            $this->entityManager->flush();
        }

        if (
            !empty($data["callback_query"]["data"]) &&
            !empty($data["callback_query"]["from"]["id"]) &&
            preg_match(
                '/^captcha_(\d+)_(.*?)$/',
                $data["callback_query"]["data"],
                $out
            ) &&
            $out[1] == $data["callback_query"]["from"]["id"]
        ) {
            $tc = $this->entityManager
                ->getRepository(TelegramCaptcha::class)
                ->findOneBy([
                    "telegramUserId" => $data["callback_query"]["from"]["id"],
                    "solved" => false,
                ]);
            if ($tc) {
                foreach ($tc->getRelatedMessages() as $rm) {
                    try {
                        $this->telegramClient->request("deleteMessage", [
                            "chat_id" => $chatId,
                            "message_id" => $rm,
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
                $tc->setRelatedMessages([]);
                $this->entityManager->persist($tc);
                $this->entityManager->flush();

                if ($out[2] === "new") {
                    $this->captchaSendImage($tc->getTelegramUserId(), $chatId);
                } else {
                    if ($out[2] == $tc->getPhrase()) {
                        $this->telegramClient->request("restrictChatMember", [
                            "chat_id" => $chatId,
                            "user_id" => $tc->getTelegramUserId(),
                            "permissions" => ["can_send_messages" => true],
                        ]);

                        $tc->setSolved(true);
                        $this->entityManager->persist($tc);
                        $this->entityManager->flush();
                    } else {
                        $this->telegramClient->request("banChatMember", [
                            "chat_id" => $chatId,
                            "user_id" => $tc->getTelegramUserId(),
                            "until_date" => time() + 60,
                        ]);
                        $this->entityManager->remove($tc);
                        $this->entityManager->flush();
                    }
                }
            }
        }

        if (!empty($data["message"]["new_chat_members"][0]["id"])) {
            foreach ($data["message"]["new_chat_members"] as $user) {
                try {
                    $this->processCaptchaNewChatMember($user, $chatId);
                } catch (\Throwable $t) {
                }
            }
        }

        if (!empty($data["message"]["left_chat_member"]["id"])) {
            $tc = $this->entityManager
                ->getRepository(TelegramCaptcha::class)
                ->findOneBy([
                    "telegramUserId" =>
                        $data["message"]["left_chat_member"]["id"],
                ]);
            if ($tc) {
                foreach ($tc->getRelatedMessages() as $rm) {
                    try {
                        $this->telegramClient->request("deleteMessage", [
                            "chat_id" => $chatId,
                            "message_id" => $rm,
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
                $this->entityManager->remove($tc);
                $this->entityManager->flush();
            }
        }
    }

    public function processCaptchaNewChatMember($data, $chatId)
    {
        $needCaptcha =
            $this->entityManager
                ->getRepository(TelegramCaptcha::class)
                ->findOneBy([
                    "telegramUserId" => $data["id"],
                    "solved" => true,
                ]) === null;
        if (!$needCaptcha) {
            return;
        }
        $this->telegramClient->request("restrictChatMember", [
            "chat_id" => $chatId,
            "user_id" => $data["id"],
            "permissions" => ["can_send_messages" => false],
        ]);
        $mention = $this->mentionPart($data);
        $res = $this->telegramClient->request("sendMessage", [
            "chat_id" => $chatId,
            "text" =>
                $mention["text"] .
                " To unlock access to the group, please solve the captcha.",
            "entities" => [$mention["entity"]],
        ]);
        $this->captchaSendImage($data["id"], $chatId, [
            $res["result"]["message_id"],
        ]);
    }

    public function captchaSendImage($userId, $chatId, $relatedMessages = [])
    {
        $phrase = self::captchaPhrase();

        $tc = $this->entityManager
            ->getRepository(TelegramCaptcha::class)
            ->findOneBy(["telegramUserId" => $userId]);
        if (!$tc) {
            $tc = new TelegramCaptcha();
            $tc->setTelegramUserId($userId);
        }
        $tc->setPhrase($phrase);
        $tc->setSolved(false);
        $tc->setCreatedAt(new \DateTime());
        $this->entityManager->persist($tc);
        $this->entityManager->flush();

        foreach ($tc->getRelatedMessages() as $rm) {
            try {
                $this->telegramClient->request("deleteMessage", [
                    "chat_id" => $chatId,
                    "message_id" => $rm,
                ]);
            } catch (\Throwable $e) {
            }
        }
        $tc->setRelatedMessages([]);
        $this->entityManager->persist($tc);
        $this->entityManager->flush();

        $phrases = [
            $phrase,
            self::captchaPhrase(),
            self::captchaPhrase(),
            self::captchaPhrase(),
            self::captchaPhrase(),
            self::captchaPhrase(),
        ];
        shuffle($phrases);

        $phrases = array_chunk($phrases, 3);

        $kb = [];
        foreach ($phrases as $phrase) {
            $line = [];

            foreach ($phrase as $p) {
                $line[] = [
                    "text" => $p,
                    "callback_data" => "captcha_" . $userId . "_" . $p,
                ];
            }

            $kb[] = $line;
        }

        $kb[] = [
            [
                "text" => "🔄 another image",
                "callback_data" => "captcha_" . $userId . "_new",
            ],
        ];

        try {
            $tmp = tempnam(sys_get_temp_dir(), "a") . ".jpg";
            file_put_contents($tmp, $this->captchaImage($userId, true));

            $res = json_decode(
                $this->telegramClient
                    ->getClient()
                    ->post("sendPhoto", [
                        "multipart" => [
                            [
                                "name" => "chat_id",
                                "contents" => $chatId,
                            ],
                            [
                                "name" => "photo",
                                "contents" => fopen($tmp, "r"),
                            ],
                            [
                                "name" => "reply_markup",
                                "contents" => json_encode([
                                    "inline_keyboard" => $kb,
                                ]),
                            ],
                        ],
                    ])
                    ->getBody()
                    ->getContents(),
                1
            );
        } finally {
            unlink($tmp);
        }

        $relatedMessages[] = $res["result"]["message_id"];

        $tc->setRelatedMessages($relatedMessages);
        $this->entityManager->persist($tc);
        $this->entityManager->flush();

        return $this->json([]);
    }
}
