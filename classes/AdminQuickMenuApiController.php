<?php

declare(strict_types=1);

namespace Grav\Plugin\AdminQuickMenu;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Own settings endpoint for the admin2 "Quick Add Content" page.
 *
 * Deliberately NOT the generic GET /config/plugins/admin-quick-menu route —
 * that one is gated on api.config.read, the same blanket permission that
 * unlocks the whole Configuration section. An account that may create pages
 * via Quick Add (api.pages.write) but must not browse/edit Configuration
 * would otherwise be unable to load its own shortcut list. Gating this
 * route on api.pages.write instead keeps the two capabilities independent.
 */
class AdminQuickMenuApiController extends AbstractApiController
{
    public function shortcuts(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.write');

        $config = (array) $this->config->get('plugins.admin-quick-menu', []);

        return ApiResponse::create([
            'menu_shortcuts' => $config['menu_shortcuts'] ?? [],
            'custom_links'   => $config['custom_links'] ?? [],
        ]);
    }
}
