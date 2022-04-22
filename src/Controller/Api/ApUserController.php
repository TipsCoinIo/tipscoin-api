<?php

namespace App\Controller\Api;

use App\Entity\Config;
use App\Entity\ReferralProgramTelegramLink;
use App\Entity\ReferralProgramUser;
use App\Entity\ReferralProgramView;
use App\Entity\User;
use App\Service\BscScan;
use App\Service\Mappers\ReferralProgramUserMap;
use App\Service\TelegramClient;
use App\Service\WalletAddressValidator;
use Decimal\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 *
 * @Route("/ap", name="api_ap_user")
 */
class ApUserController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/auth/register", methods={"POST"})
     */
    public function authRegister(
        Request $request,
        ValidatorInterface $validator,
        TelegramClient $telegramClient
    ) {
        $c = new Client();
        $res = json_decode(
            $c
                ->post("https://www.google.com/recaptcha/api/siteverify", [
                    "form_params" => [
                        "secret" => $this->getParameter("app:recaptcha_secret"),
                        "response" => $request->request->get("captcha"),
                    ],
                ])
                ->getBody()
                ->getContents(),
            1
        );

        if ($res["success"] != true || $res["score"] < 0.5) {
            return $this->json(
                [
                    "errors" => [
                        "captcha" => "Captcha check error.",
                    ],
                ],
                400
            );
        }

        $u = new ReferralProgramUser();
        $u->setUsername(trim($request->request->get("username")));
        $u->setEmail(trim($request->request->get("email")));
        $u->setPassword($request->request->get("password"));
        $v = $validator->validate($u);

        if ($v->count() === 0) {
            $u->setPassword(password_hash($u->getPassword(), PASSWORD_DEFAULT));
            $this->entityManager->persist($u);

            $res = $telegramClient->request("createChatInviteLink", [
                "chat_id" => -1001711928517,
            ]);

            $tgl = new ReferralProgramTelegramLink();
            $tgl->setUser($u);
            $tgl->setLink($res["result"]["invite_link"]);
            $this->entityManager->persist($tgl);
            $this->entityManager->flush();

            return $this->json([]);
        } else {
            $errors = [];

            foreach ($v as $error) {
                if (empty($errors[$error->getPropertyPath()])) {
                    $errors[$error->getPropertyPath()] = $error->getMessage();
                }
            }

            return $this->json(["errors" => $errors], 400);
        }
    }

    /**
     * @Route("/auth/login", methods={"POST"})
     */
    public function authLogin(Request $request, ValidatorInterface $validator)
    {
        $c = new Client();
        $res = json_decode(
            $c
                ->post("https://www.google.com/recaptcha/api/siteverify", [
                    "form_params" => [
                        "secret" => $this->getParameter("app:recaptcha_secret"),
                        "response" => $request->request->get("captcha"),
                    ],
                ])
                ->getBody()
                ->getContents(),
            1
        );

        if ($res["success"] != true || $res["score"] < 0.5) {
            return $this->json(
                [
                    "error" => "Captcha check error.",
                ],
                400
            );
        }

        $u = $this->entityManager
            ->getRepository(ReferralProgramUser::class)
            ->findOneBy(["username" => $request->request->get("username")]);

        if (
            $u &&
            $u->getPassword() !== null &&
            password_verify(
                $request->request->get("password"),
                $u->getPassword()
            )
        ) {
            if (!$u->getAccepted()) {
                return $this->json(
                    [
                        "error" => "This account has not been activated yet.",
                    ],
                    400
                );
            }
            if ($u->getBanned()) {
                return $this->json(
                    [
                        "error" => "This account has been banned.",
                    ],
                    400
                );
            }

            return $this->json([
                "hash" => $u->getId() . "." . $u->getPrivateHash(),
            ]);
        }

        return $this->json(
            ["error" => "The user was not found or the password is incorrect."],
            400
        );
    }
}
