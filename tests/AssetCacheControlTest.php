<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the Apache rule that gives content-hashed resource files a year-long cache.
 *
 * @internal
 */
final class AssetCacheControlTest extends CIUnitTestCase
{
    /**
     * Returns the LocationMatch pattern from docker/asset-cache.conf as a PHP regular expression.
     */
    private function cacheRulePattern(): string
    {
        $configuration = file_get_contents(__DIR__ . '/../docker/asset-cache.conf');

        $this->assertIsString($configuration, 'Could not read docker/asset-cache.conf.');
        $this->assertStringContainsString(
            'Header set Cache-Control "public, max-age=31536000, immutable"',
            $configuration,
        );
        $this->assertSame(
            1,
            preg_match('~<LocationMatch "([^"]+)"~', $configuration, $matches),
            'Could not read the LocationMatch rule from docker/asset-cache.conf.',
        );

        return '~' . $matches[1] . '~';
    }

    /**
     * Matches sample gulp-rev names, including a hash before a dotted suffix, and rejects copied theme files and other folders.
     */
    public function testCacheRuleMatchesSampleNames(): void
    {
        $pattern = $this->cacheRulePattern();

        $this->assertSame(1, preg_match($pattern, '/resources/opensourcepos-39c74204a5.min.js'));
        $this->assertSame(1, preg_match($pattern, '/resources/opensourcepos-33a929367d.min.css'));
        $this->assertSame(1, preg_match($pattern, '/resources/glyphicons-halflings-regular-448c34a56d.woff2'));
        $this->assertSame(1, preg_match($pattern, '/resources/css/bootstrap-4875cf7b0d.autocomplete.css'));
        $this->assertSame(1, preg_match($pattern, '/resources/js/jquery-4fa896f615.form.js'));
        $this->assertSame(0, preg_match($pattern, '/resources/bootswatch/flatly/bootstrap.min.css'));
        $this->assertSame(0, preg_match($pattern, '/resources/bootswatch5/flatly/bootstrap-0123456789.min.css'));
        $this->assertSame(0, preg_match($pattern, '/images/ospos-39c74204a5.min.js'));
        $this->assertSame(0, preg_match($pattern, '/resources/ospos-39C74204a5.min.js'));
        $this->assertSame(0, preg_match($pattern, '/resources/ospos-39c74204a55.min.js'));
    }

    /**
     * Matches every file gulp-rev wrote to the built resources folders and no file in the copied theme folders.
     */
    public function testCacheRuleMatchesBuiltResources(): void
    {
        $resources = realpath(__DIR__ . '/../public/resources');

        if ($resources === false || glob($resources . '/opensourcepos-*.min.js') === []) {
            $this->markTestSkipped('The front-end bundle is not built. Run npm run build first.');
        }

        $pattern = $this->cacheRulePattern();
        $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            $path     = '/resources' . str_replace('\\', '/', substr($file->getPathname(), strlen($resources)));
            $is_theme = preg_match('~^/resources/bootswatch5?/~', $path) === 1;

            $this->assertSame($is_theme ? 0 : 1, preg_match($pattern, $path), "Unexpected cache rule result for {$path}.");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }
}
