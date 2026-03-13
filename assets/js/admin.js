(function( $ ) {
	'use strict';

	var WSM = {

		pollTimer:    null,
		pollInterval: 2500,
		jobId:        null,
		logCount:     0,
		startedAt:    null,

		/* ============================================================= */
		/*  INIT                                                          */
		/* ============================================================= */

		init: function() {
			$( '#wsm-test-conn' ).on( 'click', function() { WSM.testConnection(); } );
			$( '#wsm-start' ).on( 'click', function() { WSM.startMigration(); } );
			$( '#wsm-cancel' ).on( 'click', function() { WSM.cancelMigration(); } );
			$( '#wsm-new-migration, #wsm-error-reset' ).on( 'click', function() { WSM.resetToForm(); } );

			$( '#wsm-host, #wsm-user, #wsm-pass, #wsm-app' ).on( 'input', function() {
				WSM.validateForm();
				WSM.updatePathPreview();
			} );

			this.checkSshpass();

			if ( sdem_data.active_job ) {
				this.jobId = sdem_data.active_job;
				this.startPolling();
			}
		},

		/* ============================================================= */
		/*  CHECK SSHPASS                                                 */
		/* ============================================================= */

		checkSshpass: function() {
			$.post( sdem_data.ajax_url, {
				action: 'sdem_check_sshpass',
				nonce:  sdem_data.nonce,
			})
			.done( function( r ) {
				if ( r.success ) {
					var d   = r.data;
					var cls = d.status === 'ok' ? 'wsm-ok' : ( d.status === 'warn' ? 'wsm-warn' : 'wsm-fail' );
					$( '#wsm-sshpass-status' ).html( d.message ).addClass( cls );
				} else {
					$( '#wsm-sshpass-status' ).html( '✗ ' + sdem_data.i18n.network_error ).addClass( 'wsm-fail' );
				}
			})
			.fail( function() {
				$( '#wsm-sshpass-status' ).html( '✗ ' + sdem_data.i18n.network_error ).addClass( 'wsm-fail' );
			});
		},

		/* ============================================================= */
		/*  PATH PREVIEW                                                  */
		/* ============================================================= */

		updatePathPreview: function() {
			var user = $( '#wsm-user' ).val().trim() || '&lt;username&gt;';
			var app  = $( '#wsm-app' ).val().trim()  || '&lt;appname&gt;';
			$( '#wsm-target-path-preview' ).html(
				sdem_data.i18n.target_path_label + ' <code>/home/' + this.escapeHtml( user ) +
				'/webapps/<strong>' + this.escapeHtml( app ) + '</strong>/</code>'
			);
		},

		/* ============================================================= */
		/*  VALIDATION                                                    */
		/* ============================================================= */

		validateForm: function() {
			var host = $( '#wsm-host' ).val().trim();
			var user = $( '#wsm-user' ).val().trim();
			var pass = $( '#wsm-pass' ).val().trim();
			var app  = $( '#wsm-app' ).val().trim();
			$( '#wsm-start' ).prop( 'disabled', ! ( host && user && pass && app ) );
		},

		/* ============================================================= */
		/*  TEST CONNECTION                                               */
		/* ============================================================= */

		testConnection: function() {
			var $btn    = $( '#wsm-test-conn' );
			var $status = $( '#wsm-conn-status' );
			var i18n    = sdem_data.i18n;

			$btn.prop( 'disabled', true ).text( i18n.testing );
			$status.html( '' ).removeClass( 'wsm-ok wsm-fail wsm-warn' );

			$.post( sdem_data.ajax_url, {
				action: 'sdem_test_connection',
				nonce:  sdem_data.nonce,
				host:   $( '#wsm-host' ).val().trim(),
				user:   $( '#wsm-user' ).val().trim(),
				pass:   $( '#wsm-pass' ).val(),
				app:    $( '#wsm-app' ).val().trim(),
			})
			.done( function( r ) {
				if ( r.success ) {
					var cls  = ( r.data.has_wpcli && r.data.has_wpconfig ) ? 'wsm-ok' : 'wsm-warn';
					var html = r.data.message;
					if ( r.data.target_info ) {
						html += '<br>' + r.data.target_info;
					}
					$status.html( html ).addClass( cls );

					// Show manual DB card if wp-config.php is missing
					if ( r.data.has_wpconfig === false ) {
						$( '#wsm-manual-db-card' ).slideDown();
					} else {
						$( '#wsm-manual-db-card' ).slideUp();
					}
				} else {
					$status.html( r.data ).addClass( 'wsm-fail' );
				}
			})
			.fail( function() {
				$status.html( i18n.network_error ).addClass( 'wsm-fail' );
			})
			.always( function() {
				$btn.prop( 'disabled', false ).text( i18n.test_connection );
			});
		},

		/* ============================================================= */
		/*  START MIGRATION                                               */
		/* ============================================================= */

		startMigration: function() {
			var i18n = sdem_data.i18n;

			if ( ! confirm( i18n.confirm_start ) ) {
				return;
			}

			if ( 'Notification' in window && Notification.permission === 'default' ) {
				Notification.requestPermission();
			}

			var $btn = $( '#wsm-start' );
			$btn.prop( 'disabled', true ).text( i18n.starting );

			var postData = {
				action: 'sdem_start_migration',
				nonce:  sdem_data.nonce,
				host:   $( '#wsm-host' ).val().trim(),
				user:   $( '#wsm-user' ).val().trim(),
				pass:   $( '#wsm-pass' ).val(),
				app:    $( '#wsm-app' ).val().trim(),
			};

			// Include manual DB fields if the card is visible
			if ( $( '#wsm-manual-db-card' ).is( ':visible' ) ) {
				postData.manual_db_name    = $( '#wsm-manual-db-name' ).val().trim();
				postData.manual_db_user    = $( '#wsm-manual-db-user' ).val().trim();
				postData.manual_db_pass    = $( '#wsm-manual-db-pass' ).val();
				postData.manual_db_host    = $( '#wsm-manual-db-host' ).val().trim() || 'localhost';
				postData.manual_db_prefix  = $( '#wsm-manual-db-prefix' ).val().trim() || 'wp_';
				postData.manual_target_url = $( '#wsm-manual-target-url' ).val().trim();

				if ( ! postData.manual_db_name || ! postData.manual_db_user ) {
					alert( i18n.fill_db_name );
					$btn.prop( 'disabled', false ).text( i18n.start_migration );
					return;
				}
				if ( ! postData.manual_target_url ) {
					alert( i18n.fill_target_url );
					$btn.prop( 'disabled', false ).text( i18n.start_migration );
					return;
				}
			}

			$.post( sdem_data.ajax_url, postData )
			.done( function( r ) {
				if ( r.success ) {
					WSM.jobId     = r.data.job_id;
					WSM.startedAt = new Date();
					WSM.showProgressSection();
					WSM.startPolling();
				} else {
					alert( i18n.error_prefix + ' ' + r.data );
					$btn.prop( 'disabled', false ).text( i18n.start_migration );
				}
			})
			.fail( function() {
				alert( i18n.network_error );
				$btn.prop( 'disabled', false ).text( i18n.start_migration );
			});
		},

		/* ============================================================= */
		/*  PROGRESS DISPLAY                                              */
		/* ============================================================= */

		showProgressSection: function() {
			$( '#wsm-form-section' ).hide();
			$( '#wsm-progress-section' ).show();
			this.buildStepsDisplay();
		},

		buildStepsDisplay: function() {
			var $container = $( '#wsm-steps-display' ).empty();
			var stepKeys   = Object.keys( sdem_data.steps );

			var html = '<div class="wsm-steps-grid">';
			$.each( stepKeys, function( _, key ) {
				html +=
					'<div class="wsm-step" data-step="' + key + '">' +
						'<span class="wsm-step-icon wsm-step-pending">&#9675;</span>' +
						'<span class="wsm-step-label">' + sdem_data.steps[ key ] + '</span>' +
						'<span class="wsm-step-msg"></span>' +
					'</div>';
			});
			html += '</div>';
			$container.html( html );
		},

		/* ============================================================= */
		/*  POLLING                                                       */
		/* ============================================================= */

		startPolling: function() {
			this.logCount = 0;
			this.pollProgress();
			this.pollTimer = setInterval( function() {
				WSM.pollProgress();
			}, this.pollInterval );
		},

		stopPolling: function() {
			if ( this.pollTimer ) {
				clearInterval( this.pollTimer );
				this.pollTimer = null;
			}
		},

		pollProgress: function() {
			if ( ! this.jobId ) return;

			$.post( sdem_data.ajax_url, {
				action: 'sdem_check_progress',
				nonce:  sdem_data.nonce,
				job_id: this.jobId,
			})
			.done( function( r ) {
				if ( r.success ) {
					WSM.updateProgressUI( r.data );
				}
			});
		},

		/* ============================================================= */
		/*  UPDATE UI                                                     */
		/* ============================================================= */

		updateProgressUI: function( data ) {
			var i18n = sdem_data.i18n;

			if ( ! $( '.wsm-step' ).length ) {
				this.buildStepsDisplay();
			}

			var pct = data.overall_progress || 0;
			$( '#wsm-overall-fill' ).css( 'width', pct + '%' );
			$( '#wsm-overall-text' ).text( pct + '% ' + i18n.completed );

			if ( this.startedAt ) {
				var elapsed = Math.round( ( new Date() - this.startedAt ) / 1000 );
				$( '#wsm-overall-time' ).text( i18n.elapsed + ' ' + this.formatDuration( elapsed ) );
			}

			if ( data.steps ) {
				$.each( data.steps, function( stepKey, step ) {
					var $step = $( '.wsm-step[data-step="' + stepKey + '"]' );
					var $icon = $step.find( '.wsm-step-icon' );
					var $msg  = $step.find( '.wsm-step-msg' );

					$icon.removeClass( 'wsm-step-pending wsm-step-running wsm-step-completed wsm-step-failed' );

					switch ( step.status ) {
						case 'running':
							$icon.addClass( 'wsm-step-running' ).html( '&#9673;' );
							$step.addClass( 'wsm-step-active' );
							break;
						case 'completed':
							$icon.addClass( 'wsm-step-completed' ).html( '&#10003;' );
							$step.removeClass( 'wsm-step-active' );
							break;
						case 'skipped':
							$icon.addClass( 'wsm-step-completed' ).html( '&#8594;' );
							$step.removeClass( 'wsm-step-active' );
							break;
						case 'failed':
							$icon.addClass( 'wsm-step-failed' ).html( '&#10007;' );
							$step.removeClass( 'wsm-step-active' );
							break;
						default:
							$icon.addClass( 'wsm-step-pending' ).html( '&#9675;' );
					}

					$msg.text( step.message || '' );
				});
			}

			if ( data.log && data.log.length > this.logCount ) {
				var $log = $( '#wsm-log' );
				for ( var i = this.logCount; i < data.log.length; i++ ) {
					var entry = data.log[ i ];
					$log.append(
						'<div class="wsm-log-entry wsm-log-' + entry.level + '">' +
							'<span class="wsm-log-time">[' + entry.time + ']</span> ' +
							'<span class="wsm-log-msg">' + this.escapeHtml( entry.message ) + '</span>' +
						'</div>'
					);
				}
				this.logCount = data.log.length;
				$log.scrollTop( $log[0].scrollHeight );
			}

			if ( data.status === 'completed' ) {
				this.stopPolling();
				this.showCompletion( data );
			} else if ( data.status === 'failed' ) {
				this.stopPolling();
				this.showError( data );
			} else if ( data.status === 'cancelled' ) {
				this.stopPolling();
				this.resetToForm();
			}
		},

		/* ============================================================= */
		/*  COMPLETED / ERROR                                             */
		/* ============================================================= */

		showCompletion: function( data ) {
			var i18n    = sdem_data.i18n;
			$( '#wsm-progress-section' ).hide();
			$( '#wsm-completion' ).show();

			var summary   = data.summary  || {};
			var targetUrl = data.target_url       || '';
			var adminUrl  = data.target_admin_url || '';

			var html = '<p>' + this.escapeHtml( summary.source_url || '' ) +
			           ' &rarr; <code>' + this.escapeHtml( summary.target_path || '' ) + '</code></p>';

			if ( targetUrl ) {
				html += '<div class="wsm-completion-links">' +
				        '<a href="' + this.escapeHtml( targetUrl ) + '" target="_blank" class="button button-primary">' + i18n.open_site + '</a> ' +
				        '<a href="' + this.escapeHtml( adminUrl )  + '" target="_blank" class="button">' + i18n.wp_admin + '</a>' +
				        '</div>';
			}

			$( '#wsm-completion-summary' ).html( html );
			this.sendNotification( i18n.migration_complete, summary.source_url || '' );
			this.playSound();
		},

		showError: function( data ) {
			var i18n = sdem_data.i18n;
			$( '#wsm-progress-section' ).hide();
			$( '#wsm-error' ).show();

			var msg = i18n.unknown_error;
			if ( data.log && data.log.length ) {
				msg = data.log[ data.log.length - 1 ].message;
			}
			$( '#wsm-error-message' ).text( msg );
			this.sendNotification( i18n.migration_failed, msg );
		},

		/* ============================================================= */
		/*  CANCEL / RESET                                                */
		/* ============================================================= */

		cancelMigration: function() {
			var i18n = sdem_data.i18n;
			if ( ! confirm( i18n.confirm_cancel ) ) {
				return;
			}

			var $btn = $( '#wsm-cancel' );
			$btn.prop( 'disabled', true ).text( i18n.cancelling );

			$.post( sdem_data.ajax_url, {
				action: 'sdem_cancel_migration',
				nonce:  sdem_data.nonce,
			})
			.done( function() {
				WSM.stopPolling();
				WSM.resetToForm();
			})
			.fail( function() {
				$btn.prop( 'disabled', false ).text( i18n.cancel );
			});
		},

		resetToForm: function() {
			var self = this;
			var i18n = sdem_data.i18n;

			$.post( sdem_data.ajax_url, {
				action: 'sdem_reset_migration',
				nonce:  sdem_data.nonce,
			})
			.always( function() {
				self.jobId     = null;
				self.logCount  = 0;
				self.startedAt = null;
				self.stopPolling();

				$( '#wsm-progress-section, #wsm-completion, #wsm-error' ).hide();
				$( '#wsm-form-section' ).show();
				$( '#wsm-start' ).prop( 'disabled', false ).text( i18n.start_migration );
				$( '#wsm-cancel' ).prop( 'disabled', false ).text( i18n.cancel );
				$( '#wsm-log' ).empty();
				$( '#wsm-steps-display' ).empty();
				$( '#wsm-overall-fill' ).css( 'width', '0%' );
				$( '#wsm-overall-text' ).text( i18n.starting );
				$( '#wsm-overall-time' ).text( '' );
			});
		},

		/* ============================================================= */
		/*  NOTIFICATIONS                                                 */
		/* ============================================================= */

		sendNotification: function( title, body ) {
			if ( ! ( 'Notification' in window ) ) return;
			if ( Notification.permission !== 'granted' ) return;
			try {
				new Notification( title, { body: body } );
			} catch ( e ) {}
		},

		playSound: function() {
			try {
				var ctx  = new ( window.AudioContext || window.webkitAudioContext )();
				var osc  = ctx.createOscillator();
				var gain = ctx.createGain();
				osc.connect( gain );
				gain.connect( ctx.destination );
				osc.frequency.value = 800;
				gain.gain.value     = 0.2;
				osc.start();
				gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + 0.6 );
				osc.stop( ctx.currentTime + 0.6 );
			} catch ( e ) {}
		},

		/* ============================================================= */
		/*  HELPERS                                                       */
		/* ============================================================= */

		escapeHtml: function( text ) {
			var div = document.createElement( 'div' );
			div.appendChild( document.createTextNode( text ) );
			return div.innerHTML;
		},

		formatDuration: function( seconds ) {
			var h     = Math.floor( seconds / 3600 );
			var m     = Math.floor( ( seconds % 3600 ) / 60 );
			var s     = seconds % 60;
			var i18n  = sdem_data.i18n;
			var parts = [];
			if ( h > 0 ) parts.push( h + i18n.hour_abbr );
			if ( m > 0 ) parts.push( m + i18n.min_abbr );
			parts.push( s + i18n.sec_abbr );
			return parts.join( ' ' );
		},
	};

	$( function() {
		WSM.init();
	});

})( jQuery );
