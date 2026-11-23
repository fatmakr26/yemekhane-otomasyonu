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
        $porsiyon = trim((string)($veri['porsiyon'] ?? 'tam'));
        if ($menuId <= 0) {
            throw new RuntimeException('Gecersiz menu.');
        }

        $sonuc = $this->repo->rezervasyonYap((int)$kullanici['id'], $menuId, $porsiyon);
        $guncelBakiye = $this->repo->guncelBakiyePrimary((int)$kullanici['id']);

        return [
            'mesaj'          => 'Rezervasyonunuz olusturuldu (' . ($porsiyon === 'yarim' ? 'Yarim Porsiyon' : 'Tam Porsiyon') . ').',
            'rezervasyon_id' => $sonuc['rezervasyon_id'] ?? null,
            'ucret'          => $sonuc['ucret'] ?? 0,
            'bakiye'         => $guncelBakiye
        ];
    }

    public function rezervasyonIptal(array $kullanici, array $veri): array
    {
        $rezId = (int)($veri['rezervasyon_id'] ?? 0);
        if ($rezId <= 0) {
            throw new RuntimeException('Gecersiz rezervasyon.');
        }

        $sonuc = $this->repo->rezervasyonIptal((int)$kullanici['id'], $rezId);
        $guncelBakiye = $this->repo->guncelBakiyePrimary((int)$kullanici['id']);

        return [
            'mesaj'  => 'Rezervasyon iptal edildi ve ucret iade edildi.',
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
            'mesaj'  => 'Bakiye yuklendi.',
            'bakiye' => $guncelBakiye
        ];
    }

    public function degerlendir(array $kullanici, array $veri): array
    {
        $menuId = (int)($veri['menu_id'] ?? 0);
        $puan   = (int)($veri['puan'] ?? 0);
        $yorum  = trim((string)($veri['yorum'] ?? ''));

        if ($menuId <= 0) {
            throw new RuntimeException('Gecersiz menu.');
        }

        $this->repo->degerlendirmeKaydet((int)$kullanici['id'], $menuId, $puan, $yorum);
        return ['mesaj' => 'Degerlendirmeniz kaydedildi.'];
    }

    public function alerjenlerim(array $kullanici): array
    {
        return [
            'alerjen_idler' => $this->repo->kullaniciAlerjenleri((int)$kullanici['id'])
        ];
    }

    public function alerjenKaydet(array $kullanici, array $veri): array
    {
        $idler = (array)($veri['alerjen_idler'] ?? []);
        $guncel = $this->repo->kullaniciAlerjenleriKaydet((int)$kullanici['id'], $idler);
        return [
            'mesaj'         => 'Alerjen tercihleriniz guncellendi.',
            'alerjen_idler' => $guncel
        ];
    }
}