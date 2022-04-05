<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Cron\CronExpression;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;

class CronCommand extends Command
{
    use LockableTrait;

    protected static $defaultName = "app:cron";

    protected $jobs = [];
    /**
     * @var KernelInterface
     */
    private $kernel;

    private $binConsole;

    public function __construct(string $name = null, KernelInterface $kernel)
    {
        parent::__construct($name);
        $this->kernel = $kernel;
        $this->binConsole =
            (new PhpExecutableFinder())->find() .
            " " .
            realpath($kernel->getProjectDir()) .
            "/bin/console";
    }

    protected function jobs()
    {
        $this->addJob("app:reddit-messages-to-bot");
        $this->addJob("app:twitter-messages-to-bot");
        $this->addJob("app:process-messages-to-bot");
        $this->addJob("app:token-tx");
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        file_put_contents(
            $this->kernel->getProjectDir() . "/cron.txt",
            date("Y-m-d H:i:s")
        );

        //kill php processes running more than 30 minutes
        $ps = $this->ps();
        foreach ($ps as $p) {
            if (strpos($p[3], "--notKill")) {
                continue;
            }

            if (intval($p[2]) > 1800 && strpos($p[3], "php") === 0) {
                exec("kill -15 " . $p[0]);
            }
        }

        if (!$this->lock()) {
            return 0;
        }

        $this->jobs();
        $this->checkIfRunning();

        foreach ($this->jobs as $job) {
            $isRunning = $job["isRunning"];

            if (
                $isRunning &&
                $job["maxTime"] > 0 &&
                $job["time"] > $job["maxTime"]
            ) {
                $isRunning = false;
                dump("kill " . $job["command"]);
                exec("kill -15 " . $job["pid"]);
            }

            if (
                !$isRunning &&
                CronExpression::factory($job["cron"])->isDue(
                    "now",
                    "Europe/Warsaw"
                )
            ) {
                $this->executeCommand($job["command"]);
            }
        }

        return 0;
    }

    public function executeCommand($command)
    {
        $command =
            $this->binConsole . " " . trim($command) . " > /dev/null 2>&1 &";
        dump($command);
        exec($command);
    }

    protected function checkIfRunning()
    {
        $ps = $this->ps();
        $this->jobs = array_map(function ($job) use ($ps) {
            $job["isRunning"] = false;

            $rcommand = strrev($job["command"]);

            foreach ($ps as $p) {
                if (strpos(strrev($p[3]), $rcommand) !== false) {
                    $job["isRunning"] = true;
                    $job["pid"] = intval($p[0]);
                    $job["time"] = intval($p[2]);
                    break;
                }
            }

            return $job;
        }, $this->jobs);
    }

    protected function ps()
    {
        $user = get_current_user();

        exec("ps ww -eo pid,user,etimes,cmd", $ps);
        $ps = array_map(function ($v) {
            return preg_split("#\s+#is", trim($v), 4);
        }, $ps);
        $ps = array_filter($ps, function ($v) use ($user) {
            return $v[1] == $user;
        });

        return $ps;
    }

    protected function addJob($command, $cron = "* * * * *", $maxTime = 0)
    {
        $this->jobs[] = [
            "command" => $command,
            "cron" => $cron,
            "maxTime" => $maxTime,
        ];
    }
}
