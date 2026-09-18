<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Einstellungsseite: Position/Abstand/Größe des Logos + Upload der 3 Logo-Varianten.
 */
class FGR_AI_Label_Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'fgr-plugins',
            'FGR AI Label',
            'AI Label',
            'manage_options',
            'fgr-ai-label',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, 'fgr-ai-label' ) ) return;
        wp_enqueue_media();
    }

    public function handle_save(): void {
        if ( ! isset( $_POST['fgr_ail_save'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'fgr_ail_save', 'fgr_ail_nonce' );

        $position = sanitize_key( $_POST['position'] ?? 'bottom-left' );
        if ( ! in_array( $position, [ 'bottom-left', 'bottom-right', 'top-left', 'top-right' ], true ) ) {
            $position = 'bottom-left';
        }

        $logos = [];
        foreach ( array_keys( fgr_ail_types() ) as $type ) {
            $logos[ $type ] = (int) ( $_POST['logo_' . $type] ?? 0 );
        }

        fgr_ail_update_settings( [
            'position' => $position,
            'margin'   => max( 0, (int) ( $_POST['margin'] ?? 12 ) ),
            'height'   => max( 8, (int) ( $_POST['height'] ?? 32 ) ),
            'logos'    => $logos,
        ] );

        add_settings_error( 'fgr_ail', 'saved', 'Einstellungen gespeichert.', 'success' );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        settings_errors( 'fgr_ail' );

        $opt = fgr_ail_get_settings();
        ?>
        <div class="wrap">
            <h1>FGR AI Label</h1>
            <p style="color:#888;margin-top:-8px">aus der <em>Freien Gestalterischen Republik</em></p>
            <p>Kennzeichnet Bilder, die in der Mediathek als „KI-generiert" markiert sind, automatisch mit einem Logo – überall wo das Bild eingebunden ist (Gutenberg, ACF, Elementor, WPBakery).</p>

            <form method="post">
                <?php wp_nonce_field( 'fgr_ail_save', 'fgr_ail_nonce' ); ?>

                <h2>Logos</h2>
                <table class="form-table" role="presentation">
                    <?php foreach ( fgr_ail_types() as $type => $label ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html( $label ); ?></th>
                        <td><?php $this->render_logo_picker( $type, (int) $opt['logos'][ $type ] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2>Position &amp; Größe</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fgr_ail_position">Position</label></th>
                        <td>
                            <select name="position" id="fgr_ail_position">
                                <option value="bottom-left"  <?php selected( $opt['position'], 'bottom-left' ); ?>>Unten links</option>
                                <option value="bottom-right" <?php selected( $opt['position'], 'bottom-right' ); ?>>Unten rechts</option>
                                <option value="top-left"     <?php selected( $opt['position'], 'top-left' ); ?>>Oben links</option>
                                <option value="top-right"    <?php selected( $opt['position'], 'top-right' ); ?>>Oben rechts</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr_ail_margin">Abstand zum Rand</label></th>
                        <td>
                            <input type="number" id="fgr_ail_margin" name="margin" min="0" step="1"
                                   value="<?php echo esc_attr( $opt['margin'] ); ?>" style="width:90px"> px
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr_ail_height">Logo-Höhe</label></th>
                        <td>
                            <input type="number" id="fgr_ail_height" name="height" min="8" step="1"
                                   value="<?php echo esc_attr( $opt['height'] ); ?>" style="width:90px"> px
                            <p class="description">Die Breite passt sich automatisch im Seitenverhältnis des Logos an.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="fgr_ail_save" class="button button-primary">Einstellungen speichern</button>
                </p>
            </form>
        </div>

        <script>
        (function () {
            document.querySelectorAll( '.fgr-ail-logo-picker' ).forEach( function ( wrap ) {
                var btn     = wrap.querySelector( '.fgr-ail-pick' );
                var clear   = wrap.querySelector( '.fgr-ail-clear' );
                var input   = wrap.querySelector( 'input[type=hidden]' );
                var preview = wrap.querySelector( '.fgr-ail-preview' );
                var frame;

                btn.addEventListener( 'click', function ( e ) {
                    e.preventDefault();
                    if ( frame ) { frame.open(); return; }
                    frame = wp.media( { title: 'Logo auswählen', multiple: false, library: { type: 'image' } } );
                    frame.on( 'select', function () {
                        var att = frame.state().get( 'selection' ).first().toJSON();
                        input.value = att.id;
                        preview.innerHTML = '<img src="' + ( att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url ) + '" style="max-height:60px;max-width:120px;display:block;margin-bottom:6px">';
                        clear.style.display = 'inline-block';
                    } );
                    frame.open();
                } );

                if ( clear ) {
                    clear.addEventListener( 'click', function ( e ) {
                        e.preventDefault();
                        input.value = '';
                        preview.innerHTML = '';
                        clear.style.display = 'none';
                    } );
                }
            } );
        })();
        </script>
        <?php
    }

    private function render_logo_picker( string $type, int $attachment_id ): void {
        $url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
        ?>
        <div class="fgr-ail-logo-picker">
            <div class="fgr-ail-preview">
                <?php if ( $url ) : ?>
                    <img src="<?php echo esc_url( $url ); ?>" style="max-height:60px;max-width:120px;display:block;margin-bottom:6px">
                <?php endif; ?>
            </div>
            <input type="hidden" name="logo_<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>">
            <button type="button" class="button fgr-ail-pick">Logo auswählen</button>
            <button type="button" class="button-link fgr-ail-clear" style="margin-left:8px;color:#a00;<?php echo $attachment_id ? '' : 'display:none'; ?>">Entfernen</button>
        </div>
        <?php
    }
}
