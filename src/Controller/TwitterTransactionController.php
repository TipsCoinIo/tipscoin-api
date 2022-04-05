<?php

namespace App\Controller;

use App\Entity\TwitterTransactionHash;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TwitterTransactionController extends AbstractController
{
    /**
     * @Route("/twitter-transaction/{hash}",)
     */
    public function index(
        $hash,
        EntityManagerInterface $entityManager
    ): Response {

        $trans = $entityManager->getRepository(TwitterTransactionHash::class)->findOneBy(['hash'=>$hash]);

        if(!$trans)
            $this->json(['message'=>'transaction not found'], 404);

        $type = [
            TwitterTransactionHash::CREATED => 'info',
            TwitterTransactionHash::NO_FUNDS => 'error',
            TwitterTransactionHash::SENT => 'success',
            TwitterTransactionHash::CLAIMED => 'success',
        ];

        return $this->json([
            'status' => $trans->getStatus(),
            'sender' => $trans->getSender(),
            'amount' => $trans->getAmount(),
            'target' => $trans->getTarget(),
            'type' => $type[$trans->getStatus()],
        ]);
    }
}
