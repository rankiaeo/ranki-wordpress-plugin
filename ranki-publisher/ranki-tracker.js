(function () {
	if ( typeof rankiTracker === 'undefined' ) return;

	var eventUrl = rankiTracker.eventUrl;
	var nonce    = rankiTracker.nonce;

	function send( type, formType, phone ) {
		fetch( eventUrl, {
			method:    'POST',
			headers:   { 'Content-Type': 'application/json' },
			body:      JSON.stringify( {
				nonce:        nonce,
				type:         type,
				page_url:     location.href,
				form_type:    formType || null,
				phone_number: phone || null,
				timestamp:    new Date().toISOString()
			} ),
			keepalive: true
		} ).catch( function () {} );
	}

	// Contact Form 7 — fires after successful ajax mail send
	document.addEventListener( 'wpcf7mailsent', function () {
		send( 'form_lead', 'cf7' );
	} );

	// WPForms — fires after successful ajax submit
	document.addEventListener( 'wpformsAjaxSubmitSuccess', function () {
		send( 'form_lead', 'wpforms' );
	} );

	// All other form submissions — Elementor, Gravity Forms, plain HTML.
	// CF7 (.wpcf7) and WPForms (.wpforms-form) are skipped to avoid double-counting.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || form.tagName !== 'FORM' ) return;
		if ( form.closest( '.wpcf7' ) ) return;
		if ( form.closest( '.wpforms-form' ) ) return;

		var type = 'html';
		if ( form.closest( '.elementor-form' ) )          type = 'elementor';
		else if ( form.id && /^gform_\d+/.test( form.id ) ) type = 'gravity';

		send( 'form_lead', type );
	}, true );

	// Phone link clicks
	document.addEventListener( 'click', function ( e ) {
		var link = e.target && e.target.closest( 'a[href^="tel:"]' );
		if ( ! link ) return;
		var phone = ( link.getAttribute( 'href' ) || '' ).replace( /^tel:/, '' );
		send( 'phone_click', null, phone );
	} );
} )();
