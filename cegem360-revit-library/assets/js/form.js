(function() {
    'use strict';

    function findForms() {
        return document.querySelectorAll('.crl-form-wrapper');
    }

    function clearErrors(form) {
        form.querySelectorAll('.crl-error').forEach(function(el) { el.textContent = ''; });
        form.querySelectorAll('.crl-field').forEach(function(el) { el.classList.remove('has-error'); });
    }

    function showFieldError(form, field, message) {
        var errorEl = form.querySelector('.crl-error[data-field="' + field + '"]');
        if (errorEl) errorEl.textContent = message;
        var input = form.querySelector('[name="' + field + '"]');
        if (input) input.closest('.crl-field').classList.add('has-error');
    }

    function showMessage(wrapper, message, type) {
        var box = wrapper.querySelector('.crl-form-message');
        if (!box) return;
        box.textContent = message;
        box.className = 'crl-form-message is-' + type;
    }

    function handleSubmit(e) {
        e.preventDefault();
        var form = e.target;
        var wrapper = form.closest('.crl-form-wrapper');
        var btn = form.querySelector('.crl-submit');
        var originalText = btn.textContent;

        clearErrors(form);
        btn.disabled = true;
        btn.textContent = CRL_FORM.messages.sending;

        var data = new FormData(form);
        data.append('action', 'crl_submit_form');

        fetch(CRL_FORM.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
            .then(function(result) {
                if (result.ok && result.json.success) {
                    showMessage(wrapper, result.json.data.message, 'success');
                    form.style.display = 'none';
                } else {
                    var payload = result.json.data || {};
                    if (payload.errors) {
                        Object.keys(payload.errors).forEach(function(field) {
                            showFieldError(form, field, payload.errors[field]);
                        });
                    } else if (payload.message) {
                        showMessage(wrapper, payload.message, 'error');
                    } else {
                        showMessage(wrapper, CRL_FORM.messages.generic_error, 'error');
                    }
                }
            })
            .catch(function() { showMessage(wrapper, CRL_FORM.messages.generic_error, 'error'); })
            .finally(function() { btn.disabled = false; btn.textContent = originalText; });
    }

    function init() {
        findForms().forEach(function(wrapper) {
            var form = wrapper.querySelector('.crl-form');
            if (form && !form.dataset.crlBound) {
                form.addEventListener('submit', handleSubmit);
                form.dataset.crlBound = '1';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
