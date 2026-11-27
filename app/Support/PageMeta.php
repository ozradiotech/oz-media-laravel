<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * SEO / OpenGraph tags for a public page.
 *
 * Passed as the `meta` Inertia prop and rendered server-side in app.blade.php,
 * so crawlers and link previews see them without SSR.
 *
 * Titles are complete and use "Page | Brand" (e.g. "Programs | OZ Bandung"):
 * the " | " tells the client title template (app.tsx) not to append the app name.
 *
 * @implements Arrayable<string, string|null>
 */
final class PageMeta implements Arrayable
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $image = null,
        public readonly string $type = 'website',
        public readonly ?string $url = null,
        public readonly ?string $publishedAt = null,
    ) {}

    public static function make(string $title, ?string $description = null, ?string $image = null): self
    {
        return new self($title, $description, $image);
    }

    public function article(?string $publishedAt): self
    {
        return new self($this->title, $this->description, $this->image, 'article', $this->url, $publishedAt);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description ? Str::limit(Str::squish(html_entity_decode(strip_tags(str_replace('<', ' <', $this->description)), ENT_QUOTES | ENT_HTML5)), 200) : null,
            'image' => $this->image,
            'type' => $this->type,
            'url' => $this->url ?? request()->url(),
            'published_at' => $this->publishedAt,
        ];
    }
}
