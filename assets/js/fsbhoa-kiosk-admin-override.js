/**
 * Admin Override sign-in dialog for the cardholder list.
 * Staff pick a guest count and an amenity; the sign-in is recorded via AJAX.
 */
jQuery(function($) {
    if (typeof fsbhoa_kiosk_override_vars === 'undefined') {
        return;
    }

    const vars = fsbhoa_kiosk_override_vars;
    let cardholderId = 0;
    let selectedGuests = 0;
    let busy = false;

    const $dialog = $('<div id="fsbhoa-kiosk-override-dialog"></div>').appendTo('body');
    const $guests = $('<div class="fsbhoa-kiosk-override-guests"></div>');
    const $amenities = $('<div class="fsbhoa-kiosk-override-amenities"></div>');
    const $message = $('<p class="fsbhoa-kiosk-override-message"></p>');

    $dialog.append('<h3>How many guests?</h3>', $guests, '<h3>Select a destination</h3>', $amenities, $message);

    for (let i = 0; i <= vars.max_guests; i++) {
        $('<button type="button" class="button fsbhoa-kiosk-override-guest"></button>')
            .text(i)
            .attr('data-count', i)
            .appendTo($guests);
    }

    if (!vars.amenities.length) {
        $amenities.append('<p>No active amenities are configured.</p>');
    }
    vars.amenities.forEach(function(amenity) {
        const $button = $('<button type="button" class="button fsbhoa-kiosk-override-amenity"></button>')
            .attr('data-name', amenity.name);
        if (amenity.image_url) {
            $('<img alt="">').attr('src', amenity.image_url).appendTo($button);
        }
        $('<span></span>').text(amenity.name).appendTo($button);
        $button.appendTo($amenities);
    });

    $dialog.dialog({
        autoOpen: false,
        modal: true,
        width: Math.min(720, $(window).width() - 32),
        dialogClass: 'fsbhoa-kiosk-override-wrapper'
    });

    function selectGuests(count) {
        selectedGuests = count;
        $guests.find('.fsbhoa-kiosk-override-guest').removeClass('button-primary');
        $guests.find('[data-count="' + count + '"]').addClass('button-primary');
    }

    function showMessage(text, isError) {
        $message.text(text).toggleClass('is-error', !!isError);
    }

    $(document).on('click', '.fsbhoa-kiosk-signin-link', function(e) {
        e.preventDefault();
        e.stopPropagation();
        cardholderId = parseInt($(this).data('id'), 10);
        busy = false;
        selectGuests(0);
        showMessage('', false);
        $amenities.find('button').prop('disabled', false);
        $dialog.dialog('option', 'title', 'Sign in: ' + $(this).data('name'));
        $dialog.dialog('open');
    });

    $guests.on('click', '.fsbhoa-kiosk-override-guest', function() {
        selectGuests(parseInt($(this).data('count'), 10));
    });

    $amenities.on('click', '.fsbhoa-kiosk-override-amenity', function() {
        if (busy) {
            return;
        }
        busy = true;
        $amenities.find('button').prop('disabled', true);
        showMessage('Signing in...', false);

        $.post(vars.ajax_url, {
            action: 'fsbhoa_kiosk_admin_signin',
            nonce: vars.nonce,
            cardholder_id: cardholderId,
            guests: selectedGuests,
            amenity: $(this).data('name')
        }).done(function(response) {
            if (response.success) {
                showMessage(response.data, false);
                setTimeout(function() { $dialog.dialog('close'); }, 1500);
            } else {
                showMessage('Error: ' + response.data, true);
                busy = false;
                $amenities.find('button').prop('disabled', false);
            }
        }).fail(function(xhr) {
            const detail = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : xhr.statusText;
            showMessage('Error: ' + detail, true);
            busy = false;
            $amenities.find('button').prop('disabled', false);
        });
    });
});
