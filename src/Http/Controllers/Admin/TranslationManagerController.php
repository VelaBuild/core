<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\TranslationStatusService;
use VelaBuild\Core\Services\Translator;

/**
 * Translation dashboard at /admin/translations.
 *
 * One page that lists every supported locale with a coverage matrix per
 * surface (pages, articles, categories, lang files), plus drill-in
 * "translate now" actions:
 *
 *   GET    /admin/translations                     dashboard
 *   GET    /admin/translations/{surface}/{locale}  drill-in (missing items)
 *   POST   /admin/translations/translate           translate one row to one locale
 *   POST   /admin/translations/translate-bulk      translate all missing in a surface+locale
 */
class TranslationManagerController extends Controller
{
    public function __construct(
        protected TranslationStatusService $status,
        protected Translator $translator,
    ) {}

    public function index()
    {
        abort_if(Gate::denies('translation_access'), Response::HTTP_FORBIDDEN);

        return view('vela::admin.translations.manager', [
            'source'   => $this->status->sourceLocale(),
            'locales'  => $this->status->targetLocales(),
            'coverage' => $this->status->coverage(),
        ]);
    }

    public function drill(string $surface, string $locale)
    {
        abort_if(Gate::denies('translation_access'), Response::HTTP_FORBIDDEN);
        abort_unless(in_array($surface, ['pages', 'articles', 'categories', 'lang_files'], true), 404);
        abort_unless(in_array($locale, $this->status->targetLocales(), true), 404);

        return view('vela::admin.translations.drill', [
            'surface' => $surface,
            'locale'  => $locale,
            'missing' => $this->status->missing($surface, $locale),
        ]);
    }

    public function translate(Request $request)
    {
        abort_if(Gate::denies('translation_edit'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'surface' => 'required|string|in:pages,articles,categories,lang_files',
            'locale'  => 'required|string',
            'id'      => 'required',
        ]);

        $result = $this->doTranslate($data['surface'], $data['locale'], $data['id']);

        if ($request->wantsJson()) {
            return response()->json($result);
        }
        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    public function translateBulk(Request $request)
    {
        abort_if(Gate::denies('translation_edit'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'surface' => 'required|string|in:pages,articles,categories,lang_files',
            'locale'  => 'required|string',
            'limit'   => 'nullable|integer|min:1|max:200',
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $missing = $this->status->missing($data['surface'], $data['locale']);
        $missing = array_slice($missing, 0, $limit);

        $ok = 0;
        $fail = 0;
        $errors = [];
        foreach ($missing as $item) {
            $r = $this->doTranslate($data['surface'], $data['locale'], $item['id']);
            if ($r['ok']) {
                $ok++;
            } else {
                $fail++;
                $errors[] = $item['label'] . ': ' . ($r['message'] ?? 'unknown');
            }
        }

        $msg = "Translated {$ok} item(s) to {$data['locale']}";
        if ($fail) $msg .= ", {$fail} failed";
        return back()->with($fail === 0 ? 'status' : 'error', $msg);
    }

    /** Single translate action — used by both single + bulk endpoints. */
    private function doTranslate(string $surface, string $locale, $id): array
    {
        try {
            return match ($surface) {
                'pages' => $this->wrap($this->translator->translatePage(Page::findOrFail((int) $id), $locale), 'page'),
                'articles' => $this->wrap($this->translator->translateContent(Content::findOrFail((int) $id), $locale), 'article'),
                'categories' => $this->wrap($this->translator->translateCategory(Category::findOrFail((int) $id), $locale), 'category'),
                'lang_files' => $this->wrapLang($this->translator->translateLangFile((string) $id, $locale)),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function wrap(array $r, string $what): array
    {
        if ($r['ok']) {
            return ['ok' => true, 'message' => "Translated {$what} (" . implode(', ', $r['fields']) . ')'];
        }
        return ['ok' => false, 'message' => $r['error'] ?: "Failed to translate {$what}"];
    }

    private function wrapLang(array $r): array
    {
        if ($r['ok']) {
            return ['ok' => true, 'message' => "Added {$r['added']} key(s)"];
        }
        return ['ok' => false, 'message' => $r['error'] ?: 'Failed to translate lang file'];
    }
}
