<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Config\ShopLockdown;

/**
 * Returns not found for routes belonging to modules removed from the shop.
 */
class ShopLockdownFilter implements FilterInterface
{
    /**
     * Rejects a request whose normalized first URI segment is a removed module.
     *
     * @param list<string>|null $arguments
     *
     * @return RequestInterface|ResponseInterface|string|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $module = $this->normalizeModuleSegment($request->getUri()->getSegment(1));

        if (in_array($module, ShopLockdown::REMOVED_MODULES, true)) {
            return Services::response()->setStatusCode(404);
        }

        return null;
    }

    /**
     * Decodes a route segment repeatedly and normalizes its module name.
     *
     * @param string $segment Raw first URI segment.
     *
     * @return string Lowercase module name without later path segments.
     */
    private function normalizeModuleSegment(string $segment): string
    {
        do {
            $decoded = rawurldecode($segment);
            $changed = $decoded !== $segment;
            $segment = $decoded;
        } while ($changed);

        return strtolower(explode('/', $segment, 2)[0]);
    }

    /**
     * Leaves the response unchanged.
     *
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
