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

function snapshotWorkerAutoload(): string
{
    foreach (ClassLoader::getRegisteredLoaders() as $vendorDirectory => $loader) {
        if ($loader->findFile(RenderedDocument::class) !== false) {
            return $vendorDirectory.'/autoload.php';
        }
    }

    throw new RuntimeException('Cannot find the Composer autoloader for the document package.');
}

it('coordinates snapshot publications and comparisons across separate processes', function () {
    Storage::fake('documents');
    $directory = Storage::disk('documents')->path('shared-snapshot-cache');
    File::makeDirectory($directory, 0755, true);
    $autoload = snapshotWorkerAutoload();
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

it('rejects stale serialized snapshots across separate rendering and publication processes', function () {
    Storage::fake('documents');
    $directory = Storage::disk('documents')->path('latest-snapshot-cache');
    File::makeDirectory($directory, 0755, true);
    $script = __DIR__.'/../Smoke/SnapshotCacheWorker.php';
    $autoload = snapshotWorkerAutoload();

    try {
        foreach (['older', 'newer'] as $html) {
            (new Process([PHP_BINARY, $script, $autoload, $directory, 'capture', $html, $directory.'/'.$html.'.snapshot', '-']))
                ->mustRun();
        }

        // Publish the newer HTTP result before the older queued result runs.
        foreach (['newer' => 'published', 'older' => 'superseded'] as $html => $expected) {
            $worker = new Process([PHP_BINARY, $script, $autoload, $directory, 'publish-captured', '-', $directory.'/'.$html.'.snapshot', '-']);
            $worker->mustRun();
            expect($worker->getOutput())->toBe($expected);
        }

        expect(File::get($directory.'/published.pdf'))->toBe('newer');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('allows an equivalent snapshot captured in another process to preserve the queued snapshot', function () {
    Storage::fake('documents');
    $directory = Storage::disk('documents')->path('equivalent-snapshot-cache');
    File::makeDirectory($directory, 0755, true);
    $script = __DIR__.'/../Smoke/SnapshotCacheWorker.php';
    $autoload = snapshotWorkerAutoload();

    try {
        foreach (['queued', 'duplicate'] as $name) {
            (new Process([PHP_BINARY, $script, $autoload, $directory, 'capture', 'same', $directory.'/'.$name.'.snapshot', '-']))
                ->mustRun();
        }

        $worker = new Process([PHP_BINARY, $script, $autoload, $directory, 'publish-captured', '-', $directory.'/queued.snapshot', '-']);
        $worker->mustRun();

        expect($worker->getOutput())->toBe('published')
            ->and(File::get($directory.'/published.pdf'))->toBe('same');
    } finally {
        File::deleteDirectory($directory);
    }
});
