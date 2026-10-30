<?php

namespace VelaBuild\Core\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The language of the admin panel, which is not the language of the site.
 *
 * A site published only in English can still be run by someone who reads
 * German, so the admin's language is chosen apart from the content languages
 * (Settings > Languages, which SiteConfigWriter also narrows
 * vela.available_languages to at boot). In order, it is:
 *
 *  1. the signed-in account's own choice (vela_users.admin_locale), set on the
 *     profile page, by an administrator under Users, or from the top bar;
 *  2. the session's choice, from ?change_language on a page with no account
 *     signed in, such as the login page;
 *  3. vela.admin_locale (VELA_ADMIN_LOCALE), the site's default admin language;
 *  4. vela.primary_language, which is what the admin used before any of this.
 *
 * The languages on offer are the ones the admin has translations for: a folder
 * in this package's resources/lang, or an app-level lang/vendor/vela/<code>.
 * They do not depend on the content languages.
 */
class AdminLanguage
{
    /** The session key that holds a choice made without an account signed in. */
    public const SESSION_KEY = 'admin_locale';

    /**
     * Each language Vela ships admin translations for, in its own name, so the
     * person who needs it can find it in a list that is not in their language.
     * Also the order they are listed in.
     */
    private const NATIVE_NAMES = [
        'en'      => 'English',
        'de'      => 'Deutsch',
        'ru'      => 'Русский',
        'fr'      => 'Français',
        'nl'      => 'Nederlands',
        'it'      => 'Italiano',
        'ar'      => 'العربية',
        'dk'      => 'Dansk',
        'zh-Hans' => '简体中文',
        'th'      => 'ไทย',
    ];

    /**
     * The languages the admin can be shown in, code => name in that language.
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        $codes = array_unique(array_merge(
            $this->translationFolders(dirname(__DIR__, 2) . '/resources/lang'),
            $this->translationFolders(lang_path('vendor/vela')),
        ));

        // Vela's own languages first, in their usual order; an app's additions after.
        $shipped = array_values(array_intersect(array_keys(self::NATIVE_NAMES), $codes));
        $added = array_diff($codes, $shipped);
        sort($added);

        $names = config('vela.available_languages', []);
        $languages = [];
        foreach (array_merge($shipped, $added) as $code) {
            $languages[$code] = self::NATIVE_NAMES[$code] ?? $names[$code] ?? $code;
        }

        return $languages;
    }

    public function isAvailable(mixed $code): bool
    {
        return is_string($code) && $code !== '' && array_key_exists($code, $this->available());
    }

    /**
     * The admin language for accounts that have not chosen one.
     */
    public function default(): string
    {
        foreach ([config('vela.admin_locale'), config('vela.primary_language')] as $code) {
            if ($this->isAvailable($code)) {
                return $code;
            }
        }

        return array_key_first($this->available()) ?? 'en';
    }

    /**
     * The admin language for this request (see the class comment for the order).
     */
    public function forRequest(Request $request, ?Authenticatable $user = null): string
    {
        $session = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        foreach ([$user?->admin_locale, $session] as $code) {
            if ($this->isAvailable($code)) {
                return $code;
            }
        }

        return $this->default();
    }

    /**
     * The name to show for a language code, in that language.
     */
    public function name(string $code): string
    {
        return $this->available()[$code] ?? self::NATIVE_NAMES[$code] ?? $code;
    }

    /**
     * The language codes that have a translation folder in $path.
     *
     * @return list<string>
     */
    private function translationFolders(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        return array_values(array_filter(
            scandir($path) ?: [],
            fn (string $entry) => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $entry)
                && is_dir($path . '/' . $entry)
        ));
    }
}
