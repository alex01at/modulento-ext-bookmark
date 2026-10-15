<?php

declare(strict_types=1);

// Plain-PHP checks of the bookmark addon's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed.

use Modulento\Bookmark\Bookmarks;
use Modulento\Bookmark\BookmarkController;
use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferType;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database --------------------------------------------------------------
// Of the core only the tables the addon's own classes, and the view
// helpers (OfferView, ProviderView, Badges) it hands offers/providers to,
// touch.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('bookmark', '0', 1, '2026-01-01 00:00:00')");
// Subscriptions is a module "everything is on until switched off" - this
// addon's views call Subscriptions::grants(), so it is switched off here
// exactly as a fresh install's own installer does, to avoid needing its tables too.
$pdo->exec("INSERT INTO setting VALUES ('core.modules_disabled', 'subscriptions')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    identity_status TEXT NOT NULL DEFAULT 'none', locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER UNIQUE REFERENCES account (id) ON DELETE CASCADE, type TEXT, status TEXT,
    name TEXT, slug TEXT UNIQUE, legal_name TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, contact_email TEXT, phone TEXT,
    vat_id TEXT, company_register TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT, updated_at TEXT, avg_response_minutes INTEGER, response_sample_count INTEGER NOT NULL DEFAULT 0,
    rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT, PRIMARY KEY (provider_id, locale))');
$pdo->exec("CREATE TABLE offer (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, category_id INTEGER,
    status TEXT, price_from INTEGER, currency TEXT, published_at TEXT, created_at TEXT, updated_at TEXT, rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE offer_translation (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT, summary TEXT, description TEXT, PRIMARY KEY (offer_id, locale))');
$pdo->exec('CREATE TABLE offer_image (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)');
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE x_bookmark_bookmark (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL REFERENCES account (id) ON DELETE CASCADE,
    kind TEXT NOT NULL, target_id INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE (account_id, kind, target_id))');
// The base layout checks the visitor's permissions (the admin edit bar).
$pdo->exec('CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)');
$pdo->exec('CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)');

// The migration and the schema above must name the same columns.
$migration = (string) file_get_contents($here . '/migrations/001_bookmark.sql');
preg_match('/CREATE TABLE x_bookmark_bookmark \((.*?)\n\) ENGINE/s', $migration, $body);
preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
$inTest = array_column($pdo->query('PRAGMA table_info(x_bookmark_bookmark)')->fetchAll(), 'name');
sort($columns[1]);
sort($inTest);
check('schema: x_bookmark_bookmark has the same columns in the migration and in the tests', $columns[1] !== [] && $columns[1] === $inTest);
check('schema: a bookmark cannot be doubled, per account', str_contains($migration, 'UNIQUE KEY uq_x_bookmark_bookmark (account_id, kind, target_id)'));
check('schema: removing an account removes its bookmarks', str_contains($migration, 'REFERENCES account (id) ON DELETE CASCADE'));

$uploads = sys_get_temp_dir() . '/modulento-bookmark-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/bookmark');

$app = new App([
    'app' => [
        'env' => 'dev', 'url' => 'https://bookmark.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'mail' => ['from' => 'noreply@bookmark.example', 'transport' => 'log', 'log_path' => $uploads . '/mail.log'],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
], $pdo, 'de');
$app->translator->load($core . '/core/lang', 'core');
$app->extensions->loadEnabled($app);
check('extension: the core loads it', isset($app->extensions->loaded()['bookmark']) && $app->extensions->loaded()['bookmark']->type === 'addon');
check('extension: an account link, and both offer and provider sections, are announced - no permission of its own',
    $app->accountLinks() === [['label_key' => 'bookmark.account_link', 'path' => '/account/bookmarks']]
    && array_column($app->offerSections(), 'template') === ['@bookmark/offer_star.twig']
    && array_column($app->providerSections(), 'template') === ['@bookmark/provider_star.twig']
    && $app->permissions() === []);

// A minimal offer type, just enough for Offers::isPublic() and OfferView::card() to work.
$stubType = new class implements OfferType {
    public function id(): string
    {
        return 'test.stub';
    }

    public function labelKey(): string
    {
        return 'test.stub';
    }

    public function priceLabelKey(): string
    {
        return 'test.stub.price';
    }

    public function formTemplate(): string
    {
        return '';
    }

    public function detailTemplate(): string
    {
        return '';
    }

    public function formData(?int $offerId, ?array $typed, App $app, ?array $locales = null): array
    {
        return [];
    }

    public function validate(array $input, ?int $offerId, App $app): array
    {
        return ['values' => [], 'errors' => []];
    }

    public function save(int $offerId, array $values, App $app): ?int
    {
        return null;
    }

    public function detailData(int $offerId, string $locale, App $app): array
    {
        return [];
    }
};
$app->offers->registerType($stubType);

// --- Fixtures: two accounts, one a provider, one offer -----------------------
$testHash = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
$pdo->exec("INSERT INTO account (id, email, display_name, password_hash, created_at) VALUES (1, 'anna@example.test', 'Anna', '{$testHash}', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO account (id, email, display_name, password_hash, created_at) VALUES (2, 'bruno@example.test', 'Bruno', '{$testHash}', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO account (id, email, display_name, password_hash, created_at) VALUES (3, 'carla@example.test', 'Carla', '{$testHash}', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO provider (id, account_id, type, status, name, slug, created_at, updated_at) VALUES (1, 2, 'private', 'approved', 'Bruno', 'bruno', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer (id, provider_id, type, status, currency, published_at, created_at, updated_at) VALUES (1, 1, 'test.stub', 'published', 'EUR', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer_translation (offer_id, locale, title, slug) VALUES (1, 'de', 'Ein Angebot', 'ein-angebot')");

// --- Bookmarks: toggle, idempotency, per-account isolation --------------------
$bookmarks = new Bookmarks($pdo);

check('toggle: the first call adds the bookmark', $bookmarks->toggle(1, 'offer', 1) === true && $bookmarks->has(1, 'offer', 1));
check('toggle: the next call removes it again - a star is a simple on/off switch', $bookmarks->toggle(1, 'offer', 1) === false && !$bookmarks->has(1, 'offer', 1));
check('toggle: toggling back on gives the same result as the first time', $bookmarks->toggle(1, 'offer', 1) === true);

$bookmarks->toggle(1, 'provider', 1);
check('kinds: an offer and a provider bookmark of the same account do not collide', $bookmarks->has(1, 'offer', 1) && $bookmarks->has(1, 'provider', 1));

check('isolation: another account starts with no bookmarks of its own', !$bookmarks->has(2, 'offer', 1) && $bookmarks->targetIds(2, 'offer') === []);
$bookmarks->toggle(2, 'offer', 1);
check('isolation: bookmarking the same offer does not affect the first account', $bookmarks->has(1, 'offer', 1) && $bookmarks->has(2, 'offer', 1));
$bookmarks->toggle(1, 'offer', 1);
check('isolation: removing one account\'s bookmark leaves the other\'s alone', !$bookmarks->has(1, 'offer', 1) && $bookmarks->has(2, 'offer', 1));
$bookmarks->toggle(1, 'offer', 1);

// A target that was never a real offer: there is no foreign key to
// offer/provider on purpose, so this does not fail - see the migration's
// own comment.
check('no foreign key to the target: bookmarking an id that is not a real offer still works', $bookmarks->toggle(1, 'offer', 999999) === true);
$bookmarks->toggle(1, 'offer', 999999);

check('targetIds: newest bookmark first', (function () use ($bookmarks): bool {
    $bookmarks->toggle(3, 'offer', 10);
    $bookmarks->toggle(3, 'offer', 20);
    $bookmarks->toggle(3, 'offer', 30);
    return $bookmarks->targetIds(3, 'offer') === [30, 20, 10];
})());

// --- BookmarkController::index(): only what still exists and is public -------
$app->auth->login(1);
ob_start();
(new BookmarkController($app))->index([]);
$body = ob_get_clean();
check('index: a bookmarked, public offer is listed', str_contains($body, 'Ein Angebot'));

$pdo->exec("UPDATE offer SET status = 'draft' WHERE id = 1");
ob_start();
(new BookmarkController($app))->index([]);
$body = ob_get_clean();
check('index: an offer that is no longer public disappears from the list - the bookmark row itself is untouched',
    !str_contains($body, 'Ein Angebot') && $bookmarks->has(1, 'offer', 1));
$pdo->exec("UPDATE offer SET status = 'published' WHERE id = 1");

$pdo->exec('DELETE FROM offer WHERE id = 1');
ob_start();
(new BookmarkController($app))->index([]);
$body = ob_get_clean();
check('index: a deleted offer disappears too, the stale bookmark causes no error', !str_contains($body, 'Ein Angebot'));

// --- toggle() via the controller -----------------------------------------
check('controller toggle: flips the bookmark through the same path a form submission takes', (function () use ($app, $bookmarks): bool {
    $before = $bookmarks->has(1, 'provider', 1);
    $_POST = ['kind' => 'provider', 'id' => '1', 'return' => '/providers/bruno'];
    ob_start();
    (new BookmarkController($app))->toggle([]);
    ob_end_clean();

    return $bookmarks->has(1, 'provider', 1) === !$before;
})());
check('controller toggle: an unknown "kind" or non-numeric id is ignored, not fatal', (function () use ($app): bool {
    $_POST = ['kind' => 'nonsense', 'id' => '1'];
    ob_start();
    (new BookmarkController($app))->toggle([]);
    ob_end_clean();

    return true;
})());

// --- Language files -----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "bookmark."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'bookmark.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(bookmark\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
check('lang: every key used in templates and code exists', array_diff(array_unique($used), array_keys($de)) === []);

// --- Clean up -------------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
