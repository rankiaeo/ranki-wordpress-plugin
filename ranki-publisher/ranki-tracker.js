(function () {
	if ( typeof rankiTracker === 'undefined' ) return;

	var eventUrl = rankiTracker.eventUrl;
	var nonce    = rankiTracker.nonce;
	var details  = rankiTracker.details !== '0';

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

	// ── Reading the submitted enquiry ────────────────────────────────────────────
	// Counting enquiries told a client an enquiry happened but not who sent it, so
	// they still had to go to their own inbox to act on one. These helpers read the
	// visible fields the visitor filled in so the enquiry itself lands in Ranki.
	//
	// Deliberately never read: passwords, files, hidden inputs (nonces, tokens and
	// spam honeypots all live there), and anything that looks like a card number.

	var MAX_VALUE  = 2000;
	var MAX_FIELDS = 12;

	var SKIP_NAME = /pass|card|cvv|cvc|ccnum|credit|security[-_ ]?code|captcha|recaptcha|hcaptcha|nonce|token|csrf|honey|_wpcf7|_wpnonce|referer|^action$|^form_id$|^post_id$/i;

	// A card number in a field nobody thought to name "card" would otherwise read as a
	// long phone number. Card numbers all satisfy Luhn; phone numbers only by accident,
	// and a phone number that long is already unusual.
	function looksLikeCard( value ) {
		var digits = String( value ).replace( /\D/g, '' );
		if ( digits.length < 13 || digits.length > 19 ) return false;
		var sum = 0, alt = false;
		for ( var i = digits.length - 1; i >= 0; i-- ) {
			var n = parseInt( digits.charAt( i ), 10 );
			if ( alt ) { n *= 2; if ( n > 9 ) n -= 9; }
			sum += n;
			alt = ! alt;
		}
		return sum % 10 === 0;
	}

	var RE_EMAIL   = /e-?mail|דוא|אימייל|correo|courriel/i;
	var RE_PHONE   = /phone|tel$|^tel|telephone|mobile|cell|whatsapp|טלפון|נייד|phone[-_]?number/i;
	var RE_NAME    = /(^|[^a-z])name([^a-z]|$)|fullname|firstname|lastname|fname|lname|your-name|שם/i;
	var RE_NOTNAME = /user-?name|file-?name|nickname|company-?name|form-?name/i;
	var RE_MESSAGE = /message|comment|enquir|inquir|question|details|notes|body|הודעה|פנייה/i;
	var RE_EMAILV  = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
	var RE_PHONEV  = /^[+()\d][\d\s().+-]{6,19}$/;

	function labelFor( el, form ) {
		// The field's own name is a slug like "your-tel-2". Prefer what the visitor
		// actually saw, so an unrecognised field still reads as itself in Ranki.
		try {
			if ( el.id && form ) {
				var lbl = form.querySelector( 'label[for="' + ( window.CSS && CSS.escape ? CSS.escape( el.id ) : el.id ) + '"]' );
				if ( lbl && lbl.textContent.trim() ) return lbl.textContent.trim().slice( 0, 60 );
			}
			var wrap = el.closest && el.closest( 'label' );
			if ( wrap && wrap.textContent.trim() ) return wrap.textContent.trim().slice( 0, 60 );
		} catch ( e ) {}
		var ph = el.getAttribute && el.getAttribute( 'placeholder' );
		if ( ph ) return String( ph ).slice( 0, 60 );
		return String( el.name || el.id || 'Field' ).replace( /[-_]+/g, ' ' ).trim().slice( 0, 60 );
	}

	function readEntries( form ) {
		var out = [];
		if ( ! form || ! form.elements ) return out;

		for ( var i = 0; i < form.elements.length; i++ ) {
			var el   = form.elements[ i ];
			var tag  = ( el.tagName || '' ).toLowerCase();
			var type = ( el.type || '' ).toLowerCase();

			if ( tag !== 'input' && tag !== 'textarea' && tag !== 'select' ) continue;
			if ( type === 'password' || type === 'hidden' || type === 'file' || type === 'submit' || type === 'button' || type === 'image' || type === 'reset' ) continue;
			if ( el.disabled ) continue;

			var key = ( el.name || el.id || '' );
			if ( SKIP_NAME.test( key ) ) continue;

			// An unchecked box or radio was not part of the enquiry.
			if ( ( type === 'checkbox' || type === 'radio' ) && ! el.checked ) continue;

			var value;
			if ( tag === 'select' ) {
				var opt = el.options && el.options[ el.selectedIndex ];
				value = opt ? ( opt.textContent || opt.value ) : el.value;
			} else if ( type === 'checkbox' && ( el.value === 'on' || el.value === '' ) ) {
				value = 'Yes';
			} else {
				value = el.value;
			}

			value = String( value == null ? '' : value ).trim();
			if ( ! value ) continue;
			if ( looksLikeCard( value ) ) continue;

			out.push( { key: key, label: labelFor( el, form ), value: value.slice( 0, MAX_VALUE ) } );
		}
		return out;
	}

	// CF7 posts the submission back on its own event rather than leaving the values
	// in the DOM, so its fields arrive as {name, value} pairs with no element to read.
	function entriesFromInputs( inputs ) {
		var out = [];
		for ( var i = 0; i < inputs.length; i++ ) {
			var name  = String( inputs[ i ].name || '' );
			var value = inputs[ i ].value;
			if ( Array.isArray( value ) ) value = value.join( ', ' );
			value = String( value == null ? '' : value ).trim();
			if ( ! value || SKIP_NAME.test( name ) ) continue;
			if ( looksLikeCard( value ) ) continue;
			out.push( {
				key:   name,
				label: name.replace( /^your-/, '' ).replace( /[-_]+/g, ' ' ).slice( 0, 60 ),
				value: value.slice( 0, MAX_VALUE )
			} );
		}
		return out;
	}

	// Sort the entries into the four things a client acts on, and keep the rest as
	// they were labelled. Matching on the field name first and the value second, so a
	// form whose fields are called input_1 and input_2 still yields a usable enquiry.
	function toContact( entries ) {
		if ( ! entries.length ) return null;

		var contact = { fields: [] };
		var used    = {};

		function claim( slot, test, valueTest ) {
			for ( var i = 0; i < entries.length; i++ ) {
				if ( used[ i ] || contact[ slot ] ) continue;
				var e = entries[ i ];
				if ( test && test( e ) ) { contact[ slot ] = e.value; used[ i ] = 1; return; }
			}
			if ( ! valueTest ) return;
			for ( var j = 0; j < entries.length; j++ ) {
				if ( used[ j ] || contact[ slot ] ) continue;
				if ( valueTest( entries[ j ] ) ) { contact[ slot ] = entries[ j ].value; used[ j ] = 1; return; }
			}
		}

		claim( 'email',
			function ( e ) { return RE_EMAIL.test( e.key ) || RE_EMAIL.test( e.label ); },
			function ( e ) { return RE_EMAILV.test( e.value ); } );

		claim( 'phone',
			function ( e ) { return RE_PHONE.test( e.key ) || RE_PHONE.test( e.label ); },
			function ( e ) { return RE_PHONEV.test( e.value ) && /\d{6}/.test( e.value.replace( /\D/g, '' ) ); } );

		claim( 'name',
			function ( e ) {
				var k = e.key + ' ' + e.label;
				return RE_NAME.test( k ) && ! RE_NOTNAME.test( k );
			}, null );

		claim( 'message',
			function ( e ) { return RE_MESSAGE.test( e.key ) || RE_MESSAGE.test( e.label ); },
			function ( e ) { return e.value.length > 60; } );

		for ( var i = 0; i < entries.length && contact.fields.length < MAX_FIELDS; i++ ) {
			if ( used[ i ] ) continue;
			contact.fields.push( { label: entries[ i ].label, value: entries[ i ].value } );
		}
		if ( ! contact.fields.length ) delete contact.fields;

		return ( contact.name || contact.email || contact.phone || contact.message || contact.fields ) ? contact : null;
	}

	function send( type, formType, phone, contact ) {
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
				contact:      contact || null,
				timestamp:    new Date().toISOString()
			} ),
			keepalive: true
		} ).catch( function () {} );
	}

	function sendForm( formType, form ) {
		send( 'form_lead', formType, null, details && form ? toContact( readEntries( form ) ) : null );
	}

	// Contact Form 7 — fires after successful ajax mail send
	document.addEventListener( 'wpcf7mailsent', function ( e ) {
		var contact = null;
		if ( details && e.detail && e.detail.inputs ) {
			contact = toContact( entriesFromInputs( e.detail.inputs ) );
		}
		send( 'form_lead', 'cf7', null, contact );
	} );

	// WPForms — fires after successful ajax submit. The event target is the form,
	// and WPForms leaves the values in place, so they are still readable here.
	document.addEventListener( 'wpformsAjaxSubmitSuccess', function ( e ) {
		var form = e.target && e.target.closest ? ( e.target.closest( 'form' ) || e.target ) : null;
		sendForm( 'wpforms', form );
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

		sendForm( type, form );
	}, true );

	// Phone link clicks
	document.addEventListener( 'click', function ( e ) {
		var link = e.target && e.target.closest( 'a[href^="tel:"]' );
		if ( ! link ) return;
		var phone = ( link.getAttribute( 'href' ) || '' ).replace( /^tel:/, '' );
		send( 'phone_click', null, phone, null );
	} );
} )();
