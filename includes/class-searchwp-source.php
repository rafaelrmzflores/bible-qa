<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', function() {

    // Bail if SearchWP 4.x isn't active.
    if ( ! class_exists( '\SearchWP\Source' ) ) {
        return;
    }

    if ( class_exists( 'BQA_SearchWP_Source' ) ) {
        return;
    }

    class BQA_SearchWP_Source extends \SearchWP\Source {

        /**
         * Canonical name for this Source.
         */
        protected $name = 'bible_qa';

        /**
         * The database table used to track index status.
         * Set in the constructor because it needs $wpdb.
         */
        protected $db_table = '';

        /**
         * The column that uniquely identifies each row.
         */
        protected $db_id_column = 'id';

        public function __construct() {
            global $wpdb;

            $this->db_table = $wpdb->get_blog_prefix() . 'bible_qa';

            $this->labels = [
                'plural'   => 'Bible Q&A Entries',
                'singular' => 'Bible Q&A Entry',
            ];

            /**
             * Attributes define what content SearchWP will index.
             * Each attribute passes through \SearchWP\Attribute's constructor.
             *
             * - 'name'      (string)   The key used in engines.
             * - 'label'     (string)   Human-readable label shown in admin.
             * - 'default'   (int)      Default weight (0 or omit to skip auto-inclusion).
             * - 'data'      (callable) Function that returns the content for a given entry ID.
             */
            $this->attributes = [
                [
                    'name'    => 'question',
                    'label'   => 'Question',
                    'default' => 5,
                    'data'    => [ $this, 'get_question_data' ],
                ],
                [
                    'name'    => 'answer',
                    'label'   => 'Answer',
                    'default' => 1,
                    'data'    => [ $this, 'get_answer_data' ],
                ],
            ];
        }

        /**
         * Retrieve the question text for an entry.
         */
        public function get_question_data( $entry_id ) {
            global $wpdb;
            return $wpdb->get_var( $wpdb->prepare(
                "SELECT question FROM {$this->db_table} WHERE id = %d",
                $entry_id
            ) );
        }

        /**
         * Retrieve the answer text for an entry.
         */
        public function get_answer_data( $entry_id ) {
            global $wpdb;
            return $wpdb->get_var( $wpdb->prepare(
                "SELECT answer FROM {$this->db_table} WHERE id = %d",
                $entry_id
            ) );
        }

        /**
         * Return the native object for a search result.
         * This is what SearchWP hands back when you run a Query.
         * The default returns an empty stdClass, so we return the full row.
         */
        public function entry( \SearchWP\Entry $entry, $doing_query = false ) {
            global $wpdb;

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->db_table} WHERE id = %d",
                $entry->get_id()
            ) );

            return $row ?: new \stdClass();
        }

        /**
         * Optional: restrict indexed entries to published Q&As only.
         * The parent turns this into a WHERE clause when counting/indexing.
         */
        protected function db_where() {
            return [
                [
                    'column'  => 'status',
                    'value'   => 'published',
                    'compare' => '=',
                    'type'    => 'CHAR',
                ],
            ];
        }

        /**
         * Optional: permalink for a SearchWP Entry.
         */
        public static function get_permalink( int $id ) {
            return BQA_Single::permalink( $id );
        }

        /**
         * Optional: admin edit link for a SearchWP Entry.
         */
        public static function get_edit_link( int $id ) {
            return admin_url( 'admin.php?page=bible-qa-edit&qa_id=' . $id );
        }
    }

    /**
     * Register the Source with SearchWP.
     */
    add_filter( 'searchwp\sources', function( $sources ) {
        $sources[] = new BQA_SearchWP_Source();
        return $sources;
    } );
} );