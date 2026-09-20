<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use Config\Services;

/**
 * Keeps the login page in English and left to right for every shop language.
 *
 * The shop language is loaded before filters run, so the login request must
 * replace it for the current request without changing the saved setting.
 */
class EnglishLoginFilter implements FilterInterface
{
    /**
     * Sets English request settings and locale for the login page only.
     *
     * @param list<string>|null $arguments
     *
     * @return RequestInterface|ResponseInterface|string|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $settings                      = config(OSPOS::class)->settings;
        $settings['language']          = 'english';
        $settings['language_code']     = 'en';
        config(OSPOS::class)->settings = $settings;

        Services::language()->setLocale('en');

        if ($request instanceof IncomingRequest) {
            $request->setLocale('en');
        }

        return null;
    }

    /**
     * Leaves the login response unchanged.
     *
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
