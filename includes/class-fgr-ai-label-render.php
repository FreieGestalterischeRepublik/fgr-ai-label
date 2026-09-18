<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fügt das KI-Logo automatisch über allen markierten Bildern ein – egal ob als
 * <img> (Gutenberg, ACF, Elementor-Bild-Widget, WPBakery vc_single_image) oder als
 * CSS-Hintergrundbild (WPBakery Row/Column, ACF-Theme-Code, Elementor-Klassik-Hintergrund).
 *
 * Arbeitet per Output-Buffer auf der fertigen HTML-Seite – dadurch Baustein-unabhängig,
 * statt sich in jeden Page-Builder einzeln einzuklinken.
 */
class FGR_AI_Label_Render {

    /** @var array<int,array{type:string,logo:string,logo_w:int}> */
    private array $by_id = [];

    /** @var array<string,int> */
    private array $by_url = [];

    /** @var array<string,int> Elementor-Element-ID => Attachment-ID (nur "Klassik"-Hintergrundbild) */
    private array $elementor_map = [];

    /** CSS-Custom-Properties für Position/Höhe, als Inline-Style an jedes Icon gehängt. */
    private string $pos_vars = '';

    public function __construct() {
        add_action( 'init', [ $this, 'maybe_start_buffer' ], 1000 );
    }

    public function maybe_start_buffer(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( is_feed() ) return;

        $map = fgr_ail_get_map();
        if ( empty( $map['by_id'] ) ) return; // keine markierten Bilder -> nichts zu tun

        $this->by_id    = $map['by_id'];
        $this->by_url   = $map['by_url'];
        $this->pos_vars = $map['pos_vars'] ?? '';

        add_action( 'wp', [ $this, 'load_elementor_map' ] );
        ob_start( [ $this, 'process' ] );
    }

    /**
     * Liest bei Elementor-Seiten die Rohdaten aus, um markierte Bilder zu finden,
     * die als "Klassik"-Hintergrundbild (nicht als <img>) gesetzt sind.
     * Diese landen bei Elementor standardmäßig in einer externen CSS-Datei,
     * lassen sich also nicht per Inline-Style im HTML erkennen.
     */
    public function load_elementor_map(): void {
        if ( ! is_singular() ) return;

        $post_id = get_queried_object_id();
        if ( ! $post_id ) return;

        $data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! $data ) return;

        $tree = json_decode( is_string( $data ) ? $data : '', true );
        if ( is_array( $tree ) ) {
            $this->walk_elementor_tree( $tree );
        }
    }

    private function walk_elementor_tree( array $nodes ): void {
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) continue;

            $bg_id = $node['settings']['background_image']['id'] ?? 0;
            if ( $bg_id && ! empty( $node['id'] ) && isset( $this->by_id[ (int) $bg_id ] ) ) {
                $this->elementor_map[ (string) $node['id'] ] = (int) $bg_id;
            }

            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->walk_elementor_tree( $node['elements'] );
            }
        }
    }

    public function process( string $html ): string {
        if ( empty( $this->by_id ) ) return $html;

        $html = $this->process_images( $html );

        if ( false !== stripos( $html, 'background-image' ) || ! empty( $this->elementor_map ) ) {
            $html = $this->process_backgrounds( $html );
        }

        return $html;
    }

    /** Ersetzt <img>-Tags markierter Bilder durch <span><img>+Logo</span>. */
    private function process_images( string $html ): string {
        $result = preg_replace_callback( '/<img\b[^>]*>/i', function ( array $m ): string {
            $tag = $m[0];
            $id  = $this->match_image_id( $tag );

            if ( ! $id || ! isset( $this->by_id[ $id ] ) ) return $tag;

            $logo  = esc_url( $this->by_id[ $id ]['logo'] );
            $style = esc_attr( $this->pos_vars );
            $badge = '<img class="fgr-ail-badge" style="' . $style . '" src="' . $logo . '" alt="KI-generiert" loading="lazy">';

            return '<span class="fgr-ail-wrap">' . $tag . $badge . '</span>';
        }, $html );

        return null !== $result ? $result : $html;
    }

    private function match_image_id( string $img_tag ): int {
        if ( preg_match( '/\bclass="[^"]*\bwp-image-(\d+)\b[^"]*"/i', $img_tag, $m ) ) {
            return (int) $m[1];
        }
        if ( preg_match( '/\bsrc="([^"]+)"/i', $img_tag, $m ) ) {
            $key = fgr_ail_normalize_url( $m[1] );
            if ( isset( $this->by_url[ $key ] ) ) return $this->by_url[ $key ];
        }
        return 0;
    }

    /**
     * Setzt bei Elementen mit markiertem Hintergrundbild eine CSS-Custom-Property mit der
     * Logo-URL (+ position:relative), damit das Stylesheet per ::after ein Logo einblenden kann.
     * Verändert nur das style-Attribut des jeweiligen Tags, keine DOM-Struktur.
     */
    private function process_backgrounds( string $html ): string {
        $result = preg_replace_callback(
            '/<([a-z0-9]+)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i',
            function ( array $m ): string {
                $tagname = strtolower( $m[1] );
                if ( 'img' === $tagname ) return $m[0];

                $attrs   = $m[2];
                $att_id  = 0;

                // a) Elementor-Klassik-Hintergrund über Element-Klasse erkennen
                if ( $this->elementor_map && preg_match( '/\bclass="([^"]*)"/i', $attrs, $cm ) ) {
                    foreach ( $this->elementor_map as $el_id => $mapped_id ) {
                        if ( false !== strpos( $cm[1], 'elementor-element-' . $el_id ) ) {
                            $att_id = $mapped_id;
                            break;
                        }
                    }
                }

                // b) Inline-CSS-Hintergrundbild (WPBakery, ACF-Theme-Code, ...)
                if ( ! $att_id && preg_match( '/\bstyle="([^"]*)"/i', $attrs, $sm )
                    && preg_match( '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $sm[1], $um ) ) {
                    $key = fgr_ail_normalize_url( $um[1] );
                    if ( isset( $this->by_url[ $key ] ) ) {
                        $att_id = $this->by_url[ $key ];
                    }
                }

                if ( ! $att_id || ! isset( $this->by_id[ $att_id ] ) ) {
                    return '<' . $m[1] . $attrs . '>';
                }

                $entry = $this->by_id[ $att_id ];
                $prop  = "position:relative;--fgr-ail-logo:url('" . esc_url_raw( $entry['logo'] ) . "');--fgr-ail-w:{$entry['logo_w']}px;{$this->pos_vars}";

                if ( preg_match( '/\bstyle="([^"]*)"/i', $attrs, $sm2 ) ) {
                    $new_style = rtrim( $sm2[1], '; ' ) . ';' . $prop;
                    $attrs     = preg_replace( '/\bstyle="[^"]*"/i', 'style="' . esc_attr( $new_style ) . '"', $attrs, 1 );
                } else {
                    $attrs .= ' style="' . esc_attr( $prop ) . '"';
                }

                return '<' . $m[1] . $attrs . '>';
            },
            $html
        );

        return null !== $result ? $result : $html;
    }
}
