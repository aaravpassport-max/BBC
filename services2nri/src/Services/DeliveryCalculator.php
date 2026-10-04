<?php
namespace S2NRI\Services;

defined( 'ABSPATH' ) || exit;

/**
 * DeliveryCalculator — working days calculator that excludes weekends and holidays.
 *
 * TRACE: calculate(from, working_days) → iterates day-by-day from from_date →
 *        skips Saturdays (6) and Sundays (0) → skips dates in s2nri_holidays table →
 *        counts until working_days reached → returns delivery_date + breakdown.
 * PRECONDITIONS: $wpdb available, s2nri_holidays table exists.
 * POSTCONDITIONS: returns array with delivery_date (YYYY-MM-DD), calendar_days, weekends_excluded, holidays_excluded.
 * EDGE CASES: max 730 calendar days loop guard to prevent infinite loops.
 */
class DeliveryCalculator {

    /**
     * Calculate delivery date from a starting date and number of working days.
     *
     * @param  \DateTime $from          Starting date (usually today)
     * @param  int       $working_days  Number of working days to add
     * @return array     {delivery_date, calendar_days, weekends_excluded, holidays_excluded, formatted}
     */
    public static function calculate( \DateTime $from, int $working_days ): array {
        global $wpdb;

        // Load all holidays into a set keyed by Y-m-d for O(1) lookup
        $holiday_dates = [];
        if ( $wpdb ) {
            $holidays = $wpdb->get_col( "SELECT DATE_FORMAT(holiday_date,'%Y-%m-%d') FROM {$wpdb->prefix}s2nri_holidays" );
            foreach ( $holidays as $h ) {
                $holiday_dates[ $h ] = true;
            }
        }

        $current          = clone $from;
        $current->modify('+1 day'); // Start counting from next day
        $worked           = 0;
        $calendar_days    = 0;
        $weekends_skipped = 0;
        $holidays_skipped = 0;
        $guard            = 0; // prevent infinite loop

        while ( $worked < $working_days && $guard < 730 ) {
            $guard++;
            $calendar_days++;
            $dow       = (int) $current->format('w'); // 0=Sun, 6=Sat
            $date_str  = $current->format('Y-m-d');

            if ( $dow === 0 || $dow === 6 ) {
                $weekends_skipped++;
            } elseif ( isset( $holiday_dates[ $date_str ] ) ) {
                $holidays_skipped++;
            } else {
                $worked++;
            }
            if ( $worked < $working_days ) {
                $current->modify('+1 day');
            }
        }

        $delivery_date = $current->format('Y-m-d');
        $formatted     = $current->format('d M Y');

        return [
            'delivery_date'      => $delivery_date,
            'formatted'          => $formatted,
            'calendar_days'      => $calendar_days,
            'working_days'       => $working_days,
            'weekends_excluded'  => $weekends_skipped,
            'holidays_excluded'  => $holidays_skipped,
            'description'        => "{$working_days} working days — excl. Sat, Sun & Govt Holidays",
        ];
    }
}
