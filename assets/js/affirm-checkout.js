jQuery( document ).ready( function( $ ) {

	var errorDidDisplay = false;
	var errorTimer = false;

	if ( ( "undefined" !== typeof affirmData ) && ( "undefined" !== typeof affirm ) ) {
		$( 'form.woocommerce-checkout' ).block( {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6
			}
		} );

		affirm.ui.ready( function() {
			affirm.ui.error.on( 'close', function() {
				window.location = affirmData.merchant.user_cancel_url;
			} );
		} );
		affirm.checkout( affirmData );
		affirm.checkout.post();
	}

} );
