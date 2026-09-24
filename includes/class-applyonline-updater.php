<?php
/**
 * The updater functionality of the plugin.
 *
 * @link       
 * @since      2.6.7.3
 *
 * @package    Applyonline
 * @subpackage Applyonline/updater
 */

/**
 * The updater functionality of the plugin.
 *
 * Defines the plugin name, version
 *
 * @package    Applyonline
 * @subpackage Applyonline/updater
 * @author     Farhan Noor <profiles.wordpress.org/farhannoor>
 */
class Applyonline_Updater{
    
    /**
	 * The ID of this plugin.
	 *
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	protected $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	protected $plugin_version;

        /**
	 * The version of this plugin last time saved in the db;
	 *
	 * @access   private
	 * @var      string    $version    The version saved in the database.
	 */
        protected $aol_version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */    
        function __construct( $plugin_name, $version ) {
            $this->plugin_name = $plugin_name;
            $this->plugin_version = $version;
            $this->aol_version = get_option('aol_version', $version);
        }

        function get_version(){
            return $this->plugin_version;
        }
        
        function update_version(){
            return update_option('aol_version', $this->get_version(), TRUE);
        }

        function after_plugin_update(){
            $saved_version = $this->aol_version;
            $version = $this->plugin_version;
            $done = FALSE;

            if( version_compare( '1.6', $saved_version, '>' ) ) {
                $this->bug_fix_before_16();
                $done = TRUE;
            }

            if( version_compare( '1.9.92', $saved_version, '>' ) ){
                $this->fix_roles();
                $done = TRUE;
            }

            if( version_compare( '2.1', $saved_version, '>' ) ){
                /*Merge Custom Filters to Default Filters*/
                $this->fix_filters();
                $done = TRUE;
            }

            if( version_compare( '2.6.7.3', $saved_version, '>' ) ){
                $this->fix_application_statuses();
                $done = TRUE;
            }

            //Scheduled for a future release.
            if( version_compare( '2.7.2', $saved_version, '>=' ) ){
                return; //Return to stop exection.
                
                $db_version = get_option( 'aol_db_version', '0.0.0' );

                // Check if database is already updated.
                if ( version_compare( $db_version, APPLYONLINE_DB_VERSION, '>=' ) ) {
                    return;
                }
                
                // Check whether this particular migration is already running.
               $status = get_option( 'aol_transcript_migration_status' );
               
               /*
                * If migration has not started yet,
                * initialize it.
                */
               if ( false === $status ) {
                       update_option( 'aol_transcript_migration_status', 'pending' );

                       update_option( 'aol_transcript_last_meta_id', 0 );

                       update_option( 'aol_transcript_migration_errors',  0 );
               }

                // Schedule the first background batch.
               if ( ! wp_next_scheduled( 'aol_migrate_transcript_batch' ) ) {

                       wp_schedule_single_event(
                               time() + 10,
                               [$this, 'aol_migrate_transcript_batch']
                       );
               }
            }

            //Update plugin version so version dependent script doesn't run again.
            if( $done === TRUE ){
                $this->update_version();
            }
        }

        function aol_migrate_transcript_batch() {

            global $wpdb;

            /*
            |--------------------------------------------------------------------------
            | Check migration status
            |--------------------------------------------------------------------------
            */

            $status = get_option( 'aol_transcript_migration_status', false );

            if ( 'pending' !== $status ) {
                    return;
            }


            /*
            |--------------------------------------------------------------------------
            | Prevent multiple cron requests from processing simultaneously
            |--------------------------------------------------------------------------
            */

            $lock_key = 'aol_transcript_migration_lock';

            if ( get_transient( $lock_key ) ) {
                    return;
            }

            /*
             * Lock for 5 minutes.
             */
            set_transient(
                    $lock_key,
                    time(),
                    5 * MINUTE_IN_SECONDS
            );


            try {

                    /*
                    |--------------------------------------------------------------------------
                    | Get last processed meta ID
                    |--------------------------------------------------------------------------
                    */

                    $last_meta_id = absint(
                            get_option(
                                    'aol_transcript_last_meta_id',
                                    0
                            )
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | Get next batch
                    |--------------------------------------------------------------------------
                    */

                    $records = $wpdb->get_results(
                            $wpdb->prepare(
                                    "
                                    SELECT
                                            meta_id,
                                            post_id,
                                            meta_value
                                    FROM {$wpdb->postmeta}
                                    WHERE meta_key = %s
                                    AND meta_id > %d
                                    ORDER BY meta_id ASC
                                    LIMIT %d
                                    ",
                                    'ad_transcript',
                                    $last_meta_id,
                                    200
                            )
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | No records remaining
                    |--------------------------------------------------------------------------
                    */

                    if ( empty( $records ) ) {

                            /*
                             * Migration is completely finished.
                             */
                            update_option(
                                    'aol_transcript_migration_status',
                                    'completed'
                            );

                            /*
                             * Only update the database version AFTER
                             * the entire migration has completed.
                             */
                            update_option(
                                    'aol_db_version',
                                    APPLYONLINE_DB_VERSION
                            );
                            
                            //Update plugin version
                            $this->update_version();

                            /*
                             * Progress is no longer required.
                             */
                            delete_option(
                                    'aol_transcript_last_meta_id'
                            );

                            /*
                             * Remove lock.
                             */
                            delete_transient( $lock_key );

                            return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Process batch
                    |--------------------------------------------------------------------------
                    */

                    foreach ( $records as $record ) {

                            $meta_id = (int) $record->meta_id;
                            $post_id = (int) $record->post_id;


                            /*
                             * Convert serialized data back into PHP data.
                             */
                            $transcript = maybe_unserialize(
                                    $record->meta_value
                            );


                            /*
                             * Convert PHP data to JSON.
                             */
                            $json = wp_json_encode(
                                    $transcript,
                                    JSON_UNESCAPED_UNICODE |
                                    JSON_UNESCAPED_SLASHES
                            );


                            /*
                             * JSON conversion failed.
                             */
                            if ( false === $json ) {

                                    $error_count = absint(
                                            get_option(
                                                    'aol_transcript_migration_errors',
                                                    0
                                            )
                                    );

                                    update_option(
                                            'aol_transcript_migration_errors',
                                            $error_count + 1
                                    );

                                    /*
                                     * Move past this record so that one bad
                                     * record doesn't stop the migration.
                                     */
                                    update_option(
                                            'aol_transcript_last_meta_id',
                                            $meta_id
                                    );

                                    continue;
                            }


                            /*
                             * Save JSON into the new _transcript field.
                             *
                             * The original ad_transcript field is NOT changed.
                             */
                            update_post_meta(
                                    $post_id,
                                    '_transcript',
                                    wp_slash( $json )
                            );


                            /*
                             * Save progress.
                             */
                            update_option(
                                    'aol_transcript_last_meta_id',
                                    $meta_id
                            );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Schedule next batch
                    |--------------------------------------------------------------------------
                    */

                    if (
                            ! wp_next_scheduled(
                                    'aol_migrate_transcript_batch'
                            )
                    ) {

                            wp_schedule_single_event(
                                    time() + 5,
                                    'aol_migrate_transcript_batch'
                            );
                    }


            } finally {

                    /*
                     * Always release the lock.
                     */
                    delete_transient( $lock_key );
            }
    }

        /**
         * This method migrates ad_transcript serialized metadata to JSON _transcript metadata. ad_transcript field is kept for backup.
         * @global type $wpdb
         */
        function fix_transcript_fields_order_and_format(){
            global $wpdb;
            $qry = "SELECT post_id, meta_value FROM $wpdb->prefix"."posts
                    JOIN $wpdb->prefix"."postmeta on ID=post_id 
                    WHERE post_type = 'aol_application' 
                    AND meta_key = 'ad_transcript';";
            $result = $wpdb->get_results($qry);
            $posts = $new = [];
            foreach($result as $row){
                //preserve existing ad_transcript as x_transcript.
                //update_post_meta($row->post_id, 'x_transcript', $row->meta_value);

                $transcript = maybe_unserialize( $row->meta_value );

                //Find old applications transcript where aol_fields_order is set.
                if( !empty($transcript['_aol_fields_order']) ){
                    //$posts[] = $row->post_id;
                    $keys = maybe_unserialize( maybe_unserialize( $transcript['_aol_fields_order'] ));
                    $new = [];
                    
                    //Create new transcript with keys from _aol_fields_order and values from ad_transcript
                    foreach($keys as $key){
                        $new[$key] = maybe_unserialize($transcript[$key]);
                    }

                    //Save new transcript in JSON format.
                    update_post_meta( $row->post_id, '_transcript', json_encode( $new ) );
                } else {
                    $new = [];

                    //Save rest of the new transcripts in JSON format.
                    update_post_meta( $row->post_id, '_transcript', json_encode( $transcript ) );
                }
            }
        }

        function fix_filters(){
               $default_filters = [
                    'category' => array('singular' => esc_html__('Category', 'apply-online'), 'plural' => esc_html__('Categories', 'apply-online')),
                    'type' => array('singular' => esc_html__('Type', 'apply-online'), 'plural' => esc_html__('Types', 'apply-online')),
                    'location' => array('singular' => esc_html__('Location', 'apply-online'), 'plural' => esc_html__('Locations', 'apply-online'))
                ];
                $custom_filters = get_option_fixed('aol_custom_filters', array());
                $filters = array_merge($default_filters, $custom_filters);
                //Update Option was not working for Existing options, hence it is 1st being deleted.
                delete_option('aol_ad_filters');
                update_option('aol_ad_filters', $filters);
                
                /*Merge Custom Statuses to Default Statuses*/
                $default_statuses = array('pending' => __('Pending', 'apply-online'), 'rejected'=> __('Rejected', 'apply-online'), 'shortlisted' => __('Shortlisted', 'apply-online'));
                $custom_statuses = get_option_fixed('aol_custom_statuses', array());
                $statuses = array_merge($default_statuses, $custom_statuses);
                //Update Option was not working for Existing options, hence it is 1st being deleted.
                delete_option('aol_custom_statuses');
                update_option('aol_custom_statuses', $statuses);
                
                //update_option('aol_mail_footer', "\n\nThank you\n".get_bloginfo('name')."\n".site_url()."n------\nPlease do not reply to the system generated message.");
        }

        function fix_application_statuses(){
            //$notices = (array)get_option( 'aol_admin_notices' );
            //update_option( 'aol_admin_notices', $notices[] = 'db_update_required' );
            global $wpdb;
            $qry = "UPDATE $wpdb->posts p
            INNER JOIN $wpdb->term_relationships tr 
                ON p.ID = tr.object_id
            INNER JOIN $wpdb->term_taxonomy tt 
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN $wpdb->terms t 
                ON tt.term_id = t.term_id
            SET p.post_status = t.slug
            WHERE p.post_type = 'aol_application'
              AND tt.taxonomy = 'aol_application_status';
            ";
            $wpdb->query($qry);
        }

        function fix_roles(){
            $role = get_role('administrator');
            $role->remove_cap( 'edit_ratings' ); //Fixing bug in version 1.9.92
        }
        
        /**
         * This function fixes a bug in versions prior to 1.6
         * 
         * The Bug: Application form fields(Post Metas) were serialized twice before save. 
         * 
         * The Fix: Check each app form field and converts it from dual serialized to single serialized value.
         * 
         * @since 1.6
         * 
         */
        function bug_fix_before_16(){
            global $wpdb;
            $fields = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM $wpdb->posts INNER JOIN $wpdb->postmeta ON ID=post_id WHERE post_type = 'aol_ad' AND meta_key LIKE '_aol_app_%'");
            foreach ($fields as $field){
                if (is_string(unserialize($field->meta_value))) update_post_meta ($field->post_id, $field->meta_key, unserialize(unserialize($field->meta_value)));
            }
        }
}