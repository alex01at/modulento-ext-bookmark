<?php

declare(strict_types=1);

namespace Modulento\Bookmark;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Lets a logged-in account bookmark an offer or a provider and see them
 * again under "My bookmarks" - no permission of its own: every account
 * manages only its own.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $registrar->routes(function (Router $router): void {
            $router->get('/account/bookmarks', [BookmarkController::class, 'index'], Router::AUTH);
            $router->post('/account/bookmarks/toggle', [BookmarkController::class, 'toggle'], Router::AUTH);
        });

        $registrar->accountLink('bookmark.account_link', '/account/bookmarks');

        $registrar->offerSection('@bookmark/offer_star.twig', function (array $offer, App $app): array {
            $account = $app->auth->account();

            return $account === null ? [] : [
                'kind' => 'offer',
                'id' => $offer['id'],
                'bookmarked' => (new Bookmarks($app->db))->has((int) $account['id'], 'offer', (int) $offer['id']),
            ];
        });

        $registrar->providerSection('@bookmark/provider_star.twig', function (array $provider, App $app): array {
            $account = $app->auth->account();

            return $account === null ? [] : [
                'kind' => 'provider',
                'id' => $provider['id'],
                'bookmarked' => (new Bookmarks($app->db))->has((int) $account['id'], 'provider', (int) $provider['id']),
            ];
        });
    }
}
