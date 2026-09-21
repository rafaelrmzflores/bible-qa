<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register the Bible Q&A custom table as a SearchWP source.
 * Requires SearchWP 4.x.
 */
add_action( 'searchwp\sources', function( $sources ) {
    if ( ! class_exists( '\SearchWP\Source' ) ) {
        return $sources;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'bible_qa';

    $sources[] = new \SearchWP\Source( [
        'name'          => 'bible_qa',
        'label'         => 'Bible Q&A',
        'option_name'   => 'bible_qa',
        'primary_key'   => 'id',
        'table'         => $table,
        'attributes'    => [
            'question' => [
                'label'   => 'Question',
                'weight'  => 5.0,
            ],
            'answer' => [
                'label'   => 'Answer',
                'weight'  => 1.0,
            ],
        ],
        'capability'    => 'edit_posts',
    ] );

    return $sources;
} );