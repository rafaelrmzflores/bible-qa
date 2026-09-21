<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BQA_Revisions {

    const MAX_PER_QA = 30;

    public static function init() {
        add_action( 'bqa_qa_saved', [ __CLASS__, 'snapshot' ], 10, 2 );
    }

    /**
     * Snapshot the current state of a Q&A.
     * Called from BQA_Admin::action_save_qa() AFTER the update.
     */
    public static function snapshot( $qa_id, $old_row = null ) {
        global $wpdb;
        $qa_table = $wpdb->prefix . 'bible_qa';
        $rev_table = $wpdb->prefix . 'bible_qa_revisions';

        $current = $wpdb->get_row( $wpdb->prepare(
            "SELECT question, answer, author_id, source_id, source_locator
             FROM {$qa_table} WHERE id = %d",
            $qa_id
        ) );

        if ( ! $current ) {
            return;
        }

        $wpdb->insert( $rev_table, [
            'qa_id'          => $qa_id,
            'question'       => $current->question,
            'answer'         => $current->answer,
            'author_id'      => $current->author_id,
            'source_id'      => $current->source_id,
            'source_locator' => $current->source_locator,
            'user_id'        => get_current_user_id() ?: null,
            'created_at'     => current_time( 'mysql' ),
        ] );

        // Trim old revisions
        self::trim( $qa_id );
    }

    /**
     * Keep only the most recent N revisions per Q&A.
     */
    private static function trim( $qa_id ) {
        global $wpdb;
        $rev_table = $wpdb->prefix . 'bible_qa_revisions';

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT revision_id FROM {$rev_table}
             WHERE qa_id = %d
             ORDER BY created_at DESC
             LIMIT 999 OFFSET %d",
            $qa_id, self::MAX_PER_QA
        ) );

        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$rev_table} WHERE revision_id IN ({$placeholders})",
                ...$ids
            ) );
        }
    }

    /**
     * Fetch revisions for a Q&A.
     */
    public static function get_for_qa( $qa_id, $limit = 20 ) {
        global $wpdb;
        $rev_table = $wpdb->prefix . 'bible_qa_revisions';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, u.display_name AS user_name
             FROM {$rev_table} r
             LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.qa_id = %d
             ORDER BY r.created_at DESC
             LIMIT %d",
            $qa_id, $limit
        ) );
    }

    /**
     * Fetch a specific revision.
     */
    public static function get( $revision_id ) {
        global $wpdb;
        $rev_table = $wpdb->prefix . 'bible_qa_revisions';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$rev_table} WHERE revision_id = %d LIMIT 1",
            $revision_id
        ) );
    }

    /**
     * Restore a revision to the Q&A.
     */
    public static function restore( $revision_id ) {
        $revision = self::get( $revision_id );
        if ( ! $revision ) {
            return false;
        }

        global $wpdb;
        $qa_table = $wpdb->prefix . 'bible_qa';

        $wpdb->update( $qa_table, [
            'question'       => $revision->question,
            'answer'         => $revision->answer,
            'author_id'      => $revision->author_id,
            'source_id'      => $revision->source_id,
            'source_locator' => $revision->source_locator,
            'updated_at'     => current_time( 'mysql' ),
        ], [ 'id' => $revision->qa_id ] );

        return (int) $revision->qa_id;
    }
}