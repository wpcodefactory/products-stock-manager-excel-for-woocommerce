/* Stock Manager admin: progress runner, export runner, drop zone, confirm buttons. No dependencies. */
( function () {
	'use strict';
	var cfg = window.wpfSm || {};

	function post( data ) {
		if ( data instanceof FormData ) {
			return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( r ) {
				return r.json();
			} );
		}
		var body = new FormData();
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	function setBar( box, pct, text ) {
		var bar = box.querySelector( '.wpf-sm-bar' );
		var span = box.querySelector( '.wpf-sm-bar span' );
		var label = box.querySelector( '[data-progress-text]' );
		if ( span ) {
			span.style.width = pct + '%';
		}
		if ( bar ) {
			bar.setAttribute( 'aria-valuenow', pct );
		}
		if ( label ) {
			label.textContent = text || pct + '%';
		}
	}

	// Checking and writing a run.
	var run = document.querySelector( '.wpf-sm-progress[data-run]' );
	if ( run ) {
		var tries = 0;
		var tick = function () {
			post( { action: 'wpf_sm_step', _ajax_nonce: cfg.nonces.step, run: run.getAttribute( 'data-run' ) } ).then( function ( r ) {
				if ( ! r || ! r.success ) {
					throw new Error( 'step' );
				}
				tries = 0;
				setBar( run, r.data.progress );
				if ( r.data.busy ) {
					tick();
				} else {
					window.location.href = run.getAttribute( 'data-next' );
				}
			} ).catch( function () {
				tries++;
				if ( tries < 4 ) {
					setTimeout( tick, 1500 * tries );
				} else {
					setBar( run, 0, cfg.text.failed );
				}
			} );
		};
		tick();
	}

	// Export in batches, then download.
	var form = document.querySelector( 'form[data-export]' );
	if ( form && window.fetch ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var box = form.querySelector( '[data-export-progress]' );
			var btn = form.querySelector( 'button[type=submit]' );
			var data = new FormData( form );
			data.set( 'action', 'wpf_sm_export_start' );
			data.set( '_ajax_nonce', cfg.nonces.export );
			btn.disabled = true;
			box.hidden = false;
			setBar( box, 0, cfg.text.working );
			post( data ).then( function ( r ) {
				if ( ! r.success ) {
					throw new Error( r.data && r.data.message ? r.data.message : 'export' );
				}
				var token = r.data.token;
				var step = function () {
					post( { action: 'wpf_sm_export_step', _ajax_nonce: cfg.nonces.export, token: token } ).then( function ( s ) {
						if ( ! s.success ) {
							throw new Error( s.data && s.data.message ? s.data.message : 'export' );
						}
						setBar( box, s.data.progress, s.data.progress + '% (' + s.data.rows + ' ' + cfg.text.rows + ')' );
						if ( s.data.done ) {
							setBar( box, 100, cfg.text.ready );
							btn.disabled = false;
							window.location.href = s.data.url;
						} else {
							step();
						}
					} ).catch( function ( err ) {
						setBar( box, 0, err.message === 'export' ? cfg.text.failed : err.message );
						btn.disabled = false;
					} );
				};
				step();
			} ).catch( function ( err ) {
				setBar( box, 0, err.message === 'export' ? cfg.text.failed : err.message );
				btn.disabled = false;
			} );
		} );
	}

	// Drop zone: show the chosen file name.
	document.querySelectorAll( '.wpf-sm [data-drop]' ).forEach( function ( drop ) {
		var input = drop.querySelector( 'input[type=file]' );
		var name = drop.querySelector( '[data-file]' );
		input.addEventListener( 'change', function () {
			name.textContent = input.files.length ? input.files[ 0 ].name : '';
		} );
		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			drop.addEventListener( ev, function () {
				drop.classList.add( 'over' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			drop.addEventListener( ev, function () {
				drop.classList.remove( 'over' );
			} );
		} );
	} );

	// Buttons that ask first.
	document.querySelectorAll( '.wpf-sm [data-confirm]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( b.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );
} )();
