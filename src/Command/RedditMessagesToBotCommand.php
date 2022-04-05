<?php

namespace App\Command;

use App\Entity\MessageToBot;
use App\Service\RedditClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RedditMessagesToBotCommand extends Command
{
    protected static $defaultName = "app:reddit-messages-to-bot";
    protected static $defaultDescription = "Add a short description for your command";

    protected $entityManager;
    protected $redditClient;
    protected $cron;

    public function __construct(
        EntityManagerInterface $entityManager,
        RedditClient $redditClient,
        CronCommand $cronCommand
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->redditClient = $redditClient;
        $this->cron = $cronCommand;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $start = time();

        while (time() < $start + 120) {
            try {
                $this->inbox();
            } catch (\Exception $e) {
                exit();
            }
            sleep(3);
        }

        $this->cron->executeCommand(self::$defaultName);

        return Command::SUCCESS;
    }

    protected function inbox()
    {
        $query = [];

        while (true) {
            $messages = $this->redditClient->request(
                "https://oauth.reddit.com/message/unread",
                $query
            )["data"]["children"];

            if (count($messages) == 0) {
                break;
            }

            foreach ($messages as $message) {
                $message = $message["data"];

                if (
                    $message["created"] < time() - 86400 ||
                    $this->entityManager
                        ->getRepository(MessageToBot::class)
                        ->findOneBy(["extId" => $message["name"]])
                ) {
                    break 2;
                }

                $mtb = new MessageToBot();
                $mtb->setMessage($message["body"]);
                $mtb->setProvider("reddit");
                $mtb->setType(
                    $message["type"] === "username_mention"
                        ? "mention"
                        : "private"
                );
                $mtb->setData($message);
                $mtb->setExtId($message["name"]);
                $mtb->setCreatedAt(
                    \DateTime::createFromFormat(
                        "U",
                        $message["created"],
                        new \DateTimeZone("UTC")
                    )
                );
                $this->entityManager->persist($mtb);
                $this->entityManager->flush();
                $query["after"] = $mtb->getExtId();
            }

            $this->entityManager->clear();
        }
        $this->entityManager->clear();
    }
}
