jQuery( document ).ready( function( $ ) {

	function updateApiKeyLinks() {
		// If the sandbox checkbox checked?
		if ( 0 < $( 'input#woocommerce_affirm_testmode:checked' ).length ) {
			$( '.woocommerce_affirm_merchant_dashboard_link' ).attr( 'href', affirmAdminData.sandboxedApiKeysURI );
		} else {
			$( '.woocommerce_affirm_merchant_dashboard_link' ).attr( 'href', affirmAdminData.apiKeysURI );
		}
	}

	updateApiKeyLinks();

	$( 'input#woocommerce_affirm_testmode' ).click(
		function( event ) {
			updateApiKeyLinks();
			event.preventDefault;
		}
	);

} );