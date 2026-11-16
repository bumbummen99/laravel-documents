<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments\Documents\Concerns;

use Illuminate\Support\Facades\View;
use RuntimeException;

trait HasView
{
    /**
     * The Blade view for the document.
     *
     * @var view-string|null
     */
    public static ?string $view = null;

    /**
     * Accessor for this Document's view.
     * Note: Overwrite this to apply custom logic.
     *
     * @return view-string
     */
    public function view(): string
    {
        if (static::$view === null) {
            throw new RuntimeException('$view can not be null!');
        }

        return static::$view;
    }

    /**
     * The data passed to the view.
     * Note: Overwrite this to load the document's data.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }

    /**
     * Render the view with the document's data.
     */
    public function html(): string
    {
        return View::make($this->view(), $this->data())->render();
    }
}
