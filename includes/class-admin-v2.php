<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Admin {

    const MENU_SLUG  = 'bible-qa';
    const CAPABILITY = 'manage_options';
    const PER_PAGE   = 20;

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /* =====================================================================
     * Menu
     * ================================================================== */

    public static function register_menu() {
        add_menu_page(
            'Bible Q&A',
            'Bible Q&A',
            self::CAPABILITY,
            self::MENU_SLUG,
            [ __CLASS__, 'render_list_page' ],
            'dashicons-book-alt',
            30
        );

        add_submenu_page( self::MENU_SLUG, 'All Questions', 'All Questions', self::CAPABILITY, self::MENU_SLUG, [ __CLASS__, 'render_list_page' ] );
        add_submenu_page( self::MENU_SLUG, 'Add New', 'Add New', self::CAPABILITY, self::MENU_SLUG . '-edit', [ __CLASS__, 'render_edit_page' ] );
        add_submenu_page( self::MENU_SLUG, 'Topics', 'Topics', self::CAPABILITY, self::MENU_SLUG . '-topics', [ __CLASS__, 'render_topics_page' ] );
        add_submenu_page( self::MENU_SLUG, 'Authors', 'Authors', self::CAPABILITY, self::MENU_SLUG . '-authors', [ __CLASS__, 'render_authors_page' ] );
        add_submenu_page( self::MENU_SLUG, 'Sources', 'Sources', self::CAPABILITY, self::MENU_SLUG . '-sources', [ __CLASS__, 'render_sources_page' ] );
        add_submenu_page( self::MENU_SLUG, 'Import', 'Import', self::CAPABILITY, self::MENU_SLUG . '-import', [ __CLASS__, 'render_import_page' ] );
        add_submenu_page( self::MENU_SLUG, 'Export', 'Export', self::CAPABILITY, self::MENU_SLUG . '-export', [ __CLASS__, 'render_export_page' ] );
    }

    /* =====================================================================
     * Assets
     * ================================================================== */

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        wp_enqueue_editor();

        if ( strpos( $hook, self::MENU_SLUG . '-edit' ) !== false ) {
            wp_enqueue_media();
            wp_add_inline_script( 'jquery-core', "
                jQuery(function($){
                    var frame;
                    $('#bqa-select-featured-image').on('click', function(e){
                        e.preventDefault();
                        if (frame) { frame.open(); return; }
                        frame = wp.media({
                            title: 'Select Featured Image',
                            button: { text: 'Use this image' },
                            multiple: false,
                            library: { type: 'image' }
                        });
                        frame.on('select', function(){
                            var attachment = frame.state().get('selection').first().toJSON();
                            $('#featured_image_id').val(attachment.id);
                            var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
                            $('#bqa-featured-image-preview').html('<img src=\"' + url + '\" style=\"max-width:200px;height:auto;\">');
                            $('#bqa-remove-featured-image').show();
                        });
                        frame.open();
                    });
                    $('#bqa-remove-featured-image').on('click', function(e){
                        e.preventDefault();
                        $('#featured_image_id').val('0');
                        $('#bqa-featured-image-preview').empty();
                        $(this).hide();
                    });
                });
            " );
        }
    }

    /* =====================================================================
     * Action routing
     * ================================================================== */

    public static function handle_actions() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $action = isset( $_REQUEST['bqa_action'] ) ? sanitize_key( $_REQUEST['bqa_action'] ) : '';

        switch ( $action ) {
            case 'save_qa':       self::action_save_qa();       break;
            case 'delete_qa':     self::action_delete_qa();     break;
            case 'toggle_qa':     self::action_toggle_qa();     break;
            case 'bulk':          self::action_bulk();          break;
            case 'restore_rev':   self::action_restore_rev();   break;
            case 'save_term':     self::action_save_term();     break;
            case 'delete_term':   self::action_delete_term();   break;
            case 'save_author':   self::action_save_author();   break;
            case 'delete_author': self::action_delete_author(); break;
            case 'save_source':   self::action_save_source();   break;
            case 'delete_source': self::action_delete_source(); break;
        }
    }

    /* =====================================================================
     * Save / delete / toggle QA
     * ================================================================== */

    private static function action_save_qa() {
        check_admin_referer( 'bqa_save_qa' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        $id       = isset( $_POST['qa_id'] ) ? (int) $_POST['qa_id'] : 0;
        $question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
        $answer   = isset( $_POST['answer'] ) ? wp_kses_post( wp_unslash( $_POST['answer'] ) ) : '';
        $status   = isset( $_POST['status'] ) && in_array( $_POST['status'], [ 'draft', 'published' ], true ) ? $_POST['status'] : 'published';
        $terms    = isset( $_POST['terms'] ) ? array_map( 'intval', (array) $_POST['terms'] ) : [];
        $refs     = isset( $_POST['scripture_refs'] ) ? sanitize_text_field( wp_unslash( $_POST['scripture_refs'] ) ) : '';
        $author_id      = isset( $_POST['author_id'] ) ? (int) $_POST['author_id'] : 0;
        $source_id      = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        $source_locator = isset( $_POST['source_locator'] ) ? sanitize_text_field( wp_unslash( $_POST['source_locator'] ) ) : '';
        $featured_image = isset( $_POST['featured_image_id'] ) ? (int) $_POST['featured_image_id'] : 0;

        if ( ! $question || ! $answer ) {
            self::redirect_with_notice( 'edit', [ 'qa_id' => $id ], 'error', 'Question and answer are required.' );
        }

        $slug = self::unique_slug( sanitize_title( $question ), $id, $table );
        $now  = current_time( 'mysql' );

        $data = [
            'question'          => $question,
            'answer'            => $answer,
            'author_id'         => $author_id ?: null,
            'source_id'         => $source_id ?: null,
            'source_locator'    => $source_locator ?: null,
            'featured_image_id' => $featured_image ?: null,
            'slug'              => $slug,
            'status'            => $status,
            'updated_at'        => $now,
        ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
        } else {
            $data['created_at'] = $now;
            $data['views']      = 0;
            $wpdb->insert( $table, $data );
            $id = (int) $wpdb->insert_id;
        }

        self::sync_terms( $id, $terms );
        self::upsert_meta( $id, 'scripture_refs', $refs );

        // Snapshot for revisions
        do_action( 'bqa_qa_saved', $id, null );

        if ( class_exists( 'BQA_REST' ) ) {
            BQA_REST::invalidate_cache();
        }

        self::redirect_with_notice( 'edit', [ 'qa_id' => $id ], 'success', 'Question saved.' );
    }

    private static function action_delete_qa() {
        $id = isset( $_GET['qa_id'] ) ? (int) $_GET['qa_id'] : 0;
        check_admin_referer( 'bqa_delete_qa_' . $id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'bible_qa',          [ 'id'    => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_meta',     [ 'qa_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_term_rel', [ 'qa_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_revisions',[ 'qa_id' => $id ], [ '%d' ] );

        if ( class_exists( 'BQA_REST' ) ) {
            BQA_REST::invalidate_cache();
        }

        self::redirect_with_notice( 'list', [], 'success', 'Question deleted.' );
    }

    private static function action_toggle_qa() {
        $id = isset( $_GET['qa_id'] ) ? (int) $_GET['qa_id'] : 0;
        check_admin_referer( 'bqa_toggle_qa_' . $id );

        global $wpdb;
        $table   = $wpdb->prefix . 'bible_qa';
        $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
        $new     = ( $current === 'published' ) ? 'draft' : 'published';

        $wpdb->update( $table, [ 'status' => $new, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        if ( class_exists( 'BQA_REST' ) ) {
            BQA_REST::invalidate_cache();
        }

        self::redirect_with_notice( 'list', [], 'success', 'Status updated.' );
    }

    private static function action_restore_rev() {
        $revision_id = isset( $_GET['revision_id'] ) ? (int) $_GET['revision_id'] : 0;
        check_admin_referer( 'bqa_restore_rev_' . $revision_id );

        $qa_id = BQA_Revisions::restore( $revision_id );
        if ( ! $qa_id ) {
            self::redirect_with_notice( 'list', [], 'error', 'Revision not found.' );
        }

        // Snapshot the pre-restore state as a new revision
        do_action( 'bqa_qa_saved', $qa_id, null );

        if ( class_exists( 'BQA_REST' ) ) {
            BQA_REST::invalidate_cache();
        }

        self::redirect_with_notice( 'edit', [ 'qa_id' => $qa_id ], 'success', 'Revision restored.' );
    }

    /* =====================================================================
     * Bulk actions
     * ================================================================== */

    private static function action_bulk() {
        check_admin_referer( 'bqa_bulk' );

        $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
        $ids         = isset( $_POST['qa_ids'] ) && is_array( $_POST['qa_ids'] ) ? array_map( 'intval', $_POST['qa_ids'] ) : [];

        if ( empty( $ids ) || ! $bulk_action ) {
            self::redirect_with_notice( 'list', [], 'error', 'No items selected.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $now = current_time( 'mysql' );

        switch ( $bulk_action ) {
            case 'delete':
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                    ...$ids
                ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}bible_qa_meta WHERE qa_id IN ({$placeholders})",
                    ...$ids
                ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}bible_qa_term_rel WHERE qa_id IN ({$placeholders})",
                    ...$ids
                ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}bible_qa_revisions WHERE qa_id IN ({$placeholders})",
                    ...$ids
                ) );
                self::redirect_with_notice( 'list', [], 'success', count( $ids ) . ' questions deleted.' );
                break;

            case 'publish':
            case 'draft':
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
                    ...array_merge( [ $bulk_action, $now ], $ids )
                ) );
                self::redirect_with_notice( 'list', [], 'success', count( $ids ) . " questions set to {$bulk_action}." );
                break;

            case 'assign_author':
                $author_id = isset( $_POST['bulk_author_id'] ) ? (int) $_POST['bulk_author_id'] : 0;
                if ( $author_id > 0 ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET author_id = %d, updated_at = %s WHERE id IN ({$placeholders})",
                        ...array_merge( [ $author_id, $now ], $ids )
                    ) );
                    self::redirect_with_notice( 'list', [], 'success', count( $ids ) . ' questions assigned to new author.' );
                }
                break;

            case 'assign_source':
                $source_id = isset( $_POST['bulk_source_id'] ) ? (int) $_POST['bulk_source_id'] : 0;
                if ( $source_id > 0 ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET source_id = %d, updated_at = %s WHERE id IN ({$placeholders})",
                        ...array_merge( [ $source_id, $now ], $ids )
                    ) );
                    self::redirect_with_notice( 'list', [], 'success', count( $ids ) . ' questions assigned to new source.' );
                }
                break;

            case 'add_topic':
                $term_id = isset( $_POST['bulk_term_id'] ) ? (int) $_POST['bulk_term_id'] : 0;
                if ( $term_id > 0 ) {
                    $rel = $wpdb->prefix . 'bible_qa_term_rel';
                    foreach ( $ids as $id ) {
                        $existing = $wpdb->get_var( $wpdb->prepare(
                            "SELECT 1 FROM {$rel} WHERE qa_id = %d AND term_id = %d LIMIT 1",
                            $id, $term_id
                        ) );
                        if ( ! $existing ) {
                            $wpdb->insert( $rel, [ 'qa_id' => $id, 'term_id' => $term_id ] );
                        }
                    }
                    self::redirect_with_notice( 'list', [], 'success', count( $ids ) . ' questions tagged with new topic.' );
                }
                break;
        }

        if ( class_exists( 'BQA_REST' ) ) {
            BQA_REST::invalidate_cache();
        }

        self::redirect_with_notice( 'list', [], 'error', 'Unknown bulk action.' );
    }

    /* =====================================================================
     * List screen — with filters and bulk actions
     * ================================================================== */

    public static function render_list_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa';

        // Filters
        $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $f_status = isset( $_GET['f_status'] ) ? sanitize_key( $_GET['f_status'] ) : '';
        $f_author = isset( $_GET['f_author'] ) ? (int) $_GET['f_author'] : 0;
        $f_source = isset( $_GET['f_source'] ) ? (int) $_GET['f_source'] : 0;
        $f_topic  = isset( $_GET['f_topic'] ) ? (int) $_GET['f_topic'] : 0;

        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $where  = '1=1';
        $params = [];

        if ( $search ) {
            $where   .= ' AND (q.question LIKE %s OR q.answer LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $f_status ) {
            $where   .= ' AND q.status = %s';
            $params[] = $f_status;
        }
        if ( $f_author ) {
            $where   .= ' AND q.author_id = %d';
            $params[] = $f_author;
        }
        if ( $f_source ) {
            $where   .= ' AND q.source_id = %d';
            $params[] = $f_source;
        }

        $join = '';
        if ( $f_topic ) {
            $join  = " INNER JOIN {$wpdb->prefix}bible_qa_term_rel r ON r.qa_id = q.id ";
            $where .= ' AND r.term_id = %d';
            $params[] = $f_topic;
        }

        $count_sql = "SELECT COUNT(DISTINCT q.id) FROM {$table} q {$join} WHERE {$where}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        $rows_sql = "SELECT DISTINCT q.id, q.question, q.slug, q.status, q.views,
                            q.created_at, q.updated_at, q.featured_image_id,
                            a.name AS author_name, s.title AS source_title
                     FROM {$table} q
                     LEFT JOIN {$wpdb->prefix}bible_qa_authors a ON a.author_id = q.author_id
                     LEFT JOIN {$wpdb->prefix}bible_qa_sources s ON s.source_id = q.source_id
                     {$join}
                     WHERE {$where}
                     ORDER BY q.id DESC
                     LIMIT %d OFFSET %d";
        $args = array_merge( $params, [ self::PER_PAGE, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$args ) );

        // Filter dropdown data
        $authors = $wpdb->get_results( "SELECT author_id, name FROM {$wpdb->prefix}bible_qa_authors ORDER BY name ASC" );
        $sources = $wpdb->get_results( "SELECT source_id, title FROM {$wpdb->prefix}bible_qa_sources ORDER BY title ASC" );
        $topics  = $wpdb->get_results( "SELECT term_id, name FROM {$wpdb->prefix}bible_qa_terms ORDER BY name ASC" );

        $total_pages = ceil( $total / self::PER_PAGE );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Bible Q&A</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-edit' ) ); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">

            <?php self::render_notice(); ?>

            <form method="get" style="margin: 1em 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                <p class="search-box" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search question/answer…">
                    <select name="f_status">
                        <option value="">All statuses</option>
                        <option value="published" <?php selected( $f_status, 'published' ); ?>>Published</option>
                        <option value="draft" <?php selected( $f_status, 'draft' ); ?>>Draft</option>
                    </select>
                    <select name="f_author">
                        <option value="0">All authors</option>
                        <?php foreach ( $authors as $a ) : ?>
                            <option value="<?php echo (int) $a->author_id; ?>" <?php selected( $f_author, $a->author_id ); ?>><?php echo esc_html( $a->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_source">
                        <option value="0">All sources</option>
                        <?php foreach ( $sources as $s ) : ?>
                            <option value="<?php echo (int) $s->source_id; ?>" <?php selected( $f_source, $s->source_id ); ?>><?php echo esc_html( $s->title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_topic">
                        <option value="0">All topics</option>
                        <?php foreach ( $topics as $t ) : ?>
                            <option value="<?php echo (int) $t->term_id; ?>" <?php selected( $f_topic, $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Filter</button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="button">Reset</a>
                </p>
            </form>

            <form method="post" id="bqa-bulk-form">
                <?php wp_nonce_field( 'bqa_bulk' ); ?>
                <input type="hidden" name="bqa_action" value="bulk">

                <div style="display:flex; gap:8px; align-items:center; margin: 1em 0;">
                    <select name="bulk_action" id="bqa-bulk-action" style="min-width:180px;">
                        <option value="">Bulk actions</option>
                        <option value="publish">Publish</option>
                        <option value="draft">Move to Draft</option>
                        <option value="assign_author">Assign Author</option>
                        <option value="assign_source">Assign Source</option>
                        <option value="add_topic">Add Topic</option>
                        <option value="delete">Delete</option>
                    </select>

                    <span class="bqa-bulk-extra" data-for="assign_author" style="display:none;">
                        <select name="bulk_author_id">
                            <option value="0">— Choose author —</option>
                            <?php foreach ( $authors as $a ) : ?>
                                <option value="<?php echo (int) $a->author_id; ?>"><?php echo esc_html( $a->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>

                    <span class="bqa-bulk-extra" data-for="assign_source" style="display:none;">
                        <select name="bulk_source_id">
                            <option value="0">— Choose source —</option>
                            <?php foreach ( $sources as $s ) : ?>
                                <option value="<?php echo (int) $s->source_id; ?>"><?php echo esc_html( $s->title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>

                    <span class="bqa-bulk-extra" data-for="add_topic" style="display:none;">
                        <select name="bulk_term_id">
                            <option value="0">— Choose topic —</option>
                            <?php foreach ( $topics as $t ) : ?>
                                <option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>

                    <button type="submit" class="button">Apply</button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td style="width:30px;"><input type="checkbox" id="bqa-select-all"></td>
                            <th style="width:60px;">ID</th>
                            <th>Question</th>
                            <th style="width:120px;">Author</th>
                            <th style="width:120px;">Source</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:60px;">Views</th>
                            <th style="width:140px;">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="8">No questions found.</td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <?php
                        $edit_url = add_query_arg( [ 'page' => self::MENU_SLUG . '-edit', 'qa_id' => $row->id ], admin_url( 'admin.php' ) );
                        $rev_url  = add_query_arg( [ 'page' => self::MENU_SLUG . '-edit', 'qa_id' => $row->id, 'show' => 'revisions' ], admin_url( 'admin.php' ) );
                        $del_url  = wp_nonce_url( add_query_arg( [ 'bqa_action' => 'delete_qa', 'qa_id' => $row->id ], admin_url( 'admin.php' ) ), 'bqa_delete_qa_' . $row->id );
                        $tog_url  = wp_nonce_url( add_query_arg( [ 'bqa_action' => 'toggle_qa', 'qa_id' => $row->id ], admin_url( 'admin.php' ) ), 'bqa_toggle_qa_' . $row->id );
                        $rev_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa_revisions WHERE qa_id = %d", $row->id ) );
                        ?>
                        <tr>
                            <td><input type="checkbox" name="qa_ids[]" value="<?php echo (int) $row->id; ?>" class="bqa-row-check"></td>
                            <td><?php echo (int) $row->id; ?></td>
                            <td>
                                <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row->question ); ?></a></strong>
                                <div class="row-actions">
                                    <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                    <span><a href="<?php echo esc_url( $tog_url ); ?>"><?php echo $row->status === 'published' ? 'Unpublish' : 'Publish'; ?></a> | </span>
                                    <span><a href="<?php echo esc_url( $rev_url ); ?>">Revisions (<?php echo $rev_count; ?>)</a> | </span>
                                    <span class="trash"><a href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('Delete this question permanently?');" style="color:#b32d2e;">Delete</a></span>
                                </div>
                            </td>
                            <td><?php echo esc_html( $row->author_name ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->source_title ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->status ); ?></td>
                            <td><?php echo (int) $row->views; ?></td>
                            <td><?php echo esc_html( $row->updated_at ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php echo paginate_links( [
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ] ); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            $('#bqa-select-all').on('change', function(){
                $('.bqa-row-check').prop('checked', $(this).prop('checked'));
            });
            $('#bqa-bulk-action').on('change', function(){
                var v = $(this).val();
                $('.bqa-bulk-extra').hide();
                $('.bqa-bulk-extra[data-for="' + v + '"]').show();
            });
        });
        </script>
        <?php
    }

    /* =====================================================================
     * Edit screen — with featured image and revisions panel
     * ================================================================== */

    public static function render_edit_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $qa_table    = $wpdb->prefix . 'bible_qa';
        $terms_table = $wpdb->prefix . 'bible_qa_terms';
        $rel_table   = $wpdb->prefix . 'bible_qa_term_rel';

        $id = isset( $_GET['qa_id'] ) ? (int) $_GET['qa_id'] : 0;
        $qa = null;

        if ( $id > 0 ) {
            $qa = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$qa_table} WHERE id = %d", $id ) );
            if ( ! $qa ) {
                echo '<div class="wrap"><h1>Question not found</h1></div>';
                return;
            }
        }

        $assigned_ids = [];
        if ( $id > 0 ) {
            $assigned_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT term_id FROM {$rel_table} WHERE qa_id = %d", $id
            ) ) );
        }

        $all_terms   = $wpdb->get_results( "SELECT term_id, name FROM {$terms_table} ORDER BY name ASC" );
        $all_authors = $wpdb->get_results( "SELECT author_id, name FROM {$wpdb->prefix}bible_qa_authors ORDER BY name ASC" );
        $all_sources = $wpdb->get_results( "SELECT source_id, title, author FROM {$wpdb->prefix}bible_qa_sources ORDER BY title ASC" );
        $scripture   = $id > 0 ? self::get_meta( $id, 'scripture_refs' ) : '';

        $show_revisions = isset( $_GET['show'] ) && $_GET['show'] === 'revisions';
        $revisions      = $id > 0 ? BQA_Revisions::get_for_qa( $id, 30 ) : [];

        $heading = $id > 0 ? 'Edit Question' : 'Add New Question';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $heading ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">&larr; Back to list</a>

            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em; align-items:flex-start;">
                <div style="flex:1; min-width:0;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_qa' ); ?>
                        <input type="hidden" name="bqa_action" value="save_qa">
                        <input type="hidden" name="qa_id" value="<?php echo (int) $id; ?>">

                        <table class="form-table">
                            <tr>
                                <th><label for="question">Question</label></th>
                                <td><input type="text" name="question" id="question" class="large-text" value="<?php echo esc_attr( $qa ? $qa->question : '' ); ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="answer">Answer</label></th>
                                <td><?php wp_editor( $qa ? $qa->answer : '', 'answer', [ 'textarea_name' => 'answer', 'textarea_rows' => 12, 'media_buttons' => true, 'teeny' => false ] ); ?></td>
                            </tr>
                            <tr>
                                <th><label for="featured_image_id">Featured Image</label></th>
                                <td>
                                    <input type="hidden" name="featured_image_id" id="featured_image_id" value="<?php echo (int) ( $qa->featured_image_id ?? 0 ); ?>">
                                    <div id="bqa-featured-image-preview" style="margin-bottom:8px;">
                                        <?php if ( $qa && $qa->featured_image_id ) : ?>
                                            <?php echo wp_get_attachment_image( (int) $qa->featured_image_id, 'medium', false, [ 'style' => 'max-width:200px;height:auto;' ] ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="button" id="bqa-select-featured-image"><?php echo ( $qa && $qa->featured_image_id ) ? 'Change Image' : 'Select Image'; ?></button>
                                    <button type="button" class="button" id="bqa-remove-featured-image" style="<?php echo ( ! $qa || ! $qa->featured_image_id ) ? 'display:none;' : ''; ?>">Remove</button>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="scripture_refs">Scripture References</label></th>
                                <td><input type="text" name="scripture_refs" id="scripture_refs" class="large-text" value="<?php echo esc_attr( $scripture ); ?>" placeholder="e.g. John 3:16; Romans 5:8"></td>
                            </tr>
                            <tr>
                                <th><label for="author_id">Author</label></th>
                                <td>
                                    <select name="author_id" id="author_id">
                                        <option value="0">— None —</option>
                                        <?php foreach ( $all_authors as $a ) : ?>
                                            <option value="<?php echo (int) $a->author_id; ?>" <?php selected( $qa ? (int) $qa->author_id : 0, (int) $a->author_id ); ?>><?php echo esc_html( $a->name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-authors' ) ); ?>">Manage authors</a></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="source_id">Source</label></th>
                                <td>
                                    <select name="source_id" id="source_id" style="max-width:500px;">
                                        <option value="0">— None —</option>
                                        <?php foreach ( $all_sources as $s ) : ?>
                                            <?php $label = $s->title . ( $s->author ? ' — ' . $s->author : '' ); ?>
                                            <option value="<?php echo (int) $s->source_id; ?>" <?php selected( $qa ? (int) $qa->source_id : 0, (int) $s->source_id ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-sources' ) ); ?>">Manage sources</a></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="source_locator">Source Locator</label></th>
                                <td><input type="text" name="source_locator" id="source_locator" class="regular-text" value="<?php echo esc_attr( $qa ? $qa->source_locator : '' ); ?>" placeholder="e.g. p. 145"></td>
                            </tr>
                            <tr>
                                <th><label for="status">Status</label></th>
                                <td>
                                    <select name="status" id="status">
                                        <option value="published" <?php selected( $qa ? $qa->status : 'published', 'published' ); ?>>Published</option>
                                        <option value="draft" <?php selected( $qa ? $qa->status : '', 'draft' ); ?>>Draft</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Topics</th>
                                <td>
                                    <?php if ( empty( $all_terms ) ) : ?>
                                        <em>No topics yet.</em>
                                    <?php else : ?>
                                        <fieldset>
                                            <?php foreach ( $all_terms as $t ) : ?>
                                                <label style="display:block;">
                                                    <input type="checkbox" name="terms[]" value="<?php echo (int) $t->term_id; ?>" <?php checked( in_array( (int) $t->term_id, $assigned_ids, true ) ); ?>>
                                                    <?php echo esc_html( $t->name ); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    <?php endif; ?>
                                    <p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-topics' ) ); ?>">Manage topics</a></p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary"><?php echo $id > 0 ? 'Update Question' : 'Create Question'; ?></button>
                        </p>
                    </form>
                </div>

                <?php if ( $id > 0 && ! empty( $revisions ) ) : ?>
                    <div style="flex:0 0 320px;">
                        <h2>Revisions</h2>
                        <p class="description">Last <?php echo count( $revisions ); ?> changes.</p>
                        <ul style="list-style:none; padding:0; margin:0;">
                            <?php foreach ( $revisions as $rev ) : ?>
                                <?php $restore_url = wp_nonce_url( add_query_arg( [ 'bqa_action' => 'restore_rev', 'revision_id' => $rev->revision_id ], admin_url( 'admin.php' ) ), 'bqa_restore_rev_' . $rev->revision_id ); ?>
                                <li style="padding:8px 0; border-bottom:1px solid #eee;">
                                    <strong><?php echo esc_html( $rev->created_at ); ?></strong><br>
                                    <small>
                                        <?php echo esc_html( $rev->user_name ?: 'System' ); ?><br>
                                        <?php echo esc_html( wp_trim_words( $rev->question, 8 ) ); ?>
                                    </small>
                                    <div style="margin-top:4px;">
                                        <a href="<?php echo esc_url( $restore_url ); ?>" onclick="return confirm('Restore this revision? Current content will be replaced.');">Restore</a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: topics
     * ------------------------------------------------------------------ */

    public static function render_topics_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_terms';

        $terms = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

        // Editing a specific term?
        $edit_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
        $edit    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE term_id = %d", $edit_id ) ) : null;
        ?>
        <div class="wrap">
            <h1>Topics</h1>

            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em;">
                <div style="flex:0 0 320px;">
                    <h2><?php echo $edit ? 'Edit Topic' : 'Add Topic'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_term' ); ?>
                        <input type="hidden" name="bqa_action" value="save_term">
                        <input type="hidden" name="term_id" value="<?php echo (int) ( $edit ? $edit->term_id : 0 ); ?>">

                        <p>
                            <label for="term_name"><strong>Name</strong></label><br>
                            <input type="text" name="term_name" id="term_name" class="regular-text"
                                   value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>" required>
                        </p>
                        <p>
                            <label for="term_slug"><strong>Slug</strong></label><br>
                            <input type="text" name="term_slug" id="term_slug" class="regular-text"
                                   value="<?php echo esc_attr( $edit ? $edit->slug : '' ); ?>">
                            <br><small>Leave blank to auto-generate.</small>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Update' : 'Create'; ?>
                            </button>
                            <?php if ( $edit ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-topics' ) ); ?>" class="button">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="flex:1;">
                    <h2>All Topics</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Name</th>
                                <th style="width:200px;">Slug</th>
                                <th style="width:120px;">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $terms ) ) : ?>
                            <tr><td colspan="4">No topics yet.</td></tr>
                        <?php else : foreach ( $terms as $t ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa_term_rel WHERE term_id = %d",
                                $t->term_id
                            ) );
                            $edit_url = add_query_arg(
                                [ 'page' => self::MENU_SLUG . '-topics', 'term_id' => $t->term_id ],
                                admin_url( 'admin.php' )
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'bqa_action' => 'delete_term', 'term_id' => $t->term_id ],
                                    admin_url( 'admin.php' )
                                ),
                                'bqa_delete_term_' . $t->term_id
                            );
                            ?>
                            <tr>
                                <td><?php echo (int) $t->term_id; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $t->name ); ?></a></strong>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url( $del_url ); ?>"
                                               onclick="return confirm('Delete this topic? Questions tagged with it will lose the tag.');"
                                               style="color:#b32d2e;">Delete</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $t->slug ); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: authors
     * ------------------------------------------------------------------ */

    public static function render_authors_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_authors';

        $authors = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

        $edit_id = isset( $_GET['author_id'] ) ? (int) $_GET['author_id'] : 0;
        $edit    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE author_id = %d", $edit_id ) ) : null;
        ?>
        <div class="wrap">
            <h1>Authors</h1>
            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em;">
                <div style="flex:0 0 360px;">
                    <h2><?php echo $edit ? 'Edit Author' : 'Add Author'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_author' ); ?>
                        <input type="hidden" name="bqa_action" value="save_author">
                        <input type="hidden" name="author_id" value="<?php echo (int) ( $edit ? $edit->author_id : 0 ); ?>">

                        <p>
                            <label><strong>Name</strong></label><br>
                            <input type="text" name="author_name" class="regular-text" required
                                value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Slug</strong></label><br>
                            <input type="text" name="author_slug" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->slug : '' ); ?>">
                            <br><small>Leave blank to auto-generate.</small>
                        </p>
                        <p>
                            <label><strong>Email</strong></label><br>
                            <input type="email" name="author_email" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->email : '' ); ?>">
                            <br><small>Used for Gravatar fallback.</small>
                        </p>
                        <p>
                            <label><strong>Website</strong></label><br>
                            <input type="url" name="author_website" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->website : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Avatar URL</strong></label><br>
                            <input type="url" name="author_avatar" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->avatar_url : '' ); ?>">
                            <br><small>Overrides Gravatar if set.</small>
                        </p>
                        <p>
                            <label><strong>Bio</strong></label><br>
                            <textarea name="author_bio" rows="4" class="large-text"><?php echo esc_textarea( $edit ? $edit->bio : '' ); ?></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Update' : 'Create'; ?>
                            </button>
                            <?php if ( $edit ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-authors' ) ); ?>" class="button">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="flex:1;">
                    <h2>All Authors</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Name</th>
                                <th style="width:160px;">Slug</th>
                                <th style="width:80px;">Answers</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $authors ) ) : ?>
                            <tr><td colspan="4">No authors yet.</td></tr>
                        <?php else : foreach ( $authors as $a ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa WHERE author_id = %d",
                                $a->author_id
                            ) );
                            $edit_url = add_query_arg(
                                [ 'page' => self::MENU_SLUG . '-authors', 'author_id' => $a->author_id ],
                                admin_url( 'admin.php' )
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'bqa_action' => 'delete_author', 'author_id' => $a->author_id ],
                                    admin_url( 'admin.php' )
                                ),
                                'bqa_delete_author_' . $a->author_id
                            );
                            ?>
                            <tr>
                                <td><?php echo (int) $a->author_id; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $a->name ); ?></a></strong>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url( $del_url ); ?>"
                                            onclick="return confirm('Delete this author? Their answers will be reassigned to “None”.');"
                                            style="color:#b32d2e;">Delete</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $a->slug ); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Screen: sources
     * ------------------------------------------------------------------ */
    public static function render_sources_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_sources';

        $sources = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC" );

        $edit_id = isset( $_GET['source_id'] ) ? (int) $_GET['source_id'] : 0;
        $edit    = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d", $edit_id ) ) : null;
        ?>
        <div class="wrap">
            <h1>Sources</h1>
            <?php self::render_notice(); ?>

            <div style="display:flex; gap:2em; margin-top:1em;">
                <div style="flex:0 0 400px;">
                    <h2><?php echo $edit ? 'Edit Source' : 'Add Source'; ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <?php wp_nonce_field( 'bqa_save_source' ); ?>
                        <input type="hidden" name="bqa_action" value="save_source">
                        <input type="hidden" name="source_id" value="<?php echo (int) ( $edit ? $edit->source_id : 0 ); ?>">

                        <p>
                            <label><strong>Title</strong></label><br>
                            <input type="text" name="source_title" class="large-text" required
                                value="<?php echo esc_attr( $edit ? $edit->title : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Author</strong></label><br>
                            <input type="text" name="source_author" class="large-text"
                                value="<?php echo esc_attr( $edit ? $edit->author : '' ); ?>"
                                placeholder="e.g. R.C. Sproul">
                        </p>
                        <p>
                            <label><strong>Publisher</strong></label><br>
                            <input type="text" name="source_publisher" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->publisher : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Year</strong></label><br>
                            <input type="text" name="source_year" class="small-text"
                                value="<?php echo esc_attr( $edit ? $edit->year : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>Edition</strong></label><br>
                            <input type="text" name="source_edition" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->edition : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>ISBN</strong></label><br>
                            <input type="text" name="source_isbn" class="regular-text"
                                value="<?php echo esc_attr( $edit ? $edit->isbn : '' ); ?>">
                        </p>
                        <p>
                            <label><strong>URL</strong></label><br>
                            <input type="url" name="source_url" class="large-text"
                                value="<?php echo esc_attr( $edit ? $edit->url : '' ); ?>"
                                placeholder="https://...">
                        </p>
                        <p>
                            <label><strong>Notes</strong></label><br>
                            <textarea name="source_notes" rows="4" class="large-text"><?php echo esc_textarea( $edit ? $edit->notes : '' ); ?></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Update' : 'Create'; ?>
                            </button>
                            <?php if ( $edit ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-sources' ) ); ?>" class="button">Cancel</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="flex:1;">
                    <h2>All Sources</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Title</th>
                                <th style="width:180px;">Author</th>
                                <th style="width:80px;">Year</th>
                                <th style="width:80px;">Answers</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $sources ) ) : ?>
                            <tr><td colspan="5">No sources yet.</td></tr>
                        <?php else : foreach ( $sources as $s ) : ?>
                            <?php
                            $count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}bible_qa WHERE source_id = %d",
                                $s->source_id
                            ) );
                            $edit_url = add_query_arg(
                                [ 'page' => self::MENU_SLUG . '-sources', 'source_id' => $s->source_id ],
                                admin_url( 'admin.php' )
                            );
                            $del_url = wp_nonce_url(
                                add_query_arg(
                                    [ 'bqa_action' => 'delete_source', 'source_id' => $s->source_id ],
                                    admin_url( 'admin.php' )
                                ),
                                'bqa_delete_source_' . $s->source_id
                            );
                            ?>
                            <tr>
                                <td><?php echo (int) $s->source_id; ?></td>
                                <td>
                                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $s->title ); ?></a></strong>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url( $del_url ); ?>"
                                            onclick="return confirm('Delete this source? Questions citing it will have their source cleared.');"
                                            style="color:#b32d2e;">Delete</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $s->author ); ?></td>
                                <td><?php echo esc_html( $s->year ); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
    * Screen: Import
    * ------------------------------------------------------------------ */

    public static function render_import_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Import Q&amp;As from CSV</h1>

            <?php if ( isset( $_GET['bqa_msg'] ) ) : ?>
                <?php if ( $_GET['bqa_msg'] === 'import_done' ) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p>
                            <strong>Import complete.</strong>
                            Created: <?php echo (int) ( $_GET['created'] ?? 0 ); ?>,
                            Updated: <?php echo (int) ( $_GET['updated'] ?? 0 ); ?>,
                            Skipped: <?php echo (int) ( $_GET['skipped'] ?? 0 ); ?>,
                            Errors: <?php echo (int) ( $_GET['errors'] ?? 0 ); ?>
                        </p>
                    </div>
                <?php elseif ( $_GET['bqa_msg'] === 'error' ) : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html( rawurldecode( $_GET['bqa_txt'] ?? 'Unknown error.' ) ); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <p>
                Upload a CSV file with the following columns. Only <code>question</code> and <code>answer</code> are required.
                Rows matching an existing slug will be updated; otherwise new Q&amp;As are created.
            </p>

            <table class="widefat striped" style="max-width:720px;margin-bottom:1.5em;">
                <thead>
                    <tr><th>Column</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>question</code></td><td>Required. The question text.</td></tr>
                    <tr><td><code>answer</code></td><td>Required. May include HTML.</td></tr>
                    <tr><td><code>slug</code></td><td>Optional. Auto-generated from the question if missing. Used to match existing rows for updates.</td></tr>
                    <tr><td><code>status</code></td><td>Optional. <code>published</code> (default) or <code>draft</code>.</td></tr>
                    <tr><td><code>author</code></td><td>Optional. Author name; matched or created.</td></tr>
                    <tr><td><code>source_title</code></td><td>Optional. Book/article title; matched or created.</td></tr>
                    <tr><td><code>source_author</code></td><td>Optional. Free text.</td></tr>
                    <tr><td><code>source_publisher</code></td><td>Optional.</td></tr>
                    <tr><td><code>source_year</code></td><td>Optional.</td></tr>
                    <tr><td><code>source_edition</code></td><td>Optional.</td></tr>
                    <tr><td><code>source_isbn</code></td><td>Optional.</td></tr>
                    <tr><td><code>source_url</code></td><td>Optional. Link to publisher/Amazon/archive.org.</td></tr>
                    <tr><td><code>source_locator</code></td><td>Optional. E.g. "p. 145", "Chapter 3".</td></tr>
                    <tr><td><code>topics</code></td><td>Optional. Comma-separated list, e.g. <code>Salvation, Grace</code>.</td></tr>
                    <tr><td><code>scripture_refs</code></td><td>Optional. E.g. <code>John 3:16; Romans 5:8</code>.</td></tr>
                </tbody>
            </table>

            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bqa_export_csv' ), BQA_CSV::EXPORT_NONCE ) ); ?>"
                class="button">Download current data as a template</a>
                <span class="description" style="margin-left:0.75em;">Exports all existing Q&amp;As in the same format — useful as a starting point.</span>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( BQA_CSV::IMPORT_NONCE ); ?>
                <input type="hidden" name="action" value="bqa_import_csv">
                <table class="form-table">
                    <tr>
                        <th><label for="bqa_csv">CSV file</label></th>
                        <td>
                            <input type="file" name="bqa_csv" id="bqa_csv" accept=".csv,text/csv" required>
                            <p class="description">UTF-8 encoded. Excel/Google Sheets exports are fine.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">Import CSV</button>
                </p>
            </form>
        </div>
        <h2>Danger zone</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete ALL Q&As, authors, sources, topics, and terms? This cannot be undone.');">
                <?php wp_nonce_field( 'bqa_wipe_all' ); ?>
                <input type="hidden" name="action" value="bqa_wipe_all">
                <p>
                    <button type="submit" class="button button-link-delete">Delete all Bible Q&A data</button>
                    <span class="description" style="margin-left:1em;">Deletes every Q&A, author, source, topic, and search log entry. Export first.</span>
                </p>
            </form>
        <?php
    }

    /* ---------------------------------------------------------------------
    * Screen: Export
    * ------------------------------------------------------------------ */

    public static function render_export_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Export Q&amp;As to CSV</h1>

            <p>
                Downloads every Q&amp;A with its author, source, topics, and scripture references.
                Use it as a backup, or edit it in a spreadsheet and re-import via the Import screen.
            </p>

            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bqa_export_csv' ), BQA_CSV::EXPORT_NONCE ) ); ?>"
                class="button button-primary button-hero">
                    Download CSV
                </a>
            </p>

            <h2>Columns in the export</h2>
            <table class="widefat striped" style="max-width:720px;">
                <thead><tr><th>Column</th><th>Contains</th></tr></thead>
                <tbody>
                    <tr><td><code>slug</code></td><td>URL slug</td></tr>
                    <tr><td><code>question</code></td><td>Question text</td></tr>
                    <tr><td><code>answer</code></td><td>Answer (HTML)</td></tr>
                    <tr><td><code>status</code></td><td>published / draft</td></tr>
                    <tr><td><code>author</code></td><td>Author name</td></tr>
                    <tr><td><code>source_title</code></td><td>Book/article title</td></tr>
                    <tr><td><code>source_author</code></td><td>Source author (free text)</td></tr>
                    <tr><td><code>source_publisher</code></td><td>Publisher</td></tr>
                    <tr><td><code>source_year</code></td><td>Year</td></tr>
                    <tr><td><code>source_edition</code></td><td>Edition</td></tr>
                    <tr><td><code>source_isbn</code></td><td>ISBN</td></tr>
                    <tr><td><code>source_url</code></td><td>Link</td></tr>
                    <tr><td><code>source_locator</code></td><td>Page/chapter</td></tr>
                    <tr><td><code>topics</code></td><td>Comma-separated topic names</td></tr>
                    <tr><td><code>scripture_refs</code></td><td>Scripture references</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Action: save a term
     * ------------------------------------------------------------------ */

    private static function action_save_term() {
        check_admin_referer( 'bqa_save_term' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_terms';

        $term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
        $name    = isset( $_POST['term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['term_name'] ) ) : '';
        $slug    = isset( $_POST['term_slug'] ) ? sanitize_title( wp_unslash( $_POST['term_slug'] ) ) : '';
        $parent  = isset( $_POST['term_parent'] ) ? (int) $_POST['term_parent'] : 0;

        if ( ! $name ) {
            self::redirect_with_notice( 'topics', [], 'error', 'Name is required.' );
        }

        if ( ! $slug ) {
            $slug = sanitize_title( $name );
        }
        $slug = self::unique_slug( $slug, $term_id, $table, 'term_id' );

        $data = [
            'name'      => $name,
            'slug'      => $slug,
            'parent_id' => $parent,
        ];

        if ( $term_id > 0 ) {
            $wpdb->update( $table, $data, [ 'term_id' => $term_id ] );
        } else {
            $wpdb->insert( $table, $data );
        }

        BQA_REST::invalidate_cache();
        self::redirect_with_notice( 'topics', [], 'success', 'Topic saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete a term
     * ------------------------------------------------------------------ */

    private static function action_delete_term() {
        $term_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
        check_admin_referer( 'bqa_delete_term_' . $term_id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'bible_qa_terms',    [ 'term_id' => $term_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'bible_qa_term_rel', [ 'term_id' => $term_id ], [ '%d' ] );

        BQA_REST::invalidate_cache();
        self::redirect_with_notice( 'topics', [], 'success', 'Topic deleted.' );
    }

    /* ---------------------------------------------------------------------
     * Action: save an author
     * ------------------------------------------------------------------ */
    private static function action_save_author() {
        check_admin_referer( 'bqa_save_author' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_authors';

        $author_id = isset( $_POST['author_id'] ) ? (int) $_POST['author_id'] : 0;
        $name      = isset( $_POST['author_name'] ) ? sanitize_text_field( wp_unslash( $_POST['author_name'] ) ) : '';
        $slug      = isset( $_POST['author_slug'] ) ? sanitize_title( wp_unslash( $_POST['author_slug'] ) ) : '';
        $email     = isset( $_POST['author_email'] ) ? sanitize_email( wp_unslash( $_POST['author_email'] ) ) : '';
        $website   = isset( $_POST['author_website'] ) ? esc_url_raw( wp_unslash( $_POST['author_website'] ) ) : '';
        $avatar    = isset( $_POST['author_avatar'] ) ? esc_url_raw( wp_unslash( $_POST['author_avatar'] ) ) : '';
        $bio       = isset( $_POST['author_bio'] ) ? wp_kses_post( wp_unslash( $_POST['author_bio'] ) ) : '';

        if ( ! $name ) {
            self::redirect_with_notice( 'authors', [], 'error', 'Author name is required.' );
        }

        if ( ! $slug ) {
            $slug = sanitize_title( $name );
        }
        $slug = self::unique_slug( $slug, $author_id, $table, 'author_id' );

        $data = [
            'name'       => $name,
            'slug'       => $slug,
            'email'      => $email ?: null,
            'website'    => $website ?: null,
            'avatar_url' => $avatar ?: null,
            'bio'        => $bio ?: null,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $author_id > 0 ) {
            $wpdb->update( $table, $data, [ 'author_id' => $author_id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        BQA_REST::invalidate_cache();
        self::redirect_with_notice( 'authors', [], 'success', 'Author saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete an author
     * ------------------------------------------------------------------ */
    private static function action_delete_author() {
        $author_id = isset( $_GET['author_id'] ) ? (int) $_GET['author_id'] : 0;
        check_admin_referer( 'bqa_delete_author_' . $author_id );

        global $wpdb;

        // Null out the author_id on any questions that reference this author
        $wpdb->update(
            $wpdb->prefix . 'bible_qa',
            [ 'author_id' => null ],
            [ 'author_id' => $author_id ]
        );

        // Delete the author row
        $wpdb->delete(
            $wpdb->prefix . 'bible_qa_authors',
            [ 'author_id' => $author_id ],
            [ '%d' ]
        );

        BQA_REST::invalidate_cache();
        self::redirect_with_notice( 'authors', [], 'success', 'Author deleted.' );
    }

    /* ---------------------------------------------------------------------
     * Action: save a source
     * ------------------------------------------------------------------ */
    private static function action_save_source() {
        check_admin_referer( 'bqa_save_source' );

        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_sources';

        $source_id = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        $title     = isset( $_POST['source_title'] ) ? sanitize_text_field( wp_unslash( $_POST['source_title'] ) ) : '';
        $author    = isset( $_POST['source_author'] ) ? sanitize_text_field( wp_unslash( $_POST['source_author'] ) ) : '';
        $publisher = isset( $_POST['source_publisher'] ) ? sanitize_text_field( wp_unslash( $_POST['source_publisher'] ) ) : '';
        $year      = isset( $_POST['source_year'] ) ? sanitize_text_field( wp_unslash( $_POST['source_year'] ) ) : '';
        $edition   = isset( $_POST['source_edition'] ) ? sanitize_text_field( wp_unslash( $_POST['source_edition'] ) ) : '';
        $isbn      = isset( $_POST['source_isbn'] ) ? sanitize_text_field( wp_unslash( $_POST['source_isbn'] ) ) : '';
        $url       = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        $notes     = isset( $_POST['source_notes'] ) ? wp_kses_post( wp_unslash( $_POST['source_notes'] ) ) : '';

        if ( ! $title ) {
            self::redirect_with_notice( 'sources', [], 'error', 'Title is required.' );
        }

        $slug = sanitize_title( $title );
        $slug = self::unique_slug( $slug, $source_id, $table, 'source_id' );

        $data = [
            'title'      => $title,
            'author'     => $author ?: null,
            'publisher'  => $publisher ?: null,
            'year'       => $year ?: null,
            'edition'    => $edition ?: null,
            'isbn'       => $isbn ?: null,
            'url'        => $url ?: null,
            'notes'      => $notes ?: null,
            'slug'       => $slug,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $source_id > 0 ) {
            $wpdb->update( $table, $data, [ 'source_id' => $source_id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        BQA_REST::invalidate_cache();
        self::redirect_with_notice( 'sources', [], 'success', 'Source saved.' );
    }

    /* ---------------------------------------------------------------------
     * Action: delete a source
     * ------------------------------------------------------------------ */

    private static function action_delete_source() {
        $source_id = isset( $_GET['source_id'] ) ? (int) $_GET['source_id'] : 0;
        check_admin_referer( 'bqa_delete_source_' . $source_id );

        global $wpdb;

        // Clear the reference on any Q&As pointing at this source
        $wpdb->update(
            $wpdb->prefix . 'bible_qa',
            [ 'source_id' => null ],
            [ 'source_id' => $source_id ]
        );

        $wpdb->delete(
            $wpdb->prefix . 'bible_qa_sources',
            [ 'source_id' => $source_id ],
            [ '%d' ]
        );

        BQA_REST::invalidate_cache();
        self::redirect_with_notice( 'sources', [], 'success', 'Source deleted.' );
    }

     /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function sync_terms( $qa_id, $term_ids ) {
        global $wpdb;
        $rel = $wpdb->prefix . 'bible_qa_term_rel';

        // Wipe existing
        $wpdb->delete( $rel, [ 'qa_id' => $qa_id ], [ '%d' ] );

        // Insert new
        foreach ( array_unique( $term_ids ) as $tid ) {
            $wpdb->insert( $rel, [ 'qa_id' => $qa_id, 'term_id' => $tid ] );
        }
    }

    private static function upsert_meta( $qa_id, $key, $value ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_meta';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$table} WHERE qa_id = %d AND meta_key = %s LIMIT 1",
            $qa_id, $key
        ) );

        if ( $existing ) {
            $wpdb->update( $table, [ 'meta_value' => $value ], [ 'meta_id' => $existing ] );
        } else {
            $wpdb->insert( $table, [
                'qa_id'      => $qa_id,
                'meta_key'   => $key,
                'meta_value' => $value,
            ] );
        }
    }

    public static function get_meta( $qa_id, $key, $default = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bible_qa_meta';
        $val   = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE qa_id = %d AND meta_key = %s LIMIT 1",
            $qa_id, $key
        ) );
        return $val !== null ? $val : $default;
    }

    private static function unique_slug( $slug, $exclude_id, $table, $id_col = 'id' ) {
        global $wpdb;
        $base = $slug;
        $i    = 2;

        while ( true ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT {$id_col} FROM {$table} WHERE slug = %s AND {$id_col} != %d LIMIT 1",
                $slug, $exclude_id
            ) );
            if ( ! $existing ) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }

    private static function redirect_with_notice( $screen, $args, $type, $message ) {
        $args = array_merge(
            [ 'page' => self::MENU_SLUG . ( $screen === 'list' ? '' : '-' . $screen ) ],
            $args,
            [ 'bqa_notice' => $type, 'bqa_message' => rawurlencode( $message ) ]
        );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function render_notice() {
        if ( empty( $_GET['bqa_notice'] ) || empty( $_GET['bqa_message'] ) ) {
            return;
        }
        $type = $_GET['bqa_notice'] === 'error' ? 'error' : 'success';
        $msg  = sanitize_text_field( wp_unslash( $_GET['bqa_message'] ) );
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }
}