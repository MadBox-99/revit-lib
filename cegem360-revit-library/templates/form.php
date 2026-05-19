<?php defined( 'ABSPATH' ) || exit; ?>
<div class="crl-form-wrapper" style="--crl-primary-color: <?php echo esc_attr( crl_option( 'primary_color', '#2271b1' ) ); ?>;">
    <?php if ( $title ) : ?>
        <h2 class="crl-form-title"><?php echo esc_html( $title ); ?></h2>
    <?php endif; ?>

    <?php if ( $intro ) : ?>
        <div class="crl-form-intro"><?php echo wp_kses_post( wpautop( $intro ) ); ?></div>
    <?php endif; ?>

    <form class="crl-form" novalidate>
        <?php wp_nonce_field( 'crl_submit_form', 'crl_nonce' ); ?>
        <div class="crl-field">
            <label for="crl-company"><?php esc_html_e( 'Cégnév', 'cegem360-revit-library' ); ?> <span aria-hidden="true">*</span></label>
            <input type="text" id="crl-company" name="company_name" required minlength="2" maxlength="255">
            <div class="crl-error" data-field="company_name"></div>
        </div>
        <div class="crl-field">
            <label for="crl-email"><?php esc_html_e( 'Email', 'cegem360-revit-library' ); ?> <span aria-hidden="true">*</span></label>
            <input type="email" id="crl-email" name="email" required>
            <div class="crl-error" data-field="email"></div>
        </div>
        <div class="crl-field">
            <label for="crl-phone"><?php esc_html_e( 'Telefonszám', 'cegem360-revit-library' ); ?> <span aria-hidden="true">*</span></label>
            <input type="tel" id="crl-phone" name="phone" required>
            <div class="crl-error" data-field="phone"></div>
        </div>
        <div class="crl-field crl-field-checkbox">
            <label><input type="checkbox" name="gdpr" value="1" required>
                <?php echo wp_kses_post( $gdpr_html ); ?>
            </label>
            <div class="crl-error" data-field="gdpr"></div>
        </div>
        <div class="crl-honeypot" aria-hidden="true">
            <label>Website <input type="text" name="crl_website" tabindex="-1" autocomplete="off"></label>
        </div>
        <button type="submit" class="crl-submit"><?php echo esc_html( $button_text ); ?></button>
    </form>
    <div class="crl-form-message" role="status" aria-live="polite"></div>
</div>
