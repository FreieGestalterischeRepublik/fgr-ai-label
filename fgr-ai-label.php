<?php
/**
 * Plugin Name:  FGR AI Label
 * Description:  Ein Plugin der Freien Gestalterischen Republik. Kennzeichnet KI-generierte oder KI-bearbeitete Bilder automatisch mit einem Logo (gemäß EU-Kennzeichnungspflicht für KI-Inhalte) – funktioniert in Gutenberg, ACF, Elementor und WPBakery, ohne das Bild selbst zu verändern.
 * Version:      1.1.3
 * Author:       Freie Gestalterische Republik
 * Author URI:   https://fgr.design
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain:  fgr-ai-label
 */

defined( 'ABSPATH' ) || exit;

define( 'FGR_AIL_VERSION', '1.1.3' );
define( 'FGR_AIL_DIR',     plugin_dir_path( __FILE__ ) );
define( 'FGR_AIL_URL',     plugin_dir_url( __FILE__ ) );

// Update-Checker: prüft GitHub auf neue Versionen
require_once FGR_AIL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$fgr_ail_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/FreieGestalterischeRepublik/fgr-ai-label/',
    __FILE__,
    'fgr-ai-label'
);
$fgr_ail_updater->setBranch( 'main' );
$fgr_ail_updater->getVcsApi()->enableReleaseAssets();

require_once FGR_AIL_DIR . 'includes/class-fgr-ai-label-settings.php';
require_once FGR_AIL_DIR . 'includes/class-fgr-ai-label-media.php';
require_once FGR_AIL_DIR . 'includes/class-fgr-ai-label-render.php';

/**
 * Die 3 Kennzeichnungs-Typen gemäß EU-Vorgabe für KI-Inhalte.
 * https://digital-strategy.ec.europa.eu/de/policies/eu-icons-labelling-ai-generated-content
 */
function fgr_ail_types(): array {
    return [
        'basic'     => 'Basis-Symbol (KI beteiligt)',
        'generated' => 'Vollständig KI-generiert',
        'modified'  => 'Teilweise KI-modifiziert',
    ];
}

/**
 * Seitenverhältnis (Breite/Höhe) der mitgelieferten offiziellen EU-Icons,
 * aus deren SVG-viewBox entnommen – für die Breitenberechnung bei fester Höhe.
 */
function fgr_ail_logo_ratio( string $type ): float {
    $ratios = [
        'basic'     => 566.93 / 566.93,
        'generated' => 1789.84 / 566.93,
        'modified'  => 1700.79 / 566.93,
    ];
    return $ratios[ $type ] ?? 1.0;
}

function fgr_ail_logo_url( string $type, string $color ): string {
    return FGR_AIL_URL . 'assets/img/' . $type . '-' . $color . '.svg';
}

/**
 * CSS-Custom-Properties für Position/Größe, direkt als Inline-Style ausgegeben.
 * Bewusst NICHT über ein separat eingebundenes Stylesheet gelöst: Cache- oder
 * CSS-Optimierungs-Plugins auf Kundenseiten filtern/verzögern dynamisch per
 * wp_add_inline_style nachgeladenes CSS mitunter, wodurch die Höhen-Begrenzung
 * wegfällt und das Icon riesig dargestellt wird.
 */
function fgr_ail_position_vars( array $settings ): string {
    [ $v, $h ] = explode( '-', $settings['position'] ); // "bottom-left" -> top/bottom, left/right
    $margin = $settings['margin'];

    $vars = "--fgr-ail-h:{$settings['height']}px;--fgr-ail-{$v}:{$margin}px;--fgr-ail-{$h}:{$margin}px";
    $vars .= ';--fgr-ail-' . ( 'top' === $v ? 'bottom' : 'top' ) . ':auto';
    $vars .= ';--fgr-ail-' . ( 'left' === $h ? 'right' : 'left' ) . ':auto';

    return $vars;
}

function fgr_ail_get_settings(): array {
    $defaults = [
        'position' => 'bottom-left', // bottom-left | bottom-right | top-left | top-right
        'margin'   => 12,
        'height'   => 32,
        'color'    => 'black', // black | white
    ];

    $opt           = (array) get_option( 'fgr_ai_label_settings', [] );
    $opt           = array_merge( $defaults, $opt );
    $opt['margin'] = max( 0, (int) $opt['margin'] );
    $opt['height'] = max( 8, min( 50, (int) $opt['height'] ) );

    if ( ! in_array( $opt['position'], [ 'bottom-left', 'bottom-right', 'top-left', 'top-right' ], true ) ) {
        $opt['position'] = 'bottom-left';
    }
    if ( ! in_array( $opt['color'], [ 'black', 'white' ], true ) ) {
        $opt['color'] = 'black';
    }

    return $opt;
}

function fgr_ail_update_settings( array $data ): void {
    update_option( 'fgr_ai_label_settings', $data, false );
    delete_transient( 'fgr_ail_map' );
}

/**
 * Baut die Zuordnung "markierte Bilder -> Logo-URL/-Größe" und "Bild-URL -> Attachment-ID".
 * Wird für einen Tag zwischengespeichert, damit nicht bei jedem Seitenaufruf neu abgefragt wird.
 *
 * @return array{by_id: array<int,array{type:string,logo:string,logo_w:int}>, by_url: array<string,int>}
 */
function fgr_ail_get_map(): array {
    $cached = get_transient( 'fgr_ail_map' );
    if ( is_array( $cached ) && isset( $cached['by_id'], $cached['by_url'] ) ) {
        return $cached;
    }

    $settings = fgr_ail_get_settings();
    $by_id    = [];
    $by_url   = [];

    // Intrinsische Breite je Logo-Typ für die konfigurierte Höhe vorberechnen
    // (nötig für Hintergrundbilder, die keine eigene Bild-Breite/-Höhe wie <img> haben).
    $logo_widths = [];
    foreach ( array_keys( fgr_ail_types() ) as $type ) {
        $logo_widths[ $type ] = (int) round( $settings['height'] * fgr_ail_logo_ratio( $type ) );
    }

    $ids = get_posts( [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => '_fgr_ai_label_enabled',
        'meta_value'     => '1',
        'no_found_rows'  => true,
    ] );

    foreach ( $ids as $id ) {
        $type = get_post_meta( $id, '_fgr_ai_label_type', true ) ?: 'basic';
        if ( ! array_key_exists( $type, $logo_widths ) ) continue;

        $by_id[ $id ] = [
            'type'   => $type,
            'logo'   => fgr_ail_logo_url( $type, $settings['color'] ),
            'logo_w' => $logo_widths[ $type ],
        ];

        $base = wp_get_attachment_url( $id );
        if ( $base ) {
            $by_url[ fgr_ail_normalize_url( $base ) ] = $id;
        }

        $meta = wp_get_attachment_metadata( $id );
        if ( $base && ! empty( $meta['sizes'] ) ) {
            $dir = trailingslashit( dirname( $base ) );
            foreach ( $meta['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $by_url[ fgr_ail_normalize_url( $dir . $size['file'] ) ] = $id;
                }
            }
        }
    }

    $map = [
        'by_id'    => $by_id,
        'by_url'   => $by_url,
        'pos_vars' => fgr_ail_position_vars( $settings ),
    ];
    set_transient( 'fgr_ail_map', $map, DAY_IN_SECONDS );
    return $map;
}

function fgr_ail_normalize_url( string $url ): string {
    $url = (string) strtok( $url, '?' );
    $url = (string) preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url );
    return strtolower( $url );
}

add_action( 'plugins_loaded', function () {
    new FGR_AI_Label_Settings();
    new FGR_AI_Label_Media();
    new FGR_AI_Label_Render();
} );

// Statisches CSS einbinden – nur wenn tatsächlich markierte Bilder existieren.
// Höhe/Position kommen als Inline-Style direkt am Element (siehe FGR_AI_Label_Render),
// damit sie auch bei Cache-/CSS-Optimierungs-Plugins zuverlässig ankommen.
add_action( 'wp_enqueue_scripts', function () {
    if ( is_admin() ) return;
    $map = fgr_ail_get_map();
    if ( empty( $map['by_id'] ) ) return;

    wp_enqueue_style( 'fgr-ai-label', FGR_AIL_URL . 'assets/css/frontend.css', [], FGR_AIL_VERSION );
} );

// Warnung, falls das Plugin über "Code herunterladen" statt über den Update-Checker
// installiert wurde – landet dann im falschen Ordner "fgr-ai-label-main".
if ( is_admin() && substr( untrailingslashit( FGR_AIL_DIR ), -5 ) === '-main' ) {
    add_action( 'admin_notices', function () {
        $zip_url = 'https://github.com/FreieGestalterischeRepublik/fgr-ai-label/releases/latest';
        echo '<div class="notice notice-error"><p>'
            . '<strong>FGR AI Label:</strong> Das Plugin ist im falschen Ordner installiert '
            . '(<code>' . esc_html( basename( FGR_AIL_DIR ) ) . '</code>). '
            . 'Bitte das Plugin <strong>deaktivieren → löschen → neu installieren</strong>. '
            . 'Deine Einstellungen bleiben dabei erhalten. '
            . '<a href="' . esc_url( $zip_url ) . '" target="_blank">ZIP herunterladen →</a>'
            . '</p></div>';
    } );
}

// Cache leeren, wenn ein als "KI-generiert" markiertes Bild gelöscht wird
add_action( 'delete_attachment', function ( $post_id ) {
    if ( get_post_meta( $post_id, '_fgr_ai_label_enabled', true ) ) {
        delete_transient( 'fgr_ail_map' );
    }
} );
