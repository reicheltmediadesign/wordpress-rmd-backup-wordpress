/**
 * Runs a backup step by step through the REST API and shows its progress.
 * Also shows the schedule fields that belong to the chosen interval.
 */
( function () {
	'use strict';

	const config = window.rmdBwpAdmin;
	if ( ! config || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	const apiFetch = window.wp.apiFetch;
	const t = config.strings;
	const panel = document.getElementById( 'rmd-bwp-progress' );
	let running = false;

	document.addEventListener( 'click', ( event ) => {
		const confirmLink = event.target.closest( '.rmd-bwp-confirm' );
		if ( confirmLink && ! window.confirm( t.confirmDelete ) ) {
			event.preventDefault();
			return;
		}

		const startButton = event.target.closest( '.rmd-bwp-start' );
		if ( startButton && ! running ) {
			const select = document.getElementById( 'rmd-bwp-profile' );
			start( select ? select.value : '' );
		}
	} );

	const schedule = document.getElementById( 'rmd-bwp-schedule' );
	if ( schedule ) {
		const update = () => {
			document.querySelectorAll( '.rmd-bwp-when' ).forEach( ( row ) => {
				row.hidden = ! row.classList.contains( 'rmd-bwp-when-' + schedule.value );
			} );
		};
		schedule.addEventListener( 'change', update );
		update();
	}

	if ( panel && config.status ) {
		resume();
	}

	function start( profile ) {
		running = true;
		setButtons( true );
		render( { progress: 0, message: t.starting } );
		apiFetch( { path: config.path, method: 'POST', data: { profile } } )
			.then( loop )
			.catch( fail );
	}

	function resume() {
		running = true;
		setButtons( true );
		render( config.status );
		step();
	}

	function step() {
		apiFetch( { path: config.path + '/step', method: 'POST' } )
			.then( loop )
			.catch( fail );
	}

	function loop( status ) {
		render( status );
		if ( status.done ) {
			running = false;
			setButtons( false );
			return;
		}
		// Another request (cron or loopback) is working on it: ask again shortly.
		window.setTimeout( step, status.busy ? 3000 : 0 );
	}

	function cancel() {
		if ( ! window.confirm( t.confirmCancel ) ) {
			return;
		}
		apiFetch( { path: config.path, method: 'DELETE' } )
			.then( ( result ) => {
				if ( result && result.cancelled ) {
					window.location.reload();
					return;
				}
				panel.append( element( 'p', 'rmd-bwp-hint', t.cancelling ) );
			} )
			.catch( fail );
	}

	function fail( error ) {
		running = false;
		setButtons( false );
		const box = element( 'div', 'notice notice-error inline' );
		box.append(
			element( 'p', '', t.failed + ' ' + ( error && error.message ? error.message : String( error ) ) )
		);
		const actions = element( 'p' );
		actions.append(
			button( t.retry, 'button button-primary', () => {
				running = true;
				setButtons( true );
				box.remove();
				step();
			} ),
			' ',
			button( t.cancel, 'button', cancel )
		);
		box.append( actions );
		panel.append( box );
	}

	function render( status ) {
		panel.hidden = false;
		panel.replaceChildren();
		panel.dataset.status = status.status || 'running';

		panel.append( element( 'h2', '', status.profile || '' ) );

		if ( status.done ) {
			const text = t.status[ status.status ] || '';
			const summary = element( 'p', 'rmd-bwp-result', text );
			if ( 'error' === status.status && status.message ) {
				summary.append( ' ', element( 'span', '', status.message ) );
			}
			if ( status.archive ) {
				summary.append( ' ', element( 'code', '', status.archive ) );
			}
			panel.append( summary );
		} else {
			const bar = element( 'progress', 'rmd-bwp-bar' );
			bar.max = 1;
			bar.value = status.progress || 0;
			panel.append(
				bar,
				element( 'p', 'rmd-bwp-message', status.message || '' ),
				element( 'p', 'rmd-bwp-hint', t.background )
			);
		}

		if ( status.warnings && status.warnings.length ) {
			panel.append( element( 'h3', '', t.warnings ) );
			const list = element( 'ul', 'ul-disc' );
			status.warnings.forEach( ( warning ) => list.append( element( 'li', '', warning ) ) );
			panel.append( list );
		}

		const actions = element( 'p', 'rmd-bwp-panel-actions' );
		if ( status.log ) {
			const link = element( 'a', 'button', t.viewLog );
			link.href = config.logUrl + '&log=' + encodeURIComponent( status.log );
			actions.append( link, ' ' );
		}
		if ( status.done ) {
			actions.append( button( t.close, 'button', () => window.location.reload() ) );
		} else if ( status.id ) {
			actions.append( button( t.cancel, 'button', cancel ) );
		}
		panel.append( actions );
	}

	function setButtons( disabled ) {
		document.querySelectorAll( '.rmd-bwp-start' ).forEach( ( el ) => {
			el.disabled = disabled;
		} );
	}

	function button( label, className, onClick ) {
		const el = element( 'button', className, label );
		el.type = 'button';
		el.addEventListener( 'click', onClick );
		return el;
	}

	function element( tag, className, text ) {
		const el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( undefined !== text ) {
			el.textContent = text;
		}
		return el;
	}
} )();
