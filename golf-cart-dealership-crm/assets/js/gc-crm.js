jQuery(function ($) {
    function crmAjax(data, cb) {
        $.post((window.gcCrmData && gcCrmData.ajaxurl) ? gcCrmData.ajaxurl : (window.gcWcAjaxUrl || ajaxurl), data, cb);
    }

    function openModal(selector) {
        $(selector).addClass('open');
    }

    function closeModal(selector) {
        $(selector).removeClass('open');
    }

    function downloadCsv(filename, content) {
        var blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    $(document).on('click', '.gc-tab', function () {
        var tab = $(this).data('tab');
        $('.gc-tab').removeClass('active');
        $(this).addClass('active');
        $('.gc-panel').removeClass('active');
        $('#gc-panel-' + tab).addClass('active');
    });

    $(document).on('click', '[data-close]', function () {
        closeModal($(this).data('close'));
    });

    $(document).on('click', '#gc-open-add-lead', function () {
        $('#gc-lead-modal-title').text('Add Lead');
        $('#gc-lead-form')[0].reset();
        $('#gc-lead-id').val('');
        $('#gc-note-list').html('');
        openModal('#gc-lead-modal');
    });

    $(document).on('click', '.gc-view-lead', function () {
        var leadId = $(this).data('id');
        crmAjax({
            action: 'gc_get_lead',
            nonce: gcCrmData.nonce,
            lead_id: leadId
        }, function (res) {
            if (!res.success || !res.data.lead) {
                return;
            }
            var lead = res.data.lead;
            $('#gc-lead-modal-title').text('Lead Details');
            $('#gc-lead-id').val(lead.id);
            $('#gc-lead-first-name').val(lead.first_name);
            $('#gc-lead-last-name').val(lead.last_name);
            $('#gc-lead-email').val(lead.email);
            $('#gc-lead-phone').val(lead.phone);
            $('#gc-lead-status').val(lead.status);
            $('#gc-lead-product-id').val(lead.product_id || '0');

            var notesHtml = '';
            $.each(res.data.notes || [], function (_, note) {
                notesHtml += '<div class="gc-card"><small>' + note.created_at + '</small><div>' + $('<div>').text(note.note_text).html() + '</div></div>';
            });
            $('#gc-note-list').html(notesHtml || '<div class="gc-card">No notes yet.</div>');
            openModal('#gc-lead-modal');
        });
    });

    $(document).on('submit', '#gc-lead-form', function (e) {
        e.preventDefault();
        var leadId = $('#gc-lead-id').val();
        var action = leadId ? 'gc_update_lead' : 'gc_add_lead';
        crmAjax({
            action: action,
            nonce: gcCrmData.nonce,
            lead_id: leadId,
            first_name: $('#gc-lead-first-name').val(),
            last_name: $('#gc-lead-last-name').val(),
            email: $('#gc-lead-email').val(),
            phone: $('#gc-lead-phone').val(),
            status: $('#gc-lead-status').val(),
            product_id: $('#gc-lead-product-id').val()
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '#gc-delete-lead', function () {
        var leadId = $('#gc-lead-id').val();
        if (!leadId) {
            return;
        }
        crmAjax({
            action: 'gc_delete_lead',
            nonce: gcCrmData.nonce,
            lead_id: leadId
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '#gc-save-note', function () {
        var leadId = $('#gc-lead-id').val();
        crmAjax({
            action: 'gc_add_note',
            nonce: gcCrmData.nonce,
            lead_id: leadId,
            note_text: $('#gc-note-text').val()
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '#gc-add-todo', function () {
        crmAjax({
            action: 'gc_add_todo',
            nonce: gcCrmData.nonce,
            todo_text: $('#gc-todo-text').val()
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '.gc-delete-todo', function () {
        crmAjax({
            action: 'gc_delete_todo',
            nonce: gcCrmData.nonce,
            todo_id: $(this).data('id')
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '.gc-edit-contact', function () {
        crmAjax({
            action: 'gc_get_contact',
            nonce: gcCrmData.nonce,
            contact_id: $(this).data('id')
        }, function (res) {
            if (!res.success || !res.data.contact) {
                return;
            }
            $('#gc-contact-id').val(res.data.contact.id);
            $('#gc-contact-first-name').val(res.data.contact.first_name);
            $('#gc-contact-last-name').val(res.data.contact.last_name);
            $('#gc-contact-email').val(res.data.contact.email);
            $('#gc-contact-phone').val(res.data.contact.phone);
            openModal('#gc-contact-modal');
        });
    });

    $(document).on('submit', '#gc-contact-form', function (e) {
        e.preventDefault();
        crmAjax({
            action: 'gc_update_contact',
            nonce: gcCrmData.nonce,
            contact_id: $('#gc-contact-id').val(),
            first_name: $('#gc-contact-first-name').val(),
            last_name: $('#gc-contact-last-name').val(),
            email: $('#gc-contact-email').val(),
            phone: $('#gc-contact-phone').val()
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '.gc-delete-contact', function () {
        crmAjax({
            action: 'gc_delete_contact',
            nonce: gcCrmData.nonce,
            contact_id: $(this).data('id')
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('submit', '#gc-settings-form', function (e) {
        e.preventDefault();
        crmAjax({
            action: 'gc_save_settings',
            nonce: gcCrmData.nonce,
            cf7_form_id: $('#gc-cf7-form-id').val(),
            notification_email: $('#gc-notification-email').val()
        }, function (res) {
            if (res.success) {
                $('#gc-settings-message').addClass('active');
                location.reload();
            }
        });
    });

    $(document).on('click', '#gc-export-leads', function () {
        crmAjax({
            action: 'gc_export_leads_csv',
            nonce: gcCrmData.nonce
        }, function (res) {
            if (res.success) {
                downloadCsv(res.data.filename, res.data.content);
                setTimeout(function () {
                    location.reload();
                }, 600);
            }
        });
    });

    $(document).on('click', '#gc-export-contacts', function () {
        crmAjax({
            action: 'gc_export_contacts_csv',
            nonce: gcCrmData.nonce
        }, function (res) {
            if (res.success) {
                downloadCsv(res.data.filename, res.data.content);
                setTimeout(function () {
                    location.reload();
                }, 600);
            }
        });
    });

    $(document).on('dragstart', '.gc-lead-card', function (e) {
        e.originalEvent.dataTransfer.setData('text/plain', $(this).data('id'));
    });

    $(document).on('dragover', '.gc-lead-list', function (e) {
        e.preventDefault();
    });

    $(document).on('drop', '.gc-lead-list', function (e) {
        e.preventDefault();
        var leadId = e.originalEvent.dataTransfer.getData('text/plain');
        var status = $(this).data('status');

        crmAjax({
            action: 'gc_move_lead',
            nonce: gcCrmData.nonce,
            lead_id: leadId,
            status: status
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

    $(document).on('click', '#gc-open-wc-inquiry', function () {
        openModal('#gc-wc-inquiry-modal');
    });

    $(document).on('submit', '#gc-wc-inquiry-form', function (e) {
        e.preventDefault();
        $.post(window.gcWcAjaxUrl || ajaxurl, {
            action: 'gc_submit_wc_inquiry',
            nonce: window.gcWcAjaxNonce || (window.gcCrmData ? gcCrmData.nonce : ''),
            first_name: $('#gc-wc-first-name').val(),
            last_name: $('#gc-wc-last-name').val(),
            email: $('#gc-wc-email').val(),
            phone: $('#gc-wc-phone').val(),
            message: $('#gc-wc-message').val(),
            product_id: window.gcWcProductId || 0
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });
});
