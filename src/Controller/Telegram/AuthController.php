<?php

namespace App\Controller\Telegram;

use App\Service\TelegramClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/telegram/auth", name="telegram_auth_")
 */
class AuthController extends AbstractController
{
    /** @var TelegramClient */
    protected $telegramClient;

    /**
     * @required
     * @param TelegramClient $telegramClient
     */
    public function setTelegramClient(TelegramClient $telegramClient): void
    {
        $this->telegramClient = $telegramClient;
    }

    /**
     * @Route("/", name="index")
     */
    public function index(Request $request)
    {
        return $this->redirect(
            $this->getParameter("app.front_url") .
                "/login?" .
                http_build_query([
                    "oauth" => "telegram",
                    "code" => base64_encode($request->getQueryString()),
                ])
        );
    }
}
