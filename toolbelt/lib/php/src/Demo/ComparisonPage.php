<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Demo;

/** A self-contained HTML page with every recording embedded, before and after side by side. */
final class ComparisonPage
{
    /**
     * @param list<string> $pages
     * @param list<array{label: string, version: string, before: array{video: string, errors: list<string>}, after: array{video: string, errors: list<string>}}> $sections
     */
    public static function render(CoreDiff $diff, array $pages, string $size, array $sections): string
    {
        $title = self::escape($diff->title);
        $heading = $diff->url === null ? $title : '<a href="' . self::escape($diff->url) . '">' . $title . '</a>';
        $routes = implode(', ', array_map(static fn(string $page): string => '<code>' . self::escape($page) . '</code>', $pages));
        $body = '';
        foreach ($sections as $section) {
            $body .= '<section><h2>' . self::escape($section['label']) . ' <span class="version">CiviCRM '
                . self::escape($section['version']) . '</span></h2><div class="row">'
                . self::figure('Before', $section['before']) . self::figure('After', $section['after'])
                . '</div></section>';
        }
        $description = $routes === '' ? '' : "<p>Pages: {$routes}</p>";
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}: before and after</title>
<style>
:root { --bg: #f6f6f6; --fg: #1a1a1a; --muted: #4d4d4d; --border: #c4c4c4; --link: #0b57b0; }
@media (prefers-color-scheme: dark) { :root { --bg: #161616; --fg: #ececec; --muted: #b8b8b8; --border: #4a4a4a; --link: #8ab4f8; } }
body { margin: 0 auto; padding: 16px; max-width: 1600px; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, sans-serif; }
a { color: var(--link); }
h2 { margin: 32px 0 8px; font-size: 1.25rem; }
.version, .meta { color: var(--muted); font-weight: normal; font-size: 0.9rem; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 800px) { .row { grid-template-columns: 1fr; } }
figure { margin: 0; }
figcaption { font-weight: 600; margin-bottom: 4px; }
video { display: block; width: 100%; height: auto; border: 1px solid var(--border); background: #000; }
details { margin-top: 4px; color: var(--muted); font-size: 0.9rem; }
</style>
</head>
<body>
<h1>{$heading}: before and after</h1>
<p class="meta">Recorded at {$size}.</p>
{$description}
{$body}
</body>
</html>

HTML;
    }

    /** @param array{video: string, errors: list<string>} $run */
    private static function figure(string $caption, array $run): string
    {
        $source = 'data:video/webm;base64,' . base64_encode((string) file_get_contents($run['video']));
        $count = count($run['errors']);
        $errors = $count === 0 ? '<p class="meta">No script errors.</p>' : '<details><summary>' . $count
            . ($count === 1 ? ' script error' : ' script errors') . '</summary><ul>'
            . implode('', array_map(static fn(string $error): string => '<li>' . self::escape($error) . '</li>', $run['errors']))
            . '</ul></details>';
        return "<figure><figcaption>{$caption}</figcaption><video src=\"{$source}\" controls autoplay muted loop playsinline></video>{$errors}</figure>";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
