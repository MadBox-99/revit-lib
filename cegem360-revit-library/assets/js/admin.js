(function($) {
    'use strict';

    function ajax(action, data) {
        var payload = Object.assign({ action: action, nonce: CRL_ADMIN.nonce }, data || {});
        return $.post(CRL_ADMIN.ajaxUrl, payload);
    }

    $(document).on('click', '#crl-regenerate-zip', function(e) {
        e.preventDefault();
        var $btn = $(this).prop('disabled', true).text(CRL_ADMIN.i18n.regenerating);
        ajax('crl_regenerate_zip').done(function(){ location.reload(); }).fail(function(){ alert(CRL_ADMIN.i18n.error); $btn.prop('disabled', false); });
    });

    $(document).on('change', '#crl-file-input', function() {
        var files = this.files; if (!files.length) return;
        var $status = $('#crl-upload-status').text(CRL_ADMIN.i18n.uploading);
        var queue = Array.from(files);
        function next() {
            if (!queue.length) { location.reload(); return; }
            var file = queue.shift();
            var fd = new FormData();
            fd.append('action', 'crl_upload_file'); fd.append('nonce', CRL_ADMIN.nonce); fd.append('file', file);
            $.ajax({ url: CRL_ADMIN.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
                .done(function(){ next(); })
                .fail(function(xhr){ $status.text((xhr.responseJSON && xhr.responseJSON.data.message) || CRL_ADMIN.i18n.error); });
        }
        next();
    });

    $(document).on('click', '.crl-delete-file', function(e) {
        e.preventDefault();
        if (!confirm(CRL_ADMIN.i18n.confirm_delete)) return;
        var $row = $(this).closest('tr');
        ajax('crl_delete_file', { filename: $row.data('filename') })
            .done(function(){ $row.remove(); })
            .fail(function(){ alert(CRL_ADMIN.i18n.error); });
    });

    $(document).on('click', '.crl-renew-token', function(e) {
        e.preventDefault();
        var sid = $(this).data('submission'); if (!sid) return;
        ajax('crl_renew_token', { submission_id: sid }).done(function(){ location.reload(); }).fail(function(){ alert(CRL_ADMIN.i18n.error); });
    });

    $(document).on('click', '.crl-resend-email', function(e) {
        e.preventDefault();
        var sid = $(this).data('submission'); if (!sid) return;
        ajax('crl_resend_email', { submission_id: sid }).done(function(){ location.reload(); }).fail(function(){ alert(CRL_ADMIN.i18n.error); });
    });

    $(document).on('click', '#crl-test-email', function(e) {
        e.preventDefault();
        var $btn = $(this).prop('disabled', true);
        ajax('crl_test_email').done(function(r){ alert(r.data.message); }).fail(function(){ alert(CRL_ADMIN.i18n.error); }).always(function(){ $btn.prop('disabled', false); });
    });
})(jQuery);
