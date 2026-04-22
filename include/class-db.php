<?php
namespace CRE;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB {

	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cre_submissions';
	}

	public static function create_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			candidate_name tinytext NOT NULL,
			user_email varchar(100) NOT NULL,
			resume_attachment_id mediumint(9) NOT NULL,
			input_data longtext NOT NULL,
			ai_result_data longtext NOT NULL,
			adzuna_data longtext NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	public static function insert_submission( $name, $email, $attach_id, $input_data, $ai_data, $adzuna_data ) {
		global $wpdb;
		$table_name = self::get_table_name();

		return $wpdb->insert(
			$table_name,
			[
				'created_at'           => current_time( 'mysql' ),
				'candidate_name'       => sanitize_text_field( $name ),
				'user_email'           => sanitize_email( $email ),
				'resume_attachment_id' => intval( $attach_id ),
				'input_data'           => json_encode( $input_data ),
				'ai_result_data'       => json_encode( $ai_data ),
				'adzuna_data'          => json_encode( $adzuna_data )
			]
		);
	}

	public static function get_submissions( $limit = 20, $offset = 0 ) {
		global $wpdb;
		$table_name = self::get_table_name();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ) );
	}

	public static function get_total_count() {
		global $wpdb;
		$table_name = self::get_table_name();
		return $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
	}

	public static function get_submission( $id ) {
		global $wpdb;
		$table_name = self::get_table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
	}

	public static function get_all_for_export() {
		global $wpdb;
		$table_name = self::get_table_name();
		return $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC" );
	}
}