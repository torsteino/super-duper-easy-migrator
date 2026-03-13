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

			// Credentials changed — hide path picker so user must re-test
			$( '#wsm-host, #wsm-user, #wsm-pass' ).on( 'input', function() {
				$( '#wsm-path-card' ).slideUp();
				$( '#wsm-manual-db-card' ).slideUp();
				$( '#wsm-start' ).prop( 'disabled', true );
			} );

			$( '#wsm-custom-path' ).on( 'input', function() {
				WSM.validateForm();
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
		/*  VALIDATION                                                    */
		/* ============================================================= */

		validateForm: function() {
			var host = $( '#wsm-host' ).val().trim();
			var user = $( '#wsm-user' ).val().trim();
			var pass = $( '#wsm-pass' ).val().trim();
			var path = this.getSelectedPath();
			$( '#wsm-start' ).prop( 'disabled', ! ( host && user && pass && path ) );
		},

		getSelectedPath: function() {
			var checked = $( 'input[name="wsm-target-path"]:checked' );
			if ( ! checked.length ) return '';
			var val = checked.val();
			if ( val === '__custom__' ) {
				return $( '#wsm-custom-path' ).val().trim();
			}
			return val;
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
			$( '#wsm-path-card' ).slideUp();
			$( '#wsm-manual-db-card' ).slideUp();
			$( '#wsm-start' ).prop( 'disabled', true );

			$.post( sdem_data.ajax_url, {
				action: 'sdem_test_connection',
				nonce:  sdem_data.nonce,
				host:   $( '#wsm-host' ).val().trim(),
				user:   $( '#wsm-user' ).val().trim(),
				pass:   $( '#wsm-pass' ).val(),
			})
			.done( function( r ) {
				if ( r.success ) {
					var cls = r.data.has_wpcli ? 'wsm-ok' : 'wsm-warn';
					$status.html( r.data.message ).addClass( cls );
					WSM.buildPathPicker( r.data.detected_paths, r.data.home_dir );
					$( '#wsm-path-card' ).slideDown();
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
		/*  BUILD PATH PICKER                                             */
		/* ============================================================= */

		buildPathPicker: function( paths, homeDir ) {
			var i18n       = sdem_data.i18n;
			var $container = $( '#wsm-path-options' ).empty();
			$( '#wsm-custom-path-row' ).hide();
			$( '#wsm-custom-path' ).val( '' );

			var html = '<div class="wsm-path-list">';

			if ( paths && paths.length ) {
				$.each( paths, function( idx, item ) {
					var badge = item.has_wp
						? ' <span class="wsm-wp-badge">&#10003; ' + i18n.wp_found + '</span>'
						: '';
					html +=
						'<label class="wsm-path-option">' +
							'<input type="radio" name="wsm-target-path" value="' + WSM.escapeAttr( item.path ) + '"> ' +
							'<code>' + WSM.escapeHtml( item.path ) + '</code>' + badge +
						'</label>';
				});
			} else {
				html += '<p class="description wsm-no-paths">' + i18n.no_folders_found + '</p>';
			}

			html +=
				'<label class="wsm-path-option wsm-path-custom">' +
					'<input type="radio" name="wsm-target-path" value="__custom__"> ' +
					'<em>' + i18n.enter_path + '</em>' +
				'</label>';

			html += '</div>';
			$container.html( html );

			// Show/hide custom row and manual DB card on radio change
			$container.on( 'change', 'input[name="wsm-target-path"]', function() {
				var val = $( this ).val();

				if ( val === '__custom__' ) {
					$( '#wsm-custom-path-row' ).slideDown();
					$( '#wsm-manual-db-card' ).slideDown();
				} else {
					$( '#wsm-custom-path-row' ).slideUp();
					$( '#wsm-custom-path' ).val( '' );

					// Show manual DB only if selected path has no wp-config
					var selectedItem = null;
					$.each( paths || [], function( _, item ) {
						if ( item.path === val ) { selectedItem = item; }
					});
					if ( selectedItem && ! selectedItem.has_wp ) {
						$( '#wsm-manual-db-card' ).slideDown();
					} else {
						$( '#wsm-manual-db-card' ).slideUp();
					}
				}

				WSM.validateForm();
			});

			// Auto-select if only one detected path
			if ( paths && paths.length === 1 ) {
				$container.find( 'input[name="wsm-target-path"]' ).first().prop( 'checked', true ).trigger( 'change' );
			}
		},

		/* ============================================================= */
		/*  START MIGRATION                                               */
		/* ============================================================= */

		startMigration: function() {
			var i18n       = sdem_data.i18n;
			var targetPath = this.getSelectedPath();

			if ( ! targetPath ) {
				alert( i18n.fill_target_path );
				return;
			}
			if ( targetPath.indexOf( '..' ) !== -1 || targetPath.charAt( 0 ) !== '/' ) {
				alert( i18n.invalid_target_path );
				return;
			}
			if ( ! confirm( i18n.confirm_start ) ) {
				return;
			}

			if ( 'Notification' in window && Notification.permission === 'default' ) {
				Notification.requestPermission();
			}

			var $btn = $( '#wsm-start' );
			$btn.prop( 'disabled', true ).text( i18n.starting );

			var postData = {
				action:      'sdem_start_migration',
				nonce:       sdem_data.nonce,
				host:        $( '#wsm-host' ).val().trim(),
				user:        $( '#wsm-user' ).val().trim(),
				pass:        $( '#wsm-pass' ).val(),
				target_path: targetPath,
			};

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
			var html = '<div class="wsm-steps-grid">';
			$.each( sdem_data.steps, function( key, label ) {
				html +=
					'<div class="wsm-step" data-step="' + key + '">' +
						'<span class="wsm-step-icon wsm-step-pending">&#9675;</span>' +
						'<span class="wsm-step-label">' + label + '</span>' +
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
			this.pollTimer = setInterval( function() { WSM.pollProgress(); }, this.pollInterval );
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
				if ( r.success ) { WSM.updateProgressUI( r.data ); }
			});
		},

		/* ============================================================= */
		/*  UPDATE UI                                                     */
		/* ============================================================= */

		updateProgressUI: function( data ) {
			var i18n = sdem_data.i18n;

			if ( ! $( '.wsm-step' ).length ) { this.buildStepsDisplay(); }

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
						case 'running':    $icon.addClass( 'wsm-step-running'   ).html( '&#9673;' ); $step.addClass( 'wsm-step-active' );    break;
						case 'completed':  $icon.addClass( 'wsm-step-completed' ).html( '&#10003;' ); $step.removeClass( 'wsm-step-active' ); break;
						case 'skipped':    $icon.addClass( 'wsm-step-completed' ).html( '&#8594;' );  $step.removeClass( 'wsm-step-active' ); break;
						case 'failed':     $icon.addClass( 'wsm-step-failed'    ).html( '&#10007;' ); $step.removeClass( 'wsm-step-active' ); break;
						default:           $icon.addClass( 'wsm-step-pending'   ).html( '&#9675;' );
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

			if ( data.status === 'completed' )       { this.stopPolling(); this.showCompletion( data ); }
			else if ( data.status === 'failed' )      { this.stopPolling(); this.showError( data ); }
			else if ( data.status === 'cancelled' )   { this.stopPolling(); this.resetToForm(); }
		},

		/* ============================================================= */
		/*  COMPLETED / ERROR                                             */
		/* ============================================================= */

		showCompletion: function( data ) {
			var i18n  = sdem_data.i18n;
			$( '#wsm-progress-section' ).hide();
			$( '#wsm-completion' ).show();

			var summary  = data.summary  || {};
			var html = '<p>' + this.escapeHtml( summary.source_url || '' ) +
					   ' &rarr; <code>' + this.escapeHtml( summary.target_path || '' ) + '</code></p>';

			if ( data.target_url ) {
				html += '<div class="wsm-completion-links">' +
						'<a href="' + this.escapeHtml( data.target_url )       + '" target="_blank" class="button button-primary">' + i18n.open_site + '</a> ' +
						'<a href="' + this.escapeHtml( data.target_admin_url ) + '" target="_blank" class="button">' + i18n.wp_admin + '</a>' +
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
			if ( data.log && data.log.length ) { msg = data.log[ data.log.length - 1 ].message; }
			$( '#wsm-error-message' ).text( msg );
			this.sendNotification( i18n.migration_failed, msg );
		},

		/* ============================================================= */
		/*  CANCEL / RESET                                                */
		/* ============================================================= */

		cancelMigration: function() {
			var i18n = sdem_data.i18n;
			if ( ! confirm( i18n.confirm_cancel ) ) { return; }
			var $btn = $( '#wsm-cancel' );
			$btn.prop( 'disabled', true ).text( i18n.cancelling );
			$.post( sdem_data.ajax_url, { action: 'sdem_cancel_migration', nonce: sdem_data.nonce } )
			.done( function() { WSM.stopPolling(); WSM.resetToForm(); } )
			.fail( function() { $btn.prop( 'disabled', false ).text( i18n.cancel ); } );
		},

		resetToForm: function() {
			var i18n = sdem_data.i18n;
			$.post( sdem_data.ajax_url, { action: 'sdem_reset_migration', nonce: sdem_data.nonce } )
			.always( function() {
				WSM.jobId     = null;
				WSM.logCount  = 0;
				WSM.startedAt = null;
				WSM.stopPolling();
				$( '#wsm-progress-section, #wsm-completion, #wsm-error' ).hide();
				$( '#wsm-form-section' ).show();
				$( '#wsm-start' ).prop( 'disabled', true ).text( i18n.start_migration );
				$( '#wsm-cancel' ).prop( 'disabled', false ).text( i18n.cancel );
				$( '#wsm-log' ).empty();
				$( '#wsm-steps-display' ).empty();
				$( '#wsm-overall-fill' ).css( 'width', '0%' );
				$( '#wsm-overall-text' ).text( i18n.starting );
				$( '#wsm-overall-time' ).text( '' );
				$( '#wsm-path-card' ).hide();
				$( '#wsm-path-options' ).empty();
				$( '#wsm-manual-db-card' ).hide();
				$( '#wsm-conn-status' ).html( '' ).removeClass( 'wsm-ok wsm-fail wsm-warn' );
			});
		},

		/* ============================================================= */
		/*  NOTIFICATIONS / SOUND                                         */
		/* ============================================================= */

		sendNotification: function( title, body ) {
			if ( ! ( 'Notification' in window ) || Notification.permission !== 'granted' ) { return; }
			try { new Notification( title, { body: body } ); } catch ( e ) {}
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

		escapeAttr: function( text ) {
			return String( text )
				.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
				.replace( /'/g, '&#39;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
		},

		formatDuration: function( seconds ) {
			var h = Math.floor( seconds / 3600 );
			var m = Math.floor( ( seconds % 3600 ) / 60 );
			var s = seconds % 60;
			var i = sdem_data.i18n;
			var p = [];
			if ( h > 0 ) p.push( h + i.hour_abbr );
			if ( m > 0 ) p.push( m + i.min_abbr );
			p.push( s + i.sec_abbr );
			return p.join( ' ' );
		},
	};

	$( function() { WSM.init(); } );

})( jQuery );
