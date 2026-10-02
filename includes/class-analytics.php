<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Analytics {

    const PER_PAGE = 50;

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );
        add_action( 'admin_post_bqa_export_analytics', [ __CLASS__, 'handle_export' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'bible-qa',
            'Analytics',
            'Analytics',
            'manage_options',
            'bible-qa-analytics',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* =====================================================================
     * Data queries
     * ================================================================== */

    /**
     * Return the SQL date cutoff for a given range key.
     */
    private static function range_to_date( $range ) {
        switch ( $range ) {
            case '7d':  return gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
            case '30d': return gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
            case '90d': return gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
            case 'all':
            default:    return '1970-01-01 00:00:00';
        }
    }

    public static function get_summary( $range = '30d' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_search_log';
        $since = self::range_to_date( $range );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total_searches,
                COUNT(DISTINCT search_term) AS unique_terms,
                SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) AS zero_results,
                SUM(CASE WHEN results_count > 0 THEN 1 ELSE 0 END) AS with_results
             FROM {$table}
             WHERE created_at >= %s",
            $since
        ) );

        return [
            'total_searches' => (int) ( $row->total_searches ?? 0 ),
            'unique_terms'   => (int) ( $row->unique_terms   ?? 0 ),
            'zero_results'   => (int) ( $row->zero_results   ?? 0 ),
            'with_results'   => (int) ( $row->with_results   ?? 0 ),
        ];
    }

    public static function get_top_searches( $range = '30d', $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_search_log';
        $since = self::range_to_date( $range );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT search_term,
                    COUNT(*) AS hits,
                    MAX(results_count) AS best_result_count,
                    SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) AS zero_hits
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY search_term
             ORDER BY hits DESC
             LIMIT %d",
            $since, $limit
        ) );
    }

    public static function get_zero_result_searches( $range = '30d', $limit = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_search_log';
        $since = self::range_to_date( $range );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT search_term, COUNT(*) AS hits, MAX(created_at) AS last_seen
             FROM {$table}
             WHERE results_count = 0 AND created_at >= %s
             GROUP BY search_term
             ORDER BY hits DESC, last_seen DESC
             LIMIT %d",
            $since, $limit
        ) );
    }

    public static function get_recent_searches( $range = '30d', $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_search_log';
        $since = self::range_to_date( $range );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, search_term, results_count, created_at
             FROM {$table}
             WHERE created_at >= %s
             ORDER BY created_at DESC
             LIMIT %d",
            $since, $limit
        ) );
    }

    /* =====================================================================
     * CSV export
     * ================================================================== */

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'bqa_export_analytics' );

        $range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30d';
        $since = self::range_to_date( $range );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_search_log';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, search_term, results_count
             FROM {$table}
             WHERE created_at >= %s
             ORDER BY created_at DESC",
            $since
        ) );

        $filename = 'bqa-search-log-' . gmdate( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM

        fputcsv( $out, [ 'Date', 'Search Term', 'Results Count' ] );

        foreach ( $rows as $r ) {
            fputcsv( $out, [ $r->created_at, $r->search_term, $r->results_count ] );
        }

        fclose( $out );
        exit;
    }

    /* =====================================================================
     * Admin page
     * ================================================================== */

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30d';

        $summary  = self::get_summary( $range );
        $top      = self::get_top_searches( $range, 20 );
        $zero     = self::get_zero_result_searches( $range, 30 );
        $recent   = self::get_recent_searches( $range, 50 );

        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=bqa_export_analytics&range=' . $range ),
            'bqa_export_analytics'
        );
        ?>
        <div class="wrap">
            <h1>Search Analytics</h1>

            <form method="get" style="margin: 1em 0;">
                <input type="hidden" name="page" value="bible-qa-analytics">
                <label for="bqa-range">Range:</label>
                <select name="range" id="bqa-range" onchange="this.form.submit()">
                    <option value="7d"  <?php selected( $range, '7d' );  ?>>Last 7 days</option>
                    <option value="30d" <?php selected( $range, '30d' ); ?>>Last 30 days</option>
                    <option value="90d" <?php selected( $range, '90d' ); ?>>Last 90 days</option>
                    <option value="all" <?php selected( $range, 'all' ); ?>>All time</option>
                </select>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button" style="margin-left: 8px;">
                    Export CSV
                </a>
            </form>

            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 1em 0 2em;">
                <?php self::render_summary_card( 'Total Searches',  $summary['total_searches'] ); ?>
                <?php self::render_summary_card( 'Unique Terms',    $summary['unique_terms']   ); ?>
                <?php self::render_summary_card( 'Zero-Result Searches', $summary['zero_results'], true ); ?>
                <?php self::render_summary_card( 'Successful Searches',  $summary['with_results'] ); ?>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">

                <div>
                    <h2>Top Searches</h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Search Term</th>
                                <th style="width: 80px;">Count</th>
                                <th style="width: 80px;">Zero</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $top ) ) : ?>
                            <tr><td colspan="3">No searches yet.</td></tr>
                        <?php else : foreach ( $top as $row ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $row->search_term ); ?></code></td>
                                <td><?php echo (int) $row->hits; ?></td>
                                <td>
                                    <?php if ( (int) $row->zero_hits > 0 ) : ?>
                                        <span style="color: #b32d2e;"><?php echo (int) $row->zero_hits; ?></span>
                                    <?php else : ?>
                                        0
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div>
                    <h2>Zero-Result Searches</h2>
                    <p class="description" style="margin-bottom: 8px;">
                        Users searched for these but found nothing. Great candidates for new Q&amp;As.
                    </p>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Search Term</th>
                                <th style="width: 80px;">Count</th>
                                <th style="width: 140px;">Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $zero ) ) : ?>
                            <tr><td colspan="3">No zero-result searches. Every query returned something.</td></tr>
                        <?php else : foreach ( $zero as $row ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $row->search_term ); ?></code></td>
                                <td><?php echo (int) $row->hits; ?></td>
                                <td><?php echo esc_html( $row->last_seen ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <h2 style="margin-top: 2em;">Recent Searches</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 180px;">When</th>
                        <th>Search Term</th>
                        <th style="width: 100px;">Results</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $recent ) ) : ?>
                    <tr><td colspan="4">No searches yet.</td></tr>
                <?php else : foreach ( $recent as $row ) : ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td><?php echo esc_html( $row->created_at ); ?></td>
                        <td><code><?php echo esc_html( $row->search_term ); ?></code></td>
                        <td>
                            <?php if ( (int) $row->results_count === 0 ) : ?>
                                <span style="color: #b32d2e;">0</span>
                            <?php else : ?>
                                <?php echo (int) $row->results_count; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_summary_card( $label, $value, $warn = false ) {
        ?>
        <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px;">
            <div style="font-size: 0.85em; color: #666; text-transform: uppercase; letter-spacing: 0.05em;">
                <?php echo esc_html( $label ); ?>
            </div>
            <div style="font-size: 2em; font-weight: 600; margin-top: 4px; <?php echo $warn && $value > 0 ? 'color:#b32d2e;' : ''; ?>">
                <?php echo (int) $value; ?>
            </div>
        </div>
        <?php
    }
}