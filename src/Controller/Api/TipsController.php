<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Bot\Providers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/tips", name="api_tips")
 */
class TipsController extends AbstractController
{
    /**
     * @Route("/send", name="_send")
     */
    public function send(Request $request, Providers $providers): Response
    {
        /** @var User $u */
        $u = $this->getUser();
        if ($request->request->get("provider") === "reddit") {
            try {
                $providers->providers[
                    $request->request->get("provider")
                ]->sendTips(
                    $u,
                    $request->request->get("user"),
                    $request->request->get("amount")
                );
                return $this->json([
                    "message" => "tips sent!",
                ]);
            } catch (\Exception $e) {
                return $this->json(
                    [
                        "message" => $e->getMessage(),
                    ],
                    400
                );
            }
        }

        return $this->json(
            [
                "message" => "error sending tips",
            ],
            400
        );
    }
}
