<?php
declare(strict_types=1);

namespace App\Repository;

use App\Config\Baglanti;
use PDO;
use PDOException;
use RuntimeException;

class YemekhaneRepo
{
    private function spHataAyikla(PDOException $e): never
    {
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1644) {
            throw new RuntimeException($e->errorInfo[2] ?? 'Islem gerceklestirilemedi.');
        }
        throw new RuntimeException('Veritabani islemi sirasinda bir hata olustu.');
    }

    // --- KULLANICI & BAKIYE & ALERJEN ---
    public function epostaIleKullaniciBul(string $eposta): ?array
    {
        $stmt = Baglanti::okuma()->prepare(
            'SELECT id, ad, soyad, eposta, sifre_hash, rol, ogrenci_no, bakiye, aktif
               FROM kullanicilar WHERE eposta = ?'
        );
        $stmt->execute([$eposta]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function guncelBakiyePrimary(int $kullaniciId): float
    {
        $stmt = Baglanti::yazma()->prepare('SELECT bakiye FROM kullanicilar WHERE id = ?');
        $stmt->execute([$kullaniciId]);
        return (float)$stmt->fetchColumn();
    }

    public function kullaniciAlerjenleri(int $kullaniciId): array
    {
        $stmt = Baglanti::okuma()->prepare(
            'SELECT alerjen_id FROM kullanici_alerjenleri WHERE kullanici_id = ? ORDER BY alerjen_id'
        );
        $stmt->execute([$kullaniciId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function kullaniciAlerjenleriKaydet(int $kullaniciId, array $alerjenIdler): array
    {
        $db = Baglanti::yazma();
        $db->beginTransaction();
        try {
            $del = $db->prepare('DELETE FROM kullanici_alerjenleri WHERE kullanici_id = ?');
            $del->execute([$kullaniciId]);

            $ins = $db->prepare('INSERT IGNORE INTO kullanici_alerjenleri (kullanici_id, alerjen_id) VALUES (?, ?)');
            foreach ($alerjenIdler as $aid) {
                $aidInt = (int)$aid;
                if ($aidInt > 0) {
                    $ins->execute([$kullaniciId, $aidInt]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new RuntimeException('Alerjen tercihleri kaydedilemedi: ' . $e->getMessage());
        }
        return $this->kullaniciAlerjenleri($kullaniciId);
    }

    public function bakiyeHareketleri(int $kullaniciId): array
    {
        $stmt = Baglanti::okuma()->prepare(
            'SELECT id, tur, tutar, rezervasyon_id, aciklama, olusturma
               FROM bakiye_hareketleri
              WHERE kullanici_id = ?
              ORDER BY olusturma DESC, id DESC LIMIT 50'
        );
        $stmt->execute([$kullaniciId]);
        return $stmt->fetchAll();
    }

    public function bakiyeYukle(int $kullaniciId, float $tutar): array
    {
        try {
            $stmt = Baglanti::yazma()->prepare('CALL sp_bakiye_yukle(?, ?)');
            $stmt->execute([$kullaniciId, $tutar]);
            $sonuc = $stmt->fetch();
            $stmt->closeCursor();
            return $sonuc ?: [];
        } catch (PDOException $e) {
            $this->spHataAyikla($e);
        }
    }

    // --- YEMEKHANE & OGUN & YEMEK & ALERJEN LISTELERI (REPLICA) ---
    public function yemekhaneler(): array
    {
        return Baglanti::okuma()->query('SELECT id, ad, konum, kapasite FROM yemekhaneler ORDER BY id')->fetchAll();
    }

    public function ogunler(): array
    {
        return Baglanti::okuma()->query('SELECT id, ad, sira, son_saat, servis_baslangic, servis_bitis FROM ogunler ORDER BY sira')->fetchAll();
    }

    public function alerjenler(): array
    {
        return Baglanti::okuma()->query('SELECT id, ad FROM alerjenler ORDER BY id')->fetchAll();
    }

    public function tumYemekler(): array
    {
        $sql = 'SELECT y.id, y.ad, y.kalori, y.vejetaryen, k.ad AS kategori, k.sira AS kategori_sira
                  FROM yemekler y
                  JOIN yemek_kategorileri k ON k.id = y.kategori_id
                 ORDER BY k.sira, y.ad';
        return Baglanti::okuma()->query($sql)->fetchAll();
    }

    // --- MENU DETAY & HAFTALIK MENU (REPLICA) ---
    public function gununMenusuGetir(int $yemekhaneId, string $tarih, int $ogunId): ?array
    {
        $stmt = Baglanti::okuma()->prepare(
            'SELECT * FROM v_menu_detay
              WHERE yemekhane_id = ? AND tarih = ? AND ogun_id = ?
              ORDER BY kategori_sira, yemek'
        );
        $stmt->execute([$yemekhaneId, $tarih, $ogunId]);
        $satirlar = $stmt->fetchAll();
        if (!$satirlar) {
            return null;
        }

        $ilk = $satirlar[0];
        $toplamKalori = 0;
        $yemekler = [];
        foreach ($satirlar as $s) {
            $toplamKalori += (int)$s['kalori'];
            $yemekler[] = [
                'yemek_id'      => (int)$s['yemek_id'],
                'ad'            => $s['yemek'],
                'kategori'      => $s['kategori'],
                'kalori'        => (int)$s['kalori'],
                'vejetaryen'    => (bool)$s['vejetaryen'],
                'alerjenler'    => $s['alerjenler'] ?? '',
                'alerjen_idler' => $s['alerjen_idler'] ? array_map('intval', explode(',', $s['alerjen_idler'])) : []
            ];
        }

        return [
            'menu_id'         => (int)$ilk['menu_id'],
            'tarih'           => $ilk['tarih'],
            'yemekhane_id'    => (int)$ilk['yemekhane_id'],
            'yemekhane'       => $ilk['yemekhane'],
            'ogun_id'         => (int)$ilk['ogun_id'],
            'ogun'            => $ilk['ogun'],
            'rezervasyon_son' => $ilk['rezervasyon_son'],
            'ogrenci_fiyati'  => (float)$ilk['ogrenci_fiyati'],
            'personel_fiyati' => (float)$ilk['personel_fiyati'],
            'toplam_kalori'   => $toplamKalori,
            'yemekler'        => $yemekler
        ];
    }

    public function haftalikMenuler(int $yemekhaneId, int $kullaniciId): array
    {
        $sql = 'SELECT d.menu_id, d.tarih, d.yemekhane_id, d.yemekhane, d.ogun_id, d.ogun,
                       d.kapasite, d.rezerve, d.bos_yer, d.doluluk_yuzde, d.rezervasyon_son,
                       m.ogrenci_fiyati, m.personel_fiyati,
                       (d.rezervasyon_son <= NOW()) AS sure_doldu,
                       r.id AS benim_rez_id, r.durum AS benim_rez_durum, r.porsiyon AS benim_porsiyon
                  FROM v_menu_doluluk d
                  JOIN menuler m ON m.id = d.menu_id
                  JOIN ogunler o ON o.id = d.ogun_id
                  LEFT JOIN rezervasyonlar r ON r.menu_id = d.menu_id AND r.kullanici_id = ?
                 WHERE d.yemekhane_id = ?
                   AND d.tarih BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)
                 ORDER BY d.tarih, o.sira';
        $stmt = Baglanti::okuma()->prepare($sql);
        $stmt->execute([$kullaniciId, $yemekhaneId]);
        $menuler = $stmt->fetchAll();

        $yemekStmt = Baglanti::okuma()->prepare(
            'SELECT y.ad, y.kalori, y.vejetaryen, k.ad AS kategori,
                    GROUP_CONCAT(ya.alerjen_id) AS alerjen_idler
               FROM menu_yemekleri my
               JOIN yemekler y ON y.id = my.yemek_id
               JOIN yemek_kategorileri k ON k.id = y.kategori_id
               LEFT JOIN yemek_alerjenleri ya ON ya.yemek_id = y.id
              WHERE my.menu_id = ?
              GROUP BY y.id, y.ad, y.kalori, y.vejetaryen, k.ad, k.sira
              ORDER BY k.sira, y.ad'
        );

        foreach ($menuler as &$menu) {
            $yemekStmt->execute([$menu['menu_id']]);
            $yemekler = $yemekStmt->fetchAll();
            foreach ($yemekler as &$ymk) {
                $ymk['alerjen_idler'] = $ymk['alerjen_idler']
                    ? array_map('intval', explode(',', (string)$ymk['alerjen_idler']))
                    : [];
            }
            $menu['yemekler'] = $yemekler;
        }
        return $menuler;
    }

    // --- REZERVASYON & DEGERLENDIRME ---
    public function rezervasyonYap(int $kullaniciId, int $menuId, string $porsiyon = 'tam'): array
    {
        try {
            $stmt = Baglanti::yazma()->prepare('CALL sp_rezervasyon_yap(?, ?, ?)');
            $stmt->execute([$kullaniciId, $menuId, $porsiyon]);
            $sonuc = $stmt->fetch();
            $stmt->closeCursor();
            return $sonuc ?: [];
        } catch (PDOException $e) {
            $this->spHataAyikla($e);
        }
    }

    public function rezervasyonIptal(int $kullaniciId, int $rezervasyonId): array
    {
        try {
            $stmt = Baglanti::yazma()->prepare('CALL sp_rezervasyon_iptal(?, ?)');
            $stmt->execute([$kullaniciId, $rezervasyonId]);
            $sonuc = $stmt->fetch();
            $stmt->closeCursor();
            return $sonuc ?: [];
        } catch (PDOException $e) {
            $this->spHataAyikla($e);
        }
    }

    public function kullaniciRezervasyonlari(int $kullaniciId): array
    {
        $sql = 'SELECT r.id AS rezervasyon_id, r.menu_id, r.porsiyon, r.durum, r.ucret, r.olusturma,
                       r.kullanma_zamani, r.iptal_zamani,
                       m.tarih, yh.ad AS yemekhane, o.ad AS ogun,
                       TIMESTAMP(m.tarih, o.son_saat) AS rezervasyon_son,
                       (TIMESTAMP(m.tarih, o.son_saat) > NOW()) AS iptal_edilebilir,
                       deg.puan AS verdigim_puan, deg.yorum AS verdigim_yorum
                  FROM rezervasyonlar r
                  JOIN menuler m ON m.id = r.menu_id
                  JOIN yemekhaneler yh ON yh.id = m.yemekhane_id
                  JOIN ogunler o ON o.id = m.ogun_id
                  LEFT JOIN degerlendirmeler deg ON deg.menu_id = r.menu_id AND deg.kullanici_id = r.kullanici_id
                 WHERE r.kullanici_id = ?
                 ORDER BY m.tarih DESC, o.sira DESC';
        $stmt = Baglanti::okuma()->prepare($sql);
        $stmt->execute([$kullaniciId]);
        return $stmt->fetchAll();
    }

    public function degerlendirmeKaydet(int $kullaniciId, int $menuId, int $puan, string $yorum): void
    {
        $kontrol = Baglanti::okuma()->prepare(
            "SELECT id FROM rezervasyonlar WHERE kullanici_id = ? AND menu_id = ? AND durum = 'kullanildi'"
        );
        $kontrol->execute([$kullaniciId, $menuId]);
        if (!$kontrol->fetch()) {
            throw new RuntimeException('Yalnizca turnikeden gecip kullandiginiz menuleri degerlendirebilirsiniz.');
        }

        if ($puan < 1) {
            throw new RuntimeException('Puan 1 ile 5 arasinda olmalidir.');
        }
        if ($puan > 5) {
            throw new RuntimeException('Puan 1 ile 5 arasinda olmalidir.');
        }

        $sql = 'INSERT INTO degerlendirmeler (kullanici_id, menu_id, puan, yorum)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE puan = VALUES(puan), yorum = VALUES(yorum), olusturma = NOW()';
        $stmt = Baglanti::yazma()->prepare($sql);
        $stmt->execute([$kullaniciId, $menuId, $puan, $yorum !== '' ? $yorum : null]);
    }

    // --- YONETICI PANELI ---
    public function yoneticiMenuRaporu(): array
    {
        $sql = 'SELECT d.menu_id, d.tarih, d.yemekhane_id, d.yemekhane, d.ogun_id, d.ogun,
                       d.kapasite, d.rezerve, d.bos_yer, d.doluluk_yuzde,
                       m.ogrenci_fiyati, m.personel_fiyati,
                       COALESCE(p.oy_sayisi, 0) AS oy_sayisi, p.ortalama_puan
                  FROM v_menu_doluluk d
                  JOIN menuler m ON m.id = d.menu_id
                  JOIN ogunler o ON o.id = d.ogun_id
                  LEFT JOIN v_menu_puan p ON p.menu_id = d.menu_id
                 ORDER BY d.tarih DESC, d.yemekhane_id, o.sira';
        return Baglanti::okuma()->query($sql)->fetchAll();
    }

    public function menuKaydet(int $yemekhaneId, string $tarih, int $ogunId, float $ogrFiyat, float $perFiyat, array $yemekIdler): int
    {
        if (empty($yemekIdler)) {
            throw new RuntimeException('Menu icin en az bir yemek secmelisiniz.');
        }

        $db = Baglanti::yazma();
        $db->beginTransaction();
        try {
            $bul = $db->prepare('SELECT id FROM menuler WHERE yemekhane_id = ? AND tarih = ? AND ogun_id = ?');
            $bul->execute([$yemekhaneId, $tarih, $ogunId]);
            $mevcutId = $bul->fetchColumn();

            if ($mevcutId) {
                $menuId = (int)$mevcutId;
                $upd = $db->prepare('UPDATE menuler SET ogrenci_fiyati = ?, personel_fiyati = ? WHERE id = ?');
                $upd->execute([$ogrFiyat, $perFiyat, $menuId]);
                $db->prepare('DELETE FROM menu_yemekleri WHERE menu_id = ?')->execute([$menuId]);
            } else {
                $ins = $db->prepare('INSERT INTO menuler (yemekhane_id, tarih, ogun_id, ogrenci_fiyati, personel_fiyati) VALUES (?, ?, ?, ?, ?)');
                $ins->execute([$yemekhaneId, $tarih, $ogunId, $ogrFiyat, $perFiyat]);
                $menuId = (int)$db->lastInsertId();
            }

            $ekle = $db->prepare('INSERT INTO menu_yemekleri (menu_id, yemek_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $yemekIdler)) as $yid) {
                if ($yid > 0) {
                    $ekle->execute([$menuId, $yid]);
                }
            }

            $db->commit();
            return $menuId;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new RuntimeException('Menu kaydedilemedi: ' . $e->getMessage());
        }
    }
}