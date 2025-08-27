<?php

namespace Vampires\Sentinels\Console\Commands;

use Illuminate\Console\Command;
use Vampires\Sentinels\Contracts\AgentMediator;

/**
 * Artisan command to list registered agents and statistics.
 */
class SentinelsListCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sentinels:list
                           {--stats : Show execution statistics}
                           {--agents : Show registered agents}
                           {--all : Show all information}';

    /**
     * The console command description.
     */
    protected $description = 'List Sentinels agents and statistics';

    /**
     * Execute the console command.
     */
    public function handle(AgentMediator $mediator): int
    {
        $showStats = $this->option('stats') || $this->option('all');
        $showAgents = $this->option('agents') || $this->option('all');

        // If no specific option is provided, show everything
        if (!$showStats && !$showAgents) {
            $showStats = $showAgents = true;
        }

        $this->info('Sentinels Package Information');
        $this->line('');

        if ($showStats) {
            $this->displayStats($mediator);
        }

        if ($showAgents) {
            $this->displayAgents();
        }

        return Command::SUCCESS;
    }

    /**
     * Display execution statistics.
     */
    protected function displayStats(AgentMediator $mediator): void
    {
        $this->info('📊 Execution Statistics');
        $this->line(str_repeat('-', 50));

        $stats = $mediator->getExecutionStats();

        $this->line("Total Executions: {$stats['total_executions']}");
        $this->line("Successful: {$stats['successful_executions']}");
        $this->line("Failed: {$stats['failed_executions']}");

        if ($stats['total_executions'] > 0) {
            $successRate = round(($stats['successful_executions'] / $stats['total_executions']) * 100, 2);
            $this->line("Success Rate: {$successRate}%");
            $this->line("Average Execution Time: " . round($stats['average_execution_time'], 2) . "ms");
        }

        if (!empty($stats['most_used_agents'])) {
            $this->line('');
            $this->info('Most Used Agents:');
            foreach (array_slice($stats['most_used_agents'], 0, 5) as $agent => $count) {
                $this->line("  • {$agent}: {$count} executions");
            }
        } else {
            $this->line('');
            $this->comment('No agents have been executed yet.');
        }

        $this->line('');
    }

    /**
     * Display information about agents.
     */
    protected function displayAgents(): void
    {
        $this->info('🤖 Agent Information');
        $this->line(str_repeat('-', 50));

        // Since we don't have agent discovery implemented yet,
        // show basic information about the package
        $this->line('Package Version: 0.1.0');
        $this->line('Agent Discovery: Available (configure in config/sentinels.php)');
        $this->line('');

        $this->info('Available Commands:');
        $this->line('  • make:agent {name}     - Create a new agent');
        $this->line('  • make:pipeline {name}  - Create a new pipeline');
        $this->line('  • sentinels:list        - Show this information');
        $this->line('');

        $this->info('Quick Start Example:');
        $this->line('  1. php artisan make:agent UppercaseAgent');
        $this->line('  2. Implement the handle() method');
        $this->line('  3. Use Sentinels::process($input, $agent)');
        $this->line('');

        $this->comment('💡 Tip: Use --stats to see execution statistics');
    }
}
