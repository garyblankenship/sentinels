<?php

namespace Vampires\Sentinels\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Artisan command to generate new agent classes.
 */
class MakeAgentCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'make:agent {name : The name of the agent class}
                           {--force : Overwrite the agent if it already exists}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new Sentinels agent class';

    /**
     * The type of class being generated.
     */
    protected $type = 'Agent';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/agent.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Agents';
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $this->replaceAgentName($stub, $name);
        $this->replaceAgentDescription($stub, $name);

        return $stub;
    }

    /**
     * Replace the agent name placeholder.
     */
    protected function replaceAgentName(&$stub, $name): self
    {
        $agentName = $this->getAgentName($name);
        $stub = str_replace('{{ agentName }}', $agentName, $stub);

        return $this;
    }

    /**
     * Replace the agent description placeholder.
     */
    protected function replaceAgentDescription(&$stub, $name): self
    {
        $description = $this->getAgentDescription($name);
        $stub = str_replace('{{ agentDescription }}', $description, $stub);

        return $this;
    }

    /**
     * Get the human-readable agent name.
     */
    protected function getAgentName($name): string
    {
        $className = class_basename($name);

        // Remove "Agent" suffix if present
        if (str_ends_with($className, 'Agent')) {
            $className = substr($className, 0, -5);
        }

        return Str::title(Str::snake($className, ' '));
    }

    /**
     * Get the agent description.
     */
    protected function getAgentDescription($name): string
    {
        $agentName = $this->getAgentName($name);

        return "Processes context through {$agentName}";
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the agent class'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the agent already exists'],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): ?bool
    {
        // Ensure the agent stub exists
        $this->ensureStubExists();

        // Generate the agent
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        // Provide additional information
        $name = $this->qualifyClass($this->getNameInput());
        $this->info("Agent created successfully!");
        $this->comment("Class: {$name}");
        $this->comment("Remember to implement the handle() method with your agent logic.");

        return true;
    }

    /**
     * Ensure the agent stub file exists.
     */
    protected function ensureStubExists(): void
    {
        $stubPath = __DIR__ . '/stubs';
        $stubFile = $stubPath . '/agent.stub';

        if (!$this->files->exists($stubFile)) {
            $this->files->makeDirectory($stubPath, 0755, true, true);
            $this->files->put($stubFile, $this->getDefaultStub());
        }
    }

    /**
     * Get the default agent stub content.
     */
    protected function getDefaultStub(): string
    {
        return '<?php

namespace {{ namespace }};

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;

class {{ class }} extends BaseAgent
{
    /**
     * Process the context through this agent.
     */
    protected function handle(Context $context): Context
    {
        // TODO: Implement your agent logic here
        // Example: Transform the payload
        // return $context->with($transformedPayload);
        
        return $context;
    }

    /**
     * Get the agent\'s name.
     */
    public function getName(): string
    {
        return \'{{ agentName }}\';
    }

    /**
     * Get the agent\'s description.
     */
    public function getDescription(): string
    {
        return \'{{ agentDescription }}\';
    }

    /**
     * Validate the payload (optional).
     */
    protected function validatePayload(Context $context): ValidationResult
    {
        // TODO: Add custom validation logic here
        // Example:
        // if ($context->payload === null) {
        //     return ValidationResult::requiredFieldMissing(\'payload\');
        // }
        
        return ValidationResult::valid($context->payload);
    }

    /**
     * Determine if this agent should execute (optional).
     */
    public function shouldExecute(Context $context): bool
    {
        // TODO: Add conditional execution logic here
        // Example:
        // return $context->hasTag(\'process-this\');
        
        return parent::shouldExecute($context);
    }

    /**
     * Get the expected input type (optional).
     */
    public function getInputType(): ?string
    {
        // TODO: Specify expected input type
        // Example: return \'string\';
        
        return parent::getInputType();
    }

    /**
     * Get agent tags (optional).
     */
    public function getTags(): array
    {
        return [\'agent\', \'custom\'];
    }

    /**
     * Get estimated execution time in milliseconds (optional).
     */
    public function getEstimatedExecutionTime(): int
    {
        return 100; // 100ms
    }
}
';
    }
}
