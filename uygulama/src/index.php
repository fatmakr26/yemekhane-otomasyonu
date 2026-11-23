<?php
declare(strict_types=1);

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/' . $rel . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

use App\Controller\AuthController;
use App\Controller\IslemController;
use App\Controller\MenuController;
use App\Repository\YemekhaneRepo;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$hamVeri = file_get_contents('php://input');
$veri   = $hamVeri ? (json_decode($hamVeri, true) ?: []) : [];

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = null;
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $token = trim($m[1]);
}

$repo   = new YemekhaneRepo();
$auth   = new AuthController($repo);
$menu   = new MenuController($repo);
$islem  = new IslemController($repo);

try {
    $sonuc = match (true) {
        $uri === '/api/' || $uri === '/api/saglik'
            => ['durum' => 'ok', 'servis' => 'Yemekhane Web API'],

        $method === 'POST' && $uri === '/api/giris'
            => $auth->giris($veri),

        $method === 'POST' && $uri === '/api/cikis'
            => $auth->cikis($token),

        $method === 'GET' && $uri === '/api/ben'
            => ['kullanici' => array_merge(
                $k = AuthController::oturumDogrula($token),
                ['bakiye' => $repo->guncelBakiyePrimary((int)$k['id'])]
            )],

        $method === 'GET' && $uri === '/api/sabitler'
            => $menu->sabitler(),

        $method === 'GET' && $uri === '/api/gunun-menusu'
            => $menu->gununMenusu(
                (int)($_GET['yemekhane_id'] ?? 1),
                (string)($_GET['tarih'] ?? date('Y-m-d')),
                (int)($_GET['ogun_id'] ?? 2)
            ),

        $method === 'GET' && $uri === '/api/haftalik-menu'
            => $menu->haftalik(
                (int)($_GET['yemekhane_id'] ?? 1),
                AuthController::oturumDogrula($token)
            ),

        $method === 'POST' && $uri === '/api/rezervasyon'
            => $islem->rezervasyonYap(AuthController::oturumDogrula($token), $veri),

        $method === 'POST' && $uri === '/api/rezervasyon-iptal'
            => $islem->rezervasyonIptal(AuthController::oturumDogrula($token), $veri),

        $method === 'GET' && $uri === '/api/rezervasyonlarim'
            => $islem->rezervasyonlarim(AuthController::oturumDogrula($token)),

        $method === 'GET' && $uri === '/api/bakiye'
            => $islem->bakiyeBilgisi(AuthController::oturumDogrula($token)),

        $method === 'POST' && $uri === '/api/bakiye-yukle'
            => $islem->bakiyeYukle(AuthController::oturumDogrula($token), $veri),

        $method === 'POST' && $uri === '/api/degerlendir'
            => $islem->degerlendir(AuthController::oturumDogrula($token), $veri),

        $method === 'GET' && $uri === '/api/alerjenlerim'
            => $islem->alerjenlerim(AuthController::oturumDogrula($token)),

        $method === 'POST' && $uri === '/api/alerjenlerim'
            => $islem->alerjenKaydet(AuthController::oturumDogrula($token), $veri),

        $method === 'GET' && $uri === '/api/yonetici/menuler'
            => $menu->yoneticiRaporu(AuthController::oturumDogrula($token)),

        $method === 'POST' && $uri === '/api/yonetici/menu-kaydet'
            => $menu->yoneticiMenuKaydet(AuthController::oturumDogrula($token), $veri),

        default => throw new RuntimeException('Istenen uc nokta bulunamadi.', 404)
    };

    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    $kod = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 400;
    http_response_code($kod);
    echo json_encode(['hata' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}