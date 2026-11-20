<?php
declare(strict_types=1);

namespace App\Controller;

use App\Repository\YemekhaneRepo;
use RuntimeException;

class IslemController
{
    public function __construct(private YemekhaneRepo $repo) {}

    public function rezervasyonYap(array $kullanici, array $veri): array
    {
        $menuId = (int)($veri['menu_id'] ?? 0);
        if ($menuId <= 0) {
            throw new RuntimeException('Geçersiz menü.');
        }

        $sonuc = $this->repo->rezervasyonYap((int)$kullanici['id'], $menuId);
        // Replikasyon gecikmesine takılmamak için bakiye Primary sunucudan okunur
        $guncelBakiye = $this->repo->guncelBakiyePrimary((int)$kullanici['id']);

        return [
            'mesaj'          => 'Rezervasyonunuz oluşturuldu.',
            'rezervasyon_id' => $sonuc['rezervasyon_id'] ?? null,
            'ucret'          => $sonuc['ucret'] ?? 0,
            'bakiye'         => $guncelBakiye
        ];
    }

    public function rezervasyonIptal(array $kullanici, array $veri): array
    {
        $rezId = (int)($veri['rezervasyon_id'] ?? 0);
        if ($rezId <= 0) {
            throw new RuntimeException('Geçersiz rezervasyon.');
        }

        $sonuc = $this->repo->rezervasyonIptal((int)$kullanici['id'], $rezId);
        $guncelBakiye = $this->repo->guncelBakiyePrimary((int)$kullanici['id']);

        return [
            'mesaj'  => 'Rezervasyon iptal edildi ve ücret iade edildi.',
            'iade'   => $sonuc['iade'] ?? 0,
            'bakiye' => $guncelBakiye
        ];
    }

    public function rezervasyonlarim(array $kullanici): array
    {
        return [
            'rezervasyonlar' => $this->repo->kullaniciRezervasyonlari((int)$kullanici['id'])
        ];
    }

    public function bakiyeBilgisi(array $kullanici): array
    {
        $kid = (int)$kullanici['id'];
        return [
            'bakiye'     => $this->repo->guncelBakiyePrimary($kid),
            'hareketler' => $this->repo->bakiyeHareketleri($kid)
        ];
    }

    public function bakiyeYukle(array $kullanici, array $veri): array
    {
        $tutar = (float)($veri['tutar'] ?? 0);
        $this->repo->bakiyeYukle((int)$kullanici['id'], $tutar);
        $guncelBakiye = $this->repo->guncelBakiyePrimary((int)$kullanici['id']);

        return [
            'mesaj'  => 'Bakiye yüklendi.',
            'bakiye' => $guncelBakiye
        ];
    }

    public function degerlendir(array $kullanici, array $veri): array
    {
        $menuId = (int)($veri['menu_id'] ?? 0);
        $puan   = (int)($veri['puan'] ?? 0);
        $yorum  = trim((string)($veri['yorum'] ?? ''));

        if ($menuId <= 0) {
            throw new RuntimeException('Geçersiz menü.');
        }

        $this->repo->degerlendirmeKaydet((int)$kullanici['id'], $menuId, $puan, $yorum);
        return ['mesaj' => 'Değerlendirmeniz kaydedildi.'];
    }
}