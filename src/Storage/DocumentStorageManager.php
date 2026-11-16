<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Storage;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SkyRaptor\LaravelDocuments\Jobs\Contracts\GenerateDocument;
use SkyRaptor\LaravelDocuments\Jobs\GenerateUniqueDocument;
use SkyRaptor\LaravelDocuments\Storage\Contracts\DocumentStorage;

final class DocumentStorageManager
{
    /**
     * @var array<string, array{factory: Closure(Container): DocumentStorage, job: class-string<GenerateDocument>}>
     */
    private array $configurations = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  Closure(Container): DocumentStorage  $factory
     * @param  class-string  $job
     */
    public function register(string $name, Closure $factory, string $job = GenerateUniqueDocument::class): void
    {
        if ($name === '' || ! is_a($job, GenerateDocument::class, true)) {
            throw new InvalidArgumentException('A storage configuration needs a name and a document generation job.');
        }

        $this->configurations[$name] = ['factory' => $factory, 'job' => $job];
    }

    public function resolve(string $name): DocumentStorage
    {
        return ($this->configuration($name)['factory'])($this->container);
    }

    /**
     * @return class-string<GenerateDocument>
     */
    public function job(string $name): string
    {
        return $this->configuration($name)['job'];
    }

    /**
     * @return array{factory: Closure(Container): DocumentStorage, job: class-string<GenerateDocument>}
     */
    private function configuration(string $name): array
    {
        return $this->configurations[$name]
            ?? throw new InvalidArgumentException("Document storage configuration [{$name}] is not registered.");
    }
}
