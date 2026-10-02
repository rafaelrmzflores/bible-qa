<?php
/**
 * Plugin Name:       Bible Q&A
 * Plugin URI:        https://example.com/bible-qa
 * Description:       A searchable database of Bible questions and answers.
 * Version:           3.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rafael Ramírez
 * License:           GPL-2.0-or-later
 * Text Domain:       bible-qa
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BQA_VERSION', '3.0.0' );
define( 'BQA_FILE', __FILE__ );
define( 'BQA_PATH', plugin_dir_path( __FILE__ ) );
define( 'BQA_URL',  plugin_dir_url( __FILE__ ) );
define( 'BQA_DB_VERSION', '3.0.0' );

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'bqa_activate' );
function bqa_activate() {
    bqa_create_tables();
    bqa_seed_terms();
    update_option( 'bqa_db_version', BQA_DB_VERSION );
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bqa_deactivate' );
function bqa_deactivate() {
    flush_rewrite_rules();
}

add_action( 'plugins_loaded', 'bqa_maybe_upgrade' );
function bqa_maybe_upgrade() {
    if ( get_option( 'bqa_db_version' ) !== BQA_DB_VERSION ) {
        bqa_create_tables();
        update_option( 'bqa_db_version', BQA_DB_VERSION );
    }
}

/* -------------------------------------------------------------------------
 * Table creation
 * ---------------------------------------------------------------------- */

function bqa_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix;

    // --- Main Q&A table ---
    $sql_qa = "CREATE TABLE {$prefix}bible_qa (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        question varchar(500) NOT NULL,
        answer longtext NOT NULL,
        author_id bigint(20) unsigned NULL,
        source_id bigint(20) unsigned NULL,
        source_locator varchar(100) NULL,
        featured_image_id bigint(20) unsigned NULL,
        slug varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'published',
        views bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY status (status),
        KEY author_id (author_id),
        KEY source_id (source_id),
        KEY featured_image_id (featured_image_id),
    ) $charset_collate;";

    // --- Authors ---
    $sql_authors = "CREATE TABLE {$prefix}bible_qa_authors (
        author_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(200) NOT NULL,
        slug varchar(200) NOT NULL,
        bio longtext NULL,
        email varchar(200) NULL,
        website varchar(255) NULL,
        avatar_url varchar(500) NULL,
        wp_user_id bigint(20) unsigned NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (author_id),
        UNIQUE KEY slug (slug),
        KEY wp_user_id (wp_user_id)
    ) $charset_collate;";

    // --- Sources ---
    $sql_sources = "CREATE TABLE {$prefix}bible_qa_sources (
        source_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        author varchar(300) NULL,
        publisher varchar(255) NULL,
        year varchar(20) NULL,
        edition varchar(100) NULL,
        isbn varchar(20) NULL,
        url varchar(500) NULL,
        notes longtext NULL,
        slug varchar(255) NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (source_id),
        UNIQUE KEY slug (slug)
    ) $charset_collate;";

    // --- Meta ---
    $sql_meta = "CREATE TABLE {$prefix}bible_qa_meta (
        meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        qa_id bigint(20) unsigned NOT NULL,
        meta_key varchar(100) NOT NULL,
        meta_value longtext NULL,
        PRIMARY KEY  (meta_id),
        KEY qa_id (qa_id),
        KEY meta_key (meta_key)
    ) $charset_collate;";

    // --- Terms ---
    $sql_terms = "CREATE TABLE {$prefix}bible_qa_terms (
        term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        slug varchar(100) NOT NULL,
        parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (term_id),
        UNIQUE KEY slug (slug)
    ) $charset_collate;";

    // --- Term relationships ---
    $sql_term_rel = "CREATE TABLE {$prefix}bible_qa_term_rel (
        qa_id bigint(20) unsigned NOT NULL,
        term_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (qa_id, term_id),
        KEY term_id (term_id)
    ) $charset_collate;";

    // --- Search log ---
    $sql_log = "CREATE TABLE {$prefix}bible_qa_search_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        search_term varchar(255) NOT NULL,
        results_count int NOT NULL DEFAULT 0,
        user_ip varbinary(16) NULL,
        created_at datetime NOT NULL,
        engine varchar(20) NULL,
        PRIMARY KEY  (id),
        KEY search_term (search_term),
        KEY created_at (created_at)
    ) $charset_collate;";

    $sql_revisions = "CREATE TABLE {$prefix}bible_qa_revisions (
        revision_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        qa_id bigint(20) unsigned NOT NULL,
        question varchar(500) NOT NULL,
        answer longtext NOT NULL,
        author_id bigint(20) unsigned NULL,
        source_id bigint(20) unsigned NULL,
        source_locator varchar(100) NULL,
        user_id bigint(20) unsigned NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (revision_id),
        KEY qa_id (qa_id),
        KEY created_at (created_at)
    ) $charset_collate;";


    dbDelta( $sql_qa );
    dbDelta( $sql_authors );
    dbDelta( $sql_sources );
    dbDelta( $sql_meta );
    dbDelta( $sql_terms );
    dbDelta( $sql_term_rel );
    dbDelta( $sql_log );
    dbDelta( $sql_revisions );

    bqa_ensure_fulltext_index();

    bqa_maybe_add_author_column();
    bqa_maybe_add_source_columns();
    bqa_maybe_add_featured_image_column();
    bqa_maybe_add_engine_column();
}

function bqa_maybe_add_author_column() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa';

    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return;
    }

    $has_column = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'author_id'
    ) );

    if ( ! $has_column ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN author_id BIGINT UNSIGNED NULL AFTER answer" );
        $wpdb->query( "ALTER TABLE {$table} ADD KEY author_id (author_id)" );
    }
}

function bqa_maybe_add_source_columns() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa';

    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return;
    }

    $has_source_id = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'source_id'
    ) );
    if ( ! $has_source_id ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER author_id" );
        $wpdb->query( "ALTER TABLE {$table} ADD KEY source_id (source_id)" );
    }

    $has_locator = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'source_locator'
    ) );
    if ( ! $has_locator ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN source_locator VARCHAR(100) NULL AFTER source_id" );
    }
}

function bqa_maybe_add_featured_image_column() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa';

    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return;
    }

    $has = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'featured_image_id'
    ) );

    if ( ! $has ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN featured_image_id BIGINT UNSIGNED NULL AFTER source_locator" );
        $wpdb->query( "ALTER TABLE {$table} ADD KEY featured_image_id (featured_image_id)" );
    }
}

function bqa_maybe_add_engine_column() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa_search_log';

    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return;
    }

    $has = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'engine'
    ) );

    if ( ! $has ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN engine varchar(20) NULL AFTER created_at" );
    }
}

function bqa_ensure_fulltext_index() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa';

    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $exists ) {
        return false;
    }

    $has_index = $wpdb->get_var( $wpdb->prepare(
        "SHOW INDEX FROM {$table} WHERE Key_name = %s",
        'search_index'
    ) );
    if ( $has_index ) {
        return true;
    }

    return $wpdb->query(
        "ALTER TABLE {$table} ADD FULLTEXT KEY search_index (question, answer)"
    ) !== false;
}

function bqa_seed_terms() {
    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa_terms';

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $count > 0 ) {
        return;
    }

    $starters = [
        [ 'Salvation',        'salvation' ],
        [ 'Trinity',          'trinity' ],
        [ 'End Times',        'end-times' ],
        [ 'Prayer',           'prayer' ],
        [ 'Faith',            'faith' ],
        [ 'Grace',            'grace' ],
        [ 'Baptism',          'baptism' ],
        [ 'Sin & Repentance', 'sin-repentance' ],
    ];

    foreach ( $starters as $term ) {
        $wpdb->insert( $table, [
            'name'      => $term[0],
            'slug'      => $term[1],
            'parent_id' => 0,
        ] );
    }
}

/* -------------------------------------------------------------------------
 * Load plugin classes
 * ---------------------------------------------------------------------- */

require_once BQA_PATH . 'includes/class-rest.php';
require_once BQA_PATH . 'includes/class-shortcode.php';
require_once BQA_PATH . 'includes/class-single.php';
require_once BQA_PATH . 'includes/class-archive.php';
require_once BQA_PATH . 'includes/class-csv.php';
require_once BQA_PATH . 'includes/class-admin.php';
require_once BQA_PATH . 'includes/class-revisions.php';
require_once BQA_PATH . 'includes/class-searchwp-source.php';
require_once BQA_PATH . 'includes/class-analytics.php';

/* -------------------------------------------------------------------------
 * Register Hooks
 * ---------------------------------------------------------------------- */
BQA_REST::init();
BQA_Shortcode::init();
BQA_Single::init();
BQA_Archive::init();
BQA_Revisions::init();
BQA_Analytics::init();
BQA_CSV::init();
if ( is_admin() ) {
    BQA_Admin::init();
}

/* -------------------------------------------------------------------------
 * Temporary diagnostic route.
 * ---------------------------------------------------------------------- */

// add_action( 'rest_api_init', function() {
//     register_rest_route( 'bible-qa/v1', '/diag', [
//         'methods'             => 'GET',
//         'permission_callback' => '__return_true',
//         'callback'            => function() {
//             $out = [];

//             $out['BQA_PATH']          = defined( 'BQA_PATH' ) ? BQA_PATH : 'NOT DEFINED';
//             $out['BQA_URL']           = defined( 'BQA_URL' ) ? BQA_URL : 'NOT DEFINED';
//             $out['BQA_DB_VERSION']    = defined( 'BQA_DB_VERSION' ) ? BQA_DB_VERSION : 'NOT DEFINED';
//             $out['db_version_option'] = get_option( 'bqa_db_version' );

//             $out['file_rest_exists']      = file_exists( BQA_PATH . 'includes/class-rest.php' );
//             $out['file_shortcode_exists'] = file_exists( BQA_PATH . 'includes/class-shortcode.php' );
//             $out['file_admin_exists']     = file_exists( BQA_PATH . 'includes/class-admin.php' );

//             $out['class_BQA_REST']      = class_exists( 'BQA_REST' );
//             $out['class_BQA_Shortcode'] = class_exists( 'BQA_Shortcode' );
//             $out['class_BQA_Admin']     = class_exists( 'BQA_Admin' );

//             if ( class_exists( 'BQA_REST' ) ) {
//                 $out['BQA_REST_has_init']     = method_exists( 'BQA_REST', 'init' );
//                 $out['BQA_REST_has_search']   = method_exists( 'BQA_REST', 'search' );
//                 $out['BQA_REST_has_register'] = method_exists( 'BQA_REST', 'register_routes' );
//             }

//             $routes = array_filter(
//                 array_keys( rest_get_server()->get_routes() ),
//                 fn( $r ) => strpos( $r, 'bible-qa' ) !== false
//             );
//             $out['registered_bible_qa_routes'] = array_values( $routes );

//             global $wpdb;
//             $table = $wpdb->prefix . 'bible_qa';
//             $out['table_name']   = $table;
//             $out['table_exists'] = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

//             if ( $out['table_exists'] ) {
//                 $out['row_count']       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
//                 $out['published_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'published'" );
//                 $out['statuses']        = $wpdb->get_col( "SELECT DISTINCT status FROM {$table}" );

//                 $out['direct_fulltext_test_atonement'] = $wpdb->get_results(
//                     "SELECT id, question,
//                             MATCH(question, answer) AGAINST ('+atonement*' IN BOOLEAN MODE) AS score
//                      FROM {$table}
//                      WHERE status = 'published'
//                        AND MATCH(question, answer) AGAINST ('+atonement*' IN BOOLEAN MODE)"
//                 );
//                 $out['last_sql_error'] = $wpdb->last_error ?: null;
//             }

//             return new WP_REST_Response( $out, 200 );
//         },
//     ] );
// } );

// add_action( 'admin_notices', function() {
//     if ( ! current_user_can( 'manage_options' ) ) return;

//     $msg = [];
//     $msg[] = 'BQA Source loaded: ' . ( class_exists( 'BQA_SearchWP_Source' ) ? 'YES' : 'NO' );
//     $msg[] = 'SearchWP\\Source exists: ' . ( class_exists( '\SearchWP\Source' ) ? 'YES' : 'NO' );
//     $msg[] = 'SearchWP\\Query exists: ' . ( class_exists( '\SearchWP\Query' ) ? 'YES' : 'NO' );

//     // Check if our filter is registered
//     global $wp_filter;
//     $has_filter = false;
//     if ( ! empty( $wp_filter['searchwp\sources'] ) ) {
//         $has_filter = true;
//     }
//     $msg[] = 'searchwp\\sources filter registered: ' . ( $has_filter ? 'YES' : 'NO' );

//     echo '<div class="notice notice-info"><p>' . esc_html( implode( ' | ', $msg ) ) . '</p></div>';
// } );

// add_action( 'admin_notices', function() {
//     if ( ! current_user_can( 'manage_options' ) ) return;
//     if ( ! class_exists( 'BQA_SearchWP_Source' ) ) {
//         echo '<div class="notice notice-error"><p>BQA_SearchWP_Source class NOT loaded</p></div>';
//         return;
//     }

//     $source = new BQA_SearchWP_Source();

//     echo '<div class="notice notice-info"><p><strong>BQA SearchWP Source Diagnostic</strong><br>';
//     echo 'Name: <code>' . esc_html( $source->get_name() ) . '</code><br>';
//     echo 'DB Table: <code>' . esc_html( $source->get_db_table() ) . '</code><br>';
//     echo 'DB ID Column: <code>' . esc_html( $source->get_db_id_column() ) . '</code><br>';
//     echo 'Is valid: ' . ( $source->is_valid() ? '<span style="color:green"><strong>YES</strong></span>' : '<span style="color:red"><strong>NO</strong></span>' ) . '<br>';

//     $attrs = $source->get_attributes();
//     echo 'Attributes: ';
//     if ( empty( $attrs ) ) {
//         echo '<span style="color:red">EMPTY</span>';
//     } else {
//         foreach ( $attrs as $name => $attr ) {
//             echo '<code>' . esc_html( $name ) . '</code> ';
//         }
//     }
//     echo '</p></div>';

//     // Also check what SearchWP currently has registered
//     if ( class_exists( '\SearchWP' ) && method_exists( '\SearchWP', 'get_sources' ) ) {
//         $sources = \SearchWP::get_sources();
//         echo '<div class="notice notice-info"><p><strong>SearchWP registered sources:</strong> ';
//         foreach ( $sources as $s ) {
//             echo '<code>' . esc_html( $s->get_name() ) . '</code> ';
//         }
//         echo '</p></div>';
//     }
// } );