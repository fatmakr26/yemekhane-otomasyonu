<?php
declare(strict_types=1);

namespace App\Controller;

use App\Config\Baglanti;
use App\Repository\YemekhaneRepo;
use RuntimeException;

class AuthController
{
    public function __construct(private YemekhaneRepo $repo) {}

    public function giris(array $veri): array
    {
        $eposta = trim((string)($veri['eposta'] ?? ''));
        $sifre  = (string)($veri['sifre'] ?? '');

        if ($eposta === '' || $sifre === '') {
            throw new RuntimeException('E-posta ve şifre zorunludur.');
        }

        $kullanici = $this->repo->epostaIleKullaniciBul($eposta);
        if (!$kullanici || !password_verify($sifre, $kullanici['sifre_hash'])) {
            throw new RuntimeException('E-posta veya şifre hatalı.');
        }
        if ((int)$kullanici['aktif'] !== 1) {
            throw new RuntimeException('Hesabınız pasif durumda.');
        }

        $token = bin2hex(random_bytes(24));
        $oturumVerisi = [
            'id'         => (int)$kullanici['id'],
            'ad'         => $kullanici['ad'],
            'soyad'      => $kullanici['soyad'],
            'eposta'     => $kullanici['eposta'],
            'rol'        => $kullanici['rol'],
            'ogrenci_no' => $kullanici['ogrenci_no'],
        ];

        // Aşama 3: Oturum Redis'te 30 dakika (1800 sn) saklanır
        $redis = Baglanti::redis();
        $redis->setex("oturum:{$token}", 1800, json_encode($oturumVerisi, JSON_UNESCAPED_UNICODE));

        $guncelBakiye = $this->repo->guncelBakiyePrimary((int)$kullanici['id']);
        $oturumVerisi['bakiye'] = $guncelBakiye;

        return [
            'token'     => $token,
            'kullanici' => $oturumVerisi
        ];
    }

    public function cikis(?string $token): array
    {
        if ($token) {
            Baglanti::redis()->del("oturum:{$token}");
        }
        return ['mesaj' => 'Çıkış yapıldı.'];
    }

    public static function oturumDogrula(?string $token): array
    {
        if (!$token) {
            throw new RuntimeException('Oturum açmanız gerekiyor.', 401);
        }
        $redis = Baglanti::redis();
        $anahtar = "oturum:{$token}";
        $kayit = $redis->get($anahtar);
        if (!$kayit) {
            throw new RuntimeException('Oturum süreniz dolmuş, lütfen tekrar giriş yapın.', 401);
        }
        // Aşama 3: Her istekte oturum süresi 30 dakika yenilenir
        $redis->expire($anahtar, 1800);
        return json_decode($kayit, true);
    }
}