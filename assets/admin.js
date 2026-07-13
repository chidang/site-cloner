(function ($) {
	'use strict';

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	var pkg = null;
	var sdBuildPass = '';
	var sdBuildIps = '';

	function post(action, data) {
		return $.post(SD.ajax, $.extend({ action: action, nonce: SD.nonce }, data || {}));
	}

	function setBar(step, pct) {
		$('.sd-step[data-step="' + step + '"] .sd-bar i').css('width', pct + '%');
	}
	function status(msg) { $('.sd-status').text(msg); }
	function fail(msg) {
		$('#sd-progress').hide();
		$('#sd-error').show().find('p').text(msg || __('An error occurred.', 'site-cloner'));
		$('#sd-build').prop('disabled', false);
		$('#sd-build-spin').hide();
	}

	// Loop a step (db/files) until it's done.
	function loopStep(action, step, onDone) {
		post(action, { package: pkg })
			.done(function (res) {
				if (!res.success) { return fail(res.data && res.data.message); }
				setBar(step, res.data.progress || 0);
				if (res.data.method === 'mysqldump') { status(__('Database: using mysqldump (fast)…', 'site-cloner')); }
				if (res.data.done) { setBar(step, 100); onDone(); }
				else { loopStep(action, step, onDone); }
			})
			.fail(function () { fail(__('Server connection error.', 'site-cloner')); });
	}

	$('#sd-build').on('click', function () {
		sdBuildPass = $('#sd-build-pass').length ? $('#sd-build-pass').val() : '';
		sdBuildIps = $('#sd-build-ips').length ? $('#sd-build-ips').val() : '';
		$(this).prop('disabled', true);
		$('#sd-build-spin').show();
		$('#sd-error').hide();
		$('#sd-result').hide();
		$('#sd-progress').show();
		setBar('db', 0); setBar('files', 0);
		status(__('Scanning site…', 'site-cloner'));

		post('sd_build_init')
			.done(function (res) {
				if (!res.success) { return fail(res.data && res.data.message); }
				pkg = res.data.package;
				status(sprintf(
					/* translators: %d: number of database tables. */
					_n('Exporting database (%d table)…', 'Exporting database (%d tables)…', res.data.tables, 'site-cloner'),
					res.data.tables
				));

				loopStep('sd_build_database', 'db', function () {
					status(sprintf(
						/* translators: %d: number of files. */
						_n('Compressing files (%d file)…', 'Compressing files (%d files)…', res.data.file_total, 'site-cloner'),
						res.data.file_total
					));
					loopStep('sd_build_files', 'files', function () {
						status(__('Finalizing…', 'site-cloner'));
						post('sd_build_finalize', { package: pkg, password: sdBuildPass, allow_ips: sdBuildIps })
							.done(function (r) {
								if (!r.success) { return fail(r.data && r.data.message); }
								renderResult(r.data);
							})
							.fail(function () { fail(__('Error while finalizing.', 'site-cloner')); });
					});
				});
			})
			.fail(function () { fail(__('Server connection error.', 'site-cloner')); });
	});

	function renderResult(data) {
		var files = data.files || {};
		$('#sd-progress').hide();
		if (data.pull_link) {
			$('#sd-pull-link').val(data.pull_link);
		}
		var $box = $('#sd-result .sd-pull-box');
		$box.find('.sd-pass-note').remove();
		if (data.has_pass) {
			$box.append($('<p class="sd-pass-note" style="color:#b26b00;margin-top:8px;"></p>')
				.text('🔒 ' + __('The package is password-protected. Send the password to the importer through a separate channel (don\'t paste it alongside the link).', 'site-cloner')));
		}
		var $ul = $('#sd-result .sd-files').empty();
		if (files.installer) { $ul.append(link(files.installer, 'installer.php')); }
		(files.archives || []).forEach(function (url) {
			$ul.append(link(url, url.split('/').pop()));
		});
		if (files.database) { $ul.append(link(files.database, 'database.sql')); }
		if (files.manifest) { $ul.append(link(files.manifest, 'manifest.json')); }
		$('#sd-result').show();
		$('#sd-build').prop('disabled', false);
		$('#sd-build-spin').hide();
	}
	function link(url, label) {
		return '<li><a href="' + url + '" download="' + label + '">⬇ ' + label + '</a></li>';
	}

	$(document).on('click', '#sd-pull-copy', function () {
		var el = document.getElementById('sd-pull-link');
		el.select(); el.setSelectionRange(0, 99999);
		try { document.execCommand('copy'); $(this).text(__('Copied!', 'site-cloner')); } catch (e) {}
	});

	// Trigger every download link one after another (staggered so the browser
	// doesn't drop the queued downloads), so the user gets all parts in a single
	// click instead of clicking each file.
	function downloadSeq(links, $btn) {
		if (!links.length) { return; }
		var origText = $btn.text();
		$btn.prop('disabled', true);
		var i = 0;
		(function next() {
			if (i >= links.length) {
				$btn.prop('disabled', false).text(origText);
				return;
			}
			var src = links[i];
			i++;
			$btn.text(sprintf(
				/* translators: 1: current file number, 2: total number of files. */
				__('Downloading %1$d/%2$d…', 'site-cloner'), i, links.length
			));
			var a = document.createElement('a');
			a.href = src.href;
			a.download = src.getAttribute('download') || '';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(next, 800);
		})();
	}

	$(document).on('click', '#sd-dl-all', function () {
		downloadSeq($('#sd-result .sd-files a[download]').toArray(), $(this));
	});

	/* ---------- Existing packages (re-shown after reload) ---------- */

	$(document).on('click', '.sd-pkg-dlall', function () {
		downloadSeq($(this).closest('.sd-pkg').find('.sd-files a[download]').toArray(), $(this));
	});

	$(document).on('click', '.sd-pkg-link', function () {
		var $pkg = $(this).closest('.sd-pkg');
		var $btn = $(this).prop('disabled', true);
		post('sd_regen_link', { package: $pkg.data('id') })
			.done(function (r) {
				$btn.prop('disabled', false);
				if (r && r.success) {
					$pkg.find('.sd-pkg-linkrow').show().find('.sd-pkg-linkinput').val(r.data.pull_link);
					$pkg.find('.sd-pkg-linknote').show();
				} else {
					window.alert((r && r.data && r.data.message) || __('An error occurred.', 'site-cloner'));
				}
			})
			.fail(function () {
				$btn.prop('disabled', false);
				window.alert(__('Server connection error.', 'site-cloner'));
			});
	});

	$(document).on('click', '.sd-pkg-linkcopy', function () {
		var el = $(this).closest('.sd-pkg-linkrow').find('.sd-pkg-linkinput')[0];
		if (!el) { return; }
		el.select(); el.setSelectionRange(0, 99999);
		try { document.execCommand('copy'); $(this).text(__('Copied!', 'site-cloner')); } catch (e) {}
	});

	$(document).on('click', '.sd-pkg-delete', function () {
		if (!window.confirm(__('Delete this package permanently? Its files will no longer be available for download.', 'site-cloner'))) {
			return;
		}
		var $pkg = $(this).closest('.sd-pkg');
		var $btn = $(this).prop('disabled', true);
		post('sd_delete_pkg', { package: $pkg.data('id') })
			.done(function (r) {
				if (r && r.success) {
					$pkg.slideUp(200, function () { $pkg.remove(); });
				} else {
					$btn.prop('disabled', false);
					window.alert((r && r.data && r.data.message) || __('An error occurred.', 'site-cloner'));
				}
			})
			.fail(function () {
				$btn.prop('disabled', false);
				window.alert(__('Server connection error.', 'site-cloner'));
			});
	});

	/* ---------------- Import (staging) ---------------- */

	var impPkg = null;
	var impRunner = null;
	var impToken = null;
	var impParts = [];
	var impFilesTotal = 0;
	var impRunnerOk = false;
	var impInsecure = 0;
	var impPassword = '';
	var impLink = '';

	function impBar(step, pct) {
		$('#sd-imp-progress .sd-step[data-step="' + step + '"] .sd-bar i').css('width', pct + '%');
	}
	function impStatus(msg) { $('#sd-imp-progress .sd-status').text(msg); }
	function impFail(msg) {
		$('#sd-imp-progress').hide();
		$('#sd-imp-error').show().find('p').text(msg || __('An error occurred.', 'site-cloner'));
		$('#sd-run-import').prop('disabled', false);
	}

	$('#sd-confirm').on('change', function () {
		var ok = $(this).is(':checked') && $('input[name=sd_pkg]:checked').length > 0;
		$('#sd-run-import').prop('disabled', !ok);
	});
	$(document).on('change', 'input[name=sd_pkg]', function () {
		var ok = $('#sd-confirm').is(':checked');
		$('#sd-run-import').prop('disabled', !ok);
	});

	function impStart() {
		$('#sd-imp-panel').show();
		$('#sd-imp-error').hide();
		$('#sd-imp-result').hide();
		$('#sd-imp-progress').show();
		impBar('download', 0); impBar('extract', 0); impBar('db', 0);
	}

	// --- Select a package already present on staging ---
	$('#sd-run-import').on('click', function () {
		impPkg = $('input[name=sd_pkg]:checked').val();
		if (!impPkg) { return; }
		$(this).prop('disabled', true);
		impStart();
		$('.sd-step[data-step="download"]').hide();
		impStatus(__('Preparing…', 'site-cloner'));
		startMigrate();
	});

	// --- Pull from production via link ---
	function pullReady() {
		var has = $.trim($('#sd-pull-input').val()) !== '';
		$('#sd-pull-test').prop('disabled', !has);
		$('#sd-pull-start').prop('disabled', !($('#sd-pull-confirm').is(':checked') && has));
	}
	$('#sd-pull-confirm').on('change', pullReady);
	$('#sd-pull-input').on('input', pullReady);

	$('#sd-pull-test').on('click', function () {
		var link = $.trim($('#sd-pull-input').val());
		if (!link) { return; }
		var $btn = $(this).prop('disabled', true).text(__('Testing…', 'site-cloner'));
		var $out = $('#sd-pull-testresult').show()
			.attr('class', 'sd-testresult sd-test-info').text(__('Contacting production…', 'site-cloner'));

		post('sd_pull_test', { link: link, password: $('#sd-pull-password').val() })
			.done(function (res) {
				$btn.prop('disabled', false).text(__('Test connection', 'site-cloner'));
				if (!res.success) {
					return $out.attr('class', 'sd-testresult sd-test-fail').text('✖ ' + (res.data && res.data.message));
				}
				var d = res.data;
				if (d.ok) {
					if (d.insecure_needed) { $('#sd-pull-insecure').prop('checked', true); }
					$out.attr('class', 'sd-testresult sd-test-ok')
						.text('✔ ' + sprintf(
							/* translators: 1: status message from production, 2: number of files, 3: human-readable size. */
							_n('%1$s — %2$d file, %3$s.', '%1$s — %2$d files, %3$s.', d.files, 'site-cloner'),
							d.message, d.files, d.size
						));
				} else {
					$out.attr('class', 'sd-testresult sd-test-fail').text('✖ ' + d.message);
				}
			})
			.fail(function () {
				$btn.prop('disabled', false).text(__('Test connection', 'site-cloner'));
				$out.attr('class', 'sd-testresult sd-test-fail').text('✖ ' + __('Connection error to staging.', 'site-cloner'));
			});
	});

	$('#sd-pull-start').on('click', function () {
		var link = $.trim($('#sd-pull-input').val());
		if (!link) { return; }
		impInsecure = $('#sd-pull-insecure').is(':checked') ? 1 : 0;
		impPassword = $('#sd-pull-password').val();
		impLink = link;
		$(this).prop('disabled', true);
		impStart();
		$('.sd-step[data-step="download"]').show();
		impStatus(__('Fetching package info from production…', 'site-cloner'));

		post('sd_pull_info', { link: link, insecure: impInsecure, password: impPassword })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				impPkg = res.data.id;
				var files = res.data.files || [];
				var totalBytes = files.reduce(function (s, f) { return s + (f.size || 0); }, 0);
				impStatus(sprintf(
					/* translators: 1: number of files, 2: human-readable total size. */
					_n('Downloading %1$d file (%2$s)…', 'Downloading %1$d files (%2$s)…', files.length, 'site-cloner'),
					files.length, bytes(totalBytes)
				));
				downloadAll(link, files, 0, 0, totalBytes, function () {
					impBar('download', 100);
					// Delete the package (including the DB dump) from the source server — best-effort.
					post('sd_pull_cleanup', { link: link, insecure: impInsecure, password: impPassword });
					startMigrate();
				});
			})
			.fail(function () { impFail(__('Connection error to staging.', 'site-cloner')); });
	});

	function downloadAll(link, files, idx, done, total, onDone) {
		if (idx >= files.length) { return onDone(); }
		var f = files[idx];
		downloadFile(link, f.name, 0, f.size, done, total, function () {
			downloadAll(link, files, idx + 1, done + (f.size || 0), total, onDone);
		});
	}
	function downloadFile(link, name, offset, size, base, total, onDone) {
		post('sd_pull_download', { link: link, name: name, offset: offset, total: size, insecure: impInsecure, password: impPassword })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				var g = base + res.data.offset;
				impBar('download', total ? Math.min(100, Math.round(g / total * 100)) : 100);
				if (res.data.done) { onDone(); }
				else { downloadFile(link, name, res.data.offset, size, base, total, onDone); }
			})
			.fail(function () { impFail(sprintf(
				/* translators: %s: file name. */
				__('Error while downloading %s.', 'site-cloner'), name)); });
	}
	function bytes(n) {
		if (!n) { return '0 B'; }
		var u = ['B', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(n) / Math.log(1024));
		return (n / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
	}

	// Run migrate on the local package impPkg (either already present or just pulled).
	function startMigrate() {
		impStatus(__('Preparing…', 'site-cloner'));
		post('sd_import_prepare', { package: impPkg })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				impParts = res.data.parts || [];
				impFilesTotal = res.data.files_total || 0;
				impRunner = res.data.runner_url || null;
				impToken = res.data.token || null;
				impStatus(sprintf(
					/* translators: 1: number of files, 2: number of parts. */
					__('Extracting %1$d files (%2$d parts)…', 'site-cloner'),
					impFilesTotal, impParts.length
				));
				extractAllParts(0, 0, function () {
					if (impRunner && impToken) { runDbViaRunner(); }
					else { runDbSingleRequest(); }
				});
			})
			.fail(function () { impFail(__('Server connection error.', 'site-cloner')); });
	}

	function extractAllParts(pi, base, onDone) {
		if (pi >= impParts.length) { impBar('extract', 100); return onDone(); }
		var part = impParts[pi];
		extractPartLoop(part.name, 0, base, function () {
			extractAllParts(pi + 1, base + part.entries, onDone);
		});
	}

	function extractPartLoop(name, offset, base, onDone) {
		post('sd_import_extract', { package: impPkg, part: name, offset: offset })
			.done(function (res) {
				if (!res.success) { return impFail(res.data && res.data.message); }
				var globalDone = base + res.data.offset;
				impBar('extract', impFilesTotal ? Math.min(100, Math.round(globalDone / impFilesTotal * 100)) : 100);
				if (res.data.done) { onDone(); }
				else { extractPartLoop(name, res.data.offset, base, onDone); }
			})
			.fail(function () { impFail(__('Error while extracting.', 'site-cloner')); });
	}

	// Large site: import + replace + finalize in chunks via the standalone runner.
	function runDbViaRunner() {
		impRunnerOk = false;
		impStatus(__('Importing database in chunks…', 'site-cloner'));
		runnerLoop('import', function () {
			impStatus(__('Running search-replace in chunks…', 'site-cloner'));
			runnerLoop('replace', function () {
				impStatus(__('Finalizing…', 'site-cloner'));
				$.post(impRunner, { token: impToken, phase: 'finalize' }, null, 'json')
					.done(function (r) {
						if (!r || !r.ok) { return impFail((r && r.message) || __('Finalize error.', 'site-cloner')); }
						showImportDone(r);
					})
					.fail(function () { impFail(__('Error while finalizing.', 'site-cloner')); });
		   });
		});
	}

	function runnerLoop(phase, onDone) {
		$.post(impRunner, { token: impToken, phase: phase }, null, 'json')
			.done(function (r) {
				if (!r || !r.ok) {
					if (phase === 'import' && !impRunnerOk) {
						impStatus(sprintf(
							/* translators: %s: error message reported by the runner. */
							__('Runner reported an error (%s) — switching to single-request import…', 'site-cloner'),
							(r && r.message) || ''
						));
						return runDbSingleRequest();
					}
					return impFail((r && r.message) || __('Runner error.', 'site-cloner'));
				}
				impRunnerOk = true;
				var p = r.progress || 0;
				impBar('db', phase === 'import' ? Math.round(p * 0.5) : 50 + Math.round(p * 0.5));
				if (r.done) { onDone(); }
				else { runnerLoop(phase, onDone); }
			})
			.fail(function (xhr) {
				// runner.php can't run (PHP is blocked in this folder, 404, or it returns source).
				if (phase === 'import' && !impRunnerOk) {
					impStatus(__('Runner couldn\'t run — switching to single-request database import…', 'site-cloner'));
					return runDbSingleRequest();
				}
				impFail(runnerFailMsg(xhr, phase));
			});
	}

	function runnerFailMsg(xhr, phase) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.message) { return xhr.responseJSON.message; }
		var t = (xhr && xhr.responseText) ? ('' + xhr.responseText).replace(/<[^>]*>/g, ' ').trim().slice(0, 200) : '';
		return sprintf(
			/* translators: 1: runner phase, 2: HTTP status code, 3: extra detail (may be empty). */
			__('Runner error (%1$s) — HTTP %2$s%3$s', 'site-cloner'),
			phase, (xhr ? xhr.status : '?'), (t ? ': ' + t : '')
		);
	}

	// Fallback (small site / runner can't be written): bundle into one request.
	function runDbSingleRequest() {
		impStatus(__('Importing database + search-replace (single request, don\'t reload the page)…', 'site-cloner'));
		post('sd_import_deploy', { package: impPkg })
			.done(function (r) {
				if (!r.success) { return impFail(r.data && r.data.message); }
				showImportDone({ stmts: r.data.statements, changed: r.data.changed, note: r.data.prefix_note, new_url: r.data.new_url });
			})
			.fail(function () { impFail(__('Error while importing database (may time out on large sites).', 'site-cloner')); });
	}

	function showImportDone(r) {
		impBar('db', 100);
		$('#sd-imp-progress').hide();
		var log = sprintf(
			/* translators: 1: number of SQL statements, 2: number of updated data cells. */
			__('Imported %1$d statements, updated %2$d data cells.', 'site-cloner'),
			(r.stmts || 0), (r.changed || 0)
		) + ' ' + (r.note || '');
		$('#sd-imp-result .sd-imp-log').text(log);
		$('#sd-imp-result .sd-imp-login').attr('href', (r.new_url || '') + '/wp-admin/');
		if (impLink) { $('#sd-src-uninstall-wrap').show(); }
		$('#sd-imp-result').show();
	}

	$('#sd-src-uninstall').on('click', function () {
		if (!impLink) { return; }
		var $btn = $(this).prop('disabled', true).text(__('Removing…', 'site-cloner'));
		var $msg = $('.sd-src-uninstall-msg').text('');
		post('sd_pull_uninstall', { link: impLink, password: impPassword, insecure: impInsecure })
			.done(function (r) {
				if (r.success && r.data && r.data.ok) {
					$btn.text(__('Removed', 'site-cloner'));
					$msg.css('color', '#1e4620').text('✔ ' + r.data.message);
				} else {
					$btn.prop('disabled', false).text(__('Remove plugin from source site', 'site-cloner'));
					$msg.css('color', '#8a1f1f').text('✖ ' + ((r.data && r.data.message) || __('Could not remove.', 'site-cloner')));
				}
			})
			.fail(function () {
				$btn.prop('disabled', false).text(__('Remove plugin from source site', 'site-cloner'));
				$msg.css('color', '#8a1f1f').text('✖ ' + __('Connection error.', 'site-cloner'));
			});
	});

})(jQuery);
