<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    /**
     * @Route("/config", name="api_config")
     */
    public function index(): Response
    {
        return $this->json([
            "deposits" => [
                "walletAddress" => $this->getParameter(
                    "app:tips_deposit_address"
                ),
            ],
            "tips" => [
                "decimals" => intval($this->getParameter("app:tips_decimals")),
            ],
        ]);
    }
}
