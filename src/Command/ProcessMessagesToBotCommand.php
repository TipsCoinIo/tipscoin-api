<?php

namespace App\Command;

use App\Entity\MessageToBot;
use App\Entity\TokenTx;
use App\Service\Bot\Providers;
use App\Service\UserToken;
use App\Service\WalletAddressValidator;
use Decimal\Decimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

class ProcessMessagesToBotCommand extends Command
{
    protected static $defaultName = "app:process-messages-to-bot";
    protected static $defaultDescription = "Add a short description for your command";
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;
    /**
     * @var Providers
     */
    private $providers;
    /**
     * @var CronCommand
     */
    private $cronCommand;
    /**
     * @var UserToken
     */
    private $userToken;
    /**
     * @var WalletAddressValidator
     */
    private $walletAddressValidator;
    /**
     * @var KernelInterface
     */
    private $kernel;

    public function __construct(
        EntityManagerInterface $entityManager,
        Providers $providers,
        CronCommand $cronCommand,
        UserToken $userToken,
        WalletAddressValidator $walletAddressValidator,
        KernelInterface $kernel
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->providers = $providers;
        $this->cronCommand = $cronCommand;
        $this->userToken = $userToken;
        $this->walletAddressValidator = $walletAddressValidator;
        $this->kernel = $kernel;
    }

    protected function configure()
    {
        $this->addOption("once");
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        if ($input->getOption("once")) {
            $this->process();
            return Command::SUCCESS;
        }

        $start = time();

        while (time() < $start + 120) {
            try {
                $this->process();
            } catch (\Exception $e) {
                exit();
            }
            sleep(3);
        }

        $this->cronCommand->executeCommand(self::$defaultName);

        return Command::SUCCESS;
    }

    protected function process()
    {
        $this->entityManager->clear();
        $messages = $this->entityManager
            ->getRepository(MessageToBot::class)
            ->findBy(["processed" => false], ["createdAt" => "ASC"], 10);

        foreach ($messages as $message) {
            $res = $this->processMessage($message);
            if ($res) {
                $message->setProcessed(true);
                $this->entityManager->persist($message);
                $this->entityManager->flush();
            }
        }
    }

    protected function processMessage(MessageToBot $messageToBot)
    {
        $provider = $this->providers->providers[$messageToBot->getProvider()];

        if(method_exists($provider,'setMessageToBot'))
        {
            $provider->setMessageToBot($messageToBot);
        }

        try {
            $parsed = $provider->parseMessage($messageToBot);
        } catch (\Exception $e) {
            return true;
        }
        $cmd = strtolower($parsed["cmd"]);
        if ($cmd == "login" && $messageToBot->getType() == "private") {
            $senderUser = $provider->getSenderUser($messageToBot);
            $user = $provider->registerUser($senderUser);
            $this->userToken->createAuthCode($user);
            $body = $provider->authCodeMessageBody($user);
            $provider->sendPrivateMessage(
                $senderUser,
                "You can login right now!",
                $body
            );
            return true;
        }
        if ($cmd == "help" && $provider->getConfig()["helpEnabled"]) {
            $senderUser = $provider->getSenderUser($messageToBot);
            $user = $provider->registerUser($senderUser);
            $provider->sendPrivateMessage(
                $senderUser,
                "Help",
                $provider->helpBody($user)
            );
            return true;
        }
        if ($cmd == "addwallet" && $provider->getConfig()["addWalletCommand"]) {
            $senderUser = $provider->getSenderUser($messageToBot);
            $user = $provider->registerUser($senderUser);
            $arguments = $parsed["args"];

            if ($this->walletAddressValidator->validate($arguments[0])) {
                $user->setWalletAddress($arguments[0]);
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                $provider->sendPrivateMessage(
                    $senderUser,
                    "Help",
                    "Wallet address has been set correctly."
                );
            } else {
                $provider->sendPrivateMessage(
                    $senderUser,
                    "Help",
                    "Incorrect wallet address"
                );
            }

            return true;
        }
        if ($cmd == "deposit" && $provider->getConfig()["depositCommand"]) {
            $senderUser = $provider->getSenderUser($messageToBot);
            $user = $provider->registerUser($senderUser);
            $arguments = $parsed["args"];

            if ($this->walletAddressValidator->validate($arguments[0])) {
                $tokenTx = $this->entityManager
                    ->getRepository(TokenTx::class)
                    ->findOneBy(
                        ["fromAddress" => $arguments[0]],
                        ["id" => "DESC"]
                    );

                if (
                    $tokenTx &&
                    $tokenTx->getData()["timeStamp"] > time() - 600
                ) {
                    $provider->sendPrivateMessage(
                        $senderUser,
                        "Deposit info",
                        "We found deposit " .
                            (new Decimal($tokenTx->getValue()))
                                ->div(
                                    10 **
                                        $this->kernel
                                            ->getContainer()
                                            ->getParameter("app:tips_decimals")
                                )
                                ->toString() .
                            " Tips"
                    );
                } else {
                    $provider->sendPrivateMessage(
                        $senderUser,
                        "Deposit info",
                        "No deposits found in last 10 minutes"
                    );
                }
            } else {
                $provider->sendPrivateMessage(
                    $senderUser,
                    "Deposit",
                    "Incorrect wallet address"
                );
            }

            return true;
        }
        if ($cmd == "balance" && $provider->getConfig()["balanceCommand"]) {
            $senderUser = $provider->getSenderUser($messageToBot);
            $user = $provider->registerUser($senderUser);

            $provider->sendPrivateMessage(
                $senderUser,
                "Balance info",
                "Your balance is " .
                    sprintf("%g", $user->getBalance()) .
                    " Tips"
            );

            return true;
        }
        if ($cmd == "withdraw" && $provider->getConfig()["withdrawCommand"]) {
            $senderUser = $provider->getSenderUser($messageToBot);
            $user = $provider->registerUser($senderUser);

            $provider->sendPrivateMessage(
                $senderUser,
                "Withdraw",
                "Withdraws are disabled in test mode."
            );

            return true;
        }
        if ($cmd == "tip") {
            $args = $parsed["args"];

            if (count($args) < 2) {
                return true;
            }

            if (!is_numeric($args[0])) {
                $target = $provider->getTargetUserFromCmdArg(
                    array_shift($args)
                );
            } else {
                if ($messageToBot->getType() === "private") {
                    return true;
                }

                $target = $provider->getTargetUser($messageToBot);
            }

            try {
                $amount = new Decimal(array_shift($args));
            } catch (\Exception $e) {
                return true;
            }

            $currency = strtolower(array_shift($args));
            if ($currency != "tips") {
                return true;
            }

            $sender = $provider->getSenderUser($messageToBot);
            $senderUser = $provider->registerUser($sender);

            try {
                $provider->sendTips($senderUser, $target, $amount->toString());
            } catch (\Exception $e) {
                dump($e);
                return true;
            }

            if (
                $provider->getConfig()["sendPublicReplyAfterTipsSent"] &&
                $messageToBot->getType() == "mention"
            ) {
                $provider->replyToMessage(
                    $messageToBot,
                    $provider->publicAfterTipsTransferBody(
                        $target,
                        $sender,
                        $amount->toString()
                    )
                );
            }

            return true;
        }

        return false;
    }
}
