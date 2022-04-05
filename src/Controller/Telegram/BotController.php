<?php

namespace App\Controller\Telegram;

use App\Entity\ReferralProgramJoinedUser;
use App\Entity\ReferralProgramTelegramLink;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

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
}
