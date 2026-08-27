jQuery(document).ready(function($) {

    // Handle Settings Save
    $('#fsbhoa-save-kiosk-settings-button').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var feedback = $('#fsbhoa-save-feedback');
        
        btn.prop('disabled', true);
        feedback.text('Saving...').css('color', '#000').show();

        var optionsData = [];
        $('#fsbhoa-kiosk-settings-page input, #fsbhoa-kiosk-settings-page select').each(function() {
            var name = $(this).attr('name');
            if (name) {
                optionsData.push({ name: name, value: $(this).val() });
            }
        });

        $.post(fsbhoa_kiosk_vars.ajax_url, {
            action: 'fsbhoa_save_kiosk_settings',
            nonce: fsbhoa_kiosk_vars.nonce,
            options: optionsData
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                feedback.text(response.data).css('color', 'green');
            } else {
                feedback.text('Error: ' + response.data).css('color', 'red');
            }
            setTimeout(function() { feedback.fadeOut(); }, 4000);
        }).fail(function() {
            btn.prop('disabled', false);
            feedback.text('AJAX request failed.').css('color', 'red');
        });
    });

    // Handle API Key Generation
    $('#fsbhoa-generate-kiosk-api-key-button').on('click', function(e) {
        e.preventDefault();
        if(confirm('Generating a new key will require restarting the Kiosk Go service to pick up the new key. Continue?')) {
            $.post(fsbhoa_kiosk_vars.ajax_url, {
                action: 'fsbhoa_generate_kiosk_api_key',
                nonce: fsbhoa_kiosk_vars.key_nonce
            }, function(response) {
                if (response.success) {
                    $('#fsbhoa_ac_kiosk_api_key').val(response.data.api_key);
                    // Trigger a save automatically
                    $('#fsbhoa-save-kiosk-settings-button').trigger('click');
                } else {
                    alert('Failed to generate key: ' + response.data);
                }
            });
        }
    });

    // Handle Media Uploader for Image fields
    var mediaUploader;
    $('#fsbhoa_kiosk_logo_url-button, #fsbhoa_kiosk_splash_url-button').on('click', function(e) {
        e.preventDefault();
        var targetInput = $(this).prev('input');
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Choose Image',
            button: { text: 'Choose Image' },
            multiple: false
        });
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            targetInput.val(attachment.url);
        });
        mediaUploader.open();
    });

});

