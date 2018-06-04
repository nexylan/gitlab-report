<?php

declare(strict_types=1);

/*
 * This file is part of the Nexylan packages.
 *
 * (c) Nexylan SAS <contact@nexylan.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nexy\GitLabReport\Console\Command;

use Gitlab\Client;
use Gitlab\ResultPager;
use League\Period\Period;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gitlab-report')
            ->addArgument('project', InputArgument::REQUIRED, 'project path')
            ->addArgument('from', InputArgument::REQUIRED, 'strtotime format')
            ->addArgument('to', InputArgument::OPTIONAL, 'strtotime format', 'now')
            ->addOption('label', 'l', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Limits to given labels')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $projectPath = $input->getArgument('project');
        $period = new Period(
            new \DateTime($input->getArgument('from'), new \DateTimeZone('UTC')),
            new \DateTime($input->getArgument('to'), new \DateTimeZone('UTC'))
        );
        $config = Yaml::parse(file_get_contents(__DIR__.'/../../../.config.yaml'));

        $gitlab = Client::create($config['url'])->authenticate($config['token'], Client::AUTH_HTTP_TOKEN);
        $pager = new ResultPager($gitlab);

        $selectedProject = null;
        $io->text('Fetching projects...');
        $projects = $pager->fetchAll($gitlab->projects(), 'all', [[
            'membership' => true,
        ]]);
        $io->text("Looking for {$projectPath}...");
        $io->progressStart(count($projects));
        foreach ($projects as $project) {
            if ($project['path_with_namespace'] === $projectPath) {
                $selectedProject = $project;
                break;
            }
            $io->progressAdvance();
        }
        $io->progressFinish();
        if (null === $selectedProject) {
            $io->error("No project found with path {$projectPath}");

            return 1;
        }

        $io->text('Fetching issues...');
        $issues = $pager->fetchAll($gitlab->issues, 'all', [$selectedProject['id'], [
            'order_by' => 'created_at',
            'sort' => 'asc',
        ]]);
        $io->text('Looking for opened issues...');
        $io->progressStart(count($issues));
        $openedIssues = array_filter($issues, static function ($issue) use ($io, $period) {
            $io->progressAdvance();

            return $period->contains($issue['created_at']);
        });
        $io->progressFinish();
        $io->text('Looking for closed issues...');
        $io->progressStart(count($issues));
        $closedIssues = array_filter($issues, static function ($issue) use ($io, $period) {
            $io->progressAdvance();

            return null !== $issue['closed_at'] && $period->contains($issue['closed_at']);
        });
        $io->progressFinish();
        $io->text('Looking for active issues...');
        $io->progressStart(count($issues));
        $activeIssues = array_filter($issues, static function ($issue) use ($io, $period) {
            return 'opened' === $issue['state'];
        });
        $io->progressFinish();

        $io->text('Fetching labels...');
        $labels = array_map(function ($label) {
            return $label['name'];
        }, $pager->fetchAll($gitlab->projects, 'labels', [$selectedProject['id']]));
        $chosenLabels = $input->getOption('label');
        if (count($chosenLabels) > 0) {
            $labels = array_intersect($labels, $chosenLabels);
        }
        sort($labels);

        $reportData = [
            ['ALL', count($openedIssues), count($closedIssues), count($activeIssues)],
        ];
        $io->text('Splitting by label...');
        $io->progressStart(count($labels));
        foreach ($labels as $label) {
            $labelFilter = function ($issue) use ($label) {
                return in_array($label, $issue['labels'], true);
            };
            $reportData[] = [
                $label,
                count(array_filter($openedIssues, $labelFilter)),
                count(array_filter($closedIssues, $labelFilter)),
                count(array_filter($activeIssues, $labelFilter)),
            ];
            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->title(
            "GitLab report for {$projectPath} from {$period->getStartDate()->format('c')} to {$period->getEndDate()->format('c')}"
        );
        $io->table([
            'Label',
            'Opened issues',
            'Closed issues',
            'Active issues (now)',
        ], $reportData);

        return 0;
    }
}
