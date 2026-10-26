/**
 * LeadCRM WhatsApp — Admin JS
 * Appended to assets/js/admin.js at plugin load (separate file, loaded after admin.js)
 */
(function($){
'use strict';

/* ── UTILS ──────────────────────────────────────────────────────────────────── */
function waPost(action, data, cb, errCb){
    data.action = action;
    data.nonce  = LCRM.nonce;
    $.post(LCRM.ajax_url, data)
     .done(function(r){
         if(r && r.success){ if(cb) cb(r.data); }
         else if(errCb) errCb(r && r.data ? r.data : {msg:'Server error.'});
     })
     .fail(function(){ if(errCb) errCb({msg:'Connection error.'}); });
}
function waMsg($el, msg, type){
    $el.removeClass('lcrm-msg-ok lcrm-msg-err')
       .addClass(type==='ok' ? 'lcrm-msg-ok' : 'lcrm-msg-err')
       .text(msg).show();
    setTimeout(function(){ $el.fadeOut(); }, 3000);
}
function waEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function waFmtTime(dt){
    if(!dt) return '';
    var p = dt.split(' ');
    if(p.length < 2) return dt;
    var date = p[0], time = p[1].slice(0,5);
    var parts = date.split('-');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parseInt(parts[2]) + ' ' + (months[parseInt(parts[1])-1]||parts[1]) + ' · ' + time;
}
function waFmtDuration(sec){
    if(!sec) return '';
    var m = Math.floor(sec/60), s = sec%60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}

/* ── LEAD MODAL — INJECT WA TAB ─────────────────────────────────────────────── */

// After lead modal opens and lead is loaded, inject WA tab if not already there
$(document).on('lcrm:lead_loaded', function(e, leadId){
    waInjectLeadModalTab();
    if(leadId){
        waLoadLeadEvents(leadId);
        waLoadCallLogs(leadId);
    }
});

function waInjectLeadModalTab(){
    if($('#lcrm-wa-tab-btn').length) return; // already injected
    var $tpl = $('#lcrm-wa-modal-templates');
    if(!$tpl.length) return;

    var content = $tpl.html();
    var $parser = $('<div>').html(content);

    // Inject tab button
    var $tabBtn = $parser.find('[data-tab="whatsapp"]').first();
    $('.lcrm-form-tabs').append($tabBtn.clone());

    // Inject tab panel
    var $panel = $parser.find('[data-pane="whatsapp"]').first();
    // Insert after the last ftab-pane
    $('.lcrm-ftab-pane').last().after($panel.clone());

    // Set default call datetime to now
    var now = new Date();
    var pad = function(n){ return n < 10 ? '0'+n : n; };
    var localDT = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())
                  +'T'+pad(now.getHours())+':'+pad(now.getMinutes());
    $('#lcrm-call-at').val(localDT);
}

// Patch lcrmOpenLeadModal to fire the lcrm:lead_loaded event
var _origOpenLeadModal = window.lcrmOpenLeadModal;
if(typeof _origOpenLeadModal === 'function'){
    window.lcrmOpenLeadModal = function(id){
        _origOpenLeadModal(id);
        if(id){
            // Fire after the AJAX inside the original function completes (~500ms delay)
            setTimeout(function(){ $(document).trigger('lcrm:lead_loaded', [id]); }, 600);
        } else {
            waInjectLeadModalTab();
        }
    };
}

/* ── WA TAB BUTTON CLICK ────────────────────────────────────────────────────── */

$(document).on('click', '#lcrm-wa-tab-btn', function(){
    var leadId = parseInt($('#lf-id').val()||0);
    if(leadId){
        waLoadLeadEvents(leadId);
        waLoadCallLogs(leadId);
    }
});

/* ── WA TIMELINE ─────────────────────────────────────────────────────────────── */

function waLoadLeadEvents(leadId){
    var $tl = $('#lcrm-wa-timeline');
    $tl.html('<div class="lcrm-conv-empty"><span class="lcrm-spinner"></span> Loading…</div>');
    waPost('lcrm_wa_get_lead_events', {lead_id: leadId}, function(d){
        waRenderTimeline(d.events || []);
    }, function(e){ $tl.html('<div class="lcrm-conv-empty" style="color:#ef4444;">'+waEsc(e.msg)+'</div>'); });
}

var WA_EVENT_ICONS = {
    message:       '&#128172;',
    call_incoming: '&#128222;',
    call_outgoing: '&#128223;',
    call_missed:   '&#128531;',
    read:          '&#10003;',
    status:        '&#9679;'
};

function waRenderTimeline(events){
    var $tl = $('#lcrm-wa-timeline');
    var unread = 0;
    if(!events.length){
        $tl.html('<div class="lcrm-conv-empty">No WhatsApp activity yet.</div>');
        $('#lcrm-wa-unread-badge').hide();
        return;
    }
    var html = '', lastDate = '';
    events.forEach(function(ev){
        var date = ev.event_time ? ev.event_time.slice(0,10) : '';
        if(date !== lastDate){
            html += '<div class="lcrm-conv-sep"><span>'+waEsc(waFmtDateLabel(date))+'</span></div>';
            lastDate = date;
        }
        var isInbound = ev.direction === 'inbound';
        var icon      = WA_EVENT_ICONS[ev.event_type] || '&#128172;';
        var timeStr   = ev.event_time ? ev.event_time.slice(11,16) : '';
        var body      = ev.message_body || waCallLabel(ev);
        var mediaTag  = ev.media_type ? ' <span class="lcrm-wa-media-tag">'+waEsc(ev.media_type)+'</span>' : '';
        var scoreTag  = (ev.capture_score !== undefined && ev.capture_score !== null)
                        ? '<span class="lcrm-wa-score" title="Capture score">S:'+ev.capture_score+'</span>' : '';
        if(!ev.is_read && isInbound) unread++;

        html += '<div class="lcrm-wa-event lcrm-wa-event--'+(isInbound?'in':'out')
              + (ev.is_read?'':' lcrm-wa-event--unread')+'">'
              + '<span class="lcrm-wa-event-icon">'+icon+'</span>'
              + '<div class="lcrm-wa-event-body">'
              + '<div class="lcrm-wa-event-text">'+waEsc(body)+mediaTag+'</div>'
              + '<div class="lcrm-wa-event-meta">'
              + '<span class="lcrm-wa-event-dir">'+(isInbound?'Inbound':'Outbound')+'</span>'
              + '<span class="lcrm-wa-event-time">'+waEsc(timeStr)+'</span>'
              + (ev.call_duration ? '<span class="lcrm-wa-event-dur">'+waFmtDuration(ev.call_duration)+'</span>' : '')
              + scoreTag
              + '</div></div></div>';
    });
    $tl.html(html);
    $tl.scrollTop($tl[0].scrollHeight);

    // Unread badge
    if(unread > 0){
        $('#lcrm-wa-unread-badge').text(unread+' unread').show();
    } else {
        $('#lcrm-wa-unread-badge').hide();
    }
}

function waFmtDateLabel(d){
    if(!d) return '';
    var today = new Date().toISOString().slice(0,10);
    if(d === today) return 'Today';
    var parts = d.split('-');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parseInt(parts[2])+' '+(months[parseInt(parts[1])-1]||parts[1])+' '+parts[0];
}
function waCallLabel(ev){
    if(ev.event_type === 'call_missed')    return 'Missed call';
    if(ev.event_type === 'call_incoming')  return 'Incoming call' + (ev.call_duration ? ' ('+waFmtDuration(ev.call_duration)+')':'');
    if(ev.event_type === 'call_outgoing')  return 'Outgoing call' + (ev.call_duration ? ' ('+waFmtDuration(ev.call_duration)+')':'');
    return '';
}

$(document).on('click','#lcrm-wa-refresh-btn',function(){
    var leadId = parseInt($('#lf-id').val()||0);
    if(leadId) waLoadLeadEvents(leadId);
});

$(document).on('click','#lcrm-wa-mark-read-btn',function(){
    var leadId = parseInt($('#lf-id').val()||0);
    if(!leadId) return;
    var $btn = $(this).prop('disabled',true).text('Marking…');
    waPost('lcrm_wa_mark_read',{lead_id:leadId},function(){
        $btn.prop('disabled',false).html('&#10003; Mark All Read');
        $('#lcrm-wa-unread-badge').hide();
        // Also clear the unread badge in the list row
        $('[data-lead-id="'+leadId+'"] .lcrm-wa-unread-dot').remove();
        waLoadLeadEvents(leadId);
    },function(e){ $btn.prop('disabled',false).text('Error'); });
});

/* ── CALL LOG ────────────────────────────────────────────────────────────────── */

$(document).on('click','#lcrm-wa-add-call-btn',function(){
    $('#lcrm-call-log-form').slideToggle(150);
    // Default to now
    var now = new Date();
    var pad = function(n){ return n<10?'0'+n:n; };
    $('#lcrm-call-at').val(now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())
        +'T'+pad(now.getHours())+':'+pad(now.getMinutes()));
});
$(document).on('click','#lcrm-call-cancel-btn',function(){
    $('#lcrm-call-log-form').slideUp(150);
});

$(document).on('click','#lcrm-call-save-btn',function(){
    var leadId = parseInt($('#lf-id').val()||0);
    if(!leadId){ waMsg($('#lcrm-call-msg'),'Save the lead first.','err'); return; }
    var callAt = $('#lcrm-call-at').val();
    if(!callAt){ waMsg($('#lcrm-call-msg'),'Date/time required.','err'); return; }
    // Convert datetime-local to MySQL format
    var mysqlDt = callAt.replace('T',' ') + ':00';
    var $btn = $(this).prop('disabled',true).text('Saving…');
    waPost('lcrm_wa_log_call',{
        lead_id:      leadId,
        call_type:    $('#lcrm-call-type').val(),
        duration_sec: parseInt($('#lcrm-call-duration').val()||0),
        phone:        $('#lf-client_phone').val()||'',
        notes:        $('#lcrm-call-notes').val(),
        call_at:      mysqlDt,
        make_reminder:$('#lcrm-call-reminder').is(':checked') ? 1 : 0,
    }, function(d){
        $btn.prop('disabled',false).text('Save Call');
        waMsg($('#lcrm-call-msg'),'Call logged.','ok');
        $('#lcrm-call-type').val('incoming');
        $('#lcrm-call-duration').val('');
        $('#lcrm-call-notes').val('');
        $('#lcrm-call-reminder').prop('checked',false);
        $('#lcrm-call-log-form').slideUp(150);
        waLoadCallLogs(leadId);
        if(d.reminder_id) waMsg($('#lcrm-call-msg'),'Call logged + reminder created.','ok');
    }, function(e){ $btn.prop('disabled',false).text('Save Call'); waMsg($('#lcrm-call-msg'),e.msg||'Error','err'); });
});

function waLoadCallLogs(leadId){
    var $list = $('#lcrm-call-logs-list');
    $list.html('<div class="lcrm-conv-empty"><span class="lcrm-spinner"></span></div>');
    waPost('lcrm_wa_get_call_logs',{lead_id:leadId},function(d){
        waRenderCallLogs(d.logs||[]);
    },function(){ $list.html('<div class="lcrm-conv-empty">Error loading calls.</div>'); });
}

var CALL_ICONS = { incoming:'&#128222;', outgoing:'&#128223;', missed:'&#128531;' };
var CALL_COLORS = { incoming:'#10b981', outgoing:'#6366f1', missed:'#ef4444' };

function waRenderCallLogs(logs){
    var $list = $('#lcrm-call-logs-list');
    if(!logs.length){
        $list.html('<div class="lcrm-conv-empty">No calls logged yet.</div>');
        return;
    }
    var html = '';
    logs.forEach(function(log){
        var icon  = CALL_ICONS[log.call_type]  || '&#128222;';
        var color = CALL_COLORS[log.call_type] || '#64748b';
        html += '<div class="lcrm-wa-call-row">'
              + '<span class="lcrm-wa-call-icon" style="color:'+color+';">'+icon+'</span>'
              + '<div class="lcrm-wa-call-info">'
              + '<span class="lcrm-wa-call-type" style="color:'+color+';">'+waEsc(log.call_type.charAt(0).toUpperCase()+log.call_type.slice(1))+'</span>'
              + (log.duration_sec ? ' <span class="lcrm-wa-call-dur">'+waFmtDuration(log.duration_sec)+'</span>' : '')
              + (log.notes ? '<div class="lcrm-wa-call-notes">'+waEsc(log.notes)+'</div>' : '')
              + '</div>'
              + '<div class="lcrm-wa-call-meta">'
              + '<span>'+waEsc(waFmtTime(log.call_at))+'</span>'
              + (log.created_by_name ? '<span style="color:#94a3b8;">'+waEsc(log.created_by_name)+'</span>' : '')
              + (log.reminder_created ? '<span class="lcrm-wa-call-rem-badge">&#9201; Reminder</span>' : '')
              + '</div>'
              + '</div>';
    });
    $list.html(html);
}

/* ── WA INBOX PAGE ───────────────────────────────────────────────────────────── */

var _waInboxEvents = [];

if($('#lcrm-wa-inbox-wrap').length){
    waLoadPending();
    pollPendingBadge();
}

function waLoadPending(){
    waPost('lcrm_wa_get_pending',{},function(d){
        _waInboxEvents = d.events || [];
        waRenderInbox(_waInboxEvents);
        var count = _waInboxEvents.length;
        $('#lcrm-wa-inbox-count').text(count + ' item'+(count!==1?'s':''));
    },function(e){
        $('#lcrm-wa-inbox-wrap').html('<div style="color:#ef4444;padding:20px;">'+waEsc(e.msg)+'</div>');
    });
}

$(document).on('click','#lcrm-wa-inbox-refresh',function(){ waLoadPending(); });

$(document).on('input','#lcrm-wa-inbox-search',function(){
    waRenderInbox(waFilterInbox(_waInboxEvents));
});
$(document).on('change','#lcrm-wa-inbox-account',function(){
    waRenderInbox(waFilterInbox(_waInboxEvents));
});

function waFilterInbox(events){
    var q       = ($('#lcrm-wa-inbox-search').val()||'').toLowerCase();
    var account = $('#lcrm-wa-inbox-account').val()||'';
    return events.filter(function(ev){
        if(account && String(ev.wa_account_id) !== account) return false;
        if(q){
            var haystack = (ev.from_number+' '+ev.message_body+' '+(ev.account_name||'')).toLowerCase();
            if(haystack.indexOf(q) === -1) return false;
        }
        return true;
    });
}

function waRenderInbox(events){
    var $wrap = $('#lcrm-wa-inbox-wrap');
    if(!events.length){
        $wrap.html('<div class="lcrm-card" style="text-align:center;padding:48px 24px;"><div style="font-size:48px;">&#128203;</div><h3 style="color:#10b981;margin:0 0 8px;">Inbox is clear!</h3><p style="color:#64748b;margin:0;">No messages waiting for review.</p></div>');
        return;
    }
    var html = '<div class="lcrm-table-wrap"><table class="lcrm-table">'
             + '<thead><tr><th>From</th><th>Brand</th><th>Account</th><th>Type</th><th>Message</th><th>Score</th><th>Time</th><th>Actions</th></tr></thead>'
             + '<tbody>';

    events.forEach(function(ev){
        var shortMsg = ev.message_body ? ev.message_body.slice(0,80)+(ev.message_body.length>80?'…':'') : waCallLabel(ev)||'—';
        var scoreColor = ev.capture_score >= 2 ? '#10b981' : ev.capture_score >= 0 ? '#f59e0b' : '#ef4444';
        html += '<tr id="wa-inbox-row-'+ev.id+'">'
              + '<td><strong>'+waEsc(ev.from_number)+'</strong></td>'
              + '<td>'+waEsc(ev.brand_name||'—')+'</td>'
              + '<td>'+waEsc(ev.account_name||ev.account_number||'—')+'</td>'
              + '<td><span class="lcrm-badge" style="background:#6366f1;">'+waEsc(ev.event_type)+'</span></td>'
              + '<td style="max-width:260px;white-space:normal;font-size:13px;">'+waEsc(shortMsg)+'</td>'
              + '<td><span class="lcrm-badge" style="background:'+scoreColor+';">'+ev.capture_score+'</span></td>'
              + '<td style="white-space:nowrap;font-size:12px;">'+waEsc(waFmtTime(ev.event_time))+'</td>'
              + '<td style="white-space:nowrap;">'
              + '<button class="button lcrm-btn lcrm-btn-sm lcrm-wa-approve-btn" data-id="'+ev.id+'" data-phone="'+waEsc(ev.from_number)+'" data-msg="'+waEsc(ev.message_body)+'">&#10003; Approve</button> '
              + '<button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-wa-reject-btn" data-id="'+ev.id+'">&#10007; Reject</button> '
              + '<button class="button lcrm-btn lcrm-btn-sm lcrm-wa-block-btn" data-id="'+ev.id+'" data-phone="'+waEsc(ev.from_number)+'" title="Add to blocklist">&#128683;</button>'
              + '</td></tr>';
    });
    html += '</tbody></table></div>';
    $wrap.html(html);
}

// Approve
$(document).on('click','.lcrm-wa-approve-btn',function(){
    var $btn = $(this);
    var eventId = $btn.data('id');
    var phone   = $btn.data('phone');
    var msg     = $btn.data('msg');

    // Show name input inline
    var name = prompt('Client name for this lead? (leave blank to use "WA +' + phone + '")', '');
    if(name === null) return; // cancelled

    $btn.prop('disabled',true).text('…');
    waPost('lcrm_wa_approve_event',{event_id:eventId, client_name:name},function(d){
        $('#wa-inbox-row-'+eventId).fadeOut(300, function(){ $(this).remove(); });
        _waInboxEvents = _waInboxEvents.filter(function(e){ return e.id != eventId; });
        $('#lcrm-wa-inbox-count').text(_waInboxEvents.length+' item'+(_waInboxEvents.length!==1?'s':''));
        // Update menu badge
        updatePendingBadge(_waInboxEvents.length);
    },function(e){ $btn.prop('disabled',false).text('Approve'); alert(e.msg||'Error'); });
});

// Reject
$(document).on('click','.lcrm-wa-reject-btn',function(){
    var $btn = $(this);
    var eventId = $btn.data('id');
    if(!confirm('Reject this message? It will be permanently dismissed.')) return;
    $btn.prop('disabled',true).text('…');
    waPost('lcrm_wa_reject_event',{event_id:eventId},function(){
        $('#wa-inbox-row-'+eventId).fadeOut(300, function(){ $(this).remove(); });
        _waInboxEvents = _waInboxEvents.filter(function(e){ return e.id != eventId; });
        $('#lcrm-wa-inbox-count').text(_waInboxEvents.length+' item'+(_waInboxEvents.length!==1?'s':''));
        updatePendingBadge(_waInboxEvents.length);
    },function(e){ $btn.prop('disabled',false).text('Reject'); });
});

// Block
$(document).on('click','.lcrm-wa-block-btn',function(){
    var $btn = $(this);
    var eventId = $btn.data('id');
    var phone   = $btn.data('phone');
    if(!confirm('Add '+phone+' to blocklist? Future messages from this number will be permanently ignored.')) return;
    $btn.prop('disabled',true);
    waPost('lcrm_wa_blocklist_number',{phone:phone},function(){
        // Also reject the event
        waPost('lcrm_wa_reject_event',{event_id:eventId},function(){
            $('#wa-inbox-row-'+eventId).fadeOut(300, function(){ $(this).remove(); });
            _waInboxEvents = _waInboxEvents.filter(function(e){ return e.id != eventId; });
            updatePendingBadge(_waInboxEvents.length);
        },function(){});
    },function(e){ $btn.prop('disabled',false); alert(e.msg||'Error'); });
});

/* ── PENDING BADGE (header menu) ─────────────────────────────────────────────── */

function pollPendingBadge(){
    waPost('lcrm_wa_count_pending',{},function(d){
        updatePendingBadge(d.count||0);
    },function(){});
    // Recheck every 2 minutes
    setTimeout(pollPendingBadge, 120000);
}

function updatePendingBadge(count){
    var $badge = $('#lcrm-wa-pending-badge');
    if(!$badge.length) return;
    if(count > 0){
        $badge.text(count).show();
    } else {
        $badge.hide();
    }
}

// Also poll when on leads page
if($('#lcrm-leads-table').length){ pollPendingBadge(); }

/* ── WA SETTINGS PAGE ────────────────────────────────────────────────────────── */

// Account form toggle
$(document).on('click','#lcrm-wa-add-account-btn',function(){
    $('#lwa-id').val('');
    $('#lwa-display,#lwa-number,#lwa-phoneid,#lwa-token,#lwa-verify-tok').val('');
    $('#lwa-brand,#lwa-platform').val($('#lwa-brand option:first').val());
    $('#lwa-active').val('1');
    $('#lcrm-wa-account-form').slideDown(150);
});
$(document).on('click','#lwa-cancel-btn',function(){
    $('#lcrm-wa-account-form').slideUp(150);
});

// Platform toggle — hide phone_number_id for generic
$(document).on('change','#lwa-platform',function(){
    if($(this).val()==='generic') $('#lwa-phoneid-wrap').hide();
    else $('#lwa-phoneid-wrap').show();
});

// Edit account
$(document).on('click','.lcrm-wa-edit-account',function(){
    var $btn = $(this);
    $('#lwa-id').val($btn.data('id'));
    $('#lwa-display').val($btn.data('display'));
    $('#lwa-number').val($btn.data('number'));
    $('#lwa-brand').val($btn.data('brand'));
    $('#lwa-platform').val($btn.data('platform')).trigger('change');
    $('#lwa-phoneid').val($btn.data('phoneid'));
    $('#lwa-verify-tok').val($btn.data('verify'));
    $('#lwa-token').val('');
    $('#lwa-active').val($btn.data('active'));
    $('#lcrm-wa-account-form').slideDown(150);
    $('html,body').animate({scrollTop:$('#lcrm-wa-account-form').offset().top-80},200);
});

// Save account
$(document).on('click','#lwa-save-btn',function(){
    var $btn = $(this).prop('disabled',true).text('Saving…');
    var $msg = $('#lwa-msg');
    waPost('lcrm_wa_save_account',{
        id:                   $('#lwa-id').val()||0,
        brand_id:             $('#lwa-brand').val(),
        wa_number:            $('#lwa-number').val().trim(),
        display_name:         $('#lwa-display').val().trim(),
        platform:             $('#lwa-platform').val(),
        phone_number_id:      $('#lwa-phoneid').val().trim(),
        api_token:            $('#lwa-token').val().trim(),
        webhook_verify_token: $('#lwa-verify-tok').val().trim(),
        is_active:            $('#lwa-active').val(),
    },function(d){
        $btn.prop('disabled',false).text('Save Account');
        waMsg($msg,'Saved!','ok');
        setTimeout(function(){ location.reload(); }, 800);
    },function(e){
        $btn.prop('disabled',false).text('Save Account');
        waMsg($msg, e.msg||'Save failed.','err');
    });
});

// Delete account
$(document).on('click','.lcrm-wa-del-account',function(){
    var id = $(this).data('id');
    if(!confirm('Delete this WhatsApp account? Existing events will be preserved but future messages will not be captured.')) return;
    var $btn = $(this).prop('disabled',true);
    waPost('lcrm_wa_delete_account',{id:id},function(){
        $('#lwa-row-'+id).fadeOut(300, function(){ $(this).remove(); });
    },function(e){ $btn.prop('disabled',false); alert(e.msg||'Error'); });
});

// Copy webhook URL
$(document).on('click','.lcrm-wa-copy-url',function(){
    var url = $(this).data('url');
    if(navigator.clipboard){ navigator.clipboard.writeText(url); }
    else {
        var $ta = $('<textarea>').val(url).appendTo('body').select();
        document.execCommand('copy');
        $ta.remove();
    }
    $(this).text('Copied!');
    var $btn = $(this);
    setTimeout(function(){ $btn.text('Copy'); }, 1500);
});

// Save capture settings
$(document).on('click','#lwa-save-settings-btn',function(){
    var $btn = $(this).prop('disabled',true).text('Saving…');
    waPost('lcrm_wa_save_settings',{
        capture_mode:             $('#lwa-mode').val(),
        capture_threshold:        $('#lwa-threshold').val(),
        missed_call_reminder_mins:$('#lwa-remind-mins').val(),
        no_reply_hours:           $('#lwa-noreply-hrs').val(),
    },function(d){
        $btn.prop('disabled',false).text('Save Settings');
        waMsg($('#lwa-settings-msg'),'Settings saved!','ok');
    },function(e){
        $btn.prop('disabled',false).text('Save Settings');
        waMsg($('#lwa-settings-msg'),e.msg||'Error','err');
    });
});

// Save blocklist
$(document).on('click','#lwa-save-blocklist-btn',function(){
    var $btn = $(this).prop('disabled',true).text('Saving…');
    waPost('lcrm_wa_save_blocklist',{numbers:$('#lwa-blocklist').val()},function(d){
        $btn.prop('disabled',false).text('Save Blocklist');
        waMsg($('#lwa-blocklist-msg'),d.count+' numbers saved.','ok');
    },function(e){
        $btn.prop('disabled',false).text('Save Blocklist');
        waMsg($('#lwa-blocklist-msg'),e.msg||'Error','err');
    });
});

/* ── WA UNREAD DOTS IN LEADS LIST ────────────────────────────────────────────── */

// After the leads table renders, add a green dot to rows with wa_unread_count > 0
$(document).on('lcrm:leads_loaded', function(e, leads){
    leads && leads.forEach(function(l){
        if(parseInt(l.wa_unread_count) > 0){
            var $row = $('[data-lead-id="'+l.id+'"]').first();
            var $nameCell = $row.find('td').filter(function(){ return $(this).find('strong').length; }).first();
            if($nameCell.length && !$nameCell.find('.lcrm-wa-unread-dot').length){
                $nameCell.find('strong').after(' <span class="lcrm-wa-unread-dot" title="'+l.wa_unread_count+' unread WA message'+(l.wa_unread_count!==1?'s':'')+'">&#128242;</span>');
            }
        }
    });
});

// Patch lcrmLoadLeads to fire leads_loaded event
var _origLoadLeads = window.lcrmLoadLeads;
if(typeof _origLoadLeads === 'function'){
    window.lcrmLoadLeads = function(){
        var result = _origLoadLeads.apply(this, arguments);
        return result;
    };
}

})(jQuery);

/* ═══════════════════════════════════════════════════════════════════════════
   V5 MODAL TABS — Internal Notes, Payment Links, Client Portal, WA Templates
   ═══════════════════════════════════════════════════════════════════════════ */
(function($){
'use strict';

// ── Internal Notes Tab ────────────────────────────────────────────────────────

$(document).on('lcrm:modal_tab_change', function(e, tabKey, leadId){
    if(tabKey === 'internal_notes') lcrmV5LoadNotes(leadId);
    if(tabKey === 'payment_links')  lcrmV5LoadPayLinks(leadId);
    if(tabKey === 'portal_link')    lcrmV5LoadPortal(leadId);
    if(tabKey === 'wa_templates')   lcrmV5LoadTemplates(leadId);
});

function lcrmV5LoadNotes(leadId){
    var $list = $('#lcrm-v5-notes-list');
    $list.html('<div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">Loading…</div>');
    lcrmReq('lcrm_team_get_notes', {lead_id: leadId}, function(res){
        if(!res.success){ $list.html('<div style="color:#ef4444;font-size:13px;padding:10px;">Failed to load notes.</div>'); return; }
        var notes = res.data.notes || [];
        if(!notes.length){ $list.html('<div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">No internal notes yet.</div>'); return; }
        var html = '';
        notes.forEach(function(n){
            var pinIcon = n.is_pinned ? '📌 ' : '';
            html += '<div style="background:'+(n.is_pinned?'#fefce8':'#f8fafc')+';border-radius:8px;padding:10px 12px;margin-bottom:8px;border:'+(n.is_pinned?'1px solid #fde68a':'1px solid #f1f5f9')+'">'
                + '<div style="display:flex;align-items:flex-start;gap:8px;">'
                + '<div style="width:28px;height:28px;border-radius:50%;background:#6366f1;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;">'+lcrmEscHtml(n.author_name.charAt(0).toUpperCase())+'</div>'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-size:12px;font-weight:600;color:#374151;">'+pinIcon+lcrmEscHtml(n.author_name)+'<span style="color:#9ca3af;font-weight:400;margin-left:6px;">'+n.created_at+'</span></div>'
                + '<div style="font-size:13px;color:#1e293b;margin-top:4px;white-space:pre-wrap;word-break:break-word;">'+lcrmEscHtml(n.note)+'</div>'
                + '</div>'
                + '<div style="display:flex;gap:4px;flex-shrink:0;">'
                + '<button class="button lcrm-btn lcrm-btn-sm lcrm-v5-pin-note" data-id="'+n.id+'" data-pinned="'+(!n.is_pinned?1:0)+'" title="'+(n.is_pinned?'Unpin':'Pin')+'">'+(n.is_pinned?'📌':'📍')+'</button>'
                + '<button class="button lcrm-btn lcrm-btn-sm lcrm-v5-del-note" data-id="'+n.id+'" style="color:#ef4444;" title="Delete">✕</button>'
                + '</div>'
                + '</div></div>';
        });
        $list.html(html);
    });
}

$(document).on('click', '#lcrm-v5-note-save-btn', function(){
    var leadId = window.lcrmCurrentLeadId;
    var note   = $('#lcrm-v5-note-text').val().trim();
    if(!note){ $('#lcrm-v5-note-msg').text('Note cannot be empty.').css('color','#ef4444'); return; }
    lcrmReq('lcrm_team_add_note', {lead_id: leadId, note: note}, function(res){
        if(res.success){ $('#lcrm-v5-note-text').val(''); $('#lcrm-v5-note-msg').text('').css('color','#10b981'); lcrmV5LoadNotes(leadId); }
        else { $('#lcrm-v5-note-msg').text(res.data?.msg||'Error').css('color','#ef4444'); }
    });
});
$(document).on('click', '.lcrm-v5-del-note', function(){
    var noteId = $(this).data('id'), leadId = window.lcrmCurrentLeadId;
    if(!confirm('Delete this note?')) return;
    lcrmReq('lcrm_team_delete_note', {note_id: noteId}, function(res){ if(res.success) lcrmV5LoadNotes(leadId); });
});
$(document).on('click', '.lcrm-v5-pin-note', function(){
    var noteId = $(this).data('id'), pinned = $(this).data('pinned'), leadId = window.lcrmCurrentLeadId;
    lcrmReq('lcrm_team_pin_note', {note_id: noteId, pinned: pinned}, function(res){ if(res.success) lcrmV5LoadNotes(leadId); });
});

// ── Payment Links Tab ─────────────────────────────────────────────────────────

function lcrmV5LoadPayLinks(leadId){
    var $list = $('#lcrm-v5-pay-links-list');
    $list.html('<div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">Loading…</div>');
    lcrmReq('lcrm_payment_get_links', {lead_id: leadId}, function(res){
        if(!res.success){ $list.html('<div style="color:#ef4444;font-size:13px;padding:10px;">Failed to load.</div>'); return; }
        var links = res.data.links || [];
        if(!links.length){ $list.html('<div style="color:#9ca3af;font-size:13px;text-align:center;padding:12px;">No payment links yet.</div>'); return; }
        var html = '';
        var statusColors = {created:'#f59e0b',sent:'#6366f1',paid:'#10b981',expired:'#9ca3af',cancelled:'#ef4444'};
        links.forEach(function(l){
            var sc = statusColors[l.status] || '#9ca3af';
            html += '<div style="background:#f8fafc;border-radius:8px;padding:10px 12px;margin-bottom:8px;border:1px solid #f1f5f9;">'
                + '<div style="display:flex;align-items:flex-start;justify-content:space-between;">'
                + '<div>'
                + '<div style="font-size:14px;font-weight:700;color:#1e293b;">'+l.amount_fmt+'<span style="font-size:10px;background:'+sc+'22;color:'+sc+';border-radius:20px;padding:2px 8px;margin-left:6px;font-weight:600;">'+l.status.toUpperCase()+'</span></div>'
                + '<div style="font-size:11px;color:#6b7280;margin-top:2px;">'+(l.description||'')+'</div>'
                + '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">'+l.gateway.toUpperCase()+' · '+l.created_by+' · '+l.created_at+'</div>'
                + '</div>'
                + '<div style="display:flex;gap:4px;flex-shrink:0;flex-direction:column;align-items:flex-end;">'
                + (l.short_url ? '<a href="'+lcrmEscHtml(l.short_url)+'" target="_blank" class="button lcrm-btn lcrm-btn-sm">Open ↗</a>' : '')
                + (l.status !== 'paid' ? '<button class="button lcrm-btn lcrm-btn-sm lcrm-v5-resend-pay" data-id="'+l.id+'" style="font-size:10px;">Resend WA</button>' : '')
                + '</div></div>'
                + (l.status==='paid' ? '<div style="margin-top:6px;font-size:12px;color:#10b981;font-weight:600;">✓ Paid ₹'+Number(l.paid_amount).toLocaleString('en-IN')+' on '+l.paid_at+'</div>' : '')
                + '</div>';
        });
        $list.html(html);
    });
}

$(document).on('click', '#lcrm-v5-pay-create-btn', function(){
    var leadId = window.lcrmCurrentLeadId;
    var amount = $('#lcrm-v5-pay-amount').val();
    var desc   = $('#lcrm-v5-pay-desc').val();
    var sendWa = $('#lcrm-v5-pay-sendwa').is(':checked') ? 1 : 0;
    if(!amount || parseFloat(amount) <= 0){ $('#lcrm-v5-pay-msg').text('Enter a valid amount.').css('color','#ef4444'); return; }
    $(this).text('Generating…').prop('disabled',true);
    lcrmReq('lcrm_payment_create_link', {lead_id:leadId, amount:amount, description:desc, send_wa:sendWa}, function(res){
        $('#lcrm-v5-pay-create-btn').text('Generate Link').prop('disabled',false);
        if(res.success){
            $('#lcrm-v5-pay-msg').text('Link created'+(sendWa?' and sent via WA':'')+'.').css('color','#10b981');
            $('#lcrm-v5-pay-amount').val(''); $('#lcrm-v5-pay-desc').val('');
            lcrmV5LoadPayLinks(leadId);
        } else { $('#lcrm-v5-pay-msg').text(res.data?.msg||'Error').css('color','#ef4444'); }
    });
});

$(document).on('click', '#lcrm-v5-pay-manual-btn', function(){
    var leadId = window.lcrmCurrentLeadId;
    var amount = prompt('Enter payment amount (₹):');
    if(!amount || parseFloat(amount) <= 0) return;
    var method = prompt('Payment method (Cash / Bank Transfer / UPI / Cheque):', 'Cash');
    lcrmReq('lcrm_payment_manual_record', {lead_id:leadId, amount:amount, method:method||'Cash'}, function(res){
        if(res.success){ alert(res.data.msg); lcrmV5LoadPayLinks(leadId); }
        else { alert(res.data?.msg||'Error recording payment.'); }
    });
});

$(document).on('click', '.lcrm-v5-resend-pay', function(){
    var linkId = $(this).data('id');
    lcrmReq('lcrm_payment_resend_wa', {link_id: linkId}, function(res){
        alert(res.success ? '✓ '+res.data.msg : '✗ '+(res.data?.msg||'Error'));
    });
});

// ── Client Portal Tab ─────────────────────────────────────────────────────────

function lcrmV5LoadPortal(leadId){
    // Load unread portal messages
    lcrmReq('lcrm_portal_get_messages', {lead_id: leadId}, function(res){
        if(!res.success) return;
        var msgs = res.data.messages || [];
        var unread = msgs.filter(function(m){ return !m.is_read; });
        if(!unread.length){ $('#lcrm-v5-portal-messages').empty(); return; }
        var html = '<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:13px;color:#92400e;">'
            + '<strong>📩 '+unread.length+' unread client message'+(unread.length!==1?'s':'')+'</strong><div style="margin-top:6px;">';
        unread.forEach(function(m){
            html += '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #fde68a;">'
                + '<div style="font-size:12px;color:#92400e;">'+m.created_at+'</div>'
                + '<div style="margin-top:2px;white-space:pre-wrap;">'+lcrmEscHtml(m.message)+'</div>'
                + '<button class="button lcrm-btn lcrm-btn-sm lcrm-v5-portal-read-msg" data-id="'+m.id+'" style="margin-top:4px;font-size:10px;">Mark read</button>'
                + '</div>';
        });
        html += '</div></div>';
        $('#lcrm-v5-portal-messages').html(html);
    });
}

$(document).on('click', '#lcrm-v5-portal-gen-btn', function(){
    var leadId  = window.lcrmCurrentLeadId;
    var expiry  = $('#lcrm-v5-portal-expiry').val();
    $(this).text('Generating…').prop('disabled',true);
    lcrmReq('lcrm_portal_generate_link', {lead_id:leadId, expiry_days:expiry}, function(res){
        $('#lcrm-v5-portal-gen-btn').text('Generate Link').prop('disabled',false);
        if(res.success){
            $('#lcrm-v5-portal-url').val(res.data.url);
            $('#lcrm-v5-portal-expires').text('Expires: '+res.data.expires_at);
            $('#lcrm-v5-portal-link-wrap').show();
            $('#lcrm-v5-portal-msg').text('Portal link generated.').css('color','#10b981');
        } else { $('#lcrm-v5-portal-msg').text(res.data?.msg||'Error').css('color','#ef4444'); }
    });
});

$(document).on('click', '#lcrm-v5-portal-copy-btn', function(){
    var url = $('#lcrm-v5-portal-url').val();
    if(navigator.clipboard){ navigator.clipboard.writeText(url).then(function(){ $('#lcrm-v5-portal-msg').text('Copied!').css('color','#10b981'); }); }
    else { $('#lcrm-v5-portal-url').select(); document.execCommand('copy'); $('#lcrm-v5-portal-msg').text('Copied!').css('color','#10b981'); }
});

$(document).on('click', '#lcrm-v5-portal-wa-btn', function(){
    var url = $('#lcrm-v5-portal-url').val();
    if(!url) return;
    var leadId = window.lcrmCurrentLeadId;
    var lead   = window.lcrmCurrentLead || {};
    var name   = lead.client_name || 'Client';
    var msg    = 'Hello '+name+' ji! 🙏\n\nYou can now check your case status, upload documents, and send us messages here:\n\n'+url+'\n\nThis link is private and expires in the period selected.\n\n'+lcrmSiteName;
    lcrmReq('lcrm_wa_send_reply', {lead_id:leadId, message:msg}, function(res){
        $('#lcrm-v5-portal-msg').text(res.success?'✓ Sent via WA':res.data?.msg||'Error').css('color',res.success?'#10b981':'#ef4444');
    });
});

$(document).on('click', '#lcrm-v5-portal-revoke-btn', function(){
    if(!confirm('Revoke the client portal link? The client will no longer be able to access it.')) return;
    lcrmReq('lcrm_portal_revoke_link', {lead_id: window.lcrmCurrentLeadId}, function(res){
        $('#lcrm-v5-portal-msg').text(res.success?'✓ Link revoked':res.data?.msg||'Error').css('color',res.success?'#10b981':'#ef4444');
        if(res.success){ $('#lcrm-v5-portal-link-wrap').hide(); }
    });
});

$(document).on('click', '.lcrm-v5-portal-read-msg', function(){
    var msgId = $(this).data('id');
    lcrmReq('lcrm_portal_read_message', {message_id: msgId}, function(res){ if(res.success) $(this).closest('div').remove(); }.bind(this));
});

// ── WA Template Picker Tab ────────────────────────────────────────────────────

var lcrmV5CurrentTemplateId = 0;

function lcrmV5LoadTemplates(leadId){
    var $list = $('#lcrm-v5-tpl-list');
    $list.html('<div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">Loading templates…</div>');
    lcrmReq('lcrm_wat_get_grouped', {lead_id: leadId}, function(res){
        if(!res.success){ $list.html('<div style="color:#ef4444;font-size:13px;padding:10px;">Failed to load templates.</div>'); return; }
        var groups = res.data.templates;
        var cats   = Object.keys(groups);
        if(!cats.length){ $list.html('<div style="color:#9ca3af;font-size:13px;text-align:center;padding:20px;">No templates. Add some in Settings → WA Templates.</div>'); return; }
        var html = '';
        cats.forEach(function(cat){
            html += '<div style="margin-bottom:12px;"><div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">'+cat+'</div>';
            groups[cat].forEach(function(t){
                html += '<div style="background:#f8fafc;border-radius:8px;padding:8px 10px;margin-bottom:6px;cursor:pointer;border:1.5px solid transparent;transition:.15s;" '
                    + 'class="lcrm-v5-tpl-row" data-id="'+t.id+'" title="Click to preview">'
                    + '<div style="font-size:13px;font-weight:600;color:#374151;">'+lcrmEscHtml(t.title)+'</div>'
                    + '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">'+lcrmEscHtml(t.preview)+'</div>'
                    + '</div>';
            });
            html += '</div>';
        });
        $list.html(html);
    });
}

$(document).on('click', '.lcrm-v5-tpl-row', function(){
    var tplId  = $(this).data('id');
    var leadId = window.lcrmCurrentLeadId;
    lcrmV5CurrentTemplateId = tplId;
    $('.lcrm-v5-tpl-row').css('border-color','transparent');
    $(this).css('border-color','#6366f1');
    lcrmReq('lcrm_wat_render', {template_id:tplId, lead_id:leadId}, function(res){
        if(res.success){ $('#lcrm-v5-tpl-text').val(res.data.text); $('#lcrm-v5-tpl-preview').show(); }
    });
});

$(document).on('click', '#lcrm-v5-tpl-cancel-btn', function(){
    $('#lcrm-v5-tpl-preview').hide();
    $('.lcrm-v5-tpl-row').css('border-color','transparent');
    lcrmV5CurrentTemplateId = 0;
});

$(document).on('click', '#lcrm-v5-tpl-send-btn', function(){
    var leadId = window.lcrmCurrentLeadId;
    var text   = $('#lcrm-v5-tpl-text').val().trim();
    if(!text){ $('#lcrm-v5-tpl-send-msg').text('Message cannot be empty.').css('color','#ef4444'); return; }
    $(this).text('Sending…').prop('disabled',true);
    lcrmReq('lcrm_wat_send', {lead_id:leadId, template_id:lcrmV5CurrentTemplateId, custom_text:text}, function(res){
        $('#lcrm-v5-tpl-send-btn').text('Send via WA').prop('disabled',false);
        if(res.success){
            $('#lcrm-v5-tpl-send-msg').text('✓ Sent!').css('color','#10b981');
            setTimeout(function(){ $('#lcrm-v5-tpl-preview').hide(); $('.lcrm-v5-tpl-row').css('border-color','transparent'); lcrmV5CurrentTemplateId=0; },1500);
        } else { $('#lcrm-v5-tpl-send-msg').text(res.data?.msg||'Error').css('color','#ef4444'); }
    });
});

// Fire tab_change event when lead modal switches tabs
$(document).on('click', '.lcrm-ftab', function(){
    var tabKey = $(this).data('tab');
    var leadId = window.lcrmCurrentLeadId;
    if(tabKey && leadId){ $(document).trigger('lcrm:modal_tab_change', [tabKey, leadId]); }
});


/* ── V5 compatibility shims ── */
// lcrmEscHtml aliases the existing lcrmEsc helper
function lcrmEscHtml(s){ return typeof lcrmEsc === 'function' ? lcrmEsc(s) : String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// lcrmReq aliases the existing lcrmPost helper
function lcrmReq(action, data, cb) {
    lcrmPost(action, data, function(res){ cb({success:true, data:res}); }, function(err){ cb({success:false, data:{msg:err.msg||'Request failed'}}); });
}
// lcrmCurrentLeadId mirrors lcrmEditId (set when lead modal opens)
Object.defineProperty(window, 'lcrmCurrentLeadId', {
    get: function(){ return window.lcrmEditId || 0; },
    set: function(v){ window.lcrmEditId = v; }
});
// lcrmCurrentLead mirrors last filled lead
Object.defineProperty(window, 'lcrmCurrentLead', {
    get: function(){ return window.lcrmLastFilledLead || {}; }
});
// lcrmSiteName from LCRM localized object
var lcrmSiteName = (typeof LCRM !== 'undefined' && LCRM.site_name) ? LCRM.site_name : '';

})(jQuery);
