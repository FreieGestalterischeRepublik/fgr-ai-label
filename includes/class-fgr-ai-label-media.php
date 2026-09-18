<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mediathek-Integration: Checkbox "KI-generiert" + Typ-Auswahl je Bild,
 * plus eigene Spalte in der Medienübersicht.
 */
class FGR_AI_Label_Media {

    public function __construct() {
        add_filter( 'attachment_fields_to_edit', [ $this, 'add_fields' ], 10, 2 );
        add_filter( 'attachment_fields_to_save', [ $this, 'save_fields' ], 10, 2 );

        add_filter( 'manage_media_columns', [ $this, 'add_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_column' ], 10, 2 );
    }

    public function add_fields( array $form_fields, WP_Post $post ): array {
        if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
            return $form_fields;
        }

        $enabled = get_post_meta( $post->ID, '_fgr_ai_label_enabled', true );
        $type    = get_post_meta( $post->ID, '_fgr_ai_label_type', true ) ?: 'basic';

        ob_start();
        ?>
        <label style="display:block;margin-bottom:6px">
            <input type="checkbox"
                   name="attachments[<?php echo esc_attr( $post->ID ); ?>][fgr_ai_label_enabled]"
                   value="1" <?php checked( $enabled, '1' ); ?>>
            KI-generiert / KI-bearbeitet
        </label>
        <select name="attachments[<?php echo esc_attr( $post->ID ); ?>][fgr_ai_label_type]">
            <?php foreach ( fgr_ail_types() as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Fügt automatisch überall, wo dieses Bild eingebunden ist, das passende Logo hinzu (Position/Größe in den <a href="<?php echo esc_url( admin_url( 'admin.php?page=fgr-ai-label' ) ); ?>">Plugin-Einstellungen</a>).</p>
        <?php
        $html = ob_get_clean();

        $form_fields['fgr_ai_label'] = [
            'label' => 'KI-Kennzeichnung',
            'input' => 'html',
            'html'  => $html,
        ];

        return $form_fields;
    }

    public function save_fields( array $post, array $attachment ): array {
        $enabled = ! empty( $attachment['fgr_ai_label_enabled'] );
        $type    = sanitize_key( $attachment['fgr_ai_label_type'] ?? 'basic' );

        if ( ! array_key_exists( $type, fgr_ail_types() ) ) {
            $type = 'basic';
        }

        if ( $enabled ) {
            update_post_meta( $post['ID'], '_fgr_ai_label_enabled', '1' );
            update_post_meta( $post['ID'], '_fgr_ai_label_type', $type );
        } else {
            delete_post_meta( $post['ID'], '_fgr_ai_label_enabled' );
            delete_post_meta( $post['ID'], '_fgr_ai_label_type' );
        }

        delete_transient( 'fgr_ail_map' );

        return $post;
    }

    public function add_column( array $columns ): array {
        $columns['fgr_ai_label'] = 'KI-Label';
        return $columns;
    }

    public function render_column( string $column_name, int $attachment_id ): void {
        if ( 'fgr_ai_label' !== $column_name ) return;

        if ( ! get_post_meta( $attachment_id, '_fgr_ai_label_enabled', true ) ) {
            echo '&#8211;';
            return;
        }

        $type  = get_post_meta( $attachment_id, '_fgr_ai_label_type', true ) ?: 'basic';
        $types = fgr_ail_types();
        echo '<span style="color:#2271b1">&#9679; ' . esc_html( $types[ $type ] ?? $type ) . '</span>';
    }
}
