jQuery(document.body).on('updated_checkout', function () {
    jQuery('#shipping_method input:checked').each(function () {
        jQuery('.pick-locker-button').attr("hidden", jQuery(this).attr('value') !== 'acs-points-plugin');
        if (jQuery(this).attr('value') === 'acs-points-plugin') {
            postcodeSearch(
                jQuery("#billing_postcode").val(),
                jQuery("#billing_address_1").val() + ',' + jQuery('#billing_city').val()
            );
        }
    });
    if (jQuery('#shipping_method input').length === 1 && jQuery('#shipping_method input').attr('value') === 'acs-points-plugin') {
        jQuery('.pick-locker-button').attr("hidden", false);
        postcodeSearch(
            jQuery("#billing_postcode").val(),
            jQuery("#billing_address_1").val() + ',' + jQuery('#billing_city').val()
        );
    }
    let element = jQuery('.locker-container').parent().find('label');
    if (element.has('span.acs-points-new').length === 0) {
        element.prepend('<span class="acs-points-new">new</span>');
    }
});