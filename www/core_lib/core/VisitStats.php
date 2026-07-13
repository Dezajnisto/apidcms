<?php
/**
 * VisitStats — встроенная статистика посещений apidcms
 *
 * Агрегирует хиты: одна строка = страница × день.
 * Минимальный дисковый след: JSON-колонки для уников, рефереров, часов, устройств.
 *
 * Использование:
 *   VisitStats::collect($db, $pageUrl);  // на каждый хит
 *   VisitStats::cleanup($db, $days);      // удалить старые записи
 *   VisitStats::getTop($db, $days, 20);   // топ страниц
 *   VisitStats::getDaily($db, $days);     // график по дням
 */

namespace Core;

class VisitStats
{
    /**
     * Собрать один хит
     */
    public static function collect(Database $db, string $pageUrl): void
    {
        try {
            $today = date('Y-m-d');
            $hour = date('H');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ref = $_SERVER['HTTP_REFERER'] ?? '';
            $status = http_response_code() ?: 200;

            // Хеш IP (с солью от site_title — защита от деанонимизации)
            $salt = defined('SITE_TITLE_SALT') ? SITE_TITLE_SALT : 'apidcms';
            $ipHash = substr(hash('sha256', $ip . $salt), 0, 16);

            // Источник трафика
            $source = 'direct';
            if (!empty($ref)) {
                $host = parse_url($ref, PHP_URL_HOST);
                if ($host) {
                    $source = preg_replace('/^www\./', '', $host);
                }
            }

            // Тип устройства
            $device = self::detectDevice($ua);

            // Браузер
            $browser = self::detectBrowser($ua);

            // День недели
            $dow = date('N'); // 1-7

            // Существующая запись за сегодня для этой страницы
            $existing = $db->query(
                "SELECT * FROM visit_stats WHERE page_url = ? AND visit_date = ?",
                [$pageUrl, $today]
            )->fetch();

            if ($existing) {
                // Обновляем JSON-колонки
                $ips = json_decode($existing['unique_ips'], true) ?: [];
                if (!in_array($ipHash, $ips) && count($ips) < 200) {
                    $ips[] = $ipHash;
                }

                $referrers = json_decode($existing['referrers'], true) ?: [];
                $referrers[$source] = ($referrers[$source] ?? 0) + 1;

                $hours = json_decode($existing['hours'], true) ?: [];
                $hours[$hour] = ($hours[$hour] ?? 0) + 1;

                $devices = json_decode($existing['devices'], true) ?: [];
                $devices[$device] = ($devices[$device] ?? 0) + 1;

                $browsers = json_decode($existing['browsers'], true) ?: [];
                $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;

                $weekdays = json_decode($existing['weekdays'], true) ?: [];
                $weekdays[$dow] = ($weekdays[$dow] ?? 0) + 1;

                $codes = json_decode($existing['status_codes'], true) ?: [];
                $sk = (string)$status;
                $codes[$sk] = ($codes[$sk] ?? 0) + 1;

                $db->query(
                    "UPDATE visit_stats SET hit_count = hit_count + 1, unique_ips = ?, referrers = ?, hours = ?, devices = ?, browsers = ?, weekdays = ?, status_codes = ? WHERE id = ?",
                    [
                        json_encode($ips, JSON_UNESCAPED_UNICODE),
                        json_encode($referrers, JSON_UNESCAPED_UNICODE),
                        json_encode($hours, JSON_UNESCAPED_UNICODE),
                        json_encode($devices, JSON_UNESCAPED_UNICODE),
                        json_encode($browsers, JSON_UNESCAPED_UNICODE),
                        json_encode($weekdays, JSON_UNESCAPED_UNICODE),
                        json_encode($codes, JSON_UNESCAPED_UNICODE),
                        $existing['id']
                    ]
                );
            } else {
                // Новая запись за сегодня
                $db->query(
                    "INSERT INTO visit_stats (page_url, visit_date, hit_count, unique_ips, referrers, hours, devices, browsers, weekdays, status_codes) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $pageUrl,
                        $today,
                        json_encode([$ipHash], JSON_UNESCAPED_UNICODE),
                        json_encode([$source => 1], JSON_UNESCAPED_UNICODE),
                        json_encode([$hour => 1], JSON_UNESCAPED_UNICODE),
                        json_encode([$device => 1], JSON_UNESCAPED_UNICODE),
                        json_encode([$browser => 1], JSON_UNESCAPED_UNICODE),
                        json_encode([$dow => 1], JSON_UNESCAPED_UNICODE),
                        json_encode([(string)$status => 1], JSON_UNESCAPED_UNICODE),
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log("VisitStats: error collecting hit: " . $e->getMessage());
        }
    }

    /**
     * Удалить записи старше N дней
     */
    public static function cleanup(Database $db, int $days): int
    {
        try {
            $cutoff = date('Y-m-d', strtotime("-{$days} days"));
            $db->exec("DELETE FROM visit_stats WHERE visit_date < '{$cutoff}'");
            return $db->changes();
        } catch (\Throwable $e) {
            error_log("VisitStats: cleanup error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Топ страниц за период
     *
     * @return array<int, array{page_url:string, hit_count:int, unique_count:int}>
     */
    public static function getTop(Database $db, int $days = 30, int $limit = 20): array
    {
        try {
            $since = date('Y-m-d', strtotime("-{$days} days"));
            return $db->query(
                "SELECT page_url, SUM(hit_count) as hit_count
                 FROM visit_stats
                 WHERE visit_date >= ?
                 GROUP BY page_url
                 ORDER BY hit_count DESC
                 LIMIT ?",
                [$since, $limit]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Хиты по дням (для графика)
     *
     * @return array<int, array{visit_date:string, hits:int}>
     */
    public static function getDaily(Database $db, int $days = 30): array
    {
        try {
            $since = date('Y-m-d', strtotime("-{$days} days"));
            return $db->query(
                "SELECT visit_date, SUM(hit_count) as hits
                 FROM visit_stats
                 WHERE visit_date >= ?
                 GROUP BY visit_date
                 ORDER BY visit_date ASC",
                [$since]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Агрегированная сводка за период
     *
     * @return array{total_hits:int, total_unique:int, top_referrers:array, device_split:array, peak_hours:array, top_browsers:array, weekday_split:array, status_codes:array}
     */
    public static function getSummary(Database $db, int $days = 30): array
    {
        try {
            $since = date('Y-m-d', strtotime("-{$days} days"));
            $rows = $db->query(
                "SELECT unique_ips, referrers, hours, devices, browsers, weekdays, status_codes
                 FROM visit_stats WHERE visit_date >= ?",
                [$since]
            )->fetchAll();

            $totalHits = $db->query(
                "SELECT COALESCE(SUM(hit_count), 0) as c FROM visit_stats WHERE visit_date >= ?",
                [$since]
            )->fetch()['c'];

            // Агрегируем JSON-колонки
            $allReferrers = [];
            $allHours = [];
            $allDevices = [];
            $allBrowsers = [];
            $allWeekdays = [];
            $allCodes = [];
            $allIps = [];

            foreach ($rows as $row) {
                foreach (json_decode($row['unique_ips'], true) ?: [] as $ip) $allIps[$ip] = true;
                foreach (json_decode($row['referrers'], true) ?: [] as $k => $v) $allReferrers[$k] = ($allReferrers[$k] ?? 0) + $v;
                foreach (json_decode($row['hours'], true) ?: [] as $k => $v) $allHours[$k] = ($allHours[$k] ?? 0) + $v;
                foreach (json_decode($row['devices'], true) ?: [] as $k => $v) $allDevices[$k] = ($allDevices[$k] ?? 0) + $v;
                foreach (json_decode($row['browsers'], true) ?: [] as $k => $v) $allBrowsers[$k] = ($allBrowsers[$k] ?? 0) + $v;
                foreach (json_decode($row['weekdays'], true) ?: [] as $k => $v) $allWeekdays[$k] = ($allWeekdays[$k] ?? 0) + $v;
                foreach (json_decode($row['status_codes'], true) ?: [] as $k => $v) $allCodes[$k] = ($allCodes[$k] ?? 0) + $v;
            }

            // Сортируем
            arsort($allReferrers);
            arsort($allHours);
            arsort($allDevices);
            arsort($allBrowsers);

            return [
                'total_hits' => (int)$totalHits,
                'total_unique' => count($allIps),
                'top_referrers' => array_slice($allReferrers, 0, 10, true),
                'peak_hours' => $allHours,
                'device_split' => $allDevices,
                'top_browsers' => array_slice($allBrowsers, 0, 10, true),
                'weekday_split' => $allWeekdays,
                'status_codes' => $allCodes,
            ];
        } catch (\Throwable $e) {
            error_log("VisitStats::getSummary: " . $e->getMessage());
            return ['total_hits' => 0, 'total_unique' => 0, 'top_referrers' => [], 'peak_hours' => [], 'device_split' => [], 'top_browsers' => [], 'weekday_split' => [], 'status_codes' => []];
        }
    }

    /**
     * Создать таблицу visit_stats
     */
    public static function initTable(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS visit_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url TEXT NOT NULL,
                visit_date TEXT NOT NULL,
                hit_count INTEGER NOT NULL DEFAULT 0,
                unique_ips TEXT DEFAULT '[]',
                referrers TEXT DEFAULT '{}',
                hours TEXT DEFAULT '{}',
                devices TEXT DEFAULT '{}',
                browsers TEXT DEFAULT '{}',
                weekdays TEXT DEFAULT '{}',
                status_codes TEXT DEFAULT '{}',
                UNIQUE(page_url, visit_date)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_visit_stats_date ON visit_stats(visit_date)");
    }

    // ======== Приватные методы ========

    private static function detectDevice(string $ua): string
    {
        $ua = strtolower($ua);
        if (strpos($ua, 'bot') !== false || strpos($ua, 'crawler') !== false || strpos($ua, 'spider') !== false) {
            return 'bot';
        }
        if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
            return 'mobile';
        }
        if (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
            return 'tablet';
        }
        return 'desktop';
    }

    private static function detectBrowser(string $ua): string
    {
        $ua = strtolower($ua);
        if (strpos($ua, 'firefox') !== false) return 'firefox';
        if (strpos($ua, 'edg') !== false) return 'edge';
        if (strpos($ua, 'chrome') !== false) return 'chrome';
        if (strpos($ua, 'safari') !== false) return 'safari';
        if (strpos($ua, 'opera') !== false) return 'opera';
        if (strpos($ua, 'yandex') !== false) return 'yandex';
        if (strpos($ua, 'bot') !== false || strpos($ua, 'crawler') !== false) return 'bot';
        return 'other';
    }
}
