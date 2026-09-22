<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the generated header partial and front-end assets against drift.
 *
 * @internal
 */
final class AssetIntegrityTest extends CIUnitTestCase
{
    private const HEADER_PATH                 = ROOTPATH . 'app/Views/partial/header.php';
    private const HEADER_ASSETS_PATH          = ROOTPATH . 'app/Views/partial/header_assets.php';
    private const GULPFILE_PATH               = ROOTPATH . 'gulpfile.js';
    private const PUBLIC_PATH                 = ROOTPATH . 'public';
    private const GENERATED_REFERENCE_PATTERN = '~<!--\s*(?:inject:|endinject\b)|resources/[^"\'\s]*-[a-f0-9]{8,}\.[^"\'\s]*(?:css|js)~i';

    /**
     * Verifies that the header, production bundle, and module icons agree with the build outputs.
     */
    public function testFrontendAssetsAreComplete(): void
    {
        $header = $this->readProjectFile(self::HEADER_PATH);

        if (! is_file(self::HEADER_ASSETS_PATH)) {
            $this->fail('The generated asset partial is missing. Run `npm run build` or start the development Docker stack.');
        }

        $assetPartial = $this->readProjectFile(self::HEADER_ASSETS_PATH);
        $gulpfile     = $this->readProjectFile(self::GULPFILE_PATH);
        $missing      = [];

        foreach ($this->headerAssetReferences($assetPartial, $header) as $reference) {
            if (! is_file(self::PUBLIC_PATH . DIRECTORY_SEPARATOR . $reference)) {
                $missing[] = $reference;
            }
        }

        $productionStylesheetTask = $this->productionStylesheetTask($gulpfile);

        foreach ($this->requiredProductionStylesheets() as $stylesheet) {
            if (! str_contains($productionStylesheetTask, $stylesheet)) {
                $missing[] = "gulpfile.js production bundle entry {$stylesheet}";
            }
        }

        foreach ($this->menubarAssets($gulpfile) as $asset) {
            if (! is_file(self::PUBLIC_PATH . DIRECTORY_SEPARATOR . 'images/menubar/' . $asset)) {
                $missing[] = 'images/menubar/' . $asset;
            }
        }

        $this->assertSame([], $missing, $this->missingAssetMessage($missing));
    }

    /**
     * Verifies that generated asset markers and hashes stay out of the tracked header.
     */
    public function testTrackedHeaderDoesNotContainGeneratedAssetReferences(): void
    {
        $header = $this->readProjectFile(self::HEADER_PATH);

        $this->assertSame(0, preg_match(self::GENERATED_REFERENCE_PATTERN, $header), 'header.php must not contain inject markers or hashed asset names.');
    }

    /**
     * Verifies that the header check recognises every shape of generated name, including suffixes after the hash.
     */
    public function testGeneratedReferencePatternMatchesHashedNames(): void
    {
        $generated = [
            '<link rel="stylesheet" href="resources/opensourcepos-389f0cce81.min.css">',
            '<script src="resources/jquery-2c872dbe60.min.js"></script>',
            '<link rel="stylesheet" href="resources/css/bootstrap-4875cf7b0d.autocomplete.css">',
            '<link rel="stylesheet" href="resources/css/ospos-28f7f540a3.css">',
            '<!-- inject:prod:js -->',
            '<!-- endinject -->',
        ];

        foreach ($generated as $line) {
            $this->assertSame(1, preg_match(self::GENERATED_REFERENCE_PATTERN, $line), "Pattern missed {$line}");
        }

        $stable = '<link rel="stylesheet" href="<?= \'resources/bootswatch/\' . $theme . \'/bootstrap.min.css\' ?>">';

        $this->assertSame(0, preg_match(self::GENERATED_REFERENCE_PATTERN, $stable), 'Pattern must not match the stable theme stylesheet.');
    }

    /**
     * Verifies that right-to-left popups keep the close button on the left, clear of the message text.
     */
    public function testRtlPopupCloseButtonSitsOnTheLeft(): void
    {
        $rtlCss = $this->readProjectFile(self::PUBLIC_PATH . '/css/ospos_rtl.css');

        $this->assertMatchesRegularExpression(
            '/\[dir="rtl"\]\s+\[data-notify="container"\]\s*{\s*padding-left:\s*35px;\s*}/',
            $rtlCss
        );
        $this->assertMatchesRegularExpression(
            '/\[dir="rtl"\]\s+\[data-notify="container"\]\s+button\.close\[data-notify="dismiss"\]\s*{\s*right:\s*auto\s*!important;\s*left:\s*10px;\s*}/',
            $rtlCss
        );
    }

    /**
     * Reads a required project file and fails with its path when it cannot be read.
     *
     * @param string $path Absolute project file path.
     *
     * @return string File contents.
     */
    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents, "Could not read required project file {$path}.");

        return $contents;
    }

    /**
     * Extracts injected paths from the generated partial and stable paths from the tracked header.
     *
     * @param string $assetPartial Generated asset partial contents.
     * @param string $header       Tracked header file contents.
     *
     * @return list<string> Relative paths below the public directory.
     */
    private function headerAssetReferences(string $assetPartial, string $header): array
    {
        $references = [];

        foreach (['debug:css', 'debug:js', 'prod:css', 'prod:js'] as $block) {
            $pattern = '/<!--\s*inject:' . preg_quote($block, '/') . '\s*-->(.*?)<!--\s*endinject\s*-->/s';

            if (preg_match($pattern, $assetPartial, $matches) !== 1) {
                $this->fail("Could not find the {$block} asset block in app/Views/partial/header_assets.php.");
            }

            preg_match_all('/(?:href|src)="([^"]+)"/', $matches[1], $assetMatches);

            if ($assetMatches[1] === []) {
                $this->fail("The {$block} asset block in app/Views/partial/header_assets.php declares no assets. Run npm run build from the project root.");
            }

            $references = [...$references, ...$assetMatches[1]];
        }

        if (preg_match('~<link rel="shortcut icon"[^>]+href="([^"]+)"~', $header, $matches) !== 1) {
            $this->fail('Could not find the favicon reference in app/Views/partial/header.php.');
        }

        $references[] = $matches[1];

        $themePattern = '~resources/bootswatch/.*empty\\(\\$config\\[[\'\"]theme[\'\"]\\]\\).*?\\?\\s*[\'\"]([^\'\"]+)[\'\"]~';

        if (preg_match($themePattern, $header, $matches) !== 1) {
            $this->fail('Could not find the default theme stylesheet path in app/Views/partial/header.php.');
        }

        $references[] = 'resources/bootswatch/' . $matches[1] . '/bootstrap.min.css';

        return array_values(array_unique(array_map($this->normalizeAssetReference(...), $references)));
    }

    /**
     * Normalizes an asset reference before it is resolved below public/.
     *
     * @param string $reference Header asset reference.
     *
     * @return string Normalized relative path.
     */
    private function normalizeAssetReference(string $reference): string
    {
        return ltrim(parse_url($reference, PHP_URL_PATH) ?: $reference, '/');
    }

    /**
     * Returns the stylesheet paths that must remain in the production bundle.
     *
     * @return list<string> Source paths required by the fork.
     */
    private function requiredProductionStylesheets(): array
    {
        return ['./public/css/ospos_rtl.css', './public/css/ospos_print.css'];
    }

    /**
     * Extracts the production stylesheet task from the Gulp build file.
     *
     * @param string $gulpfile Gulp build file contents.
     *
     * @return string Production stylesheet task contents.
     */
    private function productionStylesheetTask(string $gulpfile): string
    {
        if (preg_match("~gulp\\.task\\('prod-css'.*?gulp\\.task\\('copy-fonts'~s", $gulpfile, $matches) !== 1) {
            return '';
        }

        return $matches[0];
    }

    /**
     * Extracts the SVG filenames produced by the copy-menubar task.
     *
     * @param string $gulpfile Gulp build file contents.
     *
     * @return list<string> Generated module icon filenames.
     */
    private function menubarAssets(string $gulpfile): array
    {
        if (preg_match("~gulp\\.task\\('copy-menubar'.*?\\n\\}\\);~s", $gulpfile, $matches) !== 1) {
            $this->fail('Could not find the copy-menubar task in gulpfile.js.');
        }

        if (preg_match_all('~rename\(["\']([^"\']+\.svg)["\']\)~', $matches[0], $assets) === 0) {
            $this->fail('Could not find any SVG outputs in the copy-menubar task in gulpfile.js.');
        }

        return array_values(array_unique($assets[1]));
    }

    /**
     * Builds the actionable failure message for missing or unlisted assets.
     *
     * The remedy leads the message so it stays visible above the file list and
     * the assertion diff that PHPUnit prints beneath it.
     *
     * @param list<string> $missing Missing asset paths or build entries.
     *
     * @return string Failure message.
     */
    private function missingAssetMessage(array $missing): string
    {
        if ($missing === []) {
            return '';
        }

        return 'Run npm run build from the project root. '
            . count($missing) . " front-end asset(s) or required build entries are missing:\n- "
            . implode("\n- ", $missing);
    }
}
