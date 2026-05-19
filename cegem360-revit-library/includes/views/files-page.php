<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap crl-files-page">
    <h1><?php esc_html_e( 'Fájlkezelő', 'cegem360-revit-library' ); ?></h1>

    <div class="crl-zip-info">
        <h2><?php esc_html_e( 'Letölthető ZIP', 'cegem360-revit-library' ); ?></h2>
        <ul>
            <li><?php esc_html_e( 'Legutóbb generálva:', 'cegem360-revit-library' ); ?>
                <strong><?php echo $info['generated_at'] ? esc_html( wp_date( 'Y-m-d H:i', strtotime( $info['generated_at'] . ' UTC' ) ) ) : esc_html__( 'Még nem készült', 'cegem360-revit-library' ); ?></strong></li>
            <li><?php esc_html_e( 'Méret:', 'cegem360-revit-library' ); ?> <strong><?php echo esc_html( crl_format_bytes( $info['size'] ) ); ?></strong></li>
            <li><?php esc_html_e( 'Fájlok száma:', 'cegem360-revit-library' ); ?> <strong><?php echo (int) $info['file_count']; ?></strong></li>
        </ul>
        <p>
            <button class="button" id="crl-regenerate-zip"><?php esc_html_e( 'ZIP regenerálása most', 'cegem360-revit-library' ); ?></button>
        </p>
    </div>

    <div class="crl-upload">
        <h2><?php esc_html_e( 'Fájl feltöltése', 'cegem360-revit-library' ); ?></h2>
        <p>
            <?php printf( esc_html__( 'Engedélyezett típusok: %s', 'cegem360-revit-library' ), esc_html( $allowed ) ); ?><br>
            <?php printf( esc_html__( 'Max. fájlméret: %s', 'cegem360-revit-library' ), esc_html( $max_upload ) ); ?>
        </p>
        <input type="file" id="crl-file-input" multiple>
        <div id="crl-upload-status"></div>
    </div>

    <h2><?php esc_html_e( 'Jelenlegi fájlok', 'cegem360-revit-library' ); ?></h2>
    <table class="wp-list-table widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Név', 'cegem360-revit-library' ); ?></th>
            <th><?php esc_html_e( 'Méret', 'cegem360-revit-library' ); ?></th>
            <th><?php esc_html_e( 'Módosítva', 'cegem360-revit-library' ); ?></th>
            <th><?php esc_html_e( 'Műveletek', 'cegem360-revit-library' ); ?></th>
        </tr></thead>
        <tbody id="crl-file-list">
        <?php if ( $files ) : foreach ( $files as $f ) : ?>
            <tr data-filename="<?php echo esc_attr( $f['name'] ); ?>">
                <td><?php echo esc_html( $f['name'] ); ?></td>
                <td><?php echo esc_html( crl_format_bytes( $f['size'] ) ); ?></td>
                <td><?php echo esc_html( wp_date( 'Y-m-d H:i', $f['modified'] ) ); ?></td>
                <td><button class="button-link-delete crl-delete-file" type="button"><?php esc_html_e( 'Törlés', 'cegem360-revit-library' ); ?></button></td>
            </tr>
        <?php endforeach; else : ?>
            <tr><td colspan="4"><?php esc_html_e( 'Még nincs feltöltött fájl.', 'cegem360-revit-library' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
