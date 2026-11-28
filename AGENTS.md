# Laravel Documents

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Project Architecture

The following is a basic description of the package's architectural ideas.

### Important Classes

The following classes are important to the functionality and architecture of the package.

#### Document
The `Document` is the central, first-class definition of a Document - it acts like a Resource in Filament PHP.
In general, it defines the following:
- **Identification:** Clear identification of a Document's instance (id, date, whatever - must be available at construction).
- **Storage:**
    - How & where is the PDF file stored?
    - How is the PDF file accessed?
- **View:** What Blade template is used to render the Document's HTML markup?
- **Data:** What data is required for rendering and how is it loaded?
- **Routing:** Where can the PDF file be previewed or downloaded?

#### RenderedDocument
The `RenderedDocument` is a **finalized**, **immutable** representation of a `Document`.

It contains the `Document`'s immutable, *rendered* HTML, document-level PDF metadata, and optional metadata required to identify and process the snapshot. It prevents content drift caused by changes to the `Document`'s underlying data sources after rendering. Document-level PDF metadata participates in the fingerprint and overrides builder metadata; builder-only metadata is not frozen.

The snapshot records the generator's class name, not a generator instance.
Workers resolve that class through the container; coalesced work must not change
the selected generator after rendering. Generator identity affects the fingerprint.
Direct generator calls also resolve the captured class when coalescing is enabled.
Regular jobs reject `generator()` overrides while coalescing is enabled; select
the generator on the snapshot instead.

PDF *generation* should only accept a previously *rendered* `RenderedDocument`.

The `RenderedDocument` must be serializable so it can easily be passed to another execution context, such as a queue worker. *Rendering* itself is performed in the current context while *generating* the PDF file can be performed synchronously or asynchronously.

#### GeneratedDocument
The `GeneratedDocument` is the immutable, serializable output of PDF generation:
final bytes plus the target, original rendered fingerprint, and generation ID.
Publication consumes this result without repeating rendering or generation.
It must not retain the original document, rendered HTML, or generator service.
Its public constructor accepts caller-supplied bytes and does not authenticate
their origin; only trusted application code should construct results directly.

#### DocumentStorage
The `DocumentStorage` interface is a Contract that defines how a Document is stored and accessed.

It should remain completely agnostic, allowing for all kinds of storage implementations, such as *Flysystem*, databases, or other storage mechanisms.

The Contract defines the common storage operations, while implementations may have additional requirements for individual operations.

To accommodate implementation-specific parameters without restricting the common contract, operations should use dedicated parameter objects (e.g. DocumentStorageContext). These parameter objects allow implementations to define their own required parameters through specialized context classes while keeping the storage interface stable and consistent. **Parameter objects need to be serializable.**

### Processes
The following *processes* are important to the functionality of the package and the lifecycle of a `Document`.

#### Rendering

The `Document` can be *rendered*, that means its data is resolved and the Blade template rendered.

Rendering produces a `RenderedDocument`, which is a finalized, serializable representation of a `Document`'s rendered content. It contains the rendered HTML and optional immutable metadata, ensuring that the document's content cannot drift due to external factors such as changes to the source data.

#### Generation

PDF generation must consume a previously rendered `RenderedDocument`. The rendered HTML contained in the snapshot is then passed to an appropriate engine to *generate* the PDF file.

The *generation* of the PDF file can be done in sync or be dispatched to the Queue using a Job that implements the `GenerateDocument` Contract.

`DocumentGenerator::generate(RenderedDocument $rendered)` produces an immutable,
serializable `GeneratedDocument` containing final post-processed PDF `contents`,
the target, the rendered fingerprint, and a generation ID, without retaining HTML.
Its `generate()` method is final; customize PDF bytes through `generateContents()`.
`DocumentPublisher::publish(GeneratedDocument $generated)` coordinates storage,
fingerprint updates, and publication events. It has no generator dependency.
`Jobs\DocumentJobFactory` creates jobs from rendered input without dispatching
them; `DocumentSnapshotCache` owns change detection and records the fingerprint
carried by the generated result. Jobs resolve the generator in the worker,
generate once per execution, and publish that exact result.

`DocumentWorkflow` is the document-level coordinator exposed by
`Facades\Documents`. It accepts a document or a rendered result for generation
and also accepts a generated result for publication, performing only missing
stages. Passing the generated result to publication must never render or
generate again. Job creation uses the explicit job override, then the
document's job configuration, then the storage default. It does not dispatch
jobs or retain documents or stage results. Keep the document definition and the
generator free of workflow convenience methods.


##### `spatie/laravel-pdf` drivers
PDF generation is handled by `spatie/laravel-pdf`, allowing the use of its provided or fully customized drivers.

The package should register its own driver configurations in a manager within the service container and resolve the appropriate driver instance when needed, rather than providing a concrete instance directly to the generate method.

This is necessary because concrete driver instances of `spatie/laravel-pdf` cannot be reliably serialized. By registering and resolving the driver in the execution context, a different context, such as a queued Job, can resolve a driver instance with the appropriate customizations applied, including Browsershot configuration. These customizations must be reproducible and available in every execution context that processes documents.

It is important to consider changes to driver configurations between deployments. For example, when running Laravel Octane, application instances running different versions may coexist and process documents. If driver configurations change between deployments, a queued workload may be processed using different configurations depending on which application instance handles it.

#### Saving

After *generation*, publication consumes the `GeneratedDocument` and uses its
target's `DocumentStorage` to save those exact contents. In coalescing mode, a
matching existing PDF wins and its version is reused even if generated bytes
differ; a missing PDF is replaced. The publisher must not
render or generate. The generator must not store files or emit publication
events. Custom generators override `generateContents()` to preserve the common
result construction, generation ID, and lifecycle events. Existing
`DocumentRendering` and `DocumentRenderFailed` event names are retained;
generation-only calls now emit generation events too.

### Extendability

The package should be implemented independently of optional or recommended third-party packages. However, its design should allow integrations that provide the desired behavior.

#### Progress

The package should make it very simple to use packages that allow the tracking of the progress of a queued Job. This is especially helpful, if someone needs just some *generic* feedback mechanism from the queue, to, for example, interactively show the progress of a queued workload to the browser / user.

It should provide built-in integrations for the following packages:
- `mateffy/laravel-job-progress`

These integrations must remain optional and must not introduce mandatory dependencies on the respective packages.

Progress tracking for Jobs implementing the `GenerateDocument` contract must remain entirely optional. The package architecture should accommodate it without depending on a specific progress tracking implementation. Integrations should be straightforward and non-restrictive. Progress tracking itself is not the responsibility of this package.

### Architectural Invariants

The following rules must always be respected:

- A `RenderedDocument` must be immutable after creation.
- A `GeneratedDocument` must be immutable and serializable, carrying final bytes and the original rendered fingerprint, target, and generation ID.
- Publication must only consume `GeneratedDocument` and must never render or generate.
- PDF *generation* must never resolve or access the original `Document`'s data sources.
- PDF *generation* must only consume a `RenderedDocument`.
- Document *rendering*, PDF *generation*, and *publication* must remain separate stages of the document lifecycle.
- Storage implementations must remain interchangeable through the `DocumentStorage` contract.
- The package must not require a specific queue or progress tracking implementation.
- Concrete PDF driver instances must never be serialized into queued jobs.
- All dependencies required for processing must be resolvable in a separate application process.
- The package must not assume that queued workloads are processed by the same application version that created or dispatched them, and must not guarantee deployment-independent deterministic execution.
- Storage operations must use parameter objects to accommodate implementation-specific requirements without changing the common `DocumentStorage` contract.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `skyraptor/laravel-documents`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Architecture and Lifecycle

- Design components around cohesive responsibilities and explicit lifecycle boundaries, not merely conceptual grouping. Identify their states, transitions, invariants, and operation prerequisites before introducing or changing lifecycle-related abstractions.
- Avoid partially initialized objects and implicit ordering requirements where practical. Make lifecycle prerequisites explicit through APIs, types, composition, or dedicated coordinators rather than scattering state checks and assumptions across methods.
- Keep state transitions predictable and independently testable. Account for invalid transitions, failures, retries, recovery, and cleanup where applicable.
- Prefer eliminating unnecessary states and simplifying transitions over introducing abstractions or design patterns solely to model them. The resulting architecture should be easy to understand, test, maintain, and represent in UML.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Mutation tests: `composer test:mutation` (requires PCOV or Xdebug coverage)
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
