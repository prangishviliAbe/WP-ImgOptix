(function($){
    'use strict';

    function humanFileSize(bytes) {
        if (bytes === 0) return '0 B';
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + ['B','KB','MB','GB','TB'][i];
    }

    function rowHtml( attachment ) {
        return '<tr data-id="'+attachment.id+'">' +
            '<td><input type="checkbox" class="imgoptix-chk" value="'+attachment.id+'" /></td>' +
            '<td><img src="'+attachment.url+'" style="max-width:80px;max-height:60px;" /></td>' +
            '<td>'+attachment.title+'</td>' +
            '<td class="orig-size">'+attachment.human_orig+'</td>' +
            '<td class="new-size">-</td>' +
            '<td class="status">Pending</td>' +
            '</tr>';
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

        $load.on('click', function(e){
            e.preventDefault();
            $tbody.html('<tr><td colspan="6">Loading...</td></tr>');
            $.post(ImgOptix.ajax_url, { action: 'imgoptix_list_attachments', per_page: 200, debug: 1 }, function(resp){
                if (!resp) {
                    $tbody.html('<tr class="no-items"><td colspan="6">No response from server.</td></tr>');
                    console.error('ImgOptix: no response from imgoptix_list_attachments');
                    return;
                }
                if (!resp.success) {
                    var msg = (resp.data && resp.data.message) ? resp.data.message : JSON.stringify(resp.data || resp);
                    $tbody.html('<tr class="no-items"><td colspan="6">Unable to load images: '+ msg +'</td></tr>');
                    console.error('ImgOptix: server returned error for imgoptix_list_attachments:', resp);
                    return;
                }
                var items = resp.data.items || [];
                if (resp.data && resp.data.debug) {
                    console.info('ImgOptix debug:', resp.data.debug);
                }
                if (items.length === 0) {
                    $tbody.html('<tr class="no-items"><td colspan="6">No images found.</td></tr>');
                    return;
                }
                var html = '';
                items.forEach(function(it){
                    var attachment = {
                        id: it.id,
                        url: it.url,
                        title: it.title || '',
                        human_orig: it.human || humanFileSize(it.size || 0)
                    };
                    html += rowHtml(attachment);
                });
                $tbody.html(html);
            }).fail(function(){
                $tbody.html('<tr class="no-items"><td colspan="6">Unable to load images (server error).</td></tr>');
            });
        });

        $selectAll.on('click', function(e){ e.preventDefault(); $tbody.find('.imgoptix-chk').prop('checked', true); });
        $deselectAll.on('click', function(e){ e.preventDefault(); $tbody.find('.imgoptix-chk').prop('checked', false); });

        $optimize.on('click', function(e){
            e.preventDefault();
            var ids = [];
            $tbody.find('.imgoptix-chk:checked').each(function(){ ids.push(parseInt($(this).val(),10)); });
            if (ids.length === 0) { alert('Please select at least one image.'); return; }

            $progressWrap.show();
            $progressBar.css('width','0%');
            $progressText.text('Starting...');
            $summary.text('');

            var total = ids.length;
            var done = 0;
            var savedTotal = 0;
            function next() {
                if (ids.length === 0) return finish();
                var id = ids.shift();
                var $row = $tbody.find('tr[data-id="'+id+'"]');
                $row.find('.status').text('Processing...');
                $.post(ImgOptix.ajax_url, { action: 'imgoptix_optimize', nonce: ImgOptix.nonce, attachment_id: id }, function(res){
                    if (res && res.success && res.data) {
                        var d = res.data;
                        $row.find('.new-size').text(d.human_new);
                        $row.find('.orig-size').text(d.human_orig);
                        $row.find('.status').text(d.saved ? 'Saved' : 'No change');
                        if (d.saved) savedTotal++;
                    } else {
                        $row.find('.status').text('Failed');
                    }
                }).fail(function(){
                    $row.find('.status').text('Error');
                }).always(function(){
                    done++;
                    var pct = Math.round( (done/total) * 100 );
                    $progressBar.css('width', pct + '%');
                    $progressText.text(done + ' of ' + total + ' processed');
                    if (ids.length) {
                        // small delay to reduce server pressure
                        setTimeout(next, 250);
                    } else {
                        finish();
                    }
                });
            }

            function finish() {
                $summary.text('Processed ' + done + ' images. ' + savedTotal + ' files reduced in size.');
                $progressText.text('Completed');
            }

            // start
            next();
        });
    });
})(jQuery);
