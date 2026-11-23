USE yemekhane;

CREATE TABLE IF NOT EXISTS kullanici_alerjenleri (
    kullanici_id INT UNSIGNED NOT NULL,
    alerjen_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (kullanici_id, alerjen_id),
    CONSTRAINT fk_ka_kullanici FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
    CONSTRAINT fk_ka_alerjen FOREIGN KEY (alerjen_id) REFERENCES alerjenler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rezervasyonlar
    ADD COLUMN porsiyon ENUM('tam', 'yarim') NOT NULL DEFAULT 'tam' AFTER menu_id;

DROP PROCEDURE IF EXISTS sp_rezervasyon_yap;

DELIMITER //
CREATE PROCEDURE sp_rezervasyon_yap(
    IN p_kullanici_id INT UNSIGNED,
    IN p_menu_id INT UNSIGNED,
    IN p_porsiyon VARCHAR(10)
)
BEGIN
    DECLARE v_rol VARCHAR(20);
    DECLARE v_bakiye DECIMAL(10,2);
    DECLARE v_ogr_fiyat DECIMAL(10,2);
    DECLARE v_per_fiyat DECIMAL(10,2);
    DECLARE v_tam_ucret DECIMAL(10,2);
    DECLARE v_ucret DECIMAL(10,2);
    DECLARE v_yemekhane_id INT UNSIGNED;
    DECLARE v_kapasite INT;
    DECLARE v_dolu INT;
    DECLARE v_tarih DATE;
    DECLARE v_ogun_id INT UNSIGNED;
    DECLARE v_son_saat TIME;
    DECLARE v_mevcut INT;
    DECLARE v_rez_id INT UNSIGNED;
    DECLARE v_porsiyon VARCHAR(10);

    SET v_porsiyon = IFNULL(NULLIF(LOWER(TRIM(p_porsiyon)), ''), 'tam');
    IF v_porsiyon NOT IN ('tam', 'yarim') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz porsiyon secimi.';
    END IF;

    SELECT rol, bakiye INTO v_rol, v_bakiye
    FROM kullanicilar WHERE id = p_kullanici_id FOR UPDATE;

    IF v_rol IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kullanici bulunamadi.';
    END IF;

    SELECT m.yemekhane_id, m.tarih, m.ogun_id, m.ogrenci_fiyati, m.personel_fiyati, y.kapasite, o.son_saat
    INTO v_yemekhane_id, v_tarih, v_ogun_id, v_ogr_fiyat, v_per_fiyat, v_kapasite, v_son_saat
    FROM menuler m
    JOIN yemekhaneler y ON y.id = m.yemekhane_id
    JOIN ogunler o ON o.id = m.ogun_id
    WHERE m.id = p_menu_id;

    IF v_yemekhane_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Menu bulunamadi.';
    END IF;

    IF v_ogun_id = 1 AND v_porsiyon = 'yarim' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kahvalti ogununde yarim porsiyon secilemez.';
    END IF;

    IF NOW() > TIMESTAMP(v_tarih, v_son_saat) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bu ogun icin rezervasyon suresi dolmustur.';
    END IF;

    SELECT COUNT(*) INTO v_mevcut
    FROM rezervasyonlar
    WHERE kullanici_id = p_kullanici_id AND menu_id = p_menu_id AND durum IN ('aktif', 'kullanildi');

    IF v_mevcut > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bu menu icin zaten aktif rezervasyonunuz var.';
    END IF;

    SELECT COUNT(*) INTO v_dolu
    FROM rezervasyonlar
    WHERE menu_id = p_menu_id AND durum IN ('aktif', 'kullanildi');

    IF v_dolu >= v_kapasite THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Yemekhane kapasitesi dolmustur.';
    END IF;

    SET v_tam_ucret = IF(v_rol = 'ogrenci', v_ogr_fiyat, v_per_fiyat);
    SET v_ucret = IF(v_porsiyon = 'yarim', ROUND(v_tam_ucret * 0.60, 2), v_tam_ucret);

    IF v_bakiye < v_ucret THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bakiye yetersiz.';
    END IF;

    UPDATE kullanicilar SET bakiye = bakiye - v_ucret WHERE id = p_kullanici_id;

    INSERT INTO rezervasyonlar (kullanici_id, menu_id, porsiyon, ucret, durum)
    VALUES (p_kullanici_id, p_menu_id, v_porsiyon, v_ucret, 'aktif');
    SET v_rez_id = LAST_INSERT_ID();

    INSERT INTO bakiye_hareketleri (kullanici_id, tur, tutar, aciklama, rezervasyon_id)
    VALUES (
        p_kullanici_id,
        'rezervasyon',
        -v_ucret,
        CONCAT(v_tarih, ' tarihli menu rezervasyonu (', IF(v_porsiyon = 'yarim', 'Yarim Porsiyon', 'Tam Porsiyon'), ')'),
        v_rez_id
    );
END //
DELIMITER ;