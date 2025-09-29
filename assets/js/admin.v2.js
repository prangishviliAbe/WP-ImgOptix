// Single clean admin UI script with worker-pool and retry/backoff
(function($){
    'use strict';

    function humanFileSize(bytes) {
        if (bytes === 0) return '0 B';
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + ['B','KB','MB','GB','TB'][i];
    }

    function cardHtml( attachment ) {
        return '<div class="imgoptix-card" data-id="'+attachment.id+'" data-size="'+(attachment.size || 0)+'">' +
            '<label class="imgoptix-card-chk"><input type="checkbox" class="imgoptix-chk" value="'+attachment.id+'" /></label>' +
            '<div class="imgoptix-thumb-wrap"><img src="'+attachment.url+'" alt="'+attachment.title.replace(/"/g,'')+'" /></div>' +
            '<div class="imgoptix-meta">' +
                '<div class="imgoptix-title">'+attachment.title+'</div>' +
                '<div class="imgoptix-size-row" style="display:flex;align-items:center;gap:8px;margin-top:6px;">' +
                    '<div class="imgoptix-size-original" style="color:#777;font-size:12px">'+attachment.human_orig+'</div>' +
                    '<div class="imgoptix-size-arrow" style="color:#bbb">→</div>' +
                    '<div class="imgoptix-size-new" style="font-weight:700;color:#111">-</div>' +
                    '<div class="imgoptix-size-delta" style="margin-left:auto;color:#2b8f76;font-weight:700"></div>' +
                '</div>' +
                '<div class="imgoptix-status" style="margin-top:8px">Pending</div>' +
            '</div>' +
        '</div>';
    }

    $(function(){
        var $table = $('#imgoptix-images-table');
        var $tbody = $table.find('tbody');
        var $load = $('#imgoptix-load-images');
        var $optimize = $('#imgoptix-optimize-selected');
        var $selectAll = $('#imgoptix-select-all');
        var $deselectAll = $('#imgoptix-deselect-all');
        var $progressWrap = $('#imgoptix-progress-wrap');
        var $progressBar = $('#imgoptix-progress-bar');
        var $progressText = $('#imgoptix-progress-text');
        var $summary = $('#imgoptix-summary');

        function updateSelectedCount() {
            var count = $('#imgoptix-grid').find('.imgoptix-chk:checked').length;
            $('#imgoptix-selected-count').text(count + ' selected');
            $('#imgoptix-optimize-top').prop('disabled', count === 0);
            $('#imgoptix-optimize-selected').prop('disabled', count === 0);
        }

        updateSelectedCount();

        $load.on('click', function(e){
            e.preventDefault();
            $tbody.html('<tr><td colspan="6">Loading...</td></tr>');
            $.post(ImgOptix.ajax_url, { action: 'imgoptix_list_attachments', per_page: 200, debug: 1 }, function(resp){
                if (!resp) { $tbody.html('<tr class="no-items"><td colspan="6">No response from server.</td></tr>'); console.error('ImgOptix: no response from imgoptix_list_attachments'); return; }
                if (!resp.success) { var msg = (resp.data && resp.data.message) ? resp.data.message : JSON.stringify(resp.data || resp); $tbody.html('<tr class="no-items"><td colspan="6">Unable to load images: '+ msg +'</td></tr>'); console.error('ImgOptix: server returned error for imgoptix_list_attachments:', resp); return; }
                var items = resp.data.items || [];
                if (resp.data && resp.data.debug) console.info('ImgOptix debug:', resp.data.debug);
                if (items.length === 0) { $('#imgoptix-grid').html('<div class="imgoptix-empty">No images found.</div>'); return; }
                var html = '';
                items.forEach(function(it){ var attachment = { id: it.id, url: it.url, title: it.title || '', human_orig: it.human || humanFileSize(it.size || 0), size: it.size || 0 }; html += cardHtml(attachment); });
                $('#imgoptix-grid').html(html);
                updateSelectedCount();
            }).fail(function(){ $('#imgoptix-grid').html('<div class="imgoptix-empty">Unable to load images (server error).</div>'); });
        });

        function setViewMode(mode) { if (mode === 'list') { $('#imgoptix-grid').removeClass('grid-view').addClass('list-view'); $('#imgoptix-view-list').addClass('active'); $('#imgoptix-view-grid').removeClass('active'); } else { $('#imgoptix-grid').removeClass('list-view').addClass('grid-view'); $('#imgoptix-view-grid').addClass('active'); $('#imgoptix-view-list').removeClass('active'); } }
        $('#imgoptix-view-grid').on('click', function(e){ e.preventDefault(); setViewMode('grid'); });
        $('#imgoptix-view-list').on('click', function(e){ e.preventDefault(); setViewMode('list'); });
        setViewMode('grid');

        $('#imgoptix-sort-by').on('change', function(){ var val = $(this).val(); var $cards = $('#imgoptix-grid').find('.imgoptix-card'); var arr = $cards.toArray(); arr.sort(function(a,b){ var A = parseInt($(a).data('size')||0,10); var B = parseInt($(b).data('size')||0,10); if (val === 'size_desc') return B - A; if (val === 'size_asc') return A - B; var an = $(a).find('.imgoptix-title').text().toLowerCase(); var bn = $(b).find('.imgoptix-title').text().toLowerCase(); if (an < bn) return -1; if (an > bn) return 1; return 0; }); $('#imgoptix-grid').empty().append(arr); });

        $selectAll.on('click', function(e){ e.preventDefault(); $('#imgoptix-grid').find('.imgoptix-chk').prop('checked', true).trigger('change'); });
        $deselectAll.on('click', function(e){ e.preventDefault(); $('#imgoptix-grid').find('.imgoptix-chk').prop('checked', false).trigger('change'); });
        $('#imgoptix-select-all-top').on('click', function(e){ e.preventDefault(); $('#imgoptix-grid').find('.imgoptix-chk').prop('checked', true).trigger('change'); });
        $('#imgoptix-deselect-all-top').on('click', function(e){ e.preventDefault(); $('#imgoptix-grid').find('.imgoptix-chk').prop('checked', false).trigger('change'); });
        $('#imgoptix-optimize-top').on('click', function(e){ e.preventDefault(); $('#imgoptix-optimize-selected').trigger('click'); });

        $('#imgoptix-grid').on('change', '.imgoptix-chk', function(){ var $card = $(this).closest('.imgoptix-card'); if ( $(this).is(':checked') ) { $card.addClass('selected'); } else { $card.removeClass('selected'); } updateSelectedCount(); });
        $('#imgoptix-grid').on('click', '.imgoptix-card', function(e){ if ( $(e.target).is('input') || $(e.target).closest('input').length ) return; if ( $(e.target).is('a') || $(e.target).closest('a').length ) return; var $chk = $(this).find('.imgoptix-chk'); if ( $chk.length ) { $chk.prop('checked', !$chk.prop('checked')).trigger('change'); } });

        $optimize.on('click', function(e){
            e.preventDefault();
            var ids = [];
            $('#imgoptix-grid').find('.imgoptix-chk:checked').each(function(){ ids.push(parseInt($(this).val(),10)); });
            if (ids.length === 0) { alert('Please select at least one image.'); return; }
            var backup = $('#imgoptix-backup-originals').is(':checked') ? 1 : 0;
            $progressWrap.show(); $progressBar.css('width','0%'); $progressText.text('Starting...'); $summary.text('');

            var total = ids.length, done = 0, savedTotal = 0;
            var concurrency = parseInt($('#imgoptix-concurrency').val() || 4, 10) || 4;
            var reducePressure = $('#imgoptix-reduce-pressure').is(':checked');
            if (reducePressure) concurrency = Math.max(1, Math.floor(concurrency / 2));

            function processSingle(id, cb) {
                var $card = $('#imgoptix-grid').find('.imgoptix-card[data-id="'+id+'"]');
                $card.find('.imgoptix-status').text('Processing...'); $card.addClass('processing');
                var $overlay = $('<div class="imgoptix-overlay"><div class="imgoptix-scanner"></div></div>'); $card.append($overlay);
                var runLevel = $('#imgoptix-compression-level').val();
                var maxRetries = 3; var retryDelayBase = 1200; if (reducePressure) retryDelayBase *= 1.8; var pausedUntil = window.imgoptixPausedUntil || 0;

                function doOptimizeAttempt(attemptsLeft) {
                    var now = Date.now();
                    if (pausedUntil > now) { var wait = pausedUntil - now; $card.find('.imgoptix-status').text('Waiting due to server load...'); setTimeout(function(){ doOptimizeAttempt(attemptsLeft); }, wait); return; }

                    $.ajax({ url: ImgOptix.ajax_url, method: 'POST', data: { action: 'imgoptix_optimize', nonce: ImgOptix.nonce, attachment_id: id, backup: backup, compression_level: runLevel }, timeout: 120000 })
                    .done(function(res){
                        if (res && res.success && res.data) {
                            var d = res.data;
                            $card.find('.imgoptix-size-new').text(d.human_new);
                            $card.find('.imgoptix-size-original').text(d.human_orig);
                            $card.find('.imgoptix-status').text(d.saved ? 'Saved' : 'No change');
                            $card.find('.imgoptix-method, .imgoptix-attempts, .imgoptix-error, .imgoptix-retry').remove();
                            if (d.method) $card.find('.imgoptix-status').after('<div class="imgoptix-method">Method: '+ String(d.method) +'</div>');
                            if (d.attempts && typeof d.attempts === 'object') { var $attempts = $('<div class="imgoptix-attempts"><strong>Attempts:</strong><ul></ul></div>'); Object.keys(d.attempts).forEach(function(k){ var v = d.attempts[k]; var label = (typeof v === 'number') ? humanFileSize(v) + ' ('+ v +' bytes)' : String(v); $attempts.find('ul').append('<li>'+ k +': '+ label +'</li>'); }); $card.find('.imgoptix-status').after($attempts); }
                            if (d.saved) { var delta = d.orig_size - d.new_size; var pct = d.orig_size ? Math.round( (delta / d.orig_size) * 100 ) : 0; $card.find('.imgoptix-size-delta').text('Saved '+ (delta > 0 ? humanFileSize(delta) : '0 B') +' ('+ pct +'% )'); savedTotal++; } else { $card.find('.imgoptix-size-delta').text('No change'); }
                            $overlay.remove(); $card.removeClass('processing'); cb({ id: id, saved: !!d.saved, permanentFailure: false });
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : 'Failed';
                            $card.find('.imgoptix-status').text('Error'); $card.find('.imgoptix-status').after('<div class="imgoptix-error">'+msg+'</div>'); console.error('Optimize failed for', id, res); $overlay.remove(); $card.removeClass('processing'); cb({ id: id, saved: false, permanentFailure: true });
                        }
                    })
                    .fail(function(xhr, status, err){
                        var statusCode = xhr && xhr.status ? xhr.status : 0; var responseText = xhr && xhr.responseText ? xhr.responseText : status; console.error('AJAX error optimizing', id, status, responseText);
                        if (statusCode === 503 && attemptsLeft > 0) { var pauseMs = 5000; window.imgoptixPausedUntil = Date.now() + pauseMs; var delay = retryDelayBase * Math.pow(2, maxRetries - attemptsLeft); $card.find('.imgoptix-status').text('Server busy, retrying in ' + Math.round(delay/1000) + 's...'); $card.find('.imgoptix-status').after('<div class="imgoptix-retry">Retrying (' + (maxRetries - attemptsLeft + 1) + ')</div>'); setTimeout(function(){ doOptimizeAttempt(attemptsLeft - 1); }, delay + pauseMs); return; }
                        if (attemptsLeft > 0) { var delay2 = retryDelayBase * Math.pow(2, maxRetries - attemptsLeft); $card.find('.imgoptix-status').text('Error, retrying...'); setTimeout(function(){ doOptimizeAttempt(attemptsLeft - 1); }, delay2); return; }
                        $overlay.remove(); $card.removeClass('processing'); $card.find('.imgoptix-status').text('Error'); $card.find('.imgoptix-status').after('<div class="imgoptix-error">'+ (xhr && xhr.status ? 'Server error '+xhr.status : 'AJAX error') +'</div>');
                        var $retryBtn = $('<button class="button imgoptix-retry-btn">Retry</button>'); $retryBtn.on('click', function(e){ e.preventDefault(); $card.find('.imgoptix-error').remove(); $retryBtn.remove(); ids.unshift(id); done = Math.max(0, done - 1); startWorkers(); }); $card.append($retryBtn);
                        cb({ id: id, saved: false, permanentFailure: true });
                    });
                }
                doOptimizeAttempt(maxRetries);
            }

            var workersRunning = 0;
            function worker() {
                if (ids.length === 0) return;
                var id = ids.shift(); if (!id) return;
                workersRunning++;
                processSingle(id, function(result){
                    workersRunning = Math.max(0, workersRunning - 1);
                    if (result && result.saved) savedTotal++;
                    done++;
                    var pct = Math.round((done / total) * 100);
                    $progressBar.css('width', pct + '%'); $progressText.text(done + ' of ' + total + ' processed');
                    if (done >= total) { finish(); return; }
                    if (ids.length > 0) setTimeout(worker, reducePressure ? 300 : 120);
                });
            }

            function startWorkers(){ while (workersRunning < concurrency && ids.length > 0) worker(); }
            function finish(){ $summary.text('Processed ' + done + ' images. ' + savedTotal + ' files reduced in size.'); $progressText.text('Completed'); updateSelectedCount(); }
            startWorkers();
        });
    });
})(jQuery);
