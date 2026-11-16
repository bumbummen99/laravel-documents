<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use SkyRaptor\LaravelDocuments\RenderedDocument;
use Symfony\Component\Process\Process;

function waitForWorkerMarker(string $marker, Process $worker): void
{
    $deadline = microtime(true) + 5;

    while (! is_file($marker) && $worker->isRunning() && microtime(true) < $deadline) {
        usleep(20_000);
    }

    if (! is_file($marker)) {
        throw new RuntimeException('Worker did not reach its marker: '.$worker->getErrorOutput());
    }
}

it('coordinates snapshot publications and comparisons across separate processes', function () {
    Storage::fake('documents');
    $directory = Storage::disk('documents')->path('shared-snapshot-cache');
    File::makeDirectory($directory, 0755, true);
    $autoload = null;

    foreach (ClassLoader::getRegisteredLoaders() as $vendorDirectory => $loader) {
        if ($loader->findFile(RenderedDocument::class) !== false) {
            $autoload = $vendorDirectory.'/autoload.php';

            break;
        }
    }

    if ($autoload === null) {
        throw new RuntimeException('Cannot find the Composer autoloader for the document package.');
    }
    $script = __DIR__.'/../Smoke/SnapshotCacheWorker.php';
    $firstMarker = $directory.'/first';
    $secondMarker = $directory.'/second';
    $release = $directory.'/release';
    $first = new Process([PHP_BINARY, $script, $autoload, $directory, 'publish', 'first', $firstMarker, $release]);
    $second = new Process([PHP_BINARY, $script, $autoload, $directory, 'publish', 'second', $secondMarker, '-']);

    try {
        $first->start();
        waitForWorkerMarker($firstMarker.'.inside', $first);
        $second->start();
        waitForWorkerMarker($secondMarker.'.started', $second);
        usleep(300_000);

        expect(is_file($secondMarker.'.inside'))->toBeFalse();
        File::put($release, 'release');
        $first->wait();
        $second->wait();

        if (! $first->isSuccessful() || ! $second->isSuccessful()) {
            throw new RuntimeException($first->getErrorOutput().$second->getErrorOutput());
        }

        expect(is_file($secondMarker.'.inside'))->toBeTrue();

        $reader = new Process([PHP_BINARY, $script, $autoload, $directory, 'changed', 'second', '-', '-']);
        $reader->mustRun();
        expect($reader->getOutput())->toBe('unchanged');

        $reader = new Process([PHP_BINARY, $script, $autoload, $directory, 'changed', 'first', '-', '-']);
        $reader->mustRun();
        expect($reader->getOutput())->toBe('changed');
    } finally {
        if ($first->isRunning()) {
            $first->stop();
        }

        if ($second->isRunning()) {
            $second->stop();
        }

        File::deleteDirectory($directory);
    }
});
