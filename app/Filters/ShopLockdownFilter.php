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
     * Rejects a request whose first URI segment is a removed module.
     *
     * @param list<string>|null $arguments
     *
     * @return RequestInterface|ResponseInterface|string|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $module = $request->getUri()->getSegment(1);

        if (in_array($module, ShopLockdown::REMOVED_MODULES, true)) {
            return Services::response()->setStatusCode(404);
        }

        return null;
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
