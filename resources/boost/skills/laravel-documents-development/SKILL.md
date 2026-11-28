---
name: laravel-documents-development
description: >
  Configure and apply the Laravel Documents package in Laravel applications.
license: AGPL-3.0-only
metadata:
  author: Patrick Henninger
---

# Laravel Documents

Use this skill when a Laravel application needs to integrate the Laravel Documents package.

## Primary Goal

- Apply the public API of `skyraptor/laravel-documents` in the smallest correct way.

## Workflow

### 1. Inspect the application

- Identify the subject, Blade view, PDF backend, storage, and queue configuration.
- Install the selected backend's dependencies. `spatie/laravel-pdf` ^2.10 is required;
  Browsershot, versioned storage, and progress packages are optional.

### 2. Define and generate the document

- Extend `Documents\Document`, set `$view` and optionally `$disk`, and implement
  `path()` and `data()`. `render()` resolves data and returns immutable,
  serializable `RenderedDocument` input.
- Import `Facades\Documents`. `Documents::generate($document)` returns an
  immutable `GeneratedDocument`; read PDF bytes from `contents`. Pass that
  result to `Documents::publish($generated)` to store it without rendering or
  generating again. Alternatively, `Documents::publish($document)` completes
  all stages once.
- Create jobs with `Documents::makeJob($document)` and dispatch explicitly, or
  use `makeJobIfChanged()` and dispatch its non-null result. Both accept
  `connection:`, `queue:`, and `job:`; the explicit job overrides the document's
  job, which overrides the storage default. Built-in jobs retain their connection
  and queue defaults for omitted or null overrides.
- The facade resolves injectable `DocumentWorkflow`; it accepts rendered input
  to skip rendering and generated input for publication to skip generation too.
  The low-level `DocumentGenerator` accepts only `RenderedDocument` and returns
  `GeneratedDocument`; `DocumentPublisher` accepts only `GeneratedDocument`.
- Use `Documents::publishWithResult()` (or the publisher's method for generated
  input) to distinguish `DocumentPublicationStatus::Published` from
  `Superseded`. The result's `version` may be null even when published; failures
  still throw. Existing `publish()` returns null for either an unversioned write
  or a superseded attempt. An outcome describes the attempt, not the target's
  future availability or freshness; map it to an HTTP response in the application.
  Application tests can mock the facade with `Documents::shouldReceive(...)`.
- Read the current PDF with `get()` and check it with `exists()`; these do not
  render the view. A queued job contains the snapshot, not the document or model.
- `DocumentSnapshotCache::changed($rendered)` compares the cached snapshot, not the PDF, external assets, or
  settings changed under an existing configuration identifier. Cache eviction
  causes regeneration; deleted PDFs require an explicit forced job via
  `Documents::makeJob()`.
- Configure a shared persistent cache with atomic locks under
  `laravel-documents.snapshot_cache`. The lock duration must exceed the storage
  write. Laravel's default cache must also have shared atomic locks for unique
  jobs and disk writes. Partial nested configuration retains omitted defaults.
  Unique locks follow the active default cache after switches or driver purges.
  Set the same cache context before dispatch and worker deserialization and keep
  it through cleanup; changing stores does not migrate existing locks.
  Lock-release failures are reported without failing completed writes or masking
  original storage exceptions. Comparison and dispatch are not atomic.
- Publish `laravel-documents-config` to customize cache settings. The base
  package has no migrations unless optional coalescing is enabled.
- To prevent old queued snapshots from overwriting newer HTTP publications,
  set `public static ?bool $latestSnapshotWins = true` on the document or
  `laravel-documents.latest_snapshot_wins` globally. Null inherits; false opts
  out. Snapshot construction registers an immutable ID after HTML rendering,
  using the shared snapshot-cache publication lock. Consecutive identical
  fingerprints share an ID; a different fingerprint supersedes the earlier one.
  A later return to an earlier fingerprint does not revive its original ID.
  Existing snapshots keep their ordering when reused or serialized; IDs do not change fingerprints.
  Superseded publication skips storage and emits `DocumentSuperseded`, returning
  null from `publish()`. A different newer render supersedes old work even if never dispatched or failed.
  Missing cache markers fail explicitly; render a fresh snapshot to recover.
  Opt in consistently across HTTP and workers; untracked legacy/opted-out
  snapshots retain publication-order behavior.
- For recoverable coalesced queued generation, install Versioned Storage
  0.2.0-beta.1 or a compatible release and run its migrations, publish
  `laravel-documents-migrations`, and migrate on the same connection. Enable
  `laravel-documents.coalescing.enabled` and `latest_snapshot_wins`, and use
  versioned storage for all snapshots. Call `Documents::dispatchCoalesced($document,
  connection: 'redis', queue: 'pdf')`; unlike `makeJob()`, this dispatches.
  Schedule `documents:recover` every minute and run queue workers. Avoid calling
  coalescing operations inside database transactions.
- Pass a `Document` directly to atomically register its ordering marker and
  persist pending work. This calls `html()`, `target()`, and `pdfDriver()`,
  not an overridden `render()`. An already-rendered snapshot remains supported
  but has a crash gap between registration and dispatch. Recovery preserves
  existing wakeups through long backlogs; duplicate workers are possible.
- `Documents::invalidate($document->target())` supersedes existing coalesced
  snapshots and clears pending work. It can participate in a Laravel-managed
  transaction on the shared metadata connection. It does not delete PDFs or
  prevent new registrations, so coordinate record deletion and file cleanup
  in the application.
- Coalescing persists the latest HTML in plaintext until processing finishes.
  Protect the database and backups. Target ordering records must remain while
  old snapshots or jobs may exist. Set the recovery lease and stage-retention
  duration above expected work durations; recovery and queue retries are at
  least once, and slow stages can be reclaimed and need retrying. Monitor errors.
  Progress tracking and batches are not provided for coalesced jobs.
- Both synchronous and coalesced publication use staged versioned writes and a
  shared database ordering check. Do not mix older workers, cache-only writers,
  direct storage writes, or different connections: these bypass its guarantee.
  Run the scheduler and follow Versioned Storage's upload/cleanup limitations.

### 3. Configure integrations

- The `default` PDF configuration resolves Spatie's `PdfDriver` in the worker.
  Register additional named factories and optional `PdfBuilder` callbacks in
  `DocumentPdfDriverManager`; select one with the document's `$pdfDriver`.
  Configure page settings, metadata, and encryption on the builder. Generation
  returns post-processed bytes in `GeneratedDocument::contents` before storage; encryption requires Spatie's
  optional dependencies. Do not render views or load document data in callbacks.
  Browsershot builder settings are isolated per generation while retaining
  factory defaults. PDF caching is opt-in via `cache()`; automatic fingerprints
  include the configuration identifier. Explicit cache keys must distinguish
  configurations unless sharing cached output is intentional.
- Select a registered storage with the document's `$storage` identifier.
  `disk` is the default; `versioned` requires
  `skyraptor/laravel-versioned-storage` and its migrations. Version `0.2.0-beta.1`
  was tested and requires PCNTL, POSIX, and sockets; native Windows is unsupported.
- Register custom storages and their default job in `DocumentStorageManager`.
  For non-filesystem targets, override `storageContext()` with a readonly,
  serializable `DocumentStorageContext` subclass whose `identity()` uniquely
  identifies the underlying target across configurations.
- Register the same configuration identifiers in every application and worker.
  Concrete drivers and storage services must not be put into snapshots or jobs.
  Different deployments may resolve the same identifier differently.
- Listen to `DocumentRendering`, `DocumentStored`, `DocumentSuperseded`, and `DocumentRenderFailed`
  for lifecycle attempts. Generation emits `DocumentRendering` even without
  publication; publication emits stored/superseded/failure events. Correlate results
  and events using `generationId`, or jobs using `generationId()`. Listener
  exceptions are reported and do not change the stored result.
- Custom generators extend `DocumentGenerator` and override
  `protected generateContents(RenderedDocument $rendered): string`; choose one
  on a job with `withGenerator()`. Generation IDs belong to generated results,
  not mutable service state.
- For optional Mateffy progress, install `mateffy/laravel-job-progress` and
  select `GenerateUniqueDocumentWithProgress` or
  `GenerateVersionedDocumentWithProgress` from `Integrations\JobProgress`.
  Progress is separate from Laravel batch progress. Mateffy's error handling
  applies unchanged: progress failures after storage can fail the job, and queue
  retries may overwrite the PDF or publish another version.
- Unique jobs cannot be batched. Batch versioned jobs only with an asynchronous
  queue and storage that supports multiple writes. The last published version,
  not necessarily the last dispatched or completed job, becomes current unless
  latest-snapshot-wins rejects superseded publication.
- Latest-snapshot-wins does not change unique dispatch locks. A discarded newer
  job can still supersede older work. Select non-unique `GenerateVersionedDocument`
  (also supported with disk storage), its progress equivalent, or a custom job
  when every newer snapshot must be queued. `generated()` includes completed
  superseded attempts; use `DocumentStored` as the storage notification.
- Custom unique jobs using `SerializesModels` release their shared target lock
  when a missing related model causes Laravel to delete or fail the job. Keep
  models out of the frozen document input.

## Rules, References, and Templates

- See the package README for registration, cache, migration, event, and progress examples.

## Examples

```php
use App\Models\Invoice;
use SkyRaptor\LaravelDocuments\Documents\Document;
use SkyRaptor\LaravelDocuments\Facades\Documents;

class InvoiceDocument extends Document
{
    public static ?string $view = 'pdf.invoice';
    public static ?string $disk = 'documents';

    public function __construct(public Invoice $invoice) {}

    public function path(): string
    {
        return "invoices/{$this->invoice->getKey()}.pdf";
    }

    public function data(): array
    {
        return ['invoice' => $this->invoice];
    }
}

$document = new InvoiceDocument($invoice);
$job = Documents::makeJobIfChanged($document);

if ($job !== null) {
    dispatch($job);
}
```

## Anti-patterns

- Do not serialize a `Document`, Eloquent model, concrete PDF driver, or storage
  service in a document job.
- Do not pass the original document to publication after generating it; pass the
  `GeneratedDocument` to avoid repeating work. Explicit publication repeats and
  queue retries can still write again; there is no exactly-once queue guarantee.
- Do not assume snapshot comparison detects external PDF deletion, unversioned
  asset changes, or configuration changes under the same identifier.
- Do not treat a unique job's discarded duplicate ID as a queued generation.
- Do not batch unique jobs or use synchronous database-backed batches for the
  optional versioned-storage integration.
