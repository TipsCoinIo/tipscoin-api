<?php

namespace App\Command;

use App\Entity\MessageToBot;
use App\Service\TwitterClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TwitterMessagesToBotCommand extends Command
{
    protected static $defaultName = "app:twitter-messages-to-bot";
    protected static $defaultDescription = "Add a short description for your command";

    protected $entityManager;
    protected $twitterClient;
    protected $cron;

    public function __construct(
        EntityManagerInterface $entityManager,
        TwitterClient $twitterClient,
        CronCommand $cronCommand
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->twitterClient = $twitterClient;
        $this->cron = $cronCommand;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        try {
            $res = $this->twitterClient
                ->clientV1()
                ->get("direct_messages/events/list", ["count" => 50]);

            foreach ($res->events as $event) {
                if (
                    $event->message_create->target->recipient_id !=
                    "1488148204050862083"
                ) {
                    continue;
                }

                $mtb = $this->entityManager
                    ->getRepository(MessageToBot::class)
                    ->findOneBy([
                        "provider" => "twitter",
                        "type" => "private",
                        "extId" => $event->id,
                    ]);
                if ($mtb) {
                    continue;
                }

                $mtb = new MessageToBot();
                $mtb->setProvider("twitter");
                $mtb->setType("private");
                $mtb->setExtId($event->id);
                $mtb->setCreatedAt(
                    \DateTime::createFromFormat(
                        "U",
                        intval($event->created_timestamp / 1000),
                        new \DateTimeZone("UTC")
                    )
                );
                $mtb->setMessage($event->message_create->message_data->text);
                $mtb->setData(json_decode(json_encode($event), 1));
                $this->entityManager->persist($mtb);
            }
            $this->entityManager->flush();
        } catch (\Exception $e) {
            dump($e);
        }

        try {
            $opt = [
                "count" => 100
            ];
            $latest = $this->entityManager
                ->getRepository(MessageToBot::class)
                ->findOneBy(
                    [
                        "provider" => "twitter",
                        "type" => "mention",
                    ],
                    ["createdAt" => "DESC"]
                );
            if ($latest) {
                $opt["since_id"] = $latest->getExtId();
            }

            $res = $this->twitterClient
                ->clientV1()
                ->get("statuses/mentions_timeline", $opt);

            foreach ($res as $r) {
                if (
                    $this->entityManager
                        ->getRepository(MessageToBot::class)
                        ->findOneBy([
                            "provider" => "twitter",
                            "type" => "mention",
                            "extId" => $r->id_str,
                        ])
                ) {
                    continue;
                }

                $tweet = $this->twitterClient->clientV1()->get('statuses/show/'.$r->id_str,['tweet_mode'=>'extended']);

                $mtb = new MessageToBot();
                $mtb->setCreatedAt(new \DateTime($r->created_at));
                $mtb->setProvider("twitter");
                $mtb->setType("mention");
                $mtb->setExtId($r->id_str);
                $mtb->setMessage($tweet->full_text);
                $mtb->setData(json_decode(json_encode($tweet), 1));
                $this->entityManager->persist($mtb);
            }
            $this->entityManager->flush();
        } catch (\Exception $e) {
            dump($e);
        }

        return Command::SUCCESS;
    }
}
