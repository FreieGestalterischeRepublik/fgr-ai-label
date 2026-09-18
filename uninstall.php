<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'fgr_ai_label_settings' );
delete_transient( 'fgr_ail_map' );

global $wpdb;

// Markierungen an allen Bildern entfernen
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_fgr_ai_label_enabled', '_fgr_ai_label_type')" );
