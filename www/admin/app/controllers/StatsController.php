<?php
/**
 * StatsController — дашборд статистики посещений
 */

namespace Admin;

class StatsController extends BaseController
{
    public function index()
    {
        $days = (int)($_GET['days'] ?? 30);
        $days = in_array($days, [7, 30, 60, 90]) ? $days : 30;

        try {
            $summary = \Core\VisitStats::getSummary($this->db, $days);
            $top = \Core\VisitStats::getTop($this->db, $days, 20);
            $daily = \Core\VisitStats::getDaily($this->db, $days);
        } catch (\Throwable $e) {
            $summary = ['total_hits' => 0, 'total_unique' => 0, 'top_referrers' => [], 'peak_hours' => [], 'device_split' => [], 'top_browsers' => [], 'weekday_split' => [], 'status_codes' => []];
            $top = [];
            $daily = [];
        }

        // Максимум для графика
        $maxDaily = 1;
        foreach ($daily as $d) {
            if ((int)$d['hits'] > $maxDaily) $maxDaily = (int)$d['hits'];
        }

        // Имена дней недели
        $dayNames = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

        $this->render('stats/index', [
            'title' => 'Статистика посещений',
            'days' => $days,
            'summary' => $summary,
            'top' => $top,
            'daily' => $daily,
            'max_daily' => $maxDaily,
            'day_names' => $dayNames,
        ]);
    }
}
