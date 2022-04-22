<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class TokenAuthenticator extends AbstractAuthenticator
{
    /** @var KernelInterface */
    protected $kernel;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /**
     * @required
     */
    public function setKernel(KernelInterface $kernel): void
    {
        $this->kernel = $kernel;
    }

    /**
     * @required
     */
    public function setEntityManager(
        EntityManagerInterface $entityManager
    ): void {
        $this->entityManager = $entityManager;
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $token = trim($request->headers->get("Authorization"));
        if (
            $token === null ||
            !is_string($token) ||
            !preg_match('/^Bearer\s([^$]+)$/i', $token, $matches)
        ) {
            throw new AuthenticationException("No API token provided");
        }

        $token = $matches[1];
        try {
            $decoded = JWT::decode(
                $token,
                new Key(
                    $this->kernel
                        ->getContainer()
                        ->getParameter("app.jwt_secret"),
                    "HS256"
                )
            );
        } catch (\Throwable $e) {
            throw new \Exception("wrong token ");
        }

        return new SelfValidatingPassport(
            new UserBadge($decoded->id, function ($id) use ($token) {
                return $this->entityManager
                    ->getRepository(User::class)
                    ->findOneBy(["id" => $id, "accessToken" => $token]);
            })
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ): ?Response {
        return new JsonResponse(
            [
                "message" => strtr(
                    $exception->getMessageKey(),
                    $exception->getMessageData()
                ),
            ],
            401
        );
    }
}
