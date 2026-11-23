<?php
declare(strict_types=1);

namespace App\Controller;

use App\Config\Baglanti;
use App\Repository\YemekhaneRepo;
use RuntimeException;

class MenuController
{
    public function __construct(private YemekhaneRepo $repo) {}

    public function sabitler(): array
    {
        return [
            'yemekhaneler' => $this->repo->yemekhaneler(),
            'ogunler'      => $this->repo->ogunler(),
            'yemekler'     => $this->repo->tumYemekler(),
            'alerjenler'   => $this->repo->alerjenler(),
        ];
    }

    public function gununMenusu(int $yemekhaneId, string $tarih, int $ogunId): array
    {
        $redis = Baglanti::redis();
        $anahtar = "menu:{$yemekhaneId}:{$tarih}:{$ogunId}";

        $onbellek = $redis->get($anahtar);
        if ($onbellek !== false) {
            error_log("[REDIS CACHE HIT] {$anahtar}");
            $veri = json_decode($onbellek, true);
            $veri['kaynak'] = 'redis';
            return $veri;
        }

        error_log("[REDIS CACHE MISS - DB OKUMA] {$anahtar}");
        $menu = $this->repo->gununMenusuGetir($yemekhaneId, $tarih, $ogunId);
        if (!$menu) {
            return ['menu' => null, 'kaynak' => 'veritabani'];
        }

        $sonuc = ['menu' => $menu, 'kaynak' => 'veritabani'];

        $gunSonu = strtotime($tarih . ' 23:59:59');
        $kalanSaniye = max(60, $gunSonu - time());
        $redis->setex($anahtar, $kalanSaniye, json_encode($sonuc, JSON_UNESCAPED_UNICODE));

        return $sonuc;
    }

    public function haftalik(int $yemekhaneId, array $kullanici): array
    {
        return [
            'rol'     => $kullanici['rol'],
            'menuler' => $this->repo->haftalikMenuler($yemekhaneId, (int)$kullanici['id'])
        ];
    }

    public function yoneticiRaporu(array $kullanici): array
    {
        if ($kullanici['rol'] !== 'yonetici') {
            throw new RuntimeException('Bu islem icin yonetici yetkisi gerekir.', 403);
        }
        return ['menuler' => $this->repo->yoneticiMenuRaporu()];
    }

    public function yoneticiMenuKaydet(array $kullanici, array $veri): array
    {
        if ($kullanici['rol'] !== 'yonetici') {
            throw new RuntimeException('Bu islem icin yonetici yetkisi gerekir.', 403);
        }

        $yemekhaneId = (int)($veri['yemekhane_id'] ?? 0);
        $tarih       = trim((string)($veri['tarih'] ?? ''));
        $ogunId      = (int)($veri['ogun_id'] ?? 0);
        $ogrFiyat    = (float)($veri['ogrenci_fiyati'] ?? 0);
        $perFiyat    = (float)($veri['personel_fiyati'] ?? 0);
        $yemekIdler  = (array)($veri['yemek_idler'] ?? []);

        if ($yemekhaneId <= 0 || $tarih === '' ||$ogunId <= 0) {
            throw new RuntimeException('Yemekhane, tarih ve ogun secimi zorunludur.');
        }

        $menuId =$this->repo->menuKaydet($yemekhaneId,$tarih, $ogunId,$ogrFiyat, $perFiyat,$yemekIdler);

        $anahtar = "menu:{$yemekhaneId}:{$tarih}:{$ogunId}";
        Baglanti::redis()->del($anahtar);
        error_log("[REDIS CACHE INVALIDATE] {$anahtar} silindi.");

        return ['mesaj' => 'Menu kaydedildi ve onbellek guncellendi.', 'menu_id' => $menuId];
    }
}