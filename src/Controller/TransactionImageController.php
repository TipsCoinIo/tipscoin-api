<?php

namespace App\Controller;

use App\Entity\MessageToBot;
use App\Entity\Transaction;
use App\Service\Bot\Providers\Twitter\Twitter;
use App\Service\RedditClient;
use App\Service\TwitterClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

class TransactionImageController extends AbstractController
{
    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var KernelInterface */
    protected $kernel;

    public function __construct(
        EntityManagerInterface $entityManager,
        KernelInterface $kernel
    ) {
        $this->entityManager = $entityManager;
        $this->kernel = $kernel;
    }

    /**
     * @Route("/transaction-image/{transaction}/{hash}")
     */
    public function index($transaction, $hash): Response
    {
        $transaction = $this->entityManager
            ->getRepository(Transaction::class)
            ->find($transaction);

        if (!$transaction || $transaction->getImageHash() !== $hash) {
            return $this->json([$transaction->getImageHash()], 401);
        }

        $puppeteer = $this->kernel->getProjectDir() . "/assets/puppeteer/";
        $tipsDir = $this->kernel->getProjectDir() . "/assets/img/tips/";
        $data = json_decode(file_get_contents($tipsDir . "1.json"), 1);
        $imagePath = $tipsDir . $data["path"];

        $size = getimagesize($imagePath);
        if (array_key_exists("data", $_GET)) {
            return $this->json(["w" => $size[0], "h" => $size[1]]);
        }
        if (array_key_exists("html", $_GET)) {
            $html = file_get_contents($tipsDir . "page.html");
            $html = str_replace(
                "[SRC]",
                "data:image/jpeg;base64," .
                    base64_encode(file_get_contents($imagePath)),
                $html
            );
            $html = str_replace(
                "[USERNAME]",
                $transaction->getProviderUser(),
                $html
            );
            $html = str_replace(
                "[AMOUNT]",
                sprintf("%g", $transaction->getValue()),
                $html
            );
            $html = str_replace("[WIDTH]", $size[0], $html);
            $html = str_replace("[HEIGHT]", $size[1], $html);

            return new Response($html, 200);
        }

        exec("rm -rf " . $puppeteer . "out/*");
        $out = $puppeteer . "out/" . uniqid() . ".png";
        exec(
            "node " .
                $puppeteer .
                'index.js "https://api.tipscoin.io/transaction-image/' .
                $transaction->getId() .
                "/" .
                $transaction->getImageHash() .
                '?html" "' .
                $out .
                '"'
        );

        return new Response(file_get_contents($out), 200, [
            "content-type" => "image/png",
        ]);
    }
}
