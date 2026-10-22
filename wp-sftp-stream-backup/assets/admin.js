/* global WPSSB */
( function () {
	'use strict';

	var cfg = window.WPSSB || {};
	var t = cfg.i18n || {};
	var pollTimer = null;
	var $ = function ( id ) {
		return document.getElementById( id );
	};

	function post( action, data ) {
		var body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) && data ) {
			Object.keys( data ).forEach( function ( k ) {
				body.append( k, data[ k ] );
			} );
		}
		body.set( 'action', 'wpssb_' + action );
		body.set( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().catch( function () {
					throw new Error( 'HTTP ' + r.status );
				} );
			} );
	}

	function bytes( n ) {
		n = Number( n ) || 0;
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < u.length - 1 ) {
			n /= 1024;
			i++;
		}
		return ( i ? n.toFixed( 1 ) : n ) + ' ' + u[ i ];
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text !== undefined ) {
			e.textContent = text;
		}
		return e;
	}

	function time( ts ) {
		return new Date( ts * 1000 ).toLocaleTimeString();
	}

	/* ---------- status ---------- */

	function render( s ) {
		var box = $( 'wpssb-status' );
		var log = $( 'wpssb-log' );
		var running = !! ( s && s.status === 'running' );
		$( 'wpssb-start' ).disabled = running;
		$( 'wpssb-cancel' ).hidden = ! running;
		box.textContent = '';
		if ( ! s ) {
			box.appendChild( el( 'p', 'wpssb-empty', t.ready ) );
			return;
		}

		var head = el( 'p' );
		head.appendChild( el( 'span', 'wpssb-badge wpssb-' + s.status, ( t.statuses && t.statuses[ s.status ] ) || s.status ) );
		head.appendChild( document.createTextNode( ' ' + ( running ? ( ( t.phases && t.phases[ s.phase ] ) || s.phase ) : '' ) + ' — ' + s.id ) );
		box.appendChild( head );

		var ul = el( 'ul', 'wpssb-progress' );
		ul.appendChild( el( 'li', '', 'Database: ' + s.db_table_done + '/' + s.db_tables + ' tables, ' + s.db_rows + ' rows, ' + bytes( s.db_bytes ) + ' (gz)' ) );
		ul.appendChild( el( 'li', '', 'Files: ' + s.files_count + ' entries, ' + bytes( s.files_raw ) + ' → ' + bytes( s.files_bytes ) + ' (gz)' ) );
		if ( running && s.phase === 'files' && s.files_current ) {
			ul.appendChild( el( 'li', 'wpssb-current', s.files_current ) );
		}
		if ( s.warnings ) {
			ul.appendChild( el( 'li', 'wpssb-warn', 'Warnings: ' + s.warnings ) );
		}
		if ( s.last_error ) {
			ul.appendChild( el( 'li', 'wpssb-err', ( t.error || 'Error' ) + ': ' + s.last_error ) );
		}
		box.appendChild( ul );
		Object.keys( s.repositories || {} ).forEach( function ( id ) {
			var r = s.repositories[ id ];
			var copy = el( 'div', 'wpssb-copy' );
			copy.appendChild( el( 'strong', '', r.name ) );
			copy.appendChild( el( 'span', 'wpssb-badge wpssb-' + r.status, r.status ) );
			copy.appendChild( el( 'span', '', bytes( r.bytes ) ) );
			if ( r.error ) {
				copy.appendChild( el( 'span', 'wpssb-err', r.error ) );
			}
			box.appendChild( copy );
		} );

		if ( running ) {
			var bar = el( 'progress' );
			bar.setAttribute( 'aria-label', ( t.phases && t.phases[ s.phase ] ) || s.phase );
			if ( s.phase === 'init' || s.phase === 'db' ) {
				bar.max = Math.max( 1, s.db_tables );
				bar.value = s.phase === 'db' ? s.db_table_done : 0;
			}
			// Files phase: the total is unknown, the bar stays indeterminate.
			box.appendChild( bar );
		}

		log.textContent = ( s.log || [] ).map( function ( e ) {
			return time( e[ 0 ] ) + ' [' + e[ 1 ] + '] ' + e[ 2 ];
		} ).join( '\n' );
		log.scrollTop = log.scrollHeight;
	}

	function poll() {
		clearTimeout( pollTimer );
		post( 'status', { drive: 1 } ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data );
				if ( res.data && res.data.status === 'running' ) {
					pollTimer = setTimeout( poll, 2500 );
				}
			} else {
				pollTimer = setTimeout( poll, 5000 );
			}
		} ).catch( function () {
			pollTimer = setTimeout( poll, 5000 );
		} );
	}

	/* ---------- connection test ---------- */

	function testConnection() {
		var out = $( 'wpssb-test-result' );
		out.className = 'wpssb-test-result';
		out.textContent = t.testing;
		var data = new FormData( $( 'wpssb-form' ) );
		data.delete( 'action' );
		post( 'test', data ).then( function ( res ) {
			out.textContent = '';
			var d = res.data || {};
			if ( res.success ) {
				out.className = 'wpssb-test-result wpssb-ok';
				out.appendChild( el( 'p', '', d.message ) );
				out.appendChild( el( 'p', 'code', d.fingerprint ) );
				return;
			}
			out.className = 'wpssb-test-result wpssb-fail';
			if ( d.fingerprint ) {
				out.appendChild( el( 'p', '', d.mismatch ? t.mismatch : t.unknownHost ) );
				out.appendChild( el( 'p', 'wpssb-fp', d.fingerprint ) );
				var b = el( 'button', 'button' + ( d.mismatch ? '' : ' button-primary' ), t.trust );
				b.type = 'button';
				b.addEventListener( 'click', function () {
					$( 'wpssb-fingerprint' ).value = d.fingerprint;
					out.textContent = t.trusted;
					testConnection();
					var note = el( 'p', 'description', t.saveReminder );
					setTimeout( function () {
						out.appendChild( note );
					}, 50 );
				} );
				out.appendChild( b );
				return;
			}
			out.appendChild( el( 'p', '', ( t.error || 'Error' ) + ': ' + ( d.message || '?' ) ) );
		} ).catch( function ( e ) {
			out.className = 'wpssb-test-result wpssb-fail';
			out.textContent = ( t.error || 'Error' ) + ': ' + e.message;
		} );
	}

	/* ---------- remote list ---------- */

	function loadList() {
		var out = $( 'wpssb-remote-list' );
		out.textContent = t.loading;
		var repository = $( 'wpssb-list-repository' );
		post( 'list', repository ? { repository: repository.value } : {} ).then( function ( res ) {
			out.textContent = '';
			if ( ! res.success ) {
				out.appendChild( el( 'p', 'wpssb-err', ( t.error || 'Error' ) + ': ' + ( ( res.data && res.data.message ) || '?' ) ) );
				return;
			}
			if ( ! res.data.length ) {
				out.appendChild( el( 'p', '', t.none ) );
				return;
			}
			var table = el( 'table', 'widefat striped' );
			var tbody = el( 'tbody' );
			res.data.forEach( function ( b ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', '', b.date ) );
				var st = el( 'td' );
				var badgeStatus = b.status === 'accepted' ? 'partial' : ( b.complete ? 'done' : 'failed' );
				st.appendChild( el( 'span', 'wpssb-badge wpssb-' + badgeStatus, b.status || ( b.complete ? t.complete : t.incomplete ) ) );
				tr.appendChild( st );
				tr.appendChild( el( 'td', '', 'DB ' + b.db ) );
				tr.appendChild( el( 'td', '', 'Files ' + b.files ) );
				var p = el( 'td' );
				p.appendChild( el( 'code', '', b.path ) );
				tr.appendChild( p );
				var act = el( 'td', 'wpssb-row-actions' );
				if ( b.restorable ) {
					var rb = el( 'button', 'button button-small', t.restore );
					rb.type = 'button';
					rb.addEventListener( 'click', function () {
						openRestore( b );
					} );
					var mb = el( 'button', 'button button-small', t.migrate );
					mb.type = 'button';
					mb.addEventListener( 'click', function () {
						openMigrate( b );
					} );
					act.appendChild( rb );
					act.appendChild( mb );
				}
				tr.appendChild( act );
				tbody.appendChild( tr );
			} );
			table.appendChild( tbody );
			var scroll = el( 'div', 'wpssb-table-scroll' );
			scroll.setAttribute( 'role', 'region' );
			scroll.setAttribute( 'aria-label', t.backupList );
			scroll.tabIndex = 0;
			scroll.appendChild( table );
			out.appendChild( scroll );
		} ).catch( function ( e ) {
			out.textContent = ( t.error || 'Error' ) + ': ' + e.message;
		} );
	}

	/* ---------- one-click restore / migration ---------- */

	var selected = null;

	function showDialog( id, b ) {
		var d = $( id );
		selected = b;
		d.querySelectorAll( '.wpssb-dialog-backup' ).forEach( function ( c ) {
			c.textContent = b.path;
		} );
		var r = d.querySelector( '.wpssb-dialog-result' );
		r.textContent = '';
		r.className = 'wpssb-dialog-result';
		if ( typeof d.showModal === 'function' ) {
			d.showModal();
		} else {
			d.setAttribute( 'open', '' );
		}
		return d;
	}

	function dialogMessage( d, text, cls ) {
		var r = d.querySelector( '.wpssb-dialog-result' );
		r.textContent = text;
		r.className = 'wpssb-dialog-result' + ( cls ? ' ' + cls : '' );
	}

	function openRestore( b ) {
		showDialog( 'wpssb-restore-dialog', b );
		$( 'wpssb-restore-confirm' ).value = '';
		$( 'wpssb-restore-go' ).disabled = true;
		$( 'wpssb-restore-confirm' ).focus();
	}

	function restoreGo() {
		var d = $( 'wpssb-restore-dialog' );
		var btn = $( 'wpssb-restore-go' );
		btn.disabled = true;
		dialogMessage( d, t.preparing );
		post( 'restore_prepare', {
			repository: selected.repository,
			path: selected.path,
			confirm: $( 'wpssb-restore-confirm' ).value
		} ).then( function ( res ) {
			if ( ! res.success ) {
				throw new Error( ( res.data && res.data.message ) || '?' );
			}
			dialogMessage( d, t.redirecting, 'wpssb-ok' );
			window.location.assign( res.data.url );
		} ).catch( function ( e ) {
			dialogMessage( d, ( t.error || 'Error' ) + ': ' + e.message, 'wpssb-err' );
			btn.disabled = false;
		} );
	}

	function openMigrate( b ) {
		showDialog( 'wpssb-migrate-dialog', b );
		$( 'wpssb-migrate-password' ).value = '';
		$( 'wpssb-migrate-password2' ).value = '';
		$( 'wpssb-migrate-code' ).value = '';
		document.querySelector( '.wpssb-migrate-step1' ).hidden = false;
		document.querySelector( '.wpssb-migrate-step2' ).hidden = true;
		$( 'wpssb-migrate-go' ).hidden = false;
		$( 'wpssb-migrate-go' ).disabled = false;
		$( 'wpssb-migrate-download' ).hidden = true;
		$( 'wpssb-migrate-copy' ).hidden = true;
		$( 'wpssb-migrate-password' ).focus();
	}

	function migrateGo() {
		var d = $( 'wpssb-migrate-dialog' );
		var pw = $( 'wpssb-migrate-password' ).value;
		if ( pw.length < 12 ) {
			dialogMessage( d, t.pwShort, 'wpssb-err' );
			return;
		}
		if ( pw !== $( 'wpssb-migrate-password2' ).value ) {
			dialogMessage( d, t.pwMismatch, 'wpssb-err' );
			return;
		}
		var btn = $( 'wpssb-migrate-go' );
		btn.disabled = true;
		dialogMessage( d, t.generating );
		post( 'migration_code', {
			repository: selected.repository,
			path: selected.path,
			password: pw,
			days: $( 'wpssb-migrate-days' ).value
		} ).then( function ( res ) {
			if ( ! res.success ) {
				throw new Error( ( res.data && res.data.message ) || '?' );
			}
			$( 'wpssb-migrate-password' ).value = '';
			$( 'wpssb-migrate-password2' ).value = '';
			$( 'wpssb-migrate-code' ).value = res.data.code;
			document.querySelector( '.wpssb-migrate-expiry' ).textContent = ( t.expires || '%s' ).replace( '%s', res.data.expiresText );
			document.querySelector( '.wpssb-migrate-step1' ).hidden = true;
			document.querySelector( '.wpssb-migrate-step2' ).hidden = false;
			btn.hidden = true;
			$( 'wpssb-migrate-download' ).hidden = false;
			$( 'wpssb-migrate-copy' ).hidden = false;
			dialogMessage( d, '' );
		} ).catch( function ( e ) {
			dialogMessage( d, ( t.error || 'Error' ) + ': ' + e.message, 'wpssb-err' );
			btn.disabled = false;
		} );
	}

	function migrateDownload() {
		var f = $( 'wpssb-installer-form' );
		f.querySelector( 'input[name="code"]' ).value = $( 'wpssb-migrate-code' ).value;
		f.submit();
	}

	function migrateCopy() {
		var area = $( 'wpssb-migrate-code' );
		var done = function () {
			dialogMessage( $( 'wpssb-migrate-dialog' ), t.copied, 'wpssb-ok' );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( area.value ).then( done );
		} else {
			area.select();
			document.execCommand( 'copy' );
			done();
		}
	}

	/* ---------- wiring ---------- */

	function toggleAuth() {
		var checked = document.querySelector( 'input[name="wpssb[auth]"]:checked' );
		var mode = checked ? checked.value : 'password';
		document.querySelectorAll( '.wpssb-auth-password' ).forEach( function ( r ) {
			r.hidden = mode !== 'password';
		} );
		document.querySelectorAll( '.wpssb-auth-key' ).forEach( function ( r ) {
			r.hidden = mode !== 'key';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'input[name="wpssb[auth]"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', toggleAuth );
		} );
		toggleAuth();

		$( 'wpssb-test' ).addEventListener( 'click', testConnection );
		$( 'wpssb-list' ).addEventListener( 'click', loadList );

		if ( $( 'wpssb-restore-dialog' ) ) {
			$( 'wpssb-restore-confirm' ).addEventListener( 'input', function () {
				$( 'wpssb-restore-go' ).disabled = this.value.trim().toLowerCase() !== String( t.confirmWord || '' ).toLowerCase();
			} );
			$( 'wpssb-restore-go' ).addEventListener( 'click', restoreGo );
			[ 'wpssb-restore-form', 'wpssb-migrate-form' ].forEach( function ( id ) {
				$( id ).addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' && e.target.tagName === 'INPUT' ) {
						e.preventDefault();
						var go = $( id === 'wpssb-restore-form' ? 'wpssb-restore-go' : 'wpssb-migrate-go' );
						if ( ! go.disabled && ! go.hidden ) {
							go.click();
						}
					}
				} );
			} );
			$( 'wpssb-migrate-go' ).addEventListener( 'click', migrateGo );
			$( 'wpssb-migrate-download' ).addEventListener( 'click', migrateDownload );
			$( 'wpssb-migrate-copy' ).addEventListener( 'click', migrateCopy );
		}

		$( 'wpssb-start' ).addEventListener( 'click', function () {
			var btn = this;
			btn.disabled = true;
			$( 'wpssb-status' ).textContent = t.starting;
			post( 'start' ).then( function ( res ) {
				if ( res.success ) {
					render( res.data );
					$( 'wpssb-log-wrap' ).open = true;
					pollTimer = setTimeout( poll, 1500 );
				} else {
					btn.disabled = false;
					$( 'wpssb-status' ).textContent = ( t.error || 'Error' ) + ': ' + ( ( res.data && res.data.message ) || '?' );
				}
			} ).catch( function ( e ) {
				btn.disabled = false;
				$( 'wpssb-status' ).textContent = ( t.error || 'Error' ) + ': ' + e.message;
			} );
		} );

		$( 'wpssb-cancel' ).addEventListener( 'click', function () {
			if ( ! window.confirm( t.confirmCancel ) ) {
				return;
			}
			clearTimeout( pollTimer );
			post( 'cancel' ).then( function ( res ) {
				if ( res.success ) {
					render( res.data );
					// A running step applies the cancellation when its current phase ends.
					if ( res.data && res.data.status === 'running' ) {
						pollTimer = setTimeout( poll, 2500 );
					}
				}
			} );
		} );

		render( cfg.state );
		if ( cfg.state && cfg.state.status === 'running' ) {
			poll();
		}
	} );
}() );
