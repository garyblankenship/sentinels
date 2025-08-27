<?php

namespace Vampires\Sentinels\Console\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * Artisan command to generate new pipeline classes.
 */
class MakePipelineCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'make:pipeline {name : The name of the pipeline class}
                           {--force : Overwrite the pipeline if it already exists}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new Sentinels pipeline class';

    /**
     * The type of class being generated.
     */
    protected $type = 'Pipeline';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/pipeline.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Pipelines';
    }

    /**
     * Execute the console command.
     */
    public function handle(): ?bool
    {
        // Ensure the pipeline stub exists
        $this->ensureStubExists();

        // Generate the pipeline
        $result = parent::handle();

        if ($result === false) {
            return false;
        }

        // Provide additional information
        $name = $this->qualifyClass($this->getNameInput());
        $this->info("Pipeline created successfully!");
        $this->comment("Class: {$name}");
        $this->comment("Remember to add agents to your pipeline in the build() method.");

        return true;
    }

    /**
     * Ensure the pipeline stub file exists.
     */
    protected function ensureStubExists(): void
    {
        $stubPath = __DIR__ . '/stubs';
        $stubFile = $stubPath . '/pipeline.stub';

        if (!$this->files->exists($stubFile)) {
            $this->files->makeDirectory($stubPath, 0755, true, true);
            $this->files->put($stubFile, $this->getDefaultStub());
        }
    }

    /**
     * Get the default pipeline stub content.
     */
    protected function getDefaultStub(): string
    {
        return '<?php

namespace {{ namespace }};

use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;

class {{ class }}
{
    /**
     * Build and return the pipeline.
     */
    public static function build(): PipelineContract
    {
        return Pipeline::create()
            // TODO: Add your agents here
            // Example:
            // ->pipe(new YourFirstAgent())
            // ->pipe(new YourSecondAgent())
            // ->onError(function (Context $context, \Throwable $exception) {
            //     // Handle errors
            //     return $context->addError($exception->getMessage());
            // });
        ;
    }

    /**
     * Execute the pipeline with the given input.
     */
    public static function execute(mixed $input): mixed
    {
        return static::build()->through($input);
    }

    /**
     * Process a context through the pipeline.
     */
    public static function process(Context $context): Context
    {
        return static::build()->process($context);
    }
}
';
    }
}
