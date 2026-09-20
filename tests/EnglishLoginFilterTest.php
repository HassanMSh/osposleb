<?php

namespace Tests;

use App\Filters\EnglishLoginFilter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Filters;
use Config\OSPOS;
use ReflectionClass;

/**
 * Covers the English-only, left-to-right login request filter.
 *
 * @internal
 */
final class EnglishLoginFilterTest extends CIUnitTestCase
{
    /**
     * Loads Arabic shop settings into the test configuration.
     */
    protected function setUp(): void
    {
        parent::setUp();

        helper('locale');

        $ospos           = (new ReflectionClass(OSPOS::class))->newInstanceWithoutConstructor();
        $ospos->settings = [
            'language'      => 'arabic',
            'language_code' => 'ar-LB',
        ];
        Factories::injectMock('config', OSPOS::class, $ospos);
    }

    /**
     * Applies the filter and confirms the login page uses English and LTR.
     */
    public function testLoginFilterForcesEnglishAndLeftToRight(): void
    {
        $request = service('request');
        $request->setLocale('ar-LB');

        (new EnglishLoginFilter())->before($request);

        $this->assertSame('en', current_language_code());
        $this->assertSame('ltr', text_direction());
        $this->assertSame('en', $request->getLocale());
    }

    /**
     * Keeps the filter route-scoped instead of applying it to every request.
     */
    public function testFilterIsOnlyRegisteredForLoginRoutes(): void
    {
        $filters = new Filters();

        $this->assertNotContains('englishlogin', $filters->globals['before']);
        $this->assertSame(
            ['/', 'login', 'login/*'],
            $filters->filters['englishlogin']['before'],
        );
        $this->assertSame('ar-LB', config(OSPOS::class)->settings['language_code']);
        $this->assertSame('rtl', text_direction());
    }

    /**
     * Keeps Arabic right-to-left direction detection intact.
     */
    public function testArabicLebanonStillReportsRightToLeft(): void
    {
        $this->assertSame('rtl', text_direction('ar-LB'));
    }
}
