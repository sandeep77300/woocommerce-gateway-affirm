jQuery(
    function ( $ ) {
        var checkoutForm = $('form.checkout');
        
        checkoutForm.on(
            'click','input[name="payment_method"]',function () {
                if ($('#payment_method_affirm').prop('checked')) {
                    $('#place_order').hide()
                    $('#affirm-place-order').show()                    
                } else {    
                    $('#place_order').show
                    $('#affirm-place-order').hide()  
                }
            }
        )
    }
)

function affirmClick()
{
    var data = jQuery('form.checkout').serialize()
    jQuery.ajax(
        {
            type: "POST",
            url: affirm_checkout_url,
            data: data,
            success: affirmCheckoutSuccess,
        }
    );
}

function affirmCheckoutSuccess(data)
{
    if (data.success) {
        var checkoutObject = data.data.checkoutObject.data
        affirm.checkout(checkoutObject)
        affirm.checkout.open(
            {
                onSuccess : function (data) {
                    jQuery('form.checkout').append(jQuery('<input type="hidden" name="checkout_token" />').attr('value',data.checkout_token))
                    jQuery('form.checkout').append(jQuery('<input type="hidden" name="affirm_token_preorder" />').attr('value','true'))
                    jQuery('form.checkout').submit()
                }
            }
        )
    } else {
        jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        for (var error of data.data.messages) {
            affirmShowError('<ul class="woocommerce-error" role="alert">' + error + '</ul>')
        }  
    }
 
}

function affirmShowError(error)
{
    var container = jQuery('.woocommerce-notices-wrapper')[0]
    jQuery('.woocommerce-notices-wrapper, form.checkout').first().prepend(error)  
}