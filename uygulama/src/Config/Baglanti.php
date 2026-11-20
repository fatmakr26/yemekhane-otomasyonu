<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use Redis;

class Baglanti
{
    private static ?PDO $yazmaDb = null;
    private static ?PDO $okumaDb = null;
    private static ?Redis $redis = null;

    // Yazma işlemleri ve replikasyon gecikmesinden etkilenmemesi gereken anlık bakiye okumaları için Primary
    public static function yazma(): PDO
    {
        if (self::$yazmaDb === null) {
            $host = getenv('DB_YAZMA_HOST') ?: 'mysql-primary';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_ADI') ?: 'yemekhane';
            $user = getenv('DB_KULLANICI') ?: 'yemekhane_app';
            $pass = getenv('DB_SIFRE') ?: 'Uyg_2026';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            self::$yazmaDb = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$yazmaDb;
    }

    // Listeleme ve raporlama sorguları için salt-okunur Replica
    public static function okuma(): PDO
    {
        if (self::$okumaDb === null) {
            $host = getenv('DB_OKUMA_HOST') ?: 'mysql-replica';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_ADI') ?: 'yemekhane';
            $user = getenv('DB_KULLANICI') ?: 'yemekhane_app';
            $pass = getenv('DB_SIFRE') ?: 'Uyg_2026';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            self::$okumaDb = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$okumaDb;
    }

    // Menü önbelleği ve oturum yönetimi için Redis
    public static function redis(): Redis
    {
        if (self::$redis === null) {
            $host = getenv('REDIS_HOST') ?: 'redis';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);

            $r = new Redis();
            $r->connect($host, $port, 2.0);
            self::$redis = $r;
        }
        return self::$redis;
    }
}