<?php

declare(strict_types=1);

namespace Modulento\Bookmark;

use Modulento\Core\Catalogue\OfferView;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Provider\ProviderView;

final class BookmarkController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $bookmarks = new Bookmarks($app->db);

        $offers = array_filter(array_map(
            fn (int $id) => $app->offers->find($id),
            $bookmarks->targetIds($accountId, 'offer')
        ), fn (?array $offer) => $offer !== null && $app->offers->isPublic($offer));

        $providers = array_filter(array_map(
            fn (int $id) => $app->providers->find($id),
            $bookmarks->targetIds($accountId, 'provider')
        ), fn (?array $provider) => $provider !== null && $provider['status'] === 'approved' && $provider['account_status'] === 'active');

        $this->render('@bookmark/account/bookmarks.twig', [
            'offers' => OfferView::cards(array_values($offers), $app),
            'providers' => ProviderView::all(array_values($providers), $app),
        ]);
    }

    public function toggle(array $params): void
    {
        $app = $this->app;
        $kind = (string) ($_POST['kind'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        if (in_array($kind, Bookmarks::KINDS, true) && $id > 0) {
            (new Bookmarks($app->db))->toggle((int) $app->auth->account()['id'], $kind, $id);
        }

        $this->redirect($this->safeReturn('/account/bookmarks'));
    }
}
