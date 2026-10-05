<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Accounts\TermsKind;
use App\Domain\Cms\CookieRegistry;
use App\Domain\Cms\CurrentPages;
use App\Domain\Cms\PageKey;
use App\Domain\Cms\SafeMarkdown;
use App\Domain\Seo\SeoHead;
use App\Domain\Seo\StructuredData;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\PreContractInformation;
use App\Domain\Storefront\StorefrontShell;
use App\Http\Controllers\Controller;
use App\Http\Support\CartContext;
use App\Models\TermsVersion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.11 §2.1 — the legal and help pages (`/privacy`, `/cookies`,
 * `/delivery`, `/returns`, `/contact`) and the terms of sale (`/terms`).
 * Each shows the version in force; with none, the page is a 404 and the
 * footer has no link to it.
 *
 * Three pages carry a block the admin cannot edit (§2.3, §2.5): the cookie
 * table, the statutory cancellation and returns statement (the same text
 * as checkout and the confirmation email, PreContractInformation), and
 * the contact and legal details (the shared `brand` prop).
 */
class LegalPageController extends Controller
{
    /** PreContractInformation sections the Returns & cancellations page repeats. */
    private const RETURNS_SECTIONS = ['Your right to cancel', 'Returning goods', 'Faulty goods'];

    public function __construct(
        private readonly StorefrontShell $shell = new StorefrontShell,
        private readonly CartContext $cartContext = new CartContext,
    ) {}

    public function show(Request $request, string $key): Response
    {
        $page = PageKey::from($key);
        $version = CurrentPages::version($page);
        abort_if($version === null, 404);

        $brand = Branding::current();

        return $this->render($request, $brand, $page->path(), [
            'key' => $page->value,
            'title' => $version->title,
            'html' => self::markdown($version->body_markdown),
            'version' => (string) $version->version_no,
            'effective_from' => $version->effective_from->toIso8601String(),
        ], $this->block($page, $brand), $version->meta_description);
    }

    public function terms(Request $request): Response
    {
        $terms = TermsVersion::current(TermsKind::Sale);
        abort_if($terms === null, 404);

        return $this->render($request, Branding::current(), '/terms', [
            'key' => 'terms',
            'title' => 'Terms of sale',
            'html' => self::markdown($terms->body_markdown),
            'version' => $terms->version,
            'effective_from' => $terms->effective_from->toIso8601String(),
        ], null, 'The terms of sale that apply to orders placed on this website.');
    }

    /**
     * @param  array<string, string>  $page
     * @param  array<string, mixed>|null  $block
     */
    private function render(Request $request, Branding $brand, string $path, array $page, ?array $block, ?string $description): Response
    {
        $owner = $this->cartContext->owner($request, createGuestToken: false);
        $url = SeoHead::url($path);

        $seo = new SeoHead(
            title: $page['title'].' · '.$brand->name,
            description: SeoHead::describe($description, strip_tags($page['html'])),
            canonical: $url,
            jsonLd: [StructuredData::breadcrumb([
                ['name' => 'Home', 'url' => SeoHead::url('/')],
                ['name' => $page['title'], 'url' => $url],
            ])],
        );

        return $seo->attach(Inertia::render('Storefront/LegalPage', [
            'shell' => fn () => $this->shell->props($owner),
            'page' => $page,
            'block' => $block,
        ]));
    }

    /** @return array<string, mixed>|null */
    private function block(PageKey $page, Branding $brand): ?array
    {
        return match ($page) {
            PageKey::Cookies => ['type' => 'cookies', 'cookies' => CookieRegistry::entries()],
            PageKey::Returns => ['type' => 'statutory', 'sections' => array_values(array_filter(
                PreContractInformation::build($brand, TermsVersion::current(TermsKind::Sale))->sections,
                fn (array $section): bool => in_array($section['heading'], self::RETURNS_SECTIONS, true),
            ))],
            PageKey::Contact => ['type' => 'contact'],
            default => null,
        };
    }

    /** Markdown only, raw HTML escaped, unsafe links dropped (SafeMarkdown). */
    private static function markdown(string $markdown): string
    {
        return SafeMarkdown::toHtml($markdown);
    }
}
