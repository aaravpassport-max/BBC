/* LeadCRM admin.js v3.3.0 — Built 2026-05-09 */
(function($){
'use strict';

/* ============================================================
   GLOBALS
   ============================================================ */
var lcrmPage=1, lcrmSort='created_at', lcrmOrder='DESC', lcrmFilters={};
var lcrmEditId=0, lcrmSelectedIds=[];
var lcrmSaving=false, lcrmFormDirty=false;

/* ============================================================
   UTILITY
   ============================================================ */
function lcrmPost(action,data,cb,errCb){
    data.action=action; data.nonce=LCRM.nonce;
    $.post(LCRM.ajax_url,data)
     .done(function(r){
         if(r && typeof r==='object' && r.success){if(cb)cb(r.data);}
         else if(errCb) errCb((r && r.data)?r.data:{msg:'Server error (action: '+action+'). Check PHP logs.'});
     })
     .fail(function(){ if(errCb)errCb({msg:'Connection error. Please try again.'}); });
}
function lcrmErr(d){ return (d&&d.msg)?d.msg:'An error occurred.'; }
function lcrmMsg($el,msg,type){ $el.removeClass('lcrm-msg-ok lcrm-msg-err').addClass(type==='ok'?'lcrm-msg-ok':'lcrm-msg-err').text(msg).show(); setTimeout(function(){ $el.fadeOut(); },3000); }
function lcrmConfirm(msg){ return window.confirm(msg); }
function lcrmFmt(n){ return parseFloat(n||0).toFixed(2); }
function lcrmEsc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ============================================================
   MASTER COLUMN DEFINITIONS
   ============================================================ */
var LCRM_BUILTIN_COLS = [
    {key:'check',          label:'',              cls:'col-check',         sortable:false,resizable:false,reorderable:false,defaultWidth:36, defaultVisible:true },
    {key:'id',             label:'ID',            cls:'col-id',            sortable:true, sortField:'id',           defaultWidth:65, defaultVisible:true },
    {key:'lead_date',      label:'Date',          cls:'col-date',          sortable:true, sortField:'lead_date',    defaultWidth:90, defaultVisible:true },
    {key:'client_name',    label:'Client',        cls:'col-client',        sortable:true, sortField:'client_name',  defaultWidth:145,defaultVisible:true },
    {key:'client_phone',   label:'Phone',         cls:'col-phone',         sortable:false,defaultWidth:120,defaultVisible:true },
    {key:'client_email',   label:'Email',         cls:'col-email',         sortable:false,defaultWidth:145,defaultVisible:false},
    {key:'work_type',      label:'Work Type',     cls:'col-wt',            sortable:true, sortField:'work_type',    defaultWidth:120,defaultVisible:true },
    {key:'lead_source',    label:'Source',        cls:'col-src',           sortable:false,defaultWidth:100,defaultVisible:false},
    {key:'stage',          label:'Stage',         cls:'col-stage',         sortable:false,defaultWidth:110,defaultVisible:true },
    {key:'brand',          label:'Brand',         cls:'col-brand',         sortable:false,defaultWidth:90, defaultVisible:true },
    {key:'priority',       label:'Priority',      cls:'col-priority',      sortable:false,defaultWidth:78, defaultVisible:true },
    {key:'temperature',    label:'Temp',          cls:'col-temp',          sortable:false,defaultWidth:72, defaultVisible:true },
    {key:'next_action',    label:'Next Action',   cls:'col-next',          sortable:false,defaultWidth:108,defaultVisible:true },
    {key:'vendor',         label:'Vendor',        cls:'col-vendor',        sortable:false,defaultWidth:108,defaultVisible:true },
    {key:'total_quote',    label:'Quote \u20b9',  cls:'col-quote',         sortable:true, sortField:'total_quote',  defaultWidth:90, defaultVisible:true },
    {key:'pmt_received',   label:'PMT Recd',      cls:'col-pmt',           sortable:true, sortField:'pmt_received', defaultWidth:88, defaultVisible:true },
    {key:'balance',        label:'Balance',       cls:'col-bal',           sortable:false,defaultWidth:88, defaultVisible:true },
    {key:'vendor_charge',  label:'Vnd Charge',    cls:'col-vcharge',       sortable:false,defaultWidth:90, defaultVisible:false, confFinance:true},
    {key:'profit',         label:'Profit',        cls:'col-profit',        sortable:false,defaultWidth:82, defaultVisible:true,  confFinance:true},
    {key:'internal_status',label:'Int. Status',   cls:'col-intstat',       sortable:false,defaultWidth:100,defaultVisible:false},
    {key:'lead_score',     label:'Score',          cls:'col-score',         sortable:false,defaultWidth:58, defaultVisible:false},
    {key:'assigned_user',  label:'Assigned To',    cls:'col-assigned',      sortable:false,defaultWidth:130,defaultVisible:true},
    {key:'remarks',        label:'Remarks',        cls:'col-remarks',       sortable:false,defaultWidth:180,defaultVisible:true, wrapDefault:true},
    {key:'final_remarks',  label:'Last Conv.',     cls:'col-final-remarks', sortable:false,defaultWidth:220,defaultVisible:true, wrapDefault:true},
    {key:'last_conv_date', label:'Last Conv. Date',cls:'col-last-conv-date',sortable:false,defaultWidth:130,defaultVisible:true},
    {key:'created_at',     label:'Created',        cls:'col-created',       sortable:true, sortField:'created_at',   defaultWidth:100,defaultVisible:false},
    {key:'actions',        label:'Actions',        cls:'col-actions',       sortable:false,resizable:false,reorderable:false,defaultWidth:115,defaultVisible:true }
];
// Remove confidential finance columns for non-admin users
if (!LCRM.can_conf_finance) {
    LCRM_BUILTIN_COLS = LCRM_BUILTIN_COLS.filter(function(c){ return !c.confFinance; });
}

/* ============================================================
   COLUMN STATE  (localStorage key: lcrm_col_state_v4)
   ============================================================ */
var lcrmColState = null;

function lcrmGetAllColDefs(){
    var all = LCRM_BUILTIN_COLS.slice();
    var customFields = (LCRM.all_fields && LCRM.all_fields.length) ? LCRM.all_fields : (LCRM.list_fields || []);
    customFields.forEach(function(f){
        // avoid duplicates
        var exists = all.some(function(c){ return c.key===f.key; });
        if(!exists){
            all.push({
                key:f.key, label:f.label,
                cls:'col-cf-'+f.key.replace(/[^a-z0-9]/g,'-'),
                sortable:false, isCustom:true,
                defaultWidth:120, defaultVisible:!!f.in_list, wrapDefault:false
            });
        }
    });
    return all;
}

function lcrmDefaultColState(){
    var allDefs = lcrmGetAllColDefs();
    var order = allDefs.map(function(c){ return c.key; });
    var cols  = {};
    allDefs.forEach(function(c){
        cols[c.key] = {visible:c.defaultVisible, width:c.defaultWidth, wrap:c.wrapDefault||false};
    });
    return {order:order, cols:cols, wrapAll:false};
}

function lcrmLoadColState(){
    try{
        var raw = localStorage.getItem('lcrm_col_state_v4');
        if(raw){
            var saved = JSON.parse(raw);
            var def   = lcrmDefaultColState();
            // Add new keys not in saved
            var knownKeys={};
            (saved.order||[]).forEach(function(k){ knownKeys[k]=true; });
            def.order.forEach(function(k){ if(!knownKeys[k]) saved.order.push(k); });
            // Remove obsolete keys
            var validKeys={};
            def.order.forEach(function(k){ validKeys[k]=true; });
            saved.order = (saved.order||[]).filter(function(k){ return validKeys[k]; });
            // Fill missing col state
            if(!saved.cols) saved.cols={};
            def.order.forEach(function(k){
                if(!saved.cols[k]) saved.cols[k] = def.cols[k]||{visible:false,width:120,wrap:false};
            });
            saved.wrapAll = saved.wrapAll||false;
            lcrmColState = saved;
        } else {
            lcrmColState = lcrmDefaultColState();
        }
    } catch(e){ lcrmColState = lcrmDefaultColState(); }
}

function lcrmSaveColState(){
    try{ localStorage.setItem('lcrm_col_state_v4', JSON.stringify(lcrmColState)); }catch(e){}
}

function lcrmGetOrderedColDefs(){
    var allDefs = lcrmGetAllColDefs();
    var defMap  = {};
    allDefs.forEach(function(c){ defMap[c.key]=c; });
    var ordered = [];
    (lcrmColState.order||[]).forEach(function(k){ if(defMap[k]) ordered.push(defMap[k]); });
    allDefs.forEach(function(c){ if(!ordered.some(function(x){return x.key===c.key;})) ordered.push(c); });
    return ordered;
}

function lcrmGetVisibleCols(){
    return lcrmGetOrderedColDefs().filter(function(c){
        var s = lcrmColState.cols[c.key];
        return s ? s.visible : c.defaultVisible;
    });
}

/* ============================================================
   BUILD THEAD — completely driven by column state
   ============================================================ */
function lcrmBuildThead(){
    var visCols = lcrmGetVisibleCols();
    var $tr = $('<tr>');
    visCols.forEach(function(col){
        var state = lcrmColState.cols[col.key]||{};
        var w = state.width||col.defaultWidth;
        var $th = $('<th>').addClass('lcrm-th '+col.cls).css('width',w+'px').attr('data-col-key',col.key);
        if(col.sortable){ $th.addClass('lcrm-sort').attr('data-col',col.sortField); }
        if(lcrmSort===col.sortField) $th.addClass('lcrm-sort-active');
        var $inner = $('<div class="lcrm-th-inner">');
        if(col.reorderable!==false && col.key!=='check'){
            $inner.append('<span class="lcrm-th-drag-grip" title="Drag to reorder column">\u28bf</span>');
        } else if(col.key==='check'){
            $inner.append('<input type="checkbox" id="lcrm-check-all" style="margin:0;">');
        } else {
            $inner.append('<span style="width:14px;display:inline-block;flex-shrink:0;"></span>');
        }
        if(col.key!=='check') $inner.append($('<span class="lcrm-th-label">').text(col.label));
        if(col.sortable && lcrmSort===col.sortField){
            $inner.append($('<span class="lcrm-sort-ind">').text(lcrmOrder==='ASC'?' \u25b2':' \u25bc'));
        }
        $th.append($inner);
        if(col.resizable!==false && col.key!=='check'){
            $th.append('<span class="lcrm-col-resize" data-col-key="'+col.key+'" title="Drag to resize column"></span>');
        }
        $tr.append($th);
    });
    var $thead = $('#lcrm-thead');
    if(!$thead.length){ console.error('[LeadCRM] #lcrm-thead not found in DOM'); return; }
    $thead.empty().append($tr);
    lcrmInitColResize();
    lcrmInitColReorder();
}

/* ============================================================
   COLUMN RESIZE — drag the right edge of a header cell
   ============================================================ */
function lcrmInitColResize(){
    $('#lcrm-leads-table').off('mousedown.colresize').on('mousedown.colresize','.lcrm-col-resize',function(e){
        e.preventDefault(); e.stopPropagation();
        var $handle=$(this), colKey=$handle.data('col-key');
        var $th=$handle.closest('th'), startX=e.pageX, startW=$th.outerWidth();
        $handle.addClass('lcrm-resizing');
        $('body').css({cursor:'col-resize','user-select':'none'});
        $(document).on('mousemove.colresize',function(ev){
            var newW=Math.max(48,startW+(ev.pageX-startX));
            $th.css('width',newW+'px');
        });
        $(document).on('mouseup.colresize',function(){
            $(document).off('mousemove.colresize mouseup.colresize');
            $handle.removeClass('lcrm-resizing');
            $('body').css({cursor:'','user-select':''});
            if(!lcrmColState.cols[colKey]) lcrmColState.cols[colKey]={};
            lcrmColState.cols[colKey].width=$th.outerWidth();
            lcrmSaveColState();
        });
    });
}

/* ============================================================
   COLUMN REORDER — SortableJS on thead <tr>
   ============================================================ */
function lcrmInitColReorder(){
    var thead=document.querySelector('#lcrm-leads-table thead tr');
    if(!thead||typeof Sortable==='undefined') return;
    if($(thead).data('sortable-init')) return;
    $(thead).data('sortable-init',true);
    Sortable.create(thead,{
        handle:'.lcrm-th-drag-grip', animation:140,
        ghostClass:'lcrm-thead-drag-ghost', chosenClass:'lcrm-thead-drag-chosen',
        filter:'.col-check,.col-actions',
        onEnd:function(evt){
            if(evt.oldIndex===evt.newIndex) return;
            lcrmReorderBodyCells(evt.oldIndex,evt.newIndex);
            var newOrder=[];
            $('#lcrm-leads-table thead tr th').each(function(){ var k=$(this).data('col-key'); if(k) newOrder.push(k); });
            var hidden=lcrmColState.order.filter(function(k){ return newOrder.indexOf(k)<0; });
            lcrmColState.order=newOrder.concat(hidden);
            lcrmSaveColState();
        }
    });
}

function lcrmReorderBodyCells(fromIdx,toIdx){
    if(fromIdx===toIdx) return;
    $('#lcrm-leads-table tbody tr').each(function(){
        var $tds=$(this).children('td');
        if($tds.length<=Math.max(fromIdx,toIdx)) return;
        if(fromIdx<toIdx){ $tds.eq(fromIdx).insertAfter($tds.eq(toIdx)); }
        else { $tds.eq(fromIdx).insertBefore($tds.eq(toIdx)); }
    });
}

/* ============================================================
   RENDER SINGLE CELL per column key
   ============================================================ */
function lcrmRenderCell(col,l){
    var state=lcrmColState.cols[col.key]||{};
    var wrap=lcrmColState.wrapAll||(state.wrap||col.wrapDefault||false);
    var wrapCls=wrap?' lcrm-col-wrap':' lcrm-col-nowrap';
    var cls=col.cls+' lcrm-td'+wrapCls;

    switch(col.key){
        case 'check':
            return '<td class="'+col.cls+' lcrm-td" style="text-align:center;"><input type="checkbox" class="lcrm-row-check" value="'+l.id+'"></td>';
        case 'id':
            return '<td class="'+cls+'"><strong>#'+l.id+'</strong><div style="font-size:10px;color:#94a3b8;">Sc:'+lcrmEsc(String(l.lead_score||0))+'</div></td>';
        case 'lead_date':
            return '<td class="'+cls+'">'+lcrmEsc(l.lead_date_d||'')+'</td>';
        case 'client_name':
            return '<td class="'+cls+'"><strong>'+lcrmEsc(l.client_name)+'</strong></td>';
        case 'client_phone':
            return '<td class="'+cls+'">'+lcrmEsc(l.client_phone||'')+'</td>';
        case 'client_email':
            return '<td class="'+cls+'">'+lcrmEsc(l.client_email||'\u2014')+'</td>';
        case 'work_type':
            return '<td class="'+cls+' lcrm-editable" data-editable="work_type" data-lead-id="'+l.id+'" data-val="'+lcrmEsc(l.work_type)+'">'+lcrmEsc(l.work_type)+'</td>';
        case 'lead_source':
            return '<td class="'+cls+'">'+lcrmEsc(l.lead_source||'\u2014')+'</td>';
        case 'stage':
            return '<td class="'+cls+' lcrm-editable" data-editable="stage_id" data-lead-id="'+l.id+'" data-val="'+l.stage_id+'" style="vertical-align:middle;"><span class="lcrm-badge" style="background:'+l.stage_color+'">'+lcrmEsc(l.stage_name)+'</span></td>';
        case 'brand':
            return '<td class="'+cls+'">'+lcrmEsc(l.brand_name||'\u2014')+'</td>';
        case 'priority':
            var priCls=l.priority==='high'?'lcrm-badge-red':(l.priority==='medium'?'lcrm-badge-amber':'lcrm-badge-gray');
            return '<td class="'+cls+' lcrm-editable" data-editable="priority" data-lead-id="'+l.id+'" data-val="'+l.priority+'"><span class="lcrm-badge '+priCls+'">'+l.priority+'</span></td>';
        case 'temperature':
            var tCls=l.lead_temperature==='hot'?'lcrm-badge-red':(l.lead_temperature==='warm'?'lcrm-badge-amber':'lcrm-badge-blue');
            var tIcon=l.lead_temperature==='hot'?'\ud83d\udd25':(l.lead_temperature==='warm'?'\u2600':'\u2744');
            return '<td class="'+cls+' lcrm-editable" data-editable="lead_temperature" data-lead-id="'+l.id+'" data-val="'+l.lead_temperature+'"><span class="lcrm-badge '+tCls+'">'+tIcon+' '+l.lead_temperature+'</span></td>';
        case 'next_action':
            return '<td class="'+cls+' lcrm-editable" data-editable="next_action_date" data-lead-id="'+l.id+'" data-val="'+(l.next_action_date||'')+'">'+lcrmEsc(l.next_action_d||'\u2014')+'<br><small style="color:#94a3b8;">'+lcrmEsc(l.next_action_type||'')+'</small></td>';
        case 'vendor':
            return '<td class="'+cls+'">'+lcrmEsc(l.vendor_name||'\u2014')+'</td>';
        case 'total_quote':   return '<td class="'+cls+'">\u20b9'+l.total_quote+'</td>';
        case 'pmt_received':  return '<td class="'+cls+'">\u20b9'+l.pmt_received+'</td>';
        case 'balance':       return '<td class="'+cls+'">\u20b9'+l.balance_payment+'</td>';
        case 'vendor_charge': return '<td class="'+cls+'">\u20b9'+(l.vendor_charge||'0.00')+'</td>';
        case 'profit':        return '<td class="'+cls+'">\u20b9'+l.profit+'</td>';
        case 'internal_status': return '<td class="'+cls+'">'+lcrmEsc(l.internal_status||'\u2014')+'</td>';
        case 'lead_score':    return '<td class="'+cls+'">'+lcrmEsc(String(l.lead_score||0))+'</td>';
        case 'remarks':
            return '<td class="'+cls+' lcrm-editable" data-editable="remarks" data-lead-id="'+l.id+'" data-val="'+lcrmEsc(l.remarks)+'" title="'+lcrmEsc(l.remarks)+'">'+lcrmEsc(l.remarks)+'</td>';
        case 'assigned_user':
            if(l.is_access_revoked){
                return '<td class="'+cls+'" style="vertical-align:middle;padding:5px 8px;">'
                    +'<span style="display:inline-block;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">&#128274; Revoked</span>'
                    +'</td>';
            }
            var aName=l.assigned_user_name||'— Unassigned —';
            var aBg=l.assigned_to?'background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;':'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;font-style:italic;';
            return '<td class="'+cls+'" style="vertical-align:middle;padding:5px 8px;">'
                +'<span style="display:inline-block;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:600;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis;'+aBg+'">'+lcrmEsc((l.assigned_to?'\ud83d\udc64 ':'')+aName)+'</span>'
                +'</td>';
        case 'final_remarks':
            if(l.last_day_conv){
                var fmP=l.last_conv_date?l.last_conv_date.split('-'):[], fmM=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                var fmD=fmP.length>=3?parseInt(fmP[2],10)+' '+(fmM[parseInt(fmP[1],10)-1]||fmP[1])+' '+fmP[0]:'';
                var snip=l.last_day_conv.length>120?l.last_day_conv.slice(0,120)+'\u2026':l.last_day_conv;
                return '<td class="'+cls+' lcrm-fr-cell" title="'+lcrmEsc(l.last_day_conv)+'">'
                    +(fmD?'<div class="lcrm-fr-date">&#128197; '+lcrmEsc(fmD)+'</div>':'')
                    +'<div class="lcrm-fr-msg">'+lcrmEsc(snip)+'</div></td>';
            }
            return '<td class="'+cls+' lcrm-editable" data-editable="final_remarks" data-lead-id="'+l.id+'" data-val="'+lcrmEsc(l.final_remarks)+'" title="'+lcrmEsc(l.final_remarks)+'">'+lcrmEsc(l.final_remarks||'\u2014')+'</td>';
        case 'last_conv_date':
            if(!l.last_conv_date) return '<td class="'+cls+'" style="color:#94a3b8;padding:6px 8px;vertical-align:middle;font-size:12px;">&#128172; None</td>';
            var lp=l.last_conv_date.split('-'),lm=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var ld=parseInt(lp[2],10)+' '+(lm[parseInt(lp[1],10)-1]||lp[1])+' '+lp[0];
            return '<td class="'+cls+'" style="vertical-align:middle;padding:6px 8px;">'
                +'<div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:3px;">'+lcrmEsc(ld)+'</div>'
                +'<button type="button" class="lcrm-view-conv-btn" data-id="'+l.id+'" style="font-size:11px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:6px;padding:2px 8px;color:#6d28d9;cursor:pointer;font-weight:600;">&#128172; History</button>'
                +'</td>';
        case 'created_at': return '<td class="'+cls+'">'+lcrmEsc(l.created_at||'\u2014')+'</td>';
        case 'actions':
            var act='<td class="'+col.cls+' lcrm-td" style="white-space:nowrap;">';
            act+='<button class="button lcrm-btn lcrm-btn-sm lcrm-edit-lead" data-id="'+l.id+'">Edit</button> ';
            if(l.client_phone) act+='<a href="https://wa.me/'+l.client_phone.replace(/[^0-9]/g,'')+'" target="_blank" class="lcrm-wa-btn lcrm-btn-xs">\ud83d\udcac WA</a> ';
            if(LCRM.is_admin) act+='<button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger lcrm-delete-lead" data-id="'+l.id+'">Del</button>';
            act+='</td>'; return act;
        default:
            if(col.isCustom){
                var cfv=(l.custom_field_values||{})[col.key]||'\u2014';
                return '<td class="'+cls+'">'+lcrmEsc(cfv)+'</td>';
            }
            return '<td class="'+cls+'">\u2014</td>';
    }
}

/* ============================================================
   COLUMN OPTIONS PANEL — built dynamically by JS
   ============================================================ */
function lcrmBuildColPickerPanel(){
    $('#lcrm-col-picker-panel').remove();
    var $panel=$('<div id="lcrm-col-picker-panel" style="display:none;">');
    // Header
    $panel.append('<div class="lcrm-col-panel-hdr"><span>\u2630 Column Options</span><button class="lcrm-col-panel-close" id="lcrm-col-panel-close-btn">&times;</button></div>');
    // Global settings
    var $gs=$('<div class="lcrm-col-panel-section">');
    $gs.append('<h4>Global Settings</h4>');
    $gs.append('<label class="lcrm-wrap-global-row"><input type="checkbox" id="lcrm-wrap-all-chk"'+(lcrmColState.wrapAll?' checked':'')+'> \u21b5 Wrap Text \u2014 All Columns</label>');
    $panel.append($gs);
    // Columns section
    var $cs=$('<div class="lcrm-col-panel-section">');
    $cs.append('<h4>Columns (' + lcrmGetAllColDefs().length + ' total)</h4>');
    $cs.append('<p class="lcrm-col-panel-hint">\u283f Drag to reorder &nbsp;\u2022&nbsp; Toggle visibility &nbsp;\u2022&nbsp; \u21b5 = Wrap text</p>');
    var $list=$('<ul class="lcrm-col-panel-list" id="lcrm-col-panel-list">');
    lcrmGetOrderedColDefs().forEach(function(col){
        if(col.key==='check') return;
        var state=lcrmColState.cols[col.key]||{};
        var vis=state.hasOwnProperty('visible')?state.visible:col.defaultVisible;
        var wrp=state.hasOwnProperty('wrap')?state.wrap:(col.wrapDefault||false);
        var isFixed=(col.key==='actions');
        var $li=$('<li class="lcrm-col-item'+(isFixed?' lcrm-col-item-fixed':'')+'" data-col-key="'+col.key+'">');
        if(!isFixed) $li.append('<span class="lcrm-drag-handle" title="Drag to reorder">\u2807</span>');
        else $li.append('<span style="width:18px;display:inline-block;"></span>');
        var $lbl=$('<label>').append('<input type="checkbox" class="lcrm-col-vis-chk" data-col="'+col.key+'"'+(vis?' checked':'')+(isFixed?' disabled':'')+'> ').append(col.label);
        if(col.isCustom) $lbl.append(' <span class="lcrm-badge lcrm-badge-green" style="font-size:8px;padding:1px 3px;">CF</span>');
        $li.append($lbl);
        if(!isFixed) $li.append('<button class="lcrm-wrap-col-btn'+(wrp?' active':'')+'" data-col="'+col.key+'" title="Toggle text wrap">\u21b5</button>');
        $list.append($li);
    });
    $cs.append($list);
    $panel.append($cs);
    // Footer
    $panel.append('<div class="lcrm-col-panel-footer"><button class="button button-primary lcrm-btn lcrm-btn-sm" id="lcrm-col-apply-btn">\u2713 Apply</button><button class="button lcrm-btn lcrm-btn-sm" id="lcrm-col-reset-btn">\u21ba Defaults</button></div>');
    var $anchor=$('#lcrm-col-panel-anchor');
    if(!$anchor.length){
        console.warn('[LeadCRM] col-panel-anchor not found, attaching to topbar-actions');
        $anchor=$('.lcrm-topbar-actions');
        $panel.css({'position':'fixed','top':'48px','right':'20px'});
    }
    $anchor.append($panel);
    // Sortable
    if(typeof Sortable!=='undefined'){
        Sortable.create(document.getElementById('lcrm-col-panel-list'),{
            handle:'.lcrm-drag-handle',animation:130,
            ghostClass:'lcrm-drag-ghost',chosenClass:'lcrm-drag-chosen',
            filter:'.lcrm-col-item-fixed'
        });
    }
}

/* ============================================================
   LEADS LIST
   ============================================================ */
function lcrmLoadLeads(){
    var span=lcrmGetVisibleCols().length||20;
    $('#lcrm-tbody').html('<tr><td colspan="'+span+'" class="lcrm-loading">Loading\u2026</td></tr>');
    var data=$.extend({page:lcrmPage,orderby:lcrmSort,order:lcrmOrder},lcrmFilters);
    lcrmPost('lcrm_get_leads',data,function(d){
        var html='';
        if(!d.leads||!d.leads.length){
            html='<tr><td colspan="'+span+'" class="lcrm-loading">No leads found.</td></tr>';
        } else {
            var vc=lcrmGetVisibleCols(), colCount=vc.length;
            d.leads.forEach(function(l){
                html+='<tr class="lcrm-lead-row" data-id="'+l.id+'">';
                vc.forEach(function(col){ html+=lcrmRenderCell(col,l); });
                html+='</tr>';
                html+='<tr class="lcrm-iconv-row" id="lcrm-iconv-'+l.id+'" style="display:none;">'
                    +'<td colspan="'+colCount+'" class="lcrm-iconv-td">'
                    +'<div id="lcrm-iconv-panel-'+l.id+'" class="lcrm-iconv-panel">'
                    +'<div class="lcrm-iconv-loading"><span class="lcrm-spinner"></span> Loading\u2026</div>'
                    +'</div></td></tr>';
            });
        }
        $('#lcrm-tbody').html(html);
        lcrmBuildPagination(d.total,d.pages,d.page);
        if(lcrmColState.wrapAll) $('#lcrm-leads-table').addClass('lcrm-wrap-all');
        else $('#lcrm-leads-table').removeClass('lcrm-wrap-all');
        if(!sessionStorage.getItem('lcrm_hint_v3')){
            var $h=$('<div style="font-size:11px;color:#546e7a;padding:4px 10px;background:#e8f4ff;border-bottom:1px solid #b3e5fc;">\u270e <strong>Tip:</strong> Double-click any Stage, Priority, Temp, Work Type, or Remarks cell to edit inline. Click <strong>Columns</strong> to add, reorder, resize, or wrap text columns.</div>');
            $('#lcrm-leads-table').before($h);
            sessionStorage.setItem('lcrm_hint_v3','1');
            setTimeout(function(){ $h.fadeOut(400); },8000);
        }
    },function(msg){
        $('#lcrm-tbody').html('<tr><td colspan="'+span+'" class="lcrm-loading">'+lcrmErr(msg)+'</td></tr>');
    });
}

function lcrmBuildPagination(total,pages,page){
    var h='<span class="lcrm-pag-info">'+total+' leads</span>';
    if(pages>1){
        if(page>1) h+='<button class="lcrm-pag-btn" data-page="'+(page-1)+'">&laquo;</button>';
        var s=Math.max(1,page-3),e=Math.min(pages,page+3);
        for(var i=s;i<=e;i++) h+='<button class="lcrm-pag-btn'+(i===page?' active':'')+'" data-page="'+i+'">'+i+'</button>';
        if(page<pages) h+='<button class="lcrm-pag-btn" data-page="'+(page+1)+'">&raquo;</button>';
    }
    $('#lcrm-pagination').html(h);
}

/* ============================================================
   COLUMN PANEL EVENTS
   ============================================================ */
$(document).on('click','#lcrm-col-picker-btn',function(e){
    e.stopPropagation();
    $('#lcrm-filter-settings-panel').hide();
    try {
        if(!$('#lcrm-col-picker-panel').length){
            lcrmBuildColPickerPanel();
        }
        $('#lcrm-col-picker-panel').toggle();
    } catch(e) {
        console.error('[LeadCRM] Col picker error:', e);
        alert('Column options error: ' + e.message);
    }
});
$(document).on('click','#lcrm-col-panel-close-btn',function(){ $('#lcrm-col-picker-panel').hide(); });
$(document).on('click',function(e){
    if(!$(e.target).closest('#lcrm-col-panel-anchor,#lcrm-col-picker-btn').length) $('#lcrm-col-picker-panel').hide();
    if(!$(e.target).closest('#lcrm-filter-settings-panel,#lcrm-filter-settings-btn').length) $('#lcrm-filter-settings-panel').hide();
});
$(document).on('click','.lcrm-prefs-close',function(){ $('#'+$(this).data('target')).hide(); });

// Wrap All toggle
$(document).on('change','#lcrm-wrap-all-chk',function(){
    lcrmColState.wrapAll=$(this).is(':checked');
    $('#lcrm-leads-table').toggleClass('lcrm-wrap-all',lcrmColState.wrapAll);
});

// Per-column wrap toggle button
$(document).on('click','.lcrm-wrap-col-btn',function(e){
    e.stopPropagation();
    var colKey=$(this).data('col');
    if(!lcrmColState.cols[colKey]) lcrmColState.cols[colKey]={};
    var nowWrap=!lcrmColState.cols[colKey].wrap;
    lcrmColState.cols[colKey].wrap=nowWrap;
    $(this).toggleClass('active',nowWrap);
    // Apply immediately to existing cells
    var colDef=lcrmGetAllColDefs().filter(function(c){ return c.key===colKey; })[0];
    if(colDef){
        $('#lcrm-leads-table td.'+colDef.cls).toggleClass('lcrm-col-wrap',nowWrap).toggleClass('lcrm-col-nowrap',!nowWrap);
    }
});

// Apply & Save
$(document).on('click','#lcrm-col-apply-btn',function(){
    $('.lcrm-col-vis-chk').each(function(){
        var col=$(this).data('col');
        if(!lcrmColState.cols[col]) lcrmColState.cols[col]={};
        lcrmColState.cols[col].visible=$(this).is(':checked');
    });
    var newOrder=['check'];
    $('#lcrm-col-panel-list .lcrm-col-item').each(function(){
        var k=$(this).data('col-key');
        if(k&&k!=='check'&&k!=='actions') newOrder.push(k);
    });
    newOrder.push('actions');
    var inNew={};
    newOrder.forEach(function(k){ inNew[k]=true; });
    lcrmColState.order.forEach(function(k){ if(!inNew[k]) newOrder.push(k); });
    lcrmColState.order=newOrder;
    lcrmSaveColState();
    $('#lcrm-col-picker-panel').remove();
    lcrmBuildThead();
    lcrmLoadLeads();
    var $btn=$(this); $btn.text('\u2713 Saved!'); setTimeout(function(){ $btn.text('\u2713 Apply'); },1500);
});

// Reset to defaults
$(document).on('click','#lcrm-col-reset-btn',function(){
    if(!confirm('Reset all column settings to defaults?')) return;
    lcrmColState=lcrmDefaultColState();
    lcrmSaveColState();
    $('#lcrm-col-picker-panel').remove();
    lcrmBuildThead();
    lcrmLoadLeads();
});

/* ============================================================
   FILTER VISIBILITY
   ============================================================ */
var lcrmFilterPrefs=null;
var lcrmDefaultVisibleFilters=['lcrm-search-wrap','lcrm-fwrap-stage','lcrm-fwrap-vendor','lcrm-fwrap-brand','lcrm-fwrap-wt','lcrm-fwrap-priority','lcrm-fwrap-temp','lcrm-fwrap-date'];
function lcrmLoadFilterPrefs(cb){
    try{ var r=localStorage.getItem('lcrm_filter_prefs'); lcrmFilterPrefs=r?JSON.parse(r):null; }catch(e){ lcrmFilterPrefs=null; }
    if(cb) cb();
}
function lcrmApplyFilterPrefs(){
    $('.lcrm-filter-toggle').each(function(){
        var fid=$(this).data('filter');
        var def=lcrmDefaultVisibleFilters.indexOf(fid)!==-1;
        var vis=lcrmFilterPrefs&&lcrmFilterPrefs.hasOwnProperty(fid)?lcrmFilterPrefs[fid]:def;
        $(this).prop('checked',vis); $('#'+fid).toggle(vis);
    });
}
$(document).on('click','#lcrm-filter-settings-btn',function(e){
    e.stopPropagation(); $('#lcrm-col-picker-panel').hide(); $('#lcrm-filter-settings-panel').toggle();
});
$(document).on('change','.lcrm-filter-toggle',function(){
    var fid=$(this).data('filter'),show=$(this).is(':checked');
    $('#'+fid).toggle(show);
    if(!lcrmFilterPrefs) lcrmFilterPrefs={};
    lcrmFilterPrefs[fid]=show;
});
$(document).on('click','#lcrm-filter-save-btn',function(){
    if(!lcrmFilterPrefs) lcrmFilterPrefs={};
    $('.lcrm-filter-toggle').each(function(){ lcrmFilterPrefs[$(this).data('filter')]=$(this).is(':checked'); });
    try{ localStorage.setItem('lcrm_filter_prefs',JSON.stringify(lcrmFilterPrefs)); }catch(e){}
    var btn=this; $(btn).text('\u2713 Saved!'); setTimeout(function(){ $(btn).text('Save Preferences'); },1500);
});

/* ============================================================
   FILTERS & SORT
   ============================================================ */
$(document).on('click','#lcrm-filter-btn',function(){
    lcrmFilters={
        search:$('#lcrm-search').val(), stage_id:$('#lcrm-filter-stage').val(),
        vendor_id:$('#lcrm-filter-vendor').val(), brand_id:$('#lcrm-filter-brand').val(),
        work_type:$('#lcrm-filter-wt').val(), priority:$('#lcrm-filter-priority').val(),
        temperature:$('#lcrm-filter-temp').val(), date_from:$('#lcrm-filter-from').val(),
        date_to:$('#lcrm-filter-to').val(), internal_status:$('#lcrm-filter-status').val()||'',
        remarks_search:$('#lcrm-filter-remarks').val()||''
    };
    lcrmPage=1; lcrmLoadLeads();
});
$(document).on('click','#lcrm-reset-btn',function(){
    $('#lcrm-search,#lcrm-filter-from,#lcrm-filter-to,#lcrm-filter-status,#lcrm-filter-remarks').val('');
    $('#lcrm-filter-stage,#lcrm-filter-vendor,#lcrm-filter-brand,#lcrm-filter-wt,#lcrm-filter-priority,#lcrm-filter-temp').val('');
    lcrmFilters={}; lcrmPage=1; lcrmLoadLeads();
});
$(document).on('click','.lcrm-sort',function(){
    var col=$(this).data('col');
    if(lcrmSort===col) lcrmOrder=(lcrmOrder==='ASC'?'DESC':'ASC'); else{ lcrmSort=col; lcrmOrder='DESC'; }
    lcrmPage=1; lcrmBuildThead(); lcrmLoadLeads();
});
$(document).on('click','.lcrm-pag-btn',function(){ lcrmPage=parseInt($(this).data('page')); lcrmLoadLeads(); });
$(document).on('keypress','#lcrm-search',function(e){ if(e.which===13){ lcrmPage=1; $('#lcrm-filter-btn').click(); } });

/* ============================================================
   BULK SELECT
   ============================================================ */
$(document).on('change','#lcrm-check-all',function(){ $('.lcrm-row-check').prop('checked',$(this).is(':checked')); lcrmUpdateBulk(); });
$(document).on('change','.lcrm-row-check',function(){ lcrmUpdateBulk(); });
function lcrmUpdateBulk(){
    lcrmSelectedIds=[]; $('.lcrm-row-check:checked').each(function(){ lcrmSelectedIds.push($(this).val()); });
    var has=lcrmSelectedIds.length>0;
    $('#lcrm-bulk-status-btn,#lcrm-bulk-priority-btn,#lcrm-bulk-vendor-btn,#lcrm-bulk-delete-btn').toggle(has);
}
$(document).on('click','#lcrm-bulk-status-btn',function(){ lcrmOpenModal('#lcrm-bulk-stage-modal'); });
$(document).on('click','#lcrm-bulk-priority-btn',function(){ lcrmOpenModal('#lcrm-bulk-priority-modal'); });
$(document).on('click','#lcrm-bulk-vendor-btn',function(){ lcrmOpenModal('#lcrm-bulk-vendor-modal'); });
$(document).on('click','#lcrm-bulk-stage-confirm',function(){ lcrmPost('lcrm_bulk_action',{bulk_action:'change_stage',ids:lcrmSelectedIds,stage_id:$('#lcrm-bulk-stage-val').val()},function(d){ lcrmCloseAllModals(); lcrmLoadLeads(); alert(d.msg||'Done.'); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('click','#lcrm-bulk-priority-confirm',function(){ lcrmPost('lcrm_bulk_action',{bulk_action:'set_priority',ids:lcrmSelectedIds,priority:$('#lcrm-bulk-priority-val').val()},function(d){ lcrmCloseAllModals(); lcrmLoadLeads(); alert(d.msg||'Done.'); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('click','#lcrm-bulk-vendor-confirm',function(){ lcrmPost('lcrm_bulk_action',{bulk_action:'assign_vendor',ids:lcrmSelectedIds,vendor_id:$('#lcrm-bulk-vendor-val').val()},function(d){ lcrmCloseAllModals(); lcrmLoadLeads(); alert(d.msg||'Done.'); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('click','#lcrm-bulk-delete-btn',function(){
    if(!lcrmConfirm('Delete '+lcrmSelectedIds.length+' selected leads?')) return;
    lcrmPost('lcrm_bulk_action',{bulk_action:'delete',ids:lcrmSelectedIds},function(d){ lcrmLoadLeads(); alert(d.msg||'Done.'); },function(e){ alert(lcrmErr(e)); });
});

/* ============================================================
   INLINE EDITING — double-click cell
   ============================================================ */
$(document).on('dblclick','.lcrm-editable',function(e){
    e.stopPropagation();
    var $td=$(this);
    if($td.find('input,select,textarea').length) return;
    var field=$td.data('editable'),leadId=$td.data('lead-id'),curVal=$td.data('val')||'',orig=$td.html();
    function saveInline(newVal){
        if(String(newVal)===String(curVal)){ $td.html(orig); return; }
        lcrmPost('lcrm_quick_update',{id:leadId,field:field,value:newVal},
            function(){ lcrmLoadLeads(); },
            function(e){ alert(lcrmErr(e)); $td.html(orig); }
        );
    }
    var $inp;
    if(field==='stage_id'){
        $inp=$('<select class="lcrm-inline-input lcrm-input" style="width:100%;min-width:130px;"></select>');
        (LCRM.stages||[]).forEach(function(s){ $inp.append($('<option>').val(s.id).text(s.name).prop('selected',String(s.id)===String(curVal))); });
    } else if(field==='priority'){
        $inp=$('<select class="lcrm-inline-input lcrm-input"></select>');
        [{v:'low',t:'Low'},{v:'medium',t:'Medium'},{v:'high',t:'High'}].forEach(function(o){ $inp.append($('<option>').val(o.v).text(o.t).prop('selected',o.v===curVal)); });
    } else if(field==='lead_temperature'){
        $inp=$('<select class="lcrm-inline-input lcrm-input"></select>');
        [{v:'cold',t:'\u2744 Cold'},{v:'warm',t:'\u2600 Warm'},{v:'hot',t:'\ud83d\udd25 Hot'}].forEach(function(o){ $inp.append($('<option>').val(o.v).text(o.t).prop('selected',o.v===curVal)); });
    } else if(field==='next_action_date'){
        $inp=$('<input type="date" class="lcrm-inline-input lcrm-input" style="width:130px;">').val(curVal);
    } else if(field==='remarks'||field==='final_remarks'){
        $inp=$('<textarea class="lcrm-inline-input lcrm-input" style="width:100%;min-width:180px;min-height:70px;resize:vertical;"></textarea>').val(curVal);
    } else {
        $inp=$('<input type="text" class="lcrm-inline-input lcrm-input" style="width:100%;min-width:120px;">').val(curVal);
    }
    $td.html('').append($inp); $inp.focus();
    $inp.on('keydown',function(ev){
        if(ev.key==='Enter'){ ev.preventDefault(); saveInline($inp.val()); }
        if(ev.key==='Escape'){ $td.html(orig); }
    });
    $inp.on('blur',function(){ saveInline($inp.val()); });
    $inp.on('change',function(){ if($inp.is('select')) saveInline($inp.val()); });
});
$(document).on('mouseenter','.lcrm-editable',function(){
    if(!$(this).find('input,select').length) $(this).attr('title','Double-click to edit inline');
});

/* ============================================================
   LEAD FORM
   ============================================================ */
$(document).on('click','.lcrm-ftab',function(){
    var pane=$(this).data('tab');
    $(this).closest('.lcrm-form').find('.lcrm-ftab').removeClass('active');
    $(this).addClass('active');
    $(this).closest('.lcrm-form').find('.lcrm-ftab-pane').hide();
    $(this).closest('.lcrm-form').find('[data-pane="'+pane+'"]').show();
});
$(document).on('input','.lcrm-calc',function(){
    var q=parseFloat($('#lf-total_quote').val()||0),p=parseFloat($('#lf-pmt_received').val()||0),v=parseFloat($('#lf-vendor_charge').val()||0);
    $('#lf-balance_payment').val(Math.max(0,q-p).toFixed(2));
    $('#lf-profit').val(Math.max(0,q-v).toFixed(2));
});
$(document).on('click','.lcrm-remark-pill',function(){
    var txt=$(this).data('text'); var $ta=$('#lf-remarks');
    $ta.val(($ta.val()?$ta.val()+'\n':'')+txt);
    $(this).addClass('active'); setTimeout(function(){ $('.lcrm-remark-pill').removeClass('active'); },600);
});
$(document).on('change input','.lcrm-form input,.lcrm-form select,.lcrm-form textarea',function(){ lcrmFormDirty=true; });
$(document).on('click','#lcrm-add-lead-btn',function(){ lcrmOpenLeadModal(0); });
$(document).on('click','.lcrm-edit-lead',function(){ lcrmOpenLeadModal($(this).data('id')); });

window.lcrmOpenLeadModal = function lcrmOpenLeadModal(id){
    lcrmEditId=id; lcrmFormDirty=false;
    $('#lcrm-lead-modal').show();
    $('#lcrm-modal-title').text(id?'Edit Lead #'+id:'Add New Lead');
    lcrmResetLeadForm();
    lcrmLoadQrData(); // load quick replies immediately
    if(id){ lcrmPost('lcrm_get_lead',{id:id},function(d){ lcrmFillLeadForm(d.lead); lcrmFillConversation(d.conversations||[]); lcrmFillVendorLogs(d.vendor_logs); if(d.lead.vendor_id) $('#lcrm-vendor-msg-section').show(); lcrmFormDirty=false; },function(e){ alert(lcrmErr(e)); }); }
}
function lcrmResetLeadForm(){
    $('#lcrm-lead-form')[0].reset();
    $('#lf-id').val('');
    $('#lf-lead_date').val(new Date().toISOString().slice(0,10));
    $('#lf-balance_payment,#lf-profit').val('');
    $('#lcrm-conv-wrap').hide(); $('#lcrm-vendor-msg-section').hide();
    $('#lcrm-vendor-logs').empty(); $('#lcrm-vendor-msg-txt').val('');
    $('#lcrm-wa-buttons').hide().empty(); $('#lcrm-form-msg').hide();
    $('#lcrm-conv-timeline').html('<div class="lcrm-conv-empty">No entries yet.</div>');
    $('#lcrm-conv-count').text('');
    $('#lcrm-qr-search').val('');
    $('#lcrm-qr-add-form').hide();
    $('#lcrm-qr-pills').html('<span class="lcrm-qr-empty"><span class="lcrm-spinner"></span> Loading\u2026</span>');
    $('.lcrm-ftab:first').click();
    lcrmFormDirty=false; lcrmSaving=false;
}
function lcrmFillLeadForm(l){ window.lcrmLastFilledLead = l;
    var allFields = ['id','lead_date','client_name','client_phone','client_email','work_type','lead_source','stage_id','brand_id','priority','lead_temperature','internal_status','next_action_date','next_action_type','client_followup_date','vendor_followup_date','vendor_id','total_quote','pmt_received','remarks','final_remarks','assigned_to'];
    if (LCRM.can_conf_finance) allFields = allFields.concat(['vendor_charge','pmt_to_vendor']);
    allFields.forEach(function(f){
        var $el=$('#lf-'+f); if($el.length) $el.val(l[f]||'');
    });
    $('#lf-work_started').prop('checked',parseInt(l.work_started)===1);
    $('#lf-balance_payment').val(lcrmFmt(l.balance_payment));
    $('#lf-profit').val(lcrmFmt(l.profit));
    $('#lcrm-conv-wrap').show();
    if(l.vendor_id) $('#lcrm-vendor-msg-section').show();
    if(l.custom_field_values){
        Object.keys(l.custom_field_values).forEach(function(k){
            var $el=$('#lf-'+k); if(!$el.length) return;
            if($el.is('[type=checkbox]')) $el.prop('checked',l.custom_field_values[k]==='1'||l.custom_field_values[k]===1||l.custom_field_values[k]===true);
            else $el.val(l.custom_field_values[k]||'');
        });
    }
}
function lcrmFillActivity(a){ /* kept for compat */ }

/* ── Date helper ───────────────────────────────────────── */
function lcrmFmtDate(d){
    if(!d) return '';
    var p=d.split('-'),m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return parseInt(p[2],10)+' '+(m[parseInt(p[1],10)-1]||p[1])+' '+p[0];
}

/* ── Conversation panel ────────────────────────────────── */
function lcrmConvBubble(e){
    if(!e) return '';
    var isSys=(e.direction==='system');
    var initials='?';
    if(e.user_name){ var pts=e.user_name.trim().split(' '); initials=((pts[0]?pts[0][0]:'')+(pts[1]?pts[1][0]:'')).toUpperCase()||'?'; }
    var t=e.created_at?e.created_at.slice(11,16):'';
    if(isSys) return '<div class="lcrm-conv-sys"><div class="lcrm-conv-sys-inner"><span>&#128274;</span>'+lcrmEsc(e.message)+'</div><span class="lcrm-conv-sys-time">'+lcrmEsc(t)+'</span></div>';
    return '<div class="lcrm-conv-bubble">'
        +'<div class="lcrm-conv-avatar">'+lcrmEsc(initials)+'</div>'
        +'<div class="lcrm-conv-body">'
        +'<div class="lcrm-conv-meta"><strong>'+lcrmEsc(e.user_name||'Unknown')+'</strong>'
        +'<span class="lcrm-conv-role">'+lcrmEsc(e.user_role||'Staff')+'</span>'
        +'<span class="lcrm-conv-time">'+lcrmEsc(t)+'</span></div>'
        +'<div class="lcrm-conv-text">'+lcrmEsc(e.message).replace(/\n/g,'<br>')+'</div>'
        +'</div></div>';
}
function lcrmConvSep(d){
    var mn=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var p=d.split('-'),label=parseInt(p[2],10)+' '+(mn[parseInt(p[1],10)-1]||p[1])+' '+p[0];
    if(d===new Date().toISOString().slice(0,10)) label='Today \u00b7 '+label;
    return '<div class="lcrm-conv-sep" data-date="'+lcrmEsc(d)+'"><span>'+lcrmEsc(label)+'</span></div>';
}
function lcrmFillConversation(convs){
    var $tl=$('#lcrm-conv-timeline'), html='', lastDate='', cnt=0;
    if(!convs||!convs.length){ $tl.html('<div class="lcrm-conv-empty">No entries yet.</div>'); $('#lcrm-conv-count').text(''); return; }
    convs.forEach(function(e){
        var d=e.created_at?e.created_at.slice(0,10):'';
        if(d!==lastDate){ html+=lcrmConvSep(d); lastDate=d; }
        html+=lcrmConvBubble(e); cnt++;
    });
    $tl.html(html); $('#lcrm-conv-count').text(cnt+' entr'+(cnt!==1?'ies':'y')); $tl.scrollTop($tl[0].scrollHeight);
}

$(document).on('click','#lcrm-conv-refresh-btn',function(){
    var id=$('#lf-id').val()||0; if(!id) return;
    lcrmPost('lcrm_get_conversations',{lead_id:id},function(d){ lcrmFillConversation(d.conversations||[]); },function(e){ alert(lcrmErr(e)); });
});
$(document).on('click','#lcrm-conv-send-btn',function(){
    var id=$('#lf-id').val()||0;
    if(!id){ lcrmMsg($('#lcrm-conv-msg'),'Save the lead first.','err'); return; }
    var msg=$('#lcrm-conv-message').val().trim();
    if(!msg){ lcrmMsg($('#lcrm-conv-msg'),'Message cannot be empty.','err'); return; }
    var $btn=$(this).prop('disabled',true).text('Saving\u2026');
    lcrmPost('lcrm_add_conversation',{lead_id:id,message:msg},
        function(d){
            $btn.prop('disabled',false).html('&#10148; Add'); $('#lcrm-conv-message').val(''); lcrmMsg($('#lcrm-conv-msg'),'','');
            if(d.entry){
                var $tl=$('#lcrm-conv-timeline');
                if($tl.find('.lcrm-conv-empty').length) $tl.empty();
                var eDate=d.entry.created_at?d.entry.created_at.slice(0,10):'';
                var $last=$tl.find('.lcrm-conv-sep').last();
                if(!$last.length||$last.data('date')!==eDate) $tl.append(lcrmConvSep(eDate));
                $tl.append(lcrmConvBubble(d.entry)); $tl.scrollTop($tl[0].scrollHeight);
                var n=parseInt($('#lcrm-conv-count').text())||0; n++;
                $('#lcrm-conv-count').text(n+' entr'+(n!==1?'ies':'y'));
            }
            lcrmLoadLeads();
        },
        function(e){ $btn.prop('disabled',false).html('&#10148; Add'); lcrmMsg($('#lcrm-conv-msg'),lcrmErr(e),'err'); }
    );
});

/* ── Quick Replies ─────────────────────────────────────── */
var lcrmQrData=[], lcrmQrOpen=true;

function lcrmBuildQrPills(filter){
    var $p=$('#lcrm-qr-pills'); filter=(filter||'').toLowerCase();
    if(!lcrmQrData.length){
        $p.html('<span class="lcrm-qr-empty">No quick replies yet. Click <strong>+ Add</strong> to create one.</span>');
        return;
    }
    var html='', lastCat='', vis=0;
    lcrmQrData.forEach(function(r){
        if(filter && r.text.toLowerCase().indexOf(filter)===-1 && r.cat.toLowerCase().indexOf(filter)===-1) return;
        if(r.cat!==lastCat){ html+='<div class="lcrm-qr-cat">'+lcrmEsc(r.cat)+'</div>'; lastCat=r.cat; }
        html+='<button type="button" class="lcrm-qr-pill" data-text="'+lcrmEsc(r.text)+'">'+lcrmEsc(r.text)+'</button>';
        vis++;
    });
    $p.html(vis ? html : '<span class="lcrm-qr-empty">No matches for &ldquo;'+lcrmEsc(filter)+'&rdquo;</span>');
}

// Use inline PHP data immediately (synchronous, always works)
// AJAX refresh is available via the refresh button for newly-added replies
function lcrmLoadQrData(){
    if(window.LCRM_QR && window.LCRM_QR.length){
        lcrmQrData = window.LCRM_QR;
        lcrmBuildQrPills($('#lcrm-qr-search').val()||'');
    } else {
        // Fallback: fetch via AJAX (handles case where PHP data was empty at render time)
        lcrmPost('lcrm_get_quick_replies',{},function(d){
            lcrmQrData = d.replies||[];
            if(lcrmQrData.length){
                // Update window.LCRM_QR so it stays in sync
                window.LCRM_QR = lcrmQrData;
            }
            lcrmBuildQrPills($('#lcrm-qr-search').val()||'');
        },function(){
            $('#lcrm-qr-pills').html('<span class="lcrm-qr-empty">No quick replies loaded. Visit Settings → Remarks to add some.</span>');
        });
    }
}

// Refresh from DB (for after adding new replies)
function lcrmRefreshQrData(){
    lcrmPost('lcrm_get_quick_replies',{},function(d){
        lcrmQrData = d.replies||[];
        window.LCRM_QR = lcrmQrData;
        lcrmBuildQrPills($('#lcrm-qr-search').val()||'');
    },function(){ lcrmBuildQrPills($('#lcrm-qr-search').val()||''); });
}
$(document).on('input','#lcrm-qr-search',function(){ lcrmBuildQrPills($(this).val()); });
$(document).on('click','#lcrm-qr-toggle',function(){
    lcrmQrOpen=!lcrmQrOpen;
    $('#lcrm-qr-body').toggleClass('lcrm-qr-collapsed',!lcrmQrOpen);
    $(this).text(lcrmQrOpen?'\u25be':'\u25b8');
});
$(document).on('click','.lcrm-qr-pill',function(){
    var txt=$(this).data('text'),$ta=$('#lcrm-conv-message'),cur=$ta.val().trim();
    $ta.val(cur?cur+'\n'+txt:txt).focus();
    $(this).addClass('lcrm-qr-active'); var $s=$(this); setTimeout(function(){ $s.removeClass('lcrm-qr-active'); },400);
});
$(document).on('click','#lcrm-qr-add-btn',function(){ $('#lcrm-qr-add-form').slideToggle(150); });
$(document).on('click','#lcrm-qr-add-cancel',function(){ $('#lcrm-qr-add-form').slideUp(150); });
$(document).on('click','#lcrm-qr-save-btn',function(){
    var cat=$('#lcrm-qr-add-cat').val().trim()||'General', txt=$('#lcrm-qr-add-text').val().trim();
    if(!txt){ lcrmMsg($('#lcrm-qr-add-msg'),'Enter remark text.','err'); return; }
    var $btn=$(this).prop('disabled',true).text('Saving\u2026');
    lcrmPost('lcrm_save_quick_reply',{category:cat,remark:txt},function(d){
        $btn.prop('disabled',false).text('Save');
        $('#lcrm-qr-add-cat').val(''); $('#lcrm-qr-add-text').val(''); $('#lcrm-qr-add-form').slideUp(150);
        if(d.reply){
            lcrmQrData.push(d.reply);
            window.LCRM_QR = lcrmQrData;
            lcrmBuildQrPills($('#lcrm-qr-search').val()||'');
        }
    },function(e){ $btn.prop('disabled',false).text('Save'); lcrmMsg($('#lcrm-qr-add-msg'),lcrmErr(e),'err'); });
});

/* ================================================================
   ASSIGNMENTS PAGE — full AJAX-driven table engine
   ================================================================ */
var lasState = {page:1,orderby:'created_at',order:'DESC',search:'',filter_user:'',filter_stage:'',filter_status:'',loading:false};

function lasGetUsers(){ return window.LAS_USERS||[]; }
function lasUserOptions(selId){
    var html='<option value="">— Unassigned —</option>';
    lasGetUsers().forEach(function(u){ html+='<option value="'+u.id+'"'+(u.id==selId?' selected':'')+'>'+lcrmEsc(u.name)+'</option>'; });
    return html;
}
function lasStatusBadge(l){
    if(l.is_access_revoked) return '<span class="lcrm-assign-badge lcrm-badge-revoked">&#128274; Revoked</span>';
    if(l.assigned_to)       return '<span class="lcrm-assign-badge lcrm-badge-assigned">&#9989; Assigned</span>';
    return '<span class="lcrm-assign-badge lcrm-badge-unassigned">&#9675; Unassigned</span>';
}
function lasRenderRow(l){
    var rowBg=l.is_access_revoked?'background:#fff5f5;':(!l.assigned_to?'background:#fffbeb;':'');
    var stageDot=l.stage_color?'<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'+lcrmEsc(l.stage_color)+';margin-right:5px;vertical-align:middle;"></span>':'';
    var assignedLabel=l.assigned_user_name
        ?'<span style="background:#dbeafe;color:#1e40af;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:600;">&#128100; '+lcrmEsc(l.assigned_user_name)+'</span>'
        :'<span style="color:#94a3b8;font-style:italic;font-size:12px;">Unassigned</span>';
    var actions=l.is_access_revoked
        ?'<button class="button button-primary lcrm-btn lcrm-btn-sm las-take-btn" data-id="'+l.id+'">&#9654; Restore &amp; Take</button>'
        :'<button class="button lcrm-btn lcrm-btn-sm las-take-btn" data-id="'+l.id+'">&#128100; Take</button>'
         +'<button class="button lcrm-btn lcrm-btn-sm lcrm-btn-danger las-revoke-btn" data-id="'+l.id+'">&#128274; Revoke</button>';
    actions+='<button class="button lcrm-btn lcrm-btn-sm las-history-btn" data-id="'+l.id+'">&#128203;</button>';
    return '<tr id="las-row-'+l.id+'" style="'+rowBg+'">'
        +'<td style="width:36px;"><input type="checkbox" class="las-check" value="'+l.id+'"></td>'
        +'<td style="font-weight:700;">#'+l.id+'</td>'
        +'<td><strong>'+lcrmEsc(l.client_name)+'</strong>'+(l.client_phone?'<br><span style="font-size:11px;color:#64748b;">'+lcrmEsc(l.client_phone)+'</span>':'')+'</td>'
        +'<td style="font-size:12px;color:#334155;">'+lcrmEsc(l.work_type)+'</td>'
        +'<td style="font-size:12px;">'+lcrmEsc(l.lead_date)+'</td>'
        +'<td style="font-size:12px;">'+stageDot+lcrmEsc(l.stage_name||'—')+'</td>'
        +'<td style="font-size:12px;color:#64748b;">'+lcrmEsc(l.created_by_name||'—')+'</td>'
        +'<td><div style="display:flex;align-items:center;gap:5px;margin-bottom:4px;">'
        +'<select class="lcrm-input las-assign-sel" data-id="'+l.id+'" style="flex:1;font-size:12px;height:28px;min-width:140px;">'+lasUserOptions(l.assigned_to)+'</select>'
        +'<button class="button button-primary lcrm-btn las-assign-btn" data-id="'+l.id+'" style="padding:3px 9px;font-size:12px;white-space:nowrap;">&#10003; Assign</button>'
        +'</div>'+assignedLabel+'</td>'
        +'<td>'+lasStatusBadge(l)+'</td>'
        +'<td style="white-space:nowrap;">'+actions+'</td>'
        +'</tr>';
}
function lasLoad(){
    if(lasState.loading||!$('#las-table').length) return;
    lasState.loading=true;
    $('#las-tbody').html('<tr><td colspan="10" style="text-align:center;padding:24px;color:#94a3b8;"><span class="lcrm-spinner"></span> Loading\u2026</td></tr>');
    lcrmPost('lcrm_get_assignments_data',{search:lasState.search,filter_user:lasState.filter_user,filter_stage:lasState.filter_stage,filter_status:lasState.filter_status,orderby:lasState.orderby,order:lasState.order,page:lasState.page},
    function(d){
        lasState.loading=false;
        if(!d.leads||!d.leads.length){ $('#las-tbody').html('<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">No leads found. Try adjusting filters.</td></tr>'); $('#las-count').text('0 leads'); $('#las-pagination').html(''); return; }
        var html=''; d.leads.forEach(function(l){ html+=lasRenderRow(l); });
        $('#las-tbody').html(html);
        $('#las-count').text(d.total+' lead'+(d.total!==1?'s':''));
        var pag='';
        if(d.pages>1){ for(var i=1;i<=d.pages;i++) pag+='<button class="button lcrm-btn las-page-btn'+(i===d.page?' button-primary':'')+'" data-page="'+i+'" style="margin-right:4px;min-width:34px;">'+i+'</button>'; }
        $('#las-pagination').html(pag);
        $('#las-table .las-sort-icon').text('\u21C5');
        $('#las-table th[data-col="'+lasState.orderby+'"] .las-sort-icon').text(lasState.order==='ASC'?'\u2191':'\u2193');
        $('#las-sel-count').text('0 selected');
    },function(e){
        lasState.loading=false;
        $('#las-tbody').html('<tr><td colspan="10" style="text-align:center;padding:20px;color:#ef4444;font-weight:500;">&#9888; '+lcrmErr(e)+'<br><small style="color:#94a3b8;font-weight:400;">Check that the plugin is fully activated and try refreshing.</small></td></tr>');
    });
}
$(document).ready(function(){ if($('#las-table').length) lasLoad(); });
$(document).on('click','#las-filter-btn',function(){ lasState.search=$('#las-search').val().trim(); lasState.filter_user=$('#las-user').val(); lasState.filter_stage=$('#las-stage').val(); lasState.filter_status=$('#las-status').val(); lasState.page=1; lasLoad(); });
$(document).on('click','#las-reset-btn',function(){ $('#las-search').val(''); $('#las-user').val(''); $('#las-stage').val(''); $('#las-status').val(''); lasState.search=''; lasState.filter_user=''; lasState.filter_stage=''; lasState.filter_status=''; lasState.page=1; lasState.orderby='created_at'; lasState.order='DESC'; lasLoad(); });
$(document).on('keyup','#las-search',function(e){ if(e.key==='Enter') $('#las-filter-btn').click(); });
$(document).on('click','.las-sortable',function(){ var col=$(this).data('col'); if(lasState.orderby===col) lasState.order=lasState.order==='ASC'?'DESC':'ASC'; else{lasState.orderby=col;lasState.order='DESC';} lasState.page=1; lasLoad(); });
$(document).on('click','.las-page-btn',function(){ lasState.page=parseInt($(this).data('page')); lasLoad(); });
$(document).on('change','#las-check-all',function(){ $('.las-check').prop('checked',$(this).is(':checked')); $('#las-sel-count').text($('.las-check:checked').length+' selected'); });
$(document).on('change','.las-check',function(){ $('#las-sel-count').text($('.las-check:checked').length+' selected'); });
$(document).on('click','.las-assign-btn',function(){
    var id=$(this).data('id'), uid=$(this).closest('td').find('.las-assign-sel').val();
    if(!uid){ alert('Select a user to assign to.'); return; }
    var $btn=$(this).prop('disabled',true).text('Saving\u2026');
    lcrmPost('lcrm_assign_lead',{lead_id:id,user_id:uid},function(d){
        $btn.prop('disabled',false).text('\u2713 Assign');
        var uName=''; lasGetUsers().forEach(function(u){ if(u.id==uid) uName=u.name; });
        var $row=$('#las-row-'+id);
        $row.find('td:nth-child(8) > div + span,td:nth-child(8) > span').replaceWith('<span style="background:#dbeafe;color:#1e40af;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:600;">&#128100; '+lcrmEsc(uName)+'</span>');
        $row.find('td:nth-child(9)').html('<span class="lcrm-assign-badge lcrm-badge-assigned">&#9989; Assigned</span>');
        $row.css('background','');
    },function(e){ $btn.prop('disabled',false).text('\u2713 Assign'); alert(lcrmErr(e)); });
});
$(document).on('click','.las-take-btn',function(){ var id=$(this).data('id'); var $btn=$(this).prop('disabled',true); lcrmPost('lcrm_take_lead',{lead_id:id},function(){ $btn.prop('disabled',false); lasLoad(); },function(e){ $btn.prop('disabled',false); alert(lcrmErr(e)); }); });
$(document).on('click','.las-revoke-btn',function(){
    if(!confirm('Revoke access to this lead?\n\nThe assigned user will immediately lose all access. Only admins can view it.')) return;
    var id=$(this).data('id'), $btn=$(this).prop('disabled',true);
    lcrmPost('lcrm_revoke_lead',{lead_id:id},function(){
        $btn.prop('disabled',false);
        var $row=$('#las-row-'+id);
        $row.css('background','#fff5f5');
        $row.find('td:nth-child(9)').html('<span class="lcrm-assign-badge lcrm-badge-revoked">&#128274; Revoked</span>');
        $row.find('td:nth-child(10)').html('<button class="button button-primary lcrm-btn lcrm-btn-sm las-take-btn" data-id="'+id+'">&#9654; Restore &amp; Take</button><button class="button lcrm-btn lcrm-btn-sm las-history-btn" data-id="'+id+'">&#128203;</button>');
    },function(e){ $btn.prop('disabled',false); alert(lcrmErr(e)); });
});
$(document).on('click','#las-bulk-assign-btn',function(){
    var ids=[]; $('.las-check:checked').each(function(){ ids.push($(this).val()); });
    var uid=$('#las-bulk-user').val();
    if(!ids.length){ lcrmMsg($('#las-bulk-msg'),'Select at least one lead.','err'); return; }
    if(!uid){ lcrmMsg($('#las-bulk-msg'),'Select a user.','err'); return; }
    var $btn=$(this).prop('disabled',true).text('Assigning\u2026');
    lcrmPost('lcrm_bulk_assign',{ids:ids,user_id:uid},function(d){
        $btn.prop('disabled',false).text('\u{1F465} Assign Selected');
        lcrmMsg($('#las-bulk-msg'),d.msg||'Done!','ok');
        lasLoad();
    },function(e){ $btn.prop('disabled',false).text('\u{1F465} Assign Selected'); lcrmMsg($('#las-bulk-msg'),lcrmErr(e),'err'); });
});
$(document).on('click','.las-history-btn',function(){
    var id=$(this).data('id');
    $('#lcrm-history-modal').show();
    $('#lcrm-history-body').html('<p style="color:#94a3b8;text-align:center;padding:20px;">Loading\u2026</p>');
    lcrmPost('lcrm_get_assignment_history',{lead_id:id},function(d){
        if(!d.history||!d.history.length){ $('#lcrm-history-body').html('<p style="color:#94a3b8;text-align:center;padding:20px;">No history yet.</p>'); return; }
        var c={assigned:'#10b981',reassigned:'#6366f1',revoked:'#ef4444',taken:'#f59e0b',transferred:'#3b82f6'};
        var html='<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;"><th style="padding:8px 10px;text-align:left;">Action</th><th style="padding:8px 10px;">From</th><th style="padding:8px 10px;">To</th><th style="padding:8px 10px;">By</th><th style="padding:8px 10px;">Date</th></tr></thead><tbody>';
        d.history.forEach(function(h){ html+='<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:7px 10px;"><span style="background:'+(c[h.action]||'#64748b')+';color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600;text-transform:capitalize;">'+lcrmEsc(h.action)+'</span></td><td style="padding:7px 10px;color:#64748b;">'+lcrmEsc(h.from_name||'—')+'</td><td style="padding:7px 10px;font-weight:500;">'+lcrmEsc(h.to_name||'—')+'</td><td style="padding:7px 10px;color:#64748b;">'+lcrmEsc(h.done_by_name||'—')+'</td><td style="padding:7px 10px;color:#94a3b8;font-size:11px;">'+lcrmEsc(h.created_at?h.created_at.slice(0,16):'')+'</td></tr>'; });
        $('#lcrm-history-body').html(html+'</tbody></table>');
    },function(e){ $('#lcrm-history-body').html('<p style="color:#ef4444;padding:10px;">'+lcrmErr(e)+'</p>'); });
});
$(document).on('click','#lcrm-history-close,#lcrm-history-overlay',function(){ $('#lcrm-history-modal').hide(); });

/* ── Quick assign from main leads table ──────────────────── */
$(document).on('click','.lcrm-quick-assign-btn',function(){
    var lead=$(this).data('lead'), uid=$(this).closest('tr,div').find('.lcrm-quick-assign-sel').val()||0;
    if(!uid){ alert('Select a user first.'); return; }
    var $btn=$(this).prop('disabled',true);
    lcrmPost('lcrm_assign_lead',{lead_id:lead,user_id:uid},function(){ $btn.prop('disabled',false); lcrmLoadLeads(); },function(e){ $btn.prop('disabled',false); alert(lcrmErr(e)); });
});
function lcrmBuildInlineConv(convs){
    if(!convs||!convs.length) return '<div class="lcrm-iconv-empty">&#128172; No conversation entries yet.</div>';
    var html='<div class="lcrm-iconv-timeline">', lastDate='';
    convs.forEach(function(e){
        var d=e.created_at?e.created_at.slice(0,10):'';
        if(d!==lastDate){
            var mn=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var p=d.split('-'),lbl=parseInt(p[2],10)+' '+(mn[parseInt(p[1],10)-1]||p[1])+' '+p[0];
            if(d===new Date().toISOString().slice(0,10)) lbl='Today \u00b7 '+lbl;
            html+='<div class="lcrm-iconv-sep"><span>'+lcrmEsc(lbl)+'</span></div>'; lastDate=d;
        }
        var t=e.created_at?e.created_at.slice(11,16):'';
        var ini='?'; if(e.user_name){var pts=e.user_name.trim().split(' ');ini=((pts[0]?pts[0][0]:'')+(pts[1]?pts[1][0]:'')).toUpperCase()||'?';}
        html+='<div class="lcrm-iconv-entry">'
            +'<div class="lcrm-iconv-avatar">'+lcrmEsc(ini)+'</div>'
            +'<div class="lcrm-iconv-body"><div class="lcrm-iconv-meta"><strong>'+lcrmEsc(e.user_name||'?')+'</strong>'
            +'<span class="lcrm-iconv-role">'+lcrmEsc(e.user_role||'Staff')+'</span>'
            +'<span class="lcrm-iconv-time">'+lcrmEsc(t)+'</span></div>'
            +'<div class="lcrm-iconv-text">'+lcrmEsc(e.message).replace(/\n/g,'<br>')+'</div>'
            +'</div></div>';
    });
    return html+'</div>';
}
$(document).on('click','.lcrm-view-conv-btn',function(){
    var id=$(this).data('id'),$btn=$(this);
    var $row=$('#lcrm-iconv-'+id),$panel=$('#lcrm-iconv-panel-'+id);
    if($row.is(':visible')){ $row.hide(); $btn.html('&#128172; View History'); return; }
    $row.show(); $btn.html('&#10006; Close');
    if($panel.data('loaded')) return;
    lcrmPost('lcrm_get_conversations',{lead_id:id},function(d){
        $panel.data('loaded',true).html(lcrmBuildInlineConv(d.conversations));
    },function(e){ $panel.html('<p style="color:#ef4444;padding:8px">'+lcrmErr(e)+'</p>'); });
});
function lcrmFillVendorLogs(logs){
    if(!logs||!logs.length) return;
    var html='<div class="lcrm-activity-title" style="margin-top:12px;">Vendor Contact History</div>';
    logs.forEach(function(l){ html+='<div class="lcrm-activity-item"><div class="lcrm-act-meta"><span class="lcrm-act-type">vendor</span><span class="lcrm-act-user">'+lcrmEsc(l.vendor_name)+'</span><span class="lcrm-badge lcrm-badge-gray">'+l.status+'</span><span class="lcrm-act-time">'+l.created_at+'</span></div><div class="lcrm-act-msg">'+lcrmEsc(l.message_sent)+'</div>'+(l.response?'<div class="lcrm-act-msg" style="color:#2e7d32;"><strong>Response:</strong> '+lcrmEsc(l.response)+'</div>':'')+'</div>'; });
    $('#lcrm-vendor-logs').html(html);
}

/* ============================================================
   SAVE LEAD
   ============================================================ */
$(document).on('submit','#lcrm-lead-form',function(e){
    e.preventDefault(); if(lcrmSaving) return false;
    lcrmSaving=true;
    var $btn=$('#lcrm-form-submit').prop('disabled',true).text('Saving\u2026');
    var data={id:$('#lf-id').val()};
    $(this).serializeArray().forEach(function(f){ data[f.name]=f.value; });
    data.work_started=$('#lf-work_started').is(':checked')?1:0;
    $('#lcrm-lead-form input[type=checkbox]').not('#lf-work_started').each(function(){ if(!$(this).is(':checked')) data[$(this).attr('name')]=''; });
    function onOk(d){ lcrmSaving=false; $btn.prop('disabled',false).text('Save Lead'); if(d.id){$('#lf-id').val(d.id); lcrmEditId=d.id;} lcrmFormDirty=false; lcrmMsg($('#lcrm-form-msg'),d.msg||'Saved!','ok'); $('#lcrm-conv-wrap').show(); lcrmLoadLeads(); }
    function onErr(d){ lcrmSaving=false; $btn.prop('disabled',false).text('Save Lead'); if(d&&d.duplicate_warning){ var msg='Possible duplicate:\n'; d.duplicates.forEach(function(dup){ msg+='#'+dup.id+' '+dup.name+' ('+dup.work+')\n'; }); if(confirm(msg+'\nCreate anyway?')){ data.force_create=1; lcrmSaving=true; $btn.prop('disabled',true).text('Saving\u2026'); lcrmPost('lcrm_save_lead',data,onOk,function(e2){ lcrmSaving=false; $btn.prop('disabled',false).text('Save Lead'); lcrmMsg($('#lcrm-form-msg'),lcrmErr(e2),'err'); }); } } else { lcrmMsg($('#lcrm-form-msg'),(d&&d.msg?d.msg:'Error saving lead.'),'err'); } }
    lcrmPost('lcrm_save_lead',data,onOk,onErr);
});

$(document).on('click','#lcrm-form-done-btn',function(){
    if(!lcrmFormDirty||lcrmSaving){ lcrmCloseAllModals(); lcrmLoadLeads(); return; }
    var $btn=$(this).prop('disabled',true).text('Saving\u2026');
    var data={id:$('#lf-id').val()};
    $('#lcrm-lead-form').serializeArray().forEach(function(f){ data[f.name]=f.value; });
    data.work_started=$('#lf-work_started').is(':checked')?1:0;
    lcrmSaving=true;
    lcrmPost('lcrm_save_lead',data,function(d){ lcrmSaving=false; lcrmFormDirty=false; $btn.prop('disabled',false).text('Save & Close'); if(d.id) $('#lf-id').val(d.id); lcrmCloseAllModals(); lcrmLoadLeads(); },function(d){ lcrmSaving=false; $btn.prop('disabled',false).text('Save & Close'); if(d&&d.duplicate_warning){ data.force_create=1; lcrmSaving=true; lcrmPost('lcrm_save_lead',data,function(r){ lcrmSaving=false; lcrmFormDirty=false; if(r.id) $('#lf-id').val(r.id); lcrmCloseAllModals(); lcrmLoadLeads(); },function(){ lcrmSaving=false; lcrmCloseAllModals(); lcrmLoadLeads(); }); } else { lcrmMsg($('#lcrm-form-msg'),'Save failed: '+(d&&d.msg?d.msg:'unknown error'),'err'); setTimeout(function(){ lcrmCloseAllModals(); lcrmLoadLeads(); },2000); } });
});
$(document).on('click','.lcrm-delete-lead',function(){ var id=$(this).data('id'); if(!lcrmConfirm('Delete lead #'+id+'?')) return; lcrmPost('lcrm_delete_lead',{id:id},function(){ lcrmLoadLeads(); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('click','#lcrm-export-btn',function(){ window.location=LCRM.ajax_url+'?action=lcrm_export_csv&nonce='+LCRM.nonce+'&stage_id='+(lcrmFilters.stage_id||'')+'&search='+(lcrmFilters.search||'')+'&date_from='+(lcrmFilters.date_from||'')+'&date_to='+(lcrmFilters.date_to||''); });

/* ============================================================
   VENDOR MESSAGING
   ============================================================ */
$(document).on('change','#lcrm-msg-type',function(){ $('#lcrm-field-checkboxes').toggle($(this).val()==='vendor_share'); });
$(document).on('change','#lf-vendor_id',function(){ $('#lcrm-vendor-msg-section').toggle(!!$(this).val()); $('#lcrm-wa-buttons').hide().empty(); $('#lcrm-vendor-msg-txt').val(''); });
$(document).on('click','#lcrm-gen-msg-btn',function(){
    var lead_id=$('#lf-id').val()||0;
    if(!lead_id){ alert('Please save the lead first.'); return; }
    var type=$('#lcrm-msg-type').val(),fields=[];
    if(type==='vendor_share') $('input[name="share_fields[]"]:checked').each(function(){ fields.push($(this).val()); });
    lcrmPost('lcrm_get_vendor_message',{lead_id:lead_id,type:type,fields:fields},function(d){
        $('#lcrm-vendor-msg-txt').val(d.message); $('#lcrm-log-contact-btn').show();
        var btns='';
        if(d.whatsapp_link) btns+='<a href="'+d.whatsapp_link+'" target="_blank" class="lcrm-wa-btn">\ud83d\udcac Send to Vendor</a> ';
        if(d.vendor_phone) btns+='<a href="https://wa.me/'+d.vendor_phone.replace(/[^0-9]/g,'')+'" target="_blank" class="lcrm-wa-btn lcrm-wa-plain">\ud83d\udcac Open WhatsApp</a>';
        $('#lcrm-wa-buttons').html(btns).show();
    },function(e){ alert(lcrmErr(e)); });
});
$(document).on('click','#lcrm-log-contact-btn',function(){
    var lead_id=$('#lf-id').val(),vendor_id=$('#lf-vendor_id').val(),msg=$('#lcrm-vendor-msg-txt').val();
    if(!lead_id||!vendor_id||!msg){ alert('Lead, vendor and message required.'); return; }
    lcrmPost('lcrm_log_vendor_contact',{lead_id:lead_id,vendor_id:vendor_id,message:msg},function(){ alert('Contact logged!'); },function(e){ alert(lcrmErr(e)); });
});

/* ============================================================
   NOTES / REMINDERS / ALERTS
   ============================================================ */
$(document).on('click','#lcrm-set-reminder-btn',function(){
    var lead_id=$('#lf-id').val()||0,remind_at=$('#lcrm-remind-at').val(),note=$('#lcrm-remind-note').val();
    if(!lead_id){ alert('Please save the lead first.'); return; }
    if(!remind_at){ alert('Please set a reminder date/time.'); return; }
    lcrmPost('lcrm_save_reminder',{lead_id:lead_id,remind_at:remind_at,note:note},function(){ lcrmMsg($('#lcrm-remind-msg'),'Reminder set!','ok'); $('#lcrm-remind-at,#lcrm-remind-note').val(''); },function(msg){ lcrmMsg($('#lcrm-remind-msg'),msg,'err'); });
});
$(document).on('click','.lcrm-mark-read-btn',function(){ var id=$(this).data('id'); lcrmPost('lcrm_mark_alert_read',{id:id},function(){ $('[data-id="'+id+'"]').closest('.lcrm-alert-row,.lcrm-alert-card').fadeOut(300); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('click','#lcrm-run-automation-btn',function(){ var $btn=$(this).prop('disabled',true).text('Running\u2026'); lcrmPost('lcrm_run_automation_now',{},function(d){ alert(d.msg+' ('+d.alerts+' unread alerts)'); $btn.prop('disabled',false).text('\u2699 Run Automation Now'); location.reload(); },function(msg){ alert(msg); $btn.prop('disabled',false); }); });

/* ============================================================
   MODALS
   ============================================================ */
function lcrmOpenModal(sel){ $(sel).show(); }
function lcrmCloseAllModals(){ $('.lcrm-modal').hide(); lcrmSaving=false; lcrmFormDirty=false; }
$(document).on('click','.lcrm-modal-overlay,.lcrm-modal-close,#lcrm-modal-close,#lcrm-modal-close-btn',function(){ lcrmCloseAllModals(); });
$(document).on('keydown',function(e){ if(e.key==='Escape') lcrmCloseAllModals(); });

/* ============================================================
   IMPORT
   ============================================================ */
$(document).on('submit','#lcrm-import-form',function(e){
    e.preventDefault();
    var fd=new FormData(this); fd.append('action','lcrm_import_csv'); fd.append('nonce',LCRM.nonce);
    $.ajax({url:LCRM.ajax_url,type:'POST',data:fd,processData:false,contentType:false,success:function(r){ if(r.success){ lcrmMsg($('#lcrm-import-msg'),r.data.msg,'ok'); if(r.data.errors&&r.data.errors.length) $('#lcrm-import-errors').html(r.data.errors.join('<br>')); } else lcrmMsg($('#lcrm-import-msg'),r.data.msg||'Error','err'); }});
});

/* ============================================================
   SETTINGS — STAGES
   ============================================================ */
$(document).on('click','#lcrm-add-stage-btn',function(){ $('#ls-id,#ls-name').val(''); $('#ls-color').val('#1565c0'); $('#lcrm-stage-form-wrap').slideToggle(200); });
$(document).on('click','#ls-cancel-btn',function(){ $('#lcrm-stage-form-wrap').hide(); });
$(document).on('click','.lcrm-stage-edit',function(){ var $b=$(this); $('#ls-id').val($b.data('id')); $('#ls-name').val($b.data('name')); $('#ls-color').val($b.data('color')); $('#lcrm-stage-form-wrap').show(); $('html,body').animate({scrollTop:0}); });
$(document).on('click','#ls-save-btn',function(){ lcrmPost('lcrm_save_stage',{id:$('#ls-id').val(),name:$('#ls-name').val(),color:$('#ls-color').val()},function(){ location.reload(); },function(msg){ lcrmMsg($('#ls-msg'),msg,'err'); }); });
$(document).on('click','.lcrm-stage-del',function(){ if(!lcrmConfirm('Delete this stage?')) return; lcrmPost('lcrm_delete_stage',{id:$(this).data('id')},function(){ location.reload(); },function(msg){ alert(msg); }); });
if($('#lcrm-stages-list').length){ $('#lcrm-stages-list').sortable({update:function(){ var order=[]; $('#lcrm-stages-list li').each(function(){ order.push($(this).data('id')); }); lcrmPost('lcrm_reorder_stages',{order:order},null,function(e){ alert(lcrmErr(e)); }); }}); }

/* REMARKS */
$(document).on('click','#lcrm-add-remark-btn',function(){ $('#lr-id,#lr-category,#lr-remark').val(''); $('#lcrm-remark-form-wrap').slideToggle(200); });
$(document).on('click','#lr-cancel-btn',function(){ $('#lcrm-remark-form-wrap').hide(); });
$(document).on('click','.lcrm-remark-edit',function(){ var $b=$(this); $('#lr-id').val($b.data('id')); $('#lr-category').val($b.data('category')); $('#lr-remark').val($b.data('remark')); $('#lcrm-remark-form-wrap').show(); });
$(document).on('click','#lr-save-btn',function(){ lcrmPost('lcrm_save_remark',{id:$('#lr-id').val(),category:$('#lr-category').val(),remark:$('#lr-remark').val()},function(){ location.reload(); },function(msg){ lcrmMsg($('#lr-msg'),msg,'err'); }); });
$(document).on('click','.lcrm-remark-del',function(){ if(!lcrmConfirm('Delete this remark?')) return; var id=$(this).data('id'); lcrmPost('lcrm_delete_remark',{id:id},function(){ $('#rrow-'+id).remove(); },function(e){ alert(lcrmErr(e)); }); });

/* DROPDOWNS */
$(document).on('click','#lcrm-add-dd-btn',function(){ $('#ldd-id,#ldd-name').val(''); $('#lcrm-dd-form-wrap').slideToggle(200); });
$(document).on('click','#ldd-cancel-btn',function(){ $('#lcrm-dd-form-wrap').hide(); });
$(document).on('click','.lcrm-dd-edit',function(){ var $b=$(this); $('#ldd-id').val($b.data('id')); $('#ldd-name').val($b.data('name')); $('#ldd-dtype').val($b.data('dtype')); $('#lcrm-dd-form-wrap').show(); });
$(document).on('click','#ldd-save-btn',function(){ lcrmPost('lcrm_save_dropdown',{id:$('#ldd-id').val(),name:$('#ldd-name').val(),dtype:$('#ldd-dtype').val()},function(){ location.reload(); },function(msg){ lcrmMsg($('#ldd-msg'),msg,'err'); }); });
$(document).on('click','.lcrm-dd-del',function(){ if(!lcrmConfirm('Delete?')) return; var id=$(this).data('id'),dtype=$(this).data('dtype'); lcrmPost('lcrm_delete_dropdown',{id:id,dtype:dtype},function(){ $('#ddrow-'+id).remove(); },function(e){ alert(lcrmErr(e)); }); });

/* AUTOMATION */
$(document).on('click','#lcrm-save-automation-btn',function(){ lcrmPost('lcrm_save_automation',{no_vendor_days:$('#lcrm-no-vendor-days').val(),no_action_days:$('#lcrm-no-action-days').val(),email_alerts:$('#lcrm-email-alerts').val(),alert_email:$('#lcrm-alert-email').val(),callme_enabled:$('#lcrm-callme-enabled').val(),callme_api_key:$('#lcrm-callme-api-key').val(),callme_admin_number:$('#lcrm-callme-admin-number').val(),callme_staff_numbers:$('#lcrm-callme-staff-numbers').val(),vendor_escalation_hours:$('#lcrm-vendor-escalation-hours').val()},function(d){ lcrmMsg($('#lcrm-auto-msg'),d.msg||'Saved!','ok'); },function(msg){ lcrmMsg($('#lcrm-auto-msg'),msg,'err'); }); });

/* ============================================================
   VENDORS / BRANDS / VENDOR CATS
   ============================================================ */
$(document).on('click','#lcrm-add-vendor-btn',function(){ $('#lcrm-vendor-form-wrap').slideToggle(200); $('#lv-id,#lv-name,#lv-phone,#lv-phone2,#lv-email,#lv-company,#lv-address').val(''); $('#lcrm-vendor-form-title').text('Add Vendor'); });
$(document).on('click','#lcrm-cancel-vendor-btn',function(){ $('#lcrm-vendor-form-wrap').hide(); });
$(document).on('click','.lcrm-vendor-edit',function(){ var $b=$(this); $('#lv-id').val($b.data('id')); $('#lv-name').val($b.data('name')); $('#lv-phone').val($b.data('phone')); $('#lv-phone2').val($b.data('phone2')); $('#lv-email').val($b.data('email')); $('#lv-company').val($b.data('company')); $('#lv-category_id').val($b.data('category_id')); $('#lv-address').val($b.data('address')); $('#lcrm-vendor-form-title').text('Edit Vendor'); $('#lcrm-vendor-form-wrap').show(); $('html,body').animate({scrollTop:0}); });
$(document).on('click','#lcrm-save-vendor-btn',function(){ lcrmPost('lcrm_save_vendor',{id:$('#lv-id').val(),name:$('#lv-name').val(),phone:$('#lv-phone').val(),phone2:$('#lv-phone2').val(),email:$('#lv-email').val(),company:$('#lv-company').val(),category_id:$('#lv-category_id').val(),address:$('#lv-address').val()},function(){ location.reload(); },function(msg){ lcrmMsg($('#lcrm-vendor-msg'),msg,'err'); }); });
$(document).on('click','.lcrm-vendor-del',function(){ if(!lcrmConfirm('Delete this vendor?')) return; lcrmPost('lcrm_delete_vendor',{id:$(this).data('id')},function(){ location.reload(); },function(msg){ alert(msg); }); });
$(document).on('click','#lcrm-add-brand-btn',function(){ $('#lb-id,#lb-name').val(''); $('#lcrm-brand-form-wrap').slideToggle(200); });
$(document).on('click','#lb-cancel-btn',function(){ $('#lcrm-brand-form-wrap').hide(); });
$(document).on('click','.lcrm-brand-edit',function(){ $('#lb-id').val($(this).data('id')); $('#lb-name').val($(this).data('name')); $('#lcrm-brand-form-wrap').show(); });
$(document).on('click','#lb-save-btn',function(){ lcrmPost('lcrm_save_brand',{id:$('#lb-id').val(),name:$('#lb-name').val()},function(){ location.reload(); },function(msg){ lcrmMsg($('#lb-msg'),msg,'err'); }); });
$(document).on('click','.lcrm-brand-del',function(){ if(!lcrmConfirm('Delete?')) return; lcrmPost('lcrm_delete_brand',{id:$(this).data('id')},function(){ location.reload(); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('click','#lcrm-add-vcat-btn',function(){ $('#lvc-id,#lvc-name').val(''); $('#lcrm-vcat-form-wrap').slideToggle(200); });
$(document).on('click','#lvc-cancel-btn',function(){ $('#lcrm-vcat-form-wrap').hide(); });
$(document).on('click','.lcrm-vcat-edit',function(){ $('#lvc-id').val($(this).data('id')); $('#lvc-name').val($(this).data('name')); $('#lcrm-vcat-form-wrap').show(); });
$(document).on('click','#lvc-save-btn',function(){ lcrmPost('lcrm_save_vendor_cat',{id:$('#lvc-id').val(),name:$('#lvc-name').val()},function(){ location.reload(); },function(msg){ lcrmMsg($('#lvc-msg'),msg,'err'); }); });
$(document).on('click','.lcrm-vcat-del',function(){ if(!lcrmConfirm('Delete?')) return; lcrmPost('lcrm_delete_vendor_cat',{id:$(this).data('id')},function(){ location.reload(); },function(e){ alert(lcrmErr(e)); }); });

/* ============================================================
   LAYOUT EDITOR
   ============================================================ */
$(document).on('click','#lcrm-layout-editor-toggle',function(){
    var $ed=$('#lcrm-layout-editor'); $ed.toggle();
    $(this).text($ed.is(':visible')?'Hide Layout Editor':'Show Layout Editor');
    if($ed.is(':visible')) lcrmInitLayoutEditor();
});
function lcrmInitLayoutEditor(){
    if(typeof Sortable==='undefined') return;
    if($('#lcrm-layout-editor').data('sortable-init')) return;
    $('#lcrm-layout-editor').data('sortable-init',true);
    $('.lcrm-layout-list').each(function(){
        Sortable.create(this,{group:'lcrm-form-fields',handle:'.lcrm-drag-handle',animation:150,ghostClass:'lcrm-drag-ghost',chosenClass:'lcrm-drag-chosen',filter:'.lcrm-layout-drop-hint,[style*="cursor:default"]',onStart:function(){ $('#lcrm-layout-save-msg,#lcrm-layout-err-msg').hide(); }});
    });
}
function lcrmSerializeLayout(){ var layout={}; $('.lcrm-layout-list').each(function(){ var tab=$(this).data('tab'); $(this).find('li[data-field-key]').each(function(i){ layout[$(this).data('field-key')]={tab:tab,order:(i+1)*10}; }); }); return layout; }
$(document).on('click','#lcrm-layout-save-btn',function(){ var $btn=$(this).prop('disabled',true).text('Saving\u2026'); lcrmPost('lcrm_save_form_layout',{layout:JSON.stringify(lcrmSerializeLayout())},function(){ $btn.prop('disabled',false).text('\ud83d\udcbe Save Layout'); $('#lcrm-layout-save-msg').show().delay(3000).fadeOut(); $('#lcrm-layout-err-msg').hide(); },function(e){ $btn.prop('disabled',false).text('\ud83d\udcbe Save Layout'); $('#lcrm-layout-err-msg').text(lcrmErr(e)).show(); }); });
$(document).on('click','#lcrm-layout-reset-btn',function(){ if(!confirm('Reset all field positions to defaults?')) return; var $btn=$(this).prop('disabled',true); lcrmPost('lcrm_reset_form_layout',{},function(){ $btn.prop('disabled',false); $('#lcrm-layout-save-msg').text('\u2713 Reset! Reload to see defaults.').show(); $('#lcrm-layout-editor').data('sortable-init',false); },function(e){ $btn.prop('disabled',false); alert(lcrmErr(e)); }); });

/* ============================================================
   CUSTOM FIELDS CRUD
   ============================================================ */
$(document).on('change','#lf-field-type',function(){ $('#lf-opts-wrap').toggle($(this).val()==='select'); });
$(document).on('input','#lf-field-label',function(){ if(!$('#lf-field-id').val()){ var key='cf_'+$(this).val().toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,''); $('#lf-field-key').val(key); } });
$(document).on('click','#lcrm-save-field-btn',function(){
    var $btn=$(this).prop('disabled',true).text('Saving...');
    lcrmPost('lcrm_save_field',{
        id:$('#lf-field-id').val(), field_label:$('#lf-field-label').val(), field_key:$('#lf-field-key').val(),
        field_type:$('#lf-field-type').val(), field_opts:$('#lf-field-opts').val(), tab_name:$('#lf-field-tab').val(),
        sort_order:$('#lf-field-sort-order').val()||0,
        is_required:$('#lf-field-required').is(':checked')?1:0, show_in_list:$('#lf-field-list').is(':checked')?1:0
    },function(d){ $btn.prop('disabled',false).text('Save Field'); lcrmMsg($('#lcrm-field-msg'),'\u2705 '+d.msg,'ok'); setTimeout(function(){ location.reload(); },900); },
    function(e){ $btn.prop('disabled',false).text('Save Field'); lcrmMsg($('#lcrm-field-msg'),'\u274c '+lcrmErr(e),'err'); });
});
$(document).on('click','.lcrm-edit-field-btn',function(){
    var d=$(this).data();
    $('#lf-field-id').val(d.id); $('#lf-field-label').val(d.label); $('#lf-field-key').val(d.key);
    $('#lf-field-type').val(d.type).trigger('change'); $('#lf-field-opts').val(d.opts);
    $('#lf-field-tab').val(d.tab); $('#lf-field-sort-order').val(d.sortorder||'');
    $('#lf-field-required').prop('checked',parseInt(d.required)===1); $('#lf-field-list').prop('checked',parseInt(d.list)===1);
    $('#lcrm-field-form-title').text('Edit Field'); $('#lcrm-cancel-field-btn').show();
    $('html,body').animate({scrollTop:0},300);
});
$(document).on('click','#lcrm-cancel-field-btn',function(){
    $('#lf-field-id').val(''); $('#lf-field-label,#lf-field-key,#lf-field-opts,#lf-field-sort-order').val('');
    $('#lf-field-type').val('text').trigger('change'); $('#lf-field-tab').val('basic');
    $('#lf-field-required,#lf-field-list').prop('checked',false);
    $('#lcrm-field-form-title').text('Add New Field'); $(this).hide();
});
$(document).on('click','.lcrm-del-field-btn',function(){ if(!confirm('Delete this field? Existing lead data is NOT deleted.')) return; var $row=$(this).closest('tr'); lcrmPost('lcrm_delete_field',{id:$(this).data('id')},function(){ $row.fadeOut(300,function(){ $row.remove(); }); },function(e){ alert(lcrmErr(e)); }); });
$(document).on('change','.lcrm-field-toggle',function(){ lcrmPost('lcrm_toggle_field',{id:$(this).data('id'),is_active:$(this).is(':checked')?1:0},null,function(e){ alert(lcrmErr(e)); }); });

function lcrmInitFieldSortable(){
    var tbody=document.getElementById('lcrm-fields-tbody');
    if(!tbody||typeof Sortable==='undefined') return;
    Sortable.create(tbody,{handle:'.lcrm-drag-handle',animation:150,ghostClass:'lcrm-drag-ghost',chosenClass:'lcrm-drag-chosen',onEnd:function(){
        var ids=[]; tbody.querySelectorAll('tr[data-field-id]').forEach(function(tr){ ids.push(parseInt(tr.getAttribute('data-field-id'),10)); });
        lcrmPost('lcrm_reorder_fields',{ids:ids},function(){ var $h=$('#lcrm-fields-tbody').closest('.lcrm-card').find('h3'); $h.css('color','#2e7d32'); setTimeout(function(){ $h.css('color',''); },1200); },function(e){ alert('Reorder failed: '+lcrmErr(e)); });
    }});
}

/* ============================================================
   INIT
   ============================================================ */
$(document).ready(function(){
    if($('#lcrm-leads-table').length){
        try {
            lcrmLoadColState();
            lcrmLoadFilterPrefs(function(){
                lcrmApplyFilterPrefs();
                lcrmBuildThead();
                lcrmLoadLeads();
                if(typeof LCRM.open_add!=='undefined'&&LCRM.open_add==1) setTimeout(function(){ lcrmOpenLeadModal(0); },300);
                if(typeof LCRM_EDIT_ID!=='undefined'&&LCRM_EDIT_ID>0) setTimeout(function(){ lcrmOpenLeadModal(LCRM_EDIT_ID); },400);
            });
        } catch(e) {
            console.error('[LeadCRM] Init error:', e);
        }
    }
    if($('#lf-lead_date').length&&!$('#lf-lead_date').val()) $('#lf-lead_date').val(new Date().toISOString().slice(0,10));
    lcrmInitFieldSortable();
});

    // Export functions needed by spa.js to window scope
    window.lcrmLoadColState    = lcrmLoadColState;
    window.lcrmLoadFilterPrefs = lcrmLoadFilterPrefs;
    window.lcrmApplyFilterPrefs= lcrmApplyFilterPrefs;
    window.lcrmBuildThead      = lcrmBuildThead;
    window.lcrmLoadLeads       = lcrmLoadLeads;
    window.lcrmLoadLeadsPage   = function(page){ lcrmPage=page; lcrmLoadLeads(); };

})(jQuery);

/* table renders — sticky scroll handled by CSS max-height on .lcrm-table-wrap */
