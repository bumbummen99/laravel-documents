<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelDocuments;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class DocumentMetadata
{
    public ?string $creationDate;

    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $keywords = null,
        public ?string $creator = null,
        string|DateTimeInterface|null $creationDate = null,
    ) {
        $this->creationDate = $creationDate instanceof DateTimeInterface
            ? 'D:'.$creationDate->format('YmdHis').str_replace(':', "'", $creationDate->format('P'))."'"
            : $creationDate;
    }

    /** @return array{title: ?string, author: ?string, subject: ?string, keywords: ?string, creator: ?string, creationDate: ?string} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'author' => $this->author,
            'subject' => $this->subject,
            'keywords' => $this->keywords,
            'creator' => $this->creator,
            'creationDate' => $this->creationDate,
        ];
    }

    /** @param array<mixed> $values */
    public static function fromArray(array $values): self
    {
        $keys = ['title', 'author', 'subject', 'keywords', 'creator', 'creationDate'];

        if (count($values) !== count($keys)) {
            throw new InvalidArgumentException('The stored document metadata is invalid.');
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $values) || ($values[$key] !== null && ! is_string($values[$key]))) {
                throw new InvalidArgumentException('The stored document metadata is invalid.');
            }
        }

        return new self(
            title: $values['title'],
            author: $values['author'],
            subject: $values['subject'],
            keywords: $values['keywords'],
            creator: $values['creator'],
            creationDate: $values['creationDate'],
        );
    }
}
