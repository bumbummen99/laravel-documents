<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Pdf;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfBuilder;

final class DocumentPdfDriverManager
{
    /**
     * @var array<string, array{factory: Closure(Container): PdfDriver, configure: (Closure(PdfBuilder): mixed)|null}>
     */
    private array $configurations = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  Closure(Container): PdfDriver  $factory
     * @param  (Closure(PdfBuilder): mixed)|null  $configure
     */
    public function register(string $name, Closure $factory, ?Closure $configure = null): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('A document PDF driver configuration needs a name.');
        }

        $this->configurations[$name] = ['factory' => $factory, 'configure' => $configure];
    }

    public function resolve(string $name): PdfDriver
    {
        return ($this->configuration($name)['factory'])($this->container);
    }

    public function builder(string $name): PdfBuilder
    {
        $configuration = $this->configuration($name);
        $builder = (new DocumentPdfBuilder($name))
            ->setDriver($this->resolve($name))
            ->margins(0, 0, 0, 0)
            ->dontCache();

        if ($configuration['configure'] !== null) {
            ($configuration['configure'])($builder);
        }

        if ($builder->viewName !== '' || $builder->headerViewName !== '' || $builder->footerViewName !== '') {
            throw new InvalidArgumentException('Document PDF builders must use rendered HTML, not views.');
        }

        return $builder;
    }

    /**
     * @return array{factory: Closure(Container): PdfDriver, configure: (Closure(PdfBuilder): mixed)|null}
     */
    private function configuration(string $name): array
    {
        return $this->configurations[$name]
            ?? throw new InvalidArgumentException("Document PDF driver configuration [{$name}] is not registered.");
    }
}
