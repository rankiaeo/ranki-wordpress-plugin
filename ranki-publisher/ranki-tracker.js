(function () {
	if ( typeof rankiTracker === 'undefined' ) return;

	var eventUrl = rankiTracker.eventUrl;
	var nonce    = rankiTracker.nonce;

	// The page a visitor arrived on, captured now rather than at submit time.
	// Reporting only ever knew where a form was submitted, so a reader who landed on
	// an article and then enquired from /contact credited /contact, and every article
	// looked like it had produced nothing. Runs on load so the value is already stored
	// by the time they reach the form. 90 days matches a normal attribution window;
	// without it a returning customer would credit their first visit forever.
	var FIRST_TOUCH_KEY = 'ranki_first_touch';
	var FIRST_TOUCH_MAX = 90 * 24 * 60 * 60 * 1000;

	var landingUrl = ( function () {
		try {
			var raw = window.localStorage.getItem( FIRST_TOUCH_KEY );
			if ( raw ) {
				var saved = JSON.parse( raw );
				if ( saved && saved.u && ( Date.now() - saved.t ) < FIRST_TOUCH_MAX ) return saved.u;
			}
			window.localStorage.setItem( FIRST_TOUCH_KEY, JSON.stringify( { u: location.href, t: Date.now() } ) );
			return location.href;
		} catch ( e ) {
			// Private browsing or storage blocked. The conversion still counts, it just
			// attributes to the page it happened on, which is the old behaviour.
			return location.href;
		}
	} )();

	function send( type, formType, phone ) {
		fetch( eventUrl, {
			method:    'POST',
			headers:   { 'Content-Type': 'application/json' },
			body:      JSON.stringify( {
				nonce:        nonce,
				type:         type,
				page_url:     location.href,
				landing_url:  landingUrl,
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
