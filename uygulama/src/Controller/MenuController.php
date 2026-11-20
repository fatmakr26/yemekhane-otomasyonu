<?php
declare(strict_types=1);

namespace App\Controller;

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
        ];
    }

    public function gununMenusu(int $yemekhaneId, string $tarih, int $ogunId): array
    {
        $menu = $this->repo->gununMenusuGetir($yemekhaneId, $tarih, $ogunId);
        if (!$menu) {
            return ['menu' => null, 'kaynak' => 'veritabani'];
        }
        return ['menu' => $menu, 'kaynak' => 'veritabani'];
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
            throw new RuntimeException('Bu işlem için yönetici yetkisi gerekir.', 403);
        }
        return ['menuler' => $this->repo->yoneticiMenuRaporu()];
    }

    public function yoneticiMenuKaydet(array $kullanici, array $veri): array
    {
        if ($kullanici['rol'] !== 'yonetici') {
            throw new RuntimeException('Bu işlem için yönetici yetkisi gerekir.', 403);
        }

        $yemekhaneId = (int)($veri['yemekhane_id'] ?? 0);
        $tarih       = trim((string)($veri['tarih'] ?? ''));
        $ogunId      = (int)($veri['ogun_id'] ?? 0);
        $ogrFiyat    = (float)($veri['ogrenci_fiyati'] ?? 0);
        $perFiyat    = (float)($veri['personel_fiyati'] ?? 0);
        $yemekIdler  = (array)($veri['yemek_idler'] ?? []);

        if ($yemekhaneId <= 0 || $tarih === '' || $ogunId <= 0) {
            throw new RuntimeException('Yemekhane, tarih ve öğün seçimi zorunludur.');
        }

        $menuId = $this->repo->menuKaydet($yemekhaneId, $tarih, $ogunId, $ogrFiyat, $perFiyat, $yemekIdler);
        return ['mesaj' => 'Menü kaydedildi.', 'menu_id' => $menuId];
    }
}