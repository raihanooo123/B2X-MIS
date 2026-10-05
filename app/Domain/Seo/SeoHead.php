<?php

namespace App\Domain\Seo;

use App\Domain\Cms\SearchIndexing;
use Inertia\Response;

/**
 * 05.11 §4 — the head tags of one storefront page: title, description,
 * canonical URL, `robots`, Open Graph and JSON-LD.
 *
 * The app renders on the client (no Inertia SSR), and not every crawler
 * runs JavaScript, so these tags are written into the initial HTML by the
 * root template (`resources/views/app.blade.php`) **and** sent as the `seo`
 * page prop, which the React layout renders with the same `head-key`s.
 * Inertia matches head elements on that key, so client-side navigation
 * replaces each tag rather than leaving a stale one behind.
 *
 * `robots` is `noindex, nofollow` whatever the page says while indexing is
 * off (SearchIndexing). JSON is encoded so no catalogue text can close
 * the `<script>` element.
 */
final readonly class SeoHead
{
    /** Request attribute the RobotsHeader middleware turns into `X-Robots-Tag`. */
    public const ROBOTS_ATTRIBUTE = 'seo.robots';

    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private const DESCRIPTION_MAX = 160;

    /**
     * @param  list<array<string, mixed>>  $jsonLd  schema.org objects
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $canonical = null,
        public bool $index = true,
        public bool $follow = true,
        public string $ogType = 'website',
        public ?string $ogImage = null,
        public array $jsonLd = [],
    ) {}

    public function robots(): string
    {
        if (! SearchIndexing::enabled()) {
            return 'noindex, nofollow';
        }

        return ($this->index ? 'index' : 'noindex').', '.($this->follow ? 'follow' : 'nofollow');
    }

    /**
     * Cut at 160 characters on a word boundary, with an ellipsis. Never cost
     * or a stock figure: callers pass catalogue or page text only.
     */
    public static function describe(?string ...$candidates): ?string
    {
        foreach ($candidates as $text) {
            if ($text === null) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
                return $text;
            }
            $cut = mb_substr($text, 0, self::DESCRIPTION_MAX - 1);
            $space = mb_strrpos($cut, ' ');

            return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,.;:-').'…';
        }

        return null;
    }

    /**
     * The page prop React renders (StorefrontLayout → SeoTags).
     *
     * @return array{title: string, description: string|null, canonical: string|null, robots: string, og: array{type: string, image: string|null}, json_ld: list<string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'robots' => $this->robots(),
            'og' => ['type' => $this->ogType, 'image' => $this->ogImage],
            'json_ld' => $this->jsonLdStrings(),
        ];
    }

    /** @return list<string> */
    public function jsonLdStrings(): array
    {
        return array_map(fn (array $object): string => json_encode($object, self::JSON_FLAGS), $this->jsonLd);
    }

    /**
     * The same tags for the initial HTML, keyed like the React ones.
     */
    public function html(): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tags = ['<meta name="robots" content="'.$e($this->robots()).'" inertia="robots">'];

        if ($this->description !== null) {
            $tags[] = '<meta name="description" content="'.$e($this->description).'" inertia="description">';
        }
        if ($this->canonical !== null) {
            $tags[] = '<link rel="canonical" href="'.$e($this->canonical).'" inertia="canonical">';
        }
        $tags[] = '<meta property="og:title" content="'.$e($this->title).'" inertia="og:title">';
        $tags[] = '<meta property="og:type" content="'.$e($this->ogType).'" inertia="og:type">';
        if ($this->description !== null) {
            $tags[] = '<meta property="og:description" content="'.$e($this->description).'" inertia="og:description">';
        }
        if ($this->canonical !== null) {
            $tags[] = '<meta property="og:url" content="'.$e($this->canonical).'" inertia="og:url">';
        }
        if ($this->ogImage !== null) {
            $tags[] = '<meta property="og:image" content="'.$e($this->ogImage).'" inertia="og:image">';
        }
        foreach ($this->jsonLdStrings() as $i => $json) {
            $tags[] = '<script type="application/ld+json" inertia="ld-'.$i.'">'.$json.'</script>';
        }

        return implode("\n    ", $tags);
    }

    /** Send the tags with an Inertia page: as the `seo` prop and to the root template. */
    public function attach(Response $response): Response
    {
        request()->attributes->set(self::ROBOTS_ATTRIBUTE, $this->robots());

        return $response->with('seo', $this->toArray())->withViewData('seo', $this);
    }

    /**
     * 05.11 §4.2: tracking parameters don't make a page a filtered variant.
     *
     * @param  array<int|string, mixed>  $query  as parse_str() gives it (a key such as `?0=x` is an int)
     */
    public static function onlyTrackingParameters(array $query): bool
    {
        foreach (array_keys($query) as $name) {
            $name = strtolower((string) $name);
            if (! str_starts_with($name, 'utm_') && ! in_array($name, ['gclid', 'fbclid', 'msclkid'], true)) {
                return false;
            }
        }

        return true;
    }

    /** An absolute URL on the canonical host (05.11 §4.2): https, no trailing slash, no query. */
    public static function url(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $path === '/' ? $base.'/' : $base.'/'.ltrim($path, '/');
    }
}
