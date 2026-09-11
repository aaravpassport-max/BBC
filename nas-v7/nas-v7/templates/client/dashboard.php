<?php
/**
 * NAS Client Dashboard v3.2
 * Fully self-contained. Does NOT rely on nas-dashboard.js for booking list/drawer.
 * CSS: nas-core.css, nas-dashboard.css, nas-chat.css
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! is_user_logged_in() ) { wp_redirect( home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/client-dashboard/'))) ); exit; }

$user        = wp_get_current_user();
$av          = strtoupper( substr( $user->display_name, 0, 1 ) ?: 'U' );
$my_id       = (int) get_current_user_id();
$cfg         = \NAS\Core\Config::instance();
$brand       = $cfg->get( 'brand_name', get_bloginfo('name') );
$logo        = $cfg->get( 'logo_url', '' );
$nonce       = wp_create_nonce( 'nas_action' );
$rest_nonce  = wp_create_nonce( 'wp_rest' );
// Use frontend proxy endpoint — CDN blocks POST to /wp-admin/admin-ajax.php
$ajax        = admin_url('admin-ajax.php');
$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
?>
<style>
/* Guarantee no theme bleed-through on this page */
body.nas-fullpage,body.nas-fullpage *{box-sizing:border-box}
body.nas-fullpage>*:not(#nas-client-dashboard):not(script):not(style){display:none!important}
html{margin-top:0!important}
body{margin:0!important;padding:0!important}
#wpadminbar{display:none!important}

/* ── Client dashboard full-page layout ─ */
#nas-client-dashboard{min-height:100vh;background:#f8fafc;font-family:var(--nas-font,'Inter',sans-serif)}
.cd-topnav{position:sticky;top:0;z-index:500;background:#fff;border-bottom:1px solid #e2e8f0;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.cd-topnav-inner{max-width:1400px;margin:0 auto;display:flex;align-items:center;gap:12px;padding:0 20px;height:60px}
.cd-logo{display:flex;align-items:center;gap:10px;text-decoration:none;font-weight:800;font-size:16px;color:#1e293b}
.cd-logo img{height:28px}
.cd-logo-icon{font-size:22px}
.cd-nav{display:flex;align-items:center;gap:4px;margin-left:20px}
.cd-nav-btn{background:none;border:none;cursor:pointer;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;color:#64748b;transition:all .15s;white-space:nowrap;font-family:inherit}
.cd-nav-btn:hover{background:#f1f5f9;color:#1e293b}
.cd-nav-btn.active{background:#D7DBFF;color:#2A8AFA}
.cd-topnav-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.cd-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#2A8AFA,#202C39);color:#fff;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.cd-user-name{font-size:13px;font-weight:600;color:#1e293b;cursor:pointer}

/* ── Panels ─ */
.cd-body{max-width:1200px;margin:0 auto;padding:28px 20px}
.cd-panel{display:none}
.cd-panel.show{display:block}

/* ── Stats ─ */
.cd-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
@media(max-width:700px){.cd-stats{grid-template-columns:1fr 1fr}}
.cd-stat{background:#fff;border-radius:14px;padding:16px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:1px solid #f1f5f9}
.cd-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.cd-stat-val{font-size:20px;font-weight:800;color:#1e293b;line-height:1}
.cd-stat-lbl{font-size:11px;color:#94a3b8;margin-top:3px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}

/* ── Booking rows ─ */
.cd-filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.cd-filter-bar input,.cd-filter-bar select{padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;outline:none;background:#fff;transition:border-color .15s}
.cd-filter-bar input:focus,.cd-filter-bar select:focus{border-color:#2A8AFA}
.cd-filter-bar input{flex:1;max-width:240px}
.cd-booking{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:14px;margin-bottom:10px;cursor:pointer;transition:all .15s}
.cd-booking:hover{border-color:#2A8AFA;box-shadow:0 2px 12px rgba(42,138,250,.08)}
.cd-booking-left{flex:1;min-width:0}
.cd-booking-uid{font-size:14px;font-weight:800;color:#2A8AFA;margin-bottom:3px}
.cd-booking-meta{font-size:12px;color:#64748b}
.cd-booking-date{font-size:11px;color:#94a3b8;margin-top:2px}
.cd-booking-right{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap}
.cd-pill{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
.cd-amt{font-size:15px;font-weight:800;color:#1e293b}
.cd-view-btn{background:#f1f5f9;border:none;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;color:#374151;transition:all .15s}
.cd-view-btn:hover{background:#D7DBFF;color:#2A8AFA}

/* ── Booking detail page (full page, not drawer) ─ */
.cd-detail-page{display:none;background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:0;overflow:hidden}
.cd-detail-page.show{display:block}
.cd-detail-header{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #f1f5f9;background:#fafafa}
.cd-back-btn{background:none;border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;color:#64748b;transition:all .15s;font-family:inherit}
.cd-back-btn:hover{background:#f1f5f9;border-color:#c7d2e0}
.cd-detail-title{font-size:18px;font-weight:800;color:#1e293b}
.cd-detail-tabs{display:flex;border-bottom:2px solid #f1f5f9;overflow-x:auto;scrollbar-width:none;padding:0 22px}
.cd-detail-tabs::-webkit-scrollbar{display:none}
.cd-dtab{background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;padding:12px 16px;font-size:13px;font-weight:600;color:#94a3b8;cursor:pointer;white-space:nowrap;transition:all .15s;font-family:inherit}
.cd-dtab:hover{color:#2A8AFA}
.cd-dtab.active{color:#2A8AFA;border-bottom-color:#2A8AFA}
.cd-dtab-panel{display:none;padding:22px}
.cd-dtab-panel.show{display:block}

/* ── Status timeline ─ */
.cd-timeline{display:flex;flex-direction:column;gap:0}
.cd-tl-step{display:flex;gap:14px;position:relative}
.cd-tl-left{display:flex;flex-direction:column;align-items:center;width:24px;flex-shrink:0}
.cd-tl-dot{width:20px;height:20px;border-radius:50%;border:2px solid #e2e8f0;background:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:10px;z-index:1}
.cd-tl-dot.done{background:#16a34a;border-color:#16a34a;color:#fff}
.cd-tl-dot.current{background:#2A8AFA;border-color:#2A8AFA;color:#fff;box-shadow:0 0 0 4px rgba(42,138,250,.15)}
.cd-tl-line{width:2px;flex:1;background:#e2e8f0;margin:2px 0}
.cd-tl-line.done{background:#16a34a}
.cd-tl-body{padding-bottom:18px;flex:1}
.cd-tl-status{font-size:13px;font-weight:600;color:#64748b}
.cd-tl-status.done{color:#16a34a}
.cd-tl-status.current{color:#2A8AFA;font-weight:700}
.cd-tl-time{font-size:11px;color:#94a3b8;margin-top:2px}

/* ── Data rows ─ */
.cd-data-rows{display:flex;flex-direction:column;gap:0}
.cd-data-row{display:flex;padding:10px 0;border-bottom:1px solid #f8fafc;font-size:13px}
.cd-data-row:last-child{border-bottom:none}
.cd-data-row span{width:140px;flex-shrink:0;color:#64748b;font-weight:500}
.cd-data-row strong{color:#1e293b;flex:1}

/* ── Chat inside detail ─ */
.cd-chat-wrap{border:1px solid #f1f5f9;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.cd-chat-msgs{flex:1;min-height:280px;max-height:380px;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#fafafa}
.cd-chat-bubble{max-width:75%;display:flex;flex-direction:column;gap:2px}
.cd-chat-bubble.mine{align-self:flex-end;align-items:flex-end}
.cd-chat-bubble.theirs{align-self:flex-start;align-items:flex-start}
.cd-bubble-text{padding:9px 13px;border-radius:12px;font-size:13px;line-height:1.5;word-break:break-word}
.cd-chat-bubble.mine .cd-bubble-text{background:#2A8AFA;color:#fff;border-bottom-right-radius:4px}
.cd-chat-bubble.theirs .cd-bubble-text{background:#fff;border:1px solid #e2e8f0;color:#1e293b;border-bottom-left-radius:4px}
.cd-bubble-meta{font-size:10px;color:#94a3b8}
.cd-chat-empty{text-align:center;padding:32px;color:#94a3b8;font-size:13px}
.cd-chat-input-row{display:flex;gap:8px;padding:12px;border-top:1px solid #f1f5f9;background:#fff}
.cd-chat-input-row textarea{flex:1;border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 12px;font-size:13px;font-family:inherit;resize:none;outline:none;transition:border-color .15s}
.cd-chat-input-row textarea:focus{border-color:#2A8AFA}
.cd-chat-send{background:#2A8AFA;color:#fff;border:none;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s;white-space:nowrap;font-family:inherit}
.cd-chat-send:hover{background:#1B6FD8}

/* ── Material upload ─ */
.cd-upload-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s}
.cd-upload-zone:hover{border-color:#2A8AFA;background:#EAF2FF}
.cd-upload-icon{font-size:36px;margin-bottom:8px;opacity:.5}
.cd-upload-label{font-weight:600;color:#374151;margin-bottom:4px}
.cd-upload-hint{font-size:12px;color:#94a3b8}
.cd-mat-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px}

/* ── Profile ─ */
.cd-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:600px){.cd-profile-grid{grid-template-columns:1fr}}
.cd-form-group{display:flex;flex-direction:column;gap:5px}
.cd-form-label{font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.3px}
.cd-form-control{padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s}
.cd-form-control:focus{border-color:#2A8AFA}
textarea.cd-form-control{resize:vertical;min-height:70px}

/* ── Misc ─ */
.cd-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.cd-btn-primary{background:#2A8AFA;color:#fff}.cd-btn-primary:hover{background:#1B6FD8}
.cd-btn-ghost{background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0}.cd-btn-ghost:hover{background:#e2e8f0}
.cd-btn-danger{background:#ef4444;color:#fff}.cd-btn-danger:hover{background:#dc2626}
.cd-spinner{width:20px;height:20px;border:2px solid #e2e8f0;border-top-color:#2A8AFA;border-radius:50%;animation:cdSpin .7s linear infinite;display:inline-block;vertical-align:middle}
@keyframes cdSpin{to{transform:rotate(360deg)}}
.cd-empty{text-align:center;padding:40px;color:#94a3b8}
.cd-empty-icon{font-size:40px;display:block;margin-bottom:10px;opacity:.4}
.cd-alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px}
.cd-alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
.cd-alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.cd-alert-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.cd-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.cd-pages{display:flex;justify-content:center;gap:6px;margin-top:16px}
.cd-page-btn{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:7px;padding:5px 11px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit}
.cd-page-btn.active,.cd-page-btn:hover{background:#2A8AFA;color:#fff;border-color:#2A8AFA}
/* ── Professional centered notification system ── */
/* Small inline toasts (bottom-right, non-blocking) */
.cd-toast-wrap{position:fixed;bottom:28px;right:28px;z-index:100000;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:360px}
.cd-toast{background:#fff;color:#1e293b;padding:14px 18px;border-radius:12px;font-size:13px;font-weight:500;pointer-events:all;
  box-shadow:0 4px 6px -1px rgba(0,0,0,.07),0 10px 40px -5px rgba(0,0,0,.12);
  transform:translateX(120%);opacity:0;transition:all .3s cubic-bezier(.175,.885,.32,1.275);
  display:flex;align-items:flex-start;gap:12px;border-left:4px solid #e2e8f0}
.cd-toast.in{transform:translateX(0);opacity:1}
.cd-toast.success{border-left-color:#16a34a}
.cd-toast.error  {border-left-color:#dc2626}
.cd-toast.warn   {border-left-color:#d97706}
.cd-toast.info   {border-left-color:#2563eb}
.cd-toast-icon{font-size:18px;flex-shrink:0;line-height:1.2}
.cd-toast-body{flex:1}
.cd-toast-title{font-weight:700;font-size:13px;color:#0f172a;margin-bottom:2px}
.cd-toast-msg{font-size:12px;color:#64748b;line-height:1.5}
.cd-toast-close{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;padding:0;margin-left:4px;flex-shrink:0;line-height:1}
.cd-toast-close:hover{color:#374151}
/* ── Professional centered confirmation modal ── */
.cd-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:100001;
  display:flex;align-items:center;justify-content:center;padding:20px;
  opacity:0;transition:opacity .25s;pointer-events:none;backdrop-filter:blur(3px)}
.cd-modal-backdrop.open{opacity:1;pointer-events:all}
.cd-modal{background:#fff;border-radius:20px;width:100%;max-width:460px;
  box-shadow:0 25px 60px rgba(0,0,0,.18);
  transform:scale(.92) translateY(16px);transition:transform .3s cubic-bezier(.175,.885,.32,1.275),opacity .25s;
  opacity:0;overflow:hidden}
.cd-modal-backdrop.open .cd-modal{transform:scale(1) translateY(0);opacity:1}
.cd-modal-icon-wrap{padding:32px 32px 20px;text-align:center}
.cd-modal-icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:32px;margin:0 auto 16px;animation:cdIconPop .5s cubic-bezier(.175,.885,.32,1.275) .1s both}
@keyframes cdIconPop{from{transform:scale(0) rotate(-20deg);opacity:0}to{transform:scale(1) rotate(0);opacity:1}}
.cd-modal-title{font-size:20px;font-weight:800;color:#0f172a;margin:0 0 6px;text-align:center}
.cd-modal-sub{font-size:14px;color:#64748b;text-align:center;line-height:1.6;margin:0}
.cd-modal-body{padding:0 24px 8px}
.cd-modal-info-table{width:100%;border-collapse:collapse;background:#f8fafc;border-radius:10px;overflow:hidden;margin-top:4px}
.cd-modal-info-table tr{border-bottom:1px solid #f1f5f9}
.cd-modal-info-table tr:last-child{border-bottom:none}
.cd-modal-info-table td{padding:10px 14px;font-size:13px}
.cd-modal-info-table td:first-child{color:#64748b;font-weight:500;width:42%;white-space:nowrap}
.cd-modal-info-table td:last-child{color:#0f172a;font-weight:600}
.cd-modal-footer{padding:16px 24px 24px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.cd-modal-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 24px;border-radius:10px;
  font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;border:2px solid transparent;text-decoration:none}
.cd-modal-btn-primary{background:#2A8AFA;color:#fff;border-color:#2A8AFA}
.cd-modal-btn-primary:hover{background:#5b3dd4;border-color:#5b3dd4}
.cd-modal-btn-outline{background:#fff;color:#374151;border-color:#e2e8f0}
.cd-modal-btn-outline:hover{border-color:#94a3b8;background:#f8fafc}
/* Wallet panel */
.cd-wallet-panel{max-width:680px;margin:0 auto;padding:24px 0}
.cd-wallet-hero{background:linear-gradient(135deg,#4f35c2 0%,#7c3aed 50%,#a855f7 100%);
  border-radius:18px;padding:28px 28px 24px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden}
.cd-wallet-hero::before{content:'';position:absolute;top:-40px;right:-40px;
  width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.08)}
.cd-wallet-hero::after{content:'';position:absolute;bottom:-30px;left:-20px;
  width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.05)}
.cd-wallet-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;opacity:.75;margin-bottom:8px}
.cd-wallet-amount{font-size:48px;font-weight:800;line-height:1;margin-bottom:4px;font-variant-numeric:tabular-nums}
.cd-wallet-note{font-size:12px;opacity:.7;margin-top:6px}
.cd-wallet-chips{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}
.cd-wallet-chip{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
  border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;cursor:default}
.cd-wallet-txn-list{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.cd-wallet-txn-head{padding:14px 20px;border-bottom:1px solid #e2e8f0;
  display:flex;align-items:center;justify-content:space-between}
.cd-wallet-txn-head-title{font-size:14px;font-weight:700;color:#0f172a}
.cd-wallet-txn-item{display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid #f8fafc;transition:background .15s}
.cd-wallet-txn-item:last-child{border-bottom:none}
.cd-wallet-txn-item:hover{background:#f8fafc}
.cd-wallet-txn-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.cd-wallet-txn-icon.credit{background:#f0fdf4}
.cd-wallet-txn-icon.debit{background:#fef2f2}
.cd-wallet-txn-desc{flex:1;min-width:0}
.cd-wallet-txn-desc-title{font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cd-wallet-txn-desc-date{font-size:11px;color:#94a3b8;margin-top:1px}
.cd-wallet-txn-amount{font-size:15px;font-weight:800;flex-shrink:0}
.cd-wallet-txn-amount.credit{color:#16a34a}
.cd-wallet-txn-amount.debit{color:#dc2626}
/* Ticket panel */
.cd-ticket-panel{max-width:760px;margin:0 auto;padding:24px 0}
.cd-ticket-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
  overflow:hidden;margin-bottom:12px;cursor:pointer;transition:all .2s}
.cd-ticket-card:hover{border-color:#2A8AFA;box-shadow:0 4px 16px rgba(42,138,250,.08)}
.cd-ticket-card-head{display:flex;align-items:flex-start;gap:14px;padding:16px 20px}
.cd-ticket-status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:4px}
.cd-ticket-uid{font-size:11px;font-weight:700;font-family:monospace;color:#2A8AFA;margin-bottom:3px}
.cd-ticket-subject{font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px;line-height:1.4}
.cd-ticket-meta{font-size:11px;color:#94a3b8}
.cd-ticket-status-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;
  border-radius:99px;font-size:11px;font-weight:700;flex-shrink:0;margin-left:auto}
/* Ticket drawer */
.cd-ticket-drawer{display:none;border-top:1px solid #e2e8f0;padding:0}
.cd-ticket-drawer.open{display:block}
.cd-ticket-thread{max-height:320px;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:10px}
.cd-ticket-msg{max-width:82%;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.6}
.cd-ticket-msg.client{background:#D7DBFF;color:#3730a3;align-self:flex-end;border-radius:12px 12px 4px 12px}
.cd-ticket-msg.agent{background:#f1f5f9;color:#374151;align-self:flex-start;border-radius:12px 12px 12px 4px}
.cd-ticket-msg-meta{font-size:10px;opacity:.65;margin-top:4px}
.cd-ticket-reply-bar{padding:12px 16px;border-top:1px solid #e2e8f0;display:flex;gap:8px;background:#f8fafc}
.cd-ticket-reply-input{flex:1;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:none;outline:none;transition:border-color .15s}
.cd-ticket-reply-input:focus{border-color:#2A8AFA}
.cd-ticket-reply-btn{padding:9px 16px;background:#2A8AFA;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;flex-shrink:0;transition:background .15s}
.cd-ticket-reply-btn:hover{background:#5b3dd4}
/* Ticket form slide */
.cd-ticket-form-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-top:16px}
.cd-ticket-form-head{background:linear-gradient(135deg,#2A8AFA,#202C39);padding:16px 20px;color:#fff}
.cd-ticket-form-head h3{font-size:16px;font-weight:800;margin:0 0 2px}
.cd-ticket-form-head p{font-size:12px;opacity:.8;margin:0}
.cd-ticket-form-body{padding:20px}
.cd-ticket-field{margin-bottom:14px}
.cd-ticket-field label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
.cd-ticket-field input,.cd-ticket-field select,.cd-ticket-field textarea{
  width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:9px;
  font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;box-sizing:border-box;color:#0f172a}
.cd-ticket-field input:focus,.cd-ticket-field select:focus,.cd-ticket-field textarea:focus{border-color:#2A8AFA}
.cd-ticket-field textarea{resize:vertical;min-height:100px}
.cd-ticket-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.cd-ticket-2col{grid-template-columns:1fr}}
@media(max-width:480px){
  .cd-toast-wrap{left:16px;right:16px;max-width:none;bottom:80px}
  .cd-wallet-amount{font-size:36px}
}
</style>

<div id="nas-client-dashboard">
<?php if ( current_user_can('manage_options') ): ?>
<div style="background:#0f172a;color:#94a3b8;font-size:11px;font-family:monospace;padding:5px 16px;display:flex;gap:16px;flex-wrap:wrap;border-bottom:1px solid #1e293b">
  <span style="color:#6366f1">NAS DEBUG</span>
  <span>AJAX=<strong style="color:#22c55e"><?php echo esc_html($ajax); ?></strong></span>
  <span>UID=<strong style="color:#22c55e"><?php echo get_current_user_id(); ?></strong></span>
  <span>LOGGEDIN=<strong style="color:#22c55e"><?php echo is_user_logged_in()?'YES':'NO'; ?></strong></span>
  <span>NONCE=<strong style="color:#22c55e"><?php echo esc_html(substr($nonce,0,8)); ?>...</strong></span>
</div>
<?php endif; ?>

<!-- TOPNAV -->
<nav class="cd-topnav">
  <div class="cd-topnav-inner">
    <a class="cd-logo" href="<?php echo esc_url(home_url('/')); ?>">
      <?php if($logo): ?><img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($brand); ?>">
      <?php else: ?><span class="cd-logo-icon"><i class="fa-solid fa-newspaper"></i></span> <?php echo esc_html($brand); ?><?php endif; ?>
    </a>
    <div class="cd-nav">
      <button class="cd-nav-btn active" onclick="cdShowPanel('bookings',this)"><i class="fa-solid fa-list-check"></i> My Bookings</button>
      <button class="cd-nav-btn" onclick="cdShowPanel('profile',this)"><i class="fa-solid fa-user"></i> My Profile</button>
      <button class="cd-nav-btn" onclick="cdShowPanel('wallet',this)"><i class="fa-solid fa-wallet"></i> My Wallet</button>
      <button class="cd-nav-btn" onclick="cdShowPanel('tickets',this)"><i class="fa-solid fa-headset"></i> Support</button>

    </div>
    <div class="cd-topnav-right">
      <button class="cd-avatar" title="<?php echo esc_attr($user->display_name); ?>" onclick="cdDropdown()"><?php echo esc_html($av); ?></button>
      <div id="cd-dd" style="display:none;position:fixed;top:56px;right:16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:6px;min-width:170px;box-shadow:0 8px 30px rgba(0,0,0,.12);z-index:9999">
        <div style="padding:10px 12px;font-weight:700;font-size:13px;border-bottom:1px solid #f1f5f9;margin-bottom:4px;color:#374151"><?php echo esc_html(substr($user->display_name,0,20)); ?></div>
        <a href="<?php echo esc_url($booking_url); ?>" style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:8px;font-size:13px;color:#374151;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''"><i class="fa-solid fa-pen-to-square"></i> Book New Ad</a>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" style="display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:8px;font-size:13px;color:#dc2626;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background=''"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>
</nav>

<!-- BODY -->
<div class="cd-body">

  <!-- PANEL: BOOKINGS -->
  <div class="cd-panel show" id="cd-panel-bookings">
    <!-- Stat cards -->
    <div class="cd-stats" id="cd-stats">
      <div class="cd-stat"><div class="cd-stat-icon" style="background:#D7DBFF;color:#2A8AFA"><i class="fa-solid fa-list-check"></i></div><div><div class="cd-stat-val" id="cd-s-total">—</div><div class="cd-stat-lbl">Total Bookings</div></div></div>
      <div class="cd-stat"><div class="cd-stat-icon" style="background:#fef3c7;color:#d97706">⏳</div><div><div class="cd-stat-val" id="cd-s-active">—</div><div class="cd-stat-lbl">Active</div></div></div>
      <div class="cd-stat"><div class="cd-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><div class="cd-stat-val" id="cd-s-done">—</div><div class="cd-stat-lbl">Completed</div></div></div>
      <div class="cd-stat"><div class="cd-stat-icon" style="background:#D7DBFF;color:#7c3aed">₹</div><div><div class="cd-stat-val" id="cd-s-spent">—</div><div class="cd-stat-lbl">Total Spent</div></div></div>
    </div>

    <!-- Booking list view -->
    <div id="cd-list-view">
      <div class="cd-filter-bar">
        <span style="font-weight:700;font-size:15px;color:#1e293b">My Bookings</span>
        <input type="text" id="cd-search" placeholder="Search bookings…" oninput="cdDebounce()">
        <select id="cd-status-filter" onchange="cdLoadBookings(1)">
          <option value="">All Statuses</option>
          <option value="booking_received">Received</option>
          <option value="under_review">Under Review</option>
          <option value="payment_received">Payment Done</option>
          <option value="published">Published</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
        </select>
        <a href="<?php echo esc_url($booking_url); ?>" class="cd-btn cd-btn-primary" style="margin-left:auto">+ Book New Ad</a>
      </div>
      <div id="cd-bookings-list"><div class="cd-empty"><span class="cd-spinner"></span></div></div>
      <div class="cd-pages" id="cd-pages"></div>
    </div>

    <!-- Booking DETAIL full page (replaces list view) -->
    <div id="cd-detail-view" style="display:none">
      <div class="cd-detail-page show">
        <div class="cd-detail-header">
          <button class="cd-back-btn" onclick="cdBackToList()">← Back to Bookings</button>
          <div class="cd-detail-title" id="cd-detail-title">Order Details</div>
          <div id="cd-detail-status-pill" style="margin-left:auto"></div>
        </div>
        <div class="cd-detail-tabs">
          <button class="cd-dtab active" onclick="cdDTab('status',this)"><i class="fa-solid fa-list-check"></i> Status</button>
          <button class="cd-dtab" onclick="cdDTab('info',this)">ℹ️ Details</button>
          <button class="cd-dtab" onclick="cdDTab('material',this)"><i class="fa-solid fa-folder-open"></i> Material</button>
          <button class="cd-dtab" onclick="cdDTab('messages',this)"><i class="fa-solid fa-comments"></i> Messages</button>
          <button class="cd-dtab" onclick="cdDTab('payments',this)"><i class="fa-solid fa-credit-card"></i> Payments</button>
        </div>
        <div id="cd-dtab-status"   class="cd-dtab-panel show">  <div class="cd-empty"><span class="cd-spinner"></span></div></div>
        <div id="cd-dtab-info"     class="cd-dtab-panel">        <div class="cd-empty"><span class="cd-spinner"></span></div></div>
        <div id="cd-dtab-material" class="cd-dtab-panel">        <div class="cd-empty"><span class="cd-spinner"></span></div></div>
        <div id="cd-dtab-messages" class="cd-dtab-panel" style="padding:0"></div>
        <div id="cd-dtab-payments" class="cd-dtab-panel">        <div class="cd-empty"><span class="cd-spinner"></span></div></div>
      </div>
    </div>
  </div><!-- /panel-bookings -->

  <!-- PANEL: PROFILE -->
  <div class="cd-panel" id="cd-panel-profile">
    <div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:28px;max-width:640px">
      <h2 style="font-size:18px;font-weight:800;margin:0 0 22px;color:#1e293b">My Profile</h2>
      <div class="cd-profile-grid">
        <div class="cd-form-group"><label class="cd-form-label">Full Name</label><input id="pf-name" class="cd-form-control" type="text" value="<?php echo esc_attr($user->display_name); ?>"></div>
  <!-- ── Wallet Panel ── -->
  <div class="cd-panel" id="cd-panel-wallet">
    <div class="cd-wallet-panel">

      <!-- Balance hero card -->
      <div class="cd-wallet-hero">
        <div style="position:relative;z-index:1">
          <div class="cd-wallet-label">Available Balance</div>
          <div class="cd-wallet-amount" id="cd-wallet-balance">
            <span class="cd-spinner" style="border-color:rgba(255,255,255,.3);border-top-color:#fff;width:28px;height:28px"></span>
          </div>
          <div class="cd-wallet-note">Applied automatically at checkout · No expiry</div>
          <div class="cd-wallet-chips">
            <div class="cd-wallet-chip"><i class="fa-solid fa-lock"></i> Secure</div>
            <div class="cd-wallet-chip"><i class="fa-solid fa-bolt"></i> Instant at checkout</div>
            <div class="cd-wallet-chip" id="cd-wallet-txn-count">— transactions</div>
          </div>
        </div>
      </div>

      <!-- Summary stats row -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:20px;font-weight:800;color:#16a34a" id="cd-wallet-total-credit">—</div>
          <div style="font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Total Credited</div>
        </div>
        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:20px;font-weight:800;color:#dc2626" id="cd-wallet-total-debit">—</div>
          <div style="font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Total Used</div>
        </div>
        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center">
          <div style="font-size:20px;font-weight:800;color:#2A8AFA" id="cd-wallet-txn-total">—</div>
          <div style="font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:3px">Transactions</div>
        </div>
      </div>

      <!-- Transaction list -->
      <div class="cd-wallet-txn-list">
        <div class="cd-wallet-txn-head">
          <div class="cd-wallet-txn-head-title">Transaction History</div>
          <div style="display:flex;gap:8px">
            <select id="cd-wallet-filter" onchange="cdFilterWallet()" style="padding:5px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-weight:600;color:#374151;outline:none;cursor:pointer">
              <option value="">All</option>
              <option value="credit">Credits only</option>
              <option value="debit">Debits only</option>
            </select>
          </div>
        </div>
        <div id="cd-wallet-txns">
          <div style="padding:40px;text-align:center"><span class="cd-spinner"></span></div>
        </div>
        <div id="cd-wallet-empty" style="display:none;padding:48px;text-align:center">
          <div style="font-size:44px;margin-bottom:12px;color:#D7DBFF"><i class="fa-solid fa-wallet"></i></div>
          <div style="font-size:15px;font-weight:700;color:#374151;margin-bottom:6px">No transactions yet</div>
          <div style="font-size:13px;color:#94a3b8">Wallet credits from refunds and promotions will appear here.</div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Support Tickets Panel ── -->
  <div class="cd-panel" id="cd-panel-tickets">
    <div class="cd-ticket-panel">

      <!-- Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
          <h2 style="font-size:20px;font-weight:800;margin:0 0 2px;color:#0f172a">Support Tickets</h2>
          <p style="font-size:13px;color:#64748b;margin:0">Track and manage your support requests</p>
        </div>
        <button class="cd-btn cd-btn-primary" onclick="cdToggleTicketForm()" id="cd-new-ticket-btn">
          <i class="fa-solid fa-plus"></i> Open New Ticket
        </button>
      </div>

      <!-- New Ticket Form -->
      <div id="cd-new-ticket-form" style="display:none;margin-bottom:20px">
        <div class="cd-ticket-form-card">
          <div class="cd-ticket-form-head">
            <h3><i class="fa-solid fa-headset"></i> Open a New Support Ticket</h3>
            <p>We typically respond within 2–4 business hours</p>
          </div>
          <div class="cd-ticket-form-body">
            <div class="cd-ticket-2col">
              <div class="cd-ticket-field">
                <label>Issue Category *</label>
                <select id="tkt-category">
                  <option value="general">General Enquiry</option>
                  <option value="billing">Billing / Payment</option>
                  <option value="booking">Booking Issue</option>
                  <option value="proof">Proof / Content Issue</option>
                  <option value="publication">Publication Issue</option>
                  <option value="technical">Technical Problem</option>
                  <option value="refund">Refund Request</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="cd-ticket-field">
                <label>Related Order ID <span style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.6">(optional)</span></label>
                <input type="text" id="tkt-booking-uid" placeholder="e.g. BK240501XXXX">
              </div>
            </div>
            <div class="cd-ticket-field">
              <label>Subject *</label>
              <input type="text" id="tkt-subject" placeholder="Brief one-line description of your issue">
            </div>
            <div class="cd-ticket-field">
              <label>Describe your issue *</label>
              <textarea id="tkt-message" rows="5" placeholder="Please be as detailed as possible — include dates, order IDs, and what you expected vs what happened."></textarea>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <button class="cd-btn cd-btn-primary" id="tkt-submit-btn" onclick="cdSubmitTicket()">
                <i class="fa-solid fa-paper-plane"></i> Submit Ticket
              </button>
              <button class="cd-btn" style="background:#fff;border:1.5px solid #e2e8f0;padding:9px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;color:#374151"
                onclick="cdToggleTicketForm()">Cancel</button>
              <span style="font-size:12px;color:#94a3b8;margin-left:4px">We reply to your registered email</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Ticket list -->
      <div id="cd-tickets-list">
        <div style="padding:40px;text-align:center"><span class="cd-spinner"></span></div>
      </div>

    </div>
  </div>

        <div class="cd-form-group"><label class="cd-form-label">Email</label><input id="pf-email" class="cd-form-control" type="email" value="<?php echo esc_attr($user->user_email); ?>"></div>
        <div class="cd-form-group"><label class="cd-form-label">Phone</label><input id="pf-phone" class="cd-form-control" type="tel" placeholder="Your phone number"></div>
        <div class="cd-form-group"><label class="cd-form-label">City</label><input id="pf-city" class="cd-form-control" type="text" placeholder="Your city"></div>
        <div class="cd-form-group" style="grid-column:1/-1"><label class="cd-form-label">Address</label><textarea id="pf-address" class="cd-form-control" rows="2" placeholder="Your address"></textarea></div>
        <div class="cd-form-group"><label class="cd-form-label">New Password <small style="color:#94a3b8;font-weight:400;text-transform:none">(leave blank to keep)</small></label><input id="pf-pass" class="cd-form-control" type="password" placeholder="••••••••"></div>
        <div class="cd-form-group"><label class="cd-form-label">GST Number <small style="color:#94a3b8;font-weight:400;text-transform:none">(optional)</small></label><input id="pf-gst" class="cd-form-control" type="text" placeholder="22AAAAA0000A1Z5"></div>
      </div>
      <div style="margin-top:20px;display:flex;gap:10px">
        <button class="cd-btn cd-btn-primary" id="pf-save-btn" onclick="cdSaveProfile(this)">Save Changes</button>
      </div>
    </div>
  </div>

</div><!-- /.cd-body -->
<!-- Toast container (non-blocking small toasts) -->
<div class="cd-toast-wrap" id="cd-toasts" aria-live="polite" aria-atomic="false"></div>

<!-- Centered Professional Modal -->
<div class="cd-modal-backdrop" id="cd-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cd-modal-title" onclick="if(event.target===this)cdCloseModal()">
  <div class="cd-modal" id="cd-modal">
    <div class="cd-modal-icon-wrap">
      <div class="cd-modal-icon" id="cd-modal-icon"><i class="fa-solid fa-circle-check"></i></div>
      <h2 class="cd-modal-title" id="cd-modal-title">Done!</h2>
      <p class="cd-modal-sub" id="cd-modal-sub"></p>
    </div>
    <div class="cd-modal-body" id="cd-modal-body"></div>
    <div class="cd-modal-footer" id="cd-modal-footer">
      <button class="cd-modal-btn cd-modal-btn-primary" onclick="cdCloseModal()">
        <i class="fa-solid fa-check"></i> Got it
      </button>
    </div>
  </div>
</div>
</div><!-- /#nas-client-dashboard -->

<script>
(function(){
'use strict';
var NONCE='<?php echo esc_js($nonce); ?>';
var BOOKING_URL='<?php echo esc_js(nas_get_page_url("nas_page_booking","/book-newspaper-ad/")); ?>';
var INITIAL_STATS=<?php echo isset($initial_stats) ? $initial_stats : 'null'; ?>;
var INITIAL_BOOKINGS=<?php echo isset($initial_bookings) ? $initial_bookings : 'null'; ?>;
var REST_NONCE='<?php echo esc_js($rest_nonce); ?>';
var AJAX='<?php echo esc_js($ajax); ?>';
var MY_ID=<?php echo $my_id; ?>;
var cdPage=1, cdCurrentId=null, cdChatTimer=null, cdSearchTimer=null;

/* ── Utilities ──────────────────────────────────────────────────────── */
function esc(s){return(s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function money(n){return'₹'+parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fdate(d){if(!d)return'—';try{return new Date(d.replace(' ','T')).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}
function ftime(d){if(!d)return'';try{return new Date(d.replace(' ','T')).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});}catch(e){return'';}}

function ajax(action,data){
  var fd=new FormData();fd.append('action',action);fd.append('nonce',NONCE);fd.append('nas_action','1');
  Object.entries(data||{}).forEach(function([k,v]){fd.append(k,v??'');});
  var ctrl=new AbortController();
  var tid=setTimeout(function(){ctrl.abort();},8000);
  return fetch(AJAX,{method:'POST',body:fd,signal:ctrl.signal,credentials:'same-origin'})
    .then(function(r){
      clearTimeout(tid);
      var status=r.status;
      return r.text().then(function(txt){
        try{
          var j=JSON.parse(txt);
          if(!j.success)throw new Error(j.data?.message||j.data||'Server returned error');
          return j.data;
        }catch(pe){
          // Show the raw server response so we can diagnose the real error
          throw new Error('Server response (HTTP '+status+'): '+txt.slice(0,300));
        }
      });
    })
    .catch(function(e){
      clearTimeout(tid);
      if(e.name==='AbortError')throw new Error('Connection timed out. URL: '+AJAX);
      throw e;
    });
}

/* ── Professional Toast (small, bottom-right) ── */
function toast(msg, type, title) {
  var wrap = document.getElementById('cd-toasts');
  if (!wrap) return;
  var icons = {success:'<i class="fa-solid fa-circle-check"></i>', error:'<i class="fa-solid fa-circle-xmark"></i>', warn:'<i class="fa-solid fa-triangle-exclamation"></i>', info:'<i class="fa-solid fa-circle-info"></i>'};
  var t = type || 'info';
  var el = document.createElement('div');
  el.className = 'cd-toast ' + t;
  el.setAttribute('role', 'alert');
  el.innerHTML =
    '<span class="cd-toast-icon">' + (icons[t] || '<i class="fa-solid fa-circle-info"></i>') + '</span>' +
    '<div class="cd-toast-body">' +
    (title ? '<div class="cd-toast-title">' + esc(title) + '</div>' : '') +
    '<div class="cd-toast-msg">' + esc(msg) + '</div></div>' +
    '<button class="cd-toast-close" aria-label="Dismiss" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>';
  wrap.appendChild(el);
  requestAnimationFrame(function(){ el.classList.add('in'); });
  setTimeout(function(){
    el.style.opacity = '0'; el.style.transform = 'translateX(120%)';
    setTimeout(function(){ el.remove(); }, 300);
  }, 5000);
}

/* ── Centered Professional Success/Error Modal ── */
function cdShowModal(opts) {
  // opts: { type, icon, title, subtitle, rows, actions }
  // type: 'success' | 'error' | 'info' | 'warning'
  var backdrop = document.getElementById('cd-modal-backdrop');
  var iconEl   = document.getElementById('cd-modal-icon');
  var titleEl  = document.getElementById('cd-modal-title');
  var subEl    = document.getElementById('cd-modal-sub');
  var bodyEl   = document.getElementById('cd-modal-body');
  var footerEl = document.getElementById('cd-modal-footer');

  var colors = {
    success: {bg:'#f0fdf4', color:'#16a34a', border:'#bbf7d0'},
    error:   {bg:'#fef2f2', color:'#dc2626', border:'#fca5a5'},
    info:    {bg:'#eff6ff', color:'#2563eb', border:'#bfdbfe'},
    warning: {bg:'#fffbeb', color:'#d97706', border:'#fde68a'},
  };
  var c = colors[opts.type || 'success'];

  // Icon
  iconEl.innerHTML  = opts.icon || (opts.type === 'error' ? '<i class="fa-solid fa-circle-xmark"></i>' : opts.type === 'warning' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : opts.type === 'info' ? '<i class="fa-solid fa-circle-info"></i>' : '<i class="fa-solid fa-circle-check"></i>');
  iconEl.style.background = c.bg;
  iconEl.style.border = '2px solid ' + c.border;

  // Title + subtitle
  titleEl.textContent = opts.title || 'Done!';
  subEl.textContent   = opts.subtitle || '';
  subEl.style.display = opts.subtitle ? '' : 'none';

  // Info table (rows: [{label, value}])
  bodyEl.innerHTML = '';
  if (opts.rows && opts.rows.length) {
    var table = '<table class="cd-modal-info-table"><tbody>';
    opts.rows.forEach(function(r) {
      table += '<tr><td>' + esc(r.label) + '</td><td>' + (r.html || esc(r.value || '—')) + '</td></tr>';
    });
    table += '</tbody></table>';
    bodyEl.innerHTML = table;
    bodyEl.style.display = '';
  } else {
    bodyEl.style.display = 'none';
  }

  // Footer buttons
  footerEl.innerHTML = '';
  var actions = opts.actions || [{label: 'Got it', primary: true, onclick: 'cdCloseModal()'}];
  actions.forEach(function(a) {
    var btn = document.createElement(a.href ? 'a' : 'button');
    btn.className = 'cd-modal-btn ' + (a.primary ? 'cd-modal-btn-primary' : 'cd-modal-btn-outline');
    if (a.href)   { btn.href = a.href; if (a.target) btn.target = a.target; }
    if (a.onclick && !a.href) btn.onclick = new Function(a.onclick);
    btn.innerHTML = (a.icon ? '<i class="fa-solid fa-' + a.icon + '"></i> ' : '') + esc(a.label);
    footerEl.appendChild(btn);
  });

  backdrop.classList.add('open');
  document.body.style.overflow = 'hidden';

  // Trap focus
  setTimeout(function(){
    var firstBtn = footerEl.querySelector('button,a');
    if (firstBtn) firstBtn.focus();
  }, 300);
}

window.cdCloseModal = function() {
  var backdrop = document.getElementById('cd-modal-backdrop');
  backdrop.classList.remove('open');
  document.body.style.overflow = '';
};

// Close on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') cdCloseModal();
});


function setBtnLoading(btn,on,text){
  if(!btn)return;
  if(on){if(!btn._h)btn._h=btn.innerHTML;btn.innerHTML='<span class="cd-spinner" style="width:14px;height:14px;border-width:2px;margin-right:6px"></span>'+(text||'Saving…');btn.disabled=true;}
  else{if(btn._h)btn.innerHTML=btn._h;btn.disabled=false;}
}

/* ── Status helpers ──────────────────────────────────────────────────── */
var STATUS_LABELS={booking_received:'Booking Received',under_review:'Under Review',ready_to_process:'Ready to Process',documents_received:'Documents Received',payment_received:'Payment Received',ad_processing:'Ad Processing',submitted_to_pub:'Submitted to Publisher',published:'Published',completed:'Completed',not_able_to_process:'Not Able to Process',not_eligible:'Not Eligible',rejected:'Rejected'};
var STATUS_COLORS={booking_received:'#64748b',under_review:'#2563eb',payment_received:'#059669',ready_to_process:'#0891b2',ad_processing:'#7c3aed',submitted_to_pub:'#db2777',published:'#7c3aed',completed:'#059669',rejected:'#dc2626',not_able_to_process:'#dc2626'};
var STATUS_FLOW=['booking_received','under_review','ready_to_process','documents_received','payment_received','ad_processing','submitted_to_pub','published','completed'];

function slabel(s){return STATUS_LABELS[s]||(s||'').replace(/_/g,' ');}
function pill(status){var c=STATUS_COLORS[status]||'#94a3b8';return'<span class="cd-pill" style="background:'+c+'18;color:'+c+'">'+esc(slabel(status))+'</span>';}

/* ── Panel switching ─────────────────────────────────────────────────── */
window.cdShowPanel=function(name,btn){
  document.querySelectorAll('.cd-nav-btn').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('.cd-panel').forEach(function(p){p.classList.remove('show');});
  if(btn)btn.classList.add('active');
  var panel=document.getElementById('cd-panel-'+name);
  if(panel)panel.classList.add('show');
  if(name==='profile')cdLoadProfile();
  if(name==='wallet')cdLoadWallet();
  if(name==='tickets')cdLoadTickets();
  if(name==='bookings')cdBackToList();
};

/* ── Dropdown ────────────────────────────────────────────────────────── */
window.cdDropdown=function(){var dd=document.getElementById('cd-dd');dd.style.display=dd.style.display==='none'?'block':'none';};
document.addEventListener('click',function(e){if(!e.target.closest('.cd-avatar')&&!e.target.closest('#cd-dd')){var dd=document.getElementById('cd-dd');if(dd)dd.style.display='none';}});

/* ── Stats ───────────────────────────────────────────────────────────── */
function cdLoadStats(){
  // Show which URL is being used before the call
  var statsEl2=document.querySelector('.cd-stats');
  if(statsEl2&&!statsEl2.dataset.tracing){
    statsEl2.dataset.tracing='1';
    var dbgDiv=document.createElement('div');
    dbgDiv.style.cssText='font-size:10px;color:#94a3b8;padding:4px 0;grid-column:1/-1';
    dbgDiv.textContent='AJAX: '+AJAX;
    statsEl2.appendChild(dbgDiv);
  }
  ajax('nas_get_dashboard_stats',{}).then(function(d){
    var set=function(id,v){var el=document.getElementById(id);if(el)el.textContent=v;};
    set('cd-s-total',d.total_bookings??'0');
    set('cd-s-active',d.active_bookings??'0');
    set('cd-s-done',d.completed_bookings??'0');
    set('cd-s-spent',money(d.total_spent||0));
  }).catch(function(e){
    // Show error in stats area instead of silent fail + stuck spinner
    var statsEl=document.getElementById('cd-stats-row')||document.querySelector('.cd-stats');
    if(statsEl){statsEl.innerHTML='<div style="padding:10px;color:#ef4444;font-size:13px">Could not load stats: '+esc(e.message)+'</div>';}
  });
}

/* ── Bookings list ───────────────────────────────────────────────────── */
window.cdDebounce=function(){clearTimeout(cdSearchTimer);cdSearchTimer=setTimeout(function(){cdLoadBookings(1);},350);};
// Render bookings from pre-loaded data (server-side or AJAX response)
function cdRenderBookings(d){
  var el=document.getElementById('cd-bookings-list');
  var pEl=document.getElementById('cd-pages');
  if(!el)return;
  pEl.innerHTML='';
  var rows=d.bookings||[],total=d.total||0,perPage=d.per_page||10,page=d.page||1;
  var pages=Math.max(1,Math.ceil(total/perPage));
  if(!rows.length){
    el.innerHTML='<div class="cd-empty"><span class="cd-empty-icon"><i class="fa-solid fa-inbox"></i></span><p class="cd-empty-title">No bookings yet</p><p class="cd-empty-sub">Your newspaper ad bookings will appear here.</p><a href="'+BOOKING_URL+'" class="cd-btn cd-btn-primary" style="margin-top:12px">+ Book a New Ad</a></div>';
    return;
  }
  var P=getP&&getP()||'#6366f1';
  el.innerHTML=rows.map(function(b){
    var uid=b.booking_uid||b.uid||('#'+b.id);
    var c=STATUS_COLORS[b.status]||'#94a3b8';
    return '<div class="cd-booking" onclick="cdOpenDetail('+b.id+')">'
      +'<div class="cd-bk-top"><span class="cd-bk-uid">'+esc(uid)+'</span>'
      +'<span class="cd-pill" style="background:'+c+'18;color:'+c+'">'+esc(slabel(b.status))+'</span></div>'
      +'<div class="cd-bk-name">'+esc(b.newspaper_name||'Newspaper')+' — '+esc(b.category_name||'Ad')+'</div>'
      +'<div class="cd-bk-meta"><span>'+fdate(b.submitted_at)+'</span>'
      +(b.publish_date?'<span><i class="fa-regular fa-calendar"></i> '+fdate(b.publish_date)+'</span>':'')
      +'<span class="cd-bk-amt">'+money(b.total_amount)+'</span></div>'
      +'<button class="cd-view-btn" onclick="event.stopPropagation();cdOpenDetail('+b.id+')">View →</button>'
      +'</div>';
  }).join('');
  if(pages>1){for(var i=1;i<=pages;i++){var btn=document.createElement('button');btn.className='cd-page-btn'+(i===page?' active':'');btn.textContent=i;(function(pg){btn.addEventListener('click',function(){cdLoadBookings(pg);});})(i);pEl.appendChild(btn);}}
}

window.cdLoadBookings=function(page){
  page=page||1;cdPage=page;
  var el=document.getElementById('cd-bookings-list');
  el.innerHTML='<div class="cd-empty" style="text-align:center;padding:20px">'
    +'<span class="cd-spinner" style="display:block;margin:0 auto 12px"></span>'
    +'<div style="font-size:12px;color:#94a3b8;margin-top:8px">Loading from:</div>'
    +'<div style="font-size:11px;color:#64748b;word-break:break-all;margin-top:4px">'+AJAX+'</div>'
    +'</div>';
  var search=document.getElementById('cd-search')?.value||'';
  var status=document.getElementById('cd-status-filter')?.value||'';
  ajax('nas_get_client_bookings',{page:page,per_page:10,search:search,status:status}).then(function(d){
    var rows=d.bookings||[];
    if(!rows.length){el.innerHTML='<div class="cd-empty"><span class="cd-empty-icon"><i class="fa-solid fa-inbox"></i></span><p>No bookings found.</p><a href="<?php echo esc_js($booking_url); ?>" class="cd-btn cd-btn-primary" style="margin-top:14px">Book Your First Ad</a></div>';document.getElementById('cd-pages').innerHTML='';return;}
    el.innerHTML=rows.map(function(b){
      var uid=b.booking_uid||b.uid||('#'+b.id);
      var c=STATUS_COLORS[b.status]||'#94a3b8';
      return'<div class="cd-booking" onclick="cdOpenDetail('+b.id+')">'+
        '<div class="cd-booking-left">'+
        '<div class="cd-booking-uid">#'+esc(uid)+'</div>'+
        '<div class="cd-booking-meta">'+esc(b.newspaper_name||'—')+' · '+esc(b.city_name||'—')+' · '+esc(b.category_name||'—')+'</div>'+
        '<div class="cd-booking-date">'+fdate(b.created_at)+'</div></div>'+
        '<div class="cd-booking-right">'+
        '<span class="cd-pill" style="background:'+c+'18;color:'+c+'">'+esc(slabel(b.status))+'</span>'+
        '<span class="cd-amt">'+money(b.total_amount)+'</span>'+
        '<button class="cd-view-btn" onclick="event.stopPropagation();cdOpenDetail('+b.id+')">View →</button>'+
        '</div></div>';
    }).join('');
    // Pagination
    var total=d.total||0,pages=Math.ceil(total/10);
    var pEl=document.getElementById('cd-pages');pEl.innerHTML='';
    for(var i=1;i<=pages;i++){var btn=document.createElement('button');btn.className='cd-page-btn'+(i===page?' active':'');btn.textContent=i;(function(pg){btn.addEventListener('click',function(){cdLoadBookings(pg);});})(i);pEl.appendChild(btn);}
  }).catch(function(e){
    el.innerHTML='<div class="cd-empty" style="padding:20px;text-align:center"><p style="color:#ef4444"><i class="fa-solid fa-triangle-exclamation"></i> '+esc(e.message)+'</p><button onclick="cdLoadBookings(cdPage)" class="cd-btn cd-btn-sm" style="margin-top:10px">Retry</button></div>';
    document.getElementById('cd-pages').innerHTML='';
  });
};

/* ── Detail view ─────────────────────────────────────────────────────── */
window.cdOpenDetail=function(id){
  cdCurrentId=id;
  document.getElementById('cd-list-view').style.display='none';
  document.getElementById('cd-detail-view').style.display='block';
  document.getElementById('cd-detail-title').textContent='Loading…';
  document.getElementById('cd-detail-status-pill').innerHTML='';
  // Reset tabs
  document.querySelectorAll('.cd-dtab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.cd-dtab-panel').forEach(function(p){p.classList.remove('show');});
  document.querySelectorAll('.cd-dtab')[0].classList.add('active');
  document.getElementById('cd-dtab-status').classList.add('show');
  // Load status tab
  cdLoadStatusTab(id);
  if(cdChatTimer){clearInterval(cdChatTimer);cdChatTimer=null;}
};
window.cdBackToList=function(){
  document.getElementById('cd-list-view').style.display='';
  document.getElementById('cd-detail-view').style.display='none';
  if(cdChatTimer){clearInterval(cdChatTimer);cdChatTimer=null;}
};

window.cdDTab=function(tab,btn){
  document.querySelectorAll('.cd-dtab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.cd-dtab-panel').forEach(function(p){p.classList.remove('show');});
  if(btn)btn.classList.add('active');
  var panel=document.getElementById('cd-dtab-'+tab);if(panel)panel.classList.add('show');
  if(tab==='info')      cdLoadInfoTab(cdCurrentId);
  if(tab==='material')  cdLoadMaterialTab(cdCurrentId);
  if(tab==='messages')  cdLoadMessagesTab(cdCurrentId);
  if(tab==='payments')  cdLoadPaymentsTab(cdCurrentId);
};

/* Status tab */
function cdLoadStatusTab(id){
  var el=document.getElementById('cd-dtab-status');
  el.innerHTML='<div class="cd-empty"><span class="cd-spinner"></span></div>';
  ajax('nas_get_booking_detail',{booking_id:id}).then(function(raw){
    var b=raw.booking||raw;
    document.getElementById('cd-detail-title').textContent='Order #'+(b.booking_uid||b.uid||b.id);
    document.getElementById('cd-detail-status-pill').innerHTML=pill(b.status);
    // CTA alerts
    var cta='';
    if(b.status==='booking_received'||b.status==='under_review')
      cta='<div class="cd-alert cd-alert-info"><i class="fa-solid fa-circle-info"></i> Your booking is under review. We\'ll contact you soon regarding payment.</div>';
    else if(b.status==='payment_received')
      cta='<div class="cd-alert cd-alert-success"><i class="fa-solid fa-circle-check"></i> Payment confirmed! <button class="cd-btn cd-btn-primary" style="padding:5px 12px;font-size:12px;margin-left:8px" onclick="cdDTab(\'material\',document.querySelectorAll(\'.cd-dtab\')[2])">Upload Ad Material →</button></div>';
    else if(b.status==='published')
      cta='<div class="cd-alert cd-alert-success"><i class="fa-solid fa-newspaper"></i> Your ad has been published! You can request proof via Messages.</div>';
    else if(b.status==='rejected')
      cta='<div class="cd-alert cd-alert-danger"><i class="fa-solid fa-circle-xmark"></i> This booking was rejected. Please contact support for details.</div>';
    // Timeline
    
    // Enterprise visual stepper
    var steps5 = [
      {key:'booking_received', label:'Submitted',   icon:'<i class="fa-solid fa-inbox"></i>'},
      {key:'under_review',     label:'Under Review', icon:'<i class="fa-solid fa-magnifying-glass"></i>'},
      {key:'payment_received', label:'Payment OK',   icon:'<i class="fa-solid fa-credit-card"></i>'},
      {key:'proof_ready',      label:'Proof Ready',  icon:'<i class="fa-solid fa-palette"></i>'},
      {key:'published',        label:'Published',    icon:'<i class="fa-solid fa-newspaper"></i>'},
    ];
    // Map complex status to simplified steps
    var statusMap = {
      booking_received:'booking_received', pending:'booking_received',
      under_review:'under_review', ready_to_process:'under_review',
      documents_received:'under_review', payment_pending:'under_review',
      payment_received:'payment_received', client_approved:'proof_ready',
      ad_processing:'proof_ready', proof_ready:'proof_ready',
      submitted_to_pub:'proof_ready', sent_to_vendor:'proof_ready',
      published:'published', completed:'published',
    };
    var mappedStatus = statusMap[b.status] || 'booking_received';
    var curStep5 = steps5.findIndex(function(s){return s.key===mappedStatus;});

    var stepperHtml = '<div style="display:flex;align-items:flex-start;padding:20px 0;overflow-x:auto;-webkit-overflow-scrolling:touch">';
    steps5.forEach(function(step, i) {
      var isDone   = i < curStep5;
      var isCur    = i === curStep5;
      var dotBg    = isDone ? '#059669' : (isCur ? '#2A8AFA' : '#e2e8f0');
      var dotClr   = (isDone||isCur) ? '#fff' : '#94a3b8';
      var dotShadow= isCur ? '0 0 0 4px rgba(42,138,250,.18)' : 'none';
      var lblClr   = isDone ? '#059669' : (isCur ? '#2A8AFA' : '#94a3b8');
      var lblWt    = (isDone||isCur) ? '700' : '500';
      stepperHtml += '<div style="display:flex;flex-direction:column;align-items:center;min-width:80px;flex:1;position:relative">';
      if (i < steps5.length - 1) {
        stepperHtml += '<div style="position:absolute;top:17px;left:50%;right:-50%;height:2px;background:'+(isDone?'#059669':'#e2e8f0')+';z-index:0"></div>';
      }
      stepperHtml += '<div style="width:34px;height:34px;border-radius:50%;background:'+dotBg+';display:flex;align-items:center;justify-content:center;font-size:'+(isDone?'13px':'14px')+';z-index:1;box-shadow:'+dotShadow+';flex-shrink:0;position:relative">';
      stepperHtml += isDone ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : '<span style="color:'+dotClr+'">'+(isCur?step.icon:''+(i+1))+'</span>';
      stepperHtml += '</div>';
      stepperHtml += '<div style="font-size:10px;font-weight:'+lblWt+';color:'+lblClr+';margin-top:8px;text-align:center;line-height:1.3;white-space:nowrap">'+step.label+'</div>';
      stepperHtml += '</div>';
    });
    stepperHtml += '</div>';

    // Also build a history log
    var hist=[]; try{hist=JSON.parse(b.workflow_history||'[]');}catch(e){}
    var histHtml = '';
    if(hist.length) {
      histHtml = '<div style="margin-top:12px;background:#f8fafc;border-radius:10px;padding:12px 14px">';
      histHtml += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px">Status History</div>';
      histHtml += hist.map(function(h){
        return'<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:12px">'+
          '<span style="color:#374151;font-weight:600">'+esc(slabel(h.status||h.new_status||''))+'</span>'+
          '<span style="color:#94a3b8">'+esc(h.at||h.created_at||'')+'</span></div>';
      }).join('');
      histHtml += '</div>';
    }

    el.innerHTML=cta+stepperHtml+histHtml;
  }).catch(function(e){el.innerHTML='<div class="cd-empty" style="color:#dc2626">'+esc(e.message)+'</div>';});
}

/* Info tab */
function cdLoadInfoTab(id){
  var el=document.getElementById('cd-dtab-info');
  el.innerHTML='<div class="cd-empty"><span class="cd-spinner"></span></div>';
  ajax('nas_get_booking_detail',{booking_id:id}).then(function(raw){
    var b=raw.booking||raw;
    var fields=[
      ['Booking UID','#'+(b.booking_uid||b.uid||b.id)],
      ['Newspaper',b.newspaper_name||'—'],
      ['City',b.city_name||'—'],
      ['Category',b.category_name||'—'],
      ['Ad Type',(b.ad_type||'').replace(/_/g,' ')||'—'],
      ['Publication Date',fdate(b.publish_date)],
      ['Total Amount',money(b.total_amount)],
      ['Booked On',fdate(b.created_at)],
    ];
    var rows=fields.map(function(f){return'<div class="cd-data-row"><span>'+esc(f[0])+'</span><strong>'+esc(f[1])+'</strong></div>';}).join('');
    var content=b.ad_content?'<h4 style="font-size:13px;font-weight:700;margin:18px 0 8px;color:#374151">Ad Content</h4><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;font-size:13px;line-height:1.65;white-space:pre-wrap">'+esc(b.ad_content)+'</div>':'';
    el.innerHTML='<div class="cd-data-rows">'+rows+'</div>'+content;
  }).catch(function(e){el.innerHTML='<div class="cd-empty" style="color:#dc2626">'+esc(e.message)+'</div>';});
}

/* Material tab */
function cdLoadMaterialTab(id){
  var el=document.getElementById('cd-dtab-material');
  el.innerHTML='<div class="cd-empty"><span class="cd-spinner"></span></div>';
  ajax('nas_get_materials',{booking_id:id}).then(function(d){
    var mats=d.materials||[];
    var matHtml=mats.length?mats.map(function(m){
      var sc={pending:'#f59e0b',under_review:'#2563eb',approved:'#16a34a',rejected:'#dc2626'};
      var c=sc[m.status]||'#94a3b8';
      return'<div class="cd-mat-item">'+
        '<div style="flex:1"><div style="font-size:13px;font-weight:700">Version '+m.version+(m.file_name?' — '+esc(m.file_name):'')+'</div>'+
        (m.file_url?'<a href="'+esc(m.file_url)+'" target="_blank" style="font-size:12px;color:#2A8AFA;text-decoration:none">View File ↗</a>':'<div style="font-size:12px;color:#64748b">Text Ad</div>')+
        (m.status==='rejected'&&m.rejection_reason?'<div style="font-size:12px;color:#dc2626;margin-top:4px">Reason: '+esc(m.rejection_reason)+'</div>':'')+
        '</div><span class="cd-pill" style="background:'+c+'18;color:'+c+'">'+esc(m.status)+'</span></div>';
    }).join(''):'<p style="color:#94a3b8;font-size:13px">No materials uploaded yet.</p>';
    var approved=mats.some(function(m){return m.status==='approved';});
    var uploadArea=approved?'<div class="cd-alert cd-alert-success" style="margin-top:12px"><i class="fa-solid fa-circle-check"></i> Material approved. No further uploads needed.</div>':
      '<div style="margin-top:16px"><h4 style="font-size:13px;font-weight:700;margin:0 0 10px;color:#374151">Upload New Material</h4>'+
      '<div class="cd-upload-zone" onclick="document.getElementById(\'cd-mat-file-'+id+'\').click()">'+
      '<input type="file" id="cd-mat-file-'+id+'" accept=".jpg,.jpeg,.png,.gif,.pdf,.tif,.tiff" style="display:none" onchange="cdUploadFile('+id+',this)">'+
      '<div class="cd-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div><div class="cd-upload-label">Click to upload or drag file here</div><div class="cd-upload-hint">JPG, PNG, PDF — max 10MB</div></div>'+
      '<p style="text-align:center;font-size:13px;color:#94a3b8;margin:14px 0">— or type your classified ad text —</p>'+
      '<textarea id="cd-mat-text-'+id+'" style="width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:10px 12px;font-size:13px;font-family:inherit;resize:vertical;min-height:80px;outline:none;box-sizing:border-box" placeholder="Type your ad text here…"></textarea>'+
      '<button class="cd-btn cd-btn-primary" style="margin-top:10px;width:100%;justify-content:center" onclick="cdUploadText('+id+')">Submit Text Ad</button></div>';
    el.innerHTML=matHtml+uploadArea;
  }).catch(function(e){el.innerHTML='<div class="cd-empty" style="color:#dc2626">'+esc(e.message)+'</div>';});
}
window.cdUploadFile=function(id,input){
  var file=input.files[0];if(!file)return;
  var fd=new FormData();fd.append('action','nas_upload_material');fd.append('nonce',NONCE);fd.append('booking_id',id);fd.append('material_file',file);
  toast('Uploading…','');
  fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){
    if(r.success){
        cdLoadMaterialTab(id);
        cdShowModal({type:'success',icon:'<i class="fa-solid fa-cloud-arrow-up"></i>',title:'File Uploaded!',
          subtitle:'Your material file has been uploaded and is ready for review.',
          rows:[{label:'Status',value:'Under review by our team'},{label:'File',value:file.name}],
          actions:[{label:'View Status',primary:true,icon:'eye',onclick:'cdCloseModal();cdDTab("status",null)'},{label:'Close',primary:false,onclick:'cdCloseModal()'}]
        });
      }
    else toast(r.data?.message||'Upload failed','error');
  }).catch(function(){toast('Upload failed','error');});
};
window.cdUploadText=function(id){
  var text=document.getElementById('cd-mat-text-'+id)?.value.trim();
  if(!text){toast('Please enter ad text','error');return;}
  ajax('nas_upload_material',{booking_id:id,ad_text:text}).then(function(){
    cdLoadMaterialTab(id);
    cdShowModal({type:'success',icon:'<i class="fa-solid fa-pen-to-square"></i>',title:'Ad Text Submitted!',
      subtitle:'Our team will review your content and prepare a proof within a few hours.',
      rows:[{label:'Status',value:'Submitted for review'},{label:'Next step',value:'We\'ll send your proof via email & dashboard'}],
      actions:[{label:'Track Progress',primary:true,icon:'chart-simple',onclick:'cdCloseModal();cdDTab("status",null)'},{label:'Close',primary:false,onclick:'cdCloseModal()'}]
    });
  }).catch(function(e){toast(e.message,'error','Upload Failed');});
};

/* Messages tab */
function cdLoadMessagesTab(id){
  var el=document.getElementById('cd-dtab-messages');
  el.innerHTML='<div class="cd-chat-wrap">'+
    '<div class="cd-chat-msgs" id="cd-chat-msgs"><div class="cd-chat-empty"><span class="cd-spinner"></span></div></div>'+
    '<div class="cd-chat-input-row">'+
    '<textarea id="cd-chat-inp" placeholder="Type a message to support…" rows="2" onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();cdSendMsg('+id+')}"></textarea>'+
    '<button class="cd-chat-send" onclick="cdSendMsg('+id+')"><i class="fa-solid fa-paper-plane"></i> Send</button>'+
    '</div></div>';
  cdRefreshChat(id);
  if(cdChatTimer)clearInterval(cdChatTimer);
  cdChatTimer=setInterval(function(){cdRefreshChat(id);},5000);
}
function cdRefreshChat(id){
  ajax('nas_get_messages',{booking_id:id}).then(function(d){
    var area=document.getElementById('cd-chat-msgs');if(!area)return;
    var msgs=Array.isArray(d)?d:(d.messages||[]);
    if(!msgs.length){area.innerHTML='<div class="cd-chat-empty">No messages yet. Send us a message!</div>';return;}
    var atBot=area.scrollHeight-area.scrollTop-area.clientHeight<60;
    area.innerHTML=msgs.map(function(m){
      var mine=parseInt(m.sender_id)===MY_ID;
      var t=ftime(m.created_at);
      return'<div class="cd-chat-bubble '+(mine?'mine':'theirs')+'">'+
        '<div class="cd-bubble-text">'+esc(m.message)+'</div>'+
        '<div class="cd-bubble-meta">'+(mine?'You':esc(m.sender_name||'Support'))+' · '+t+'</div></div>';
    }).join('');
    if(atBot)area.scrollTop=area.scrollHeight;
  }).catch(function(){});
}
window.cdSendMsg=function(id){
  var inp=document.getElementById('cd-chat-inp');
  var msg=inp?.value.trim();if(!msg)return;inp.value='';
  ajax('nas_send_message',{booking_id:id,message:msg}).then(function(){cdRefreshChat(id);}).catch(function(e){toast(e.message,'error');});
};

/* Payments tab */
function cdLoadPaymentsTab(id){
  var el=document.getElementById('cd-dtab-payments');
  el.innerHTML='<div class="cd-empty"><span class="cd-spinner"></span></div>';
  ajax('nas_get_booking_detail',{booking_id:id}).then(function(raw){
    var payments=raw.payments||[];
    if(!payments.length){el.innerHTML='<div class="cd-empty"><span class="cd-empty-icon"><i class="fa-solid fa-credit-card"></i></span><p>No payment records yet.</p></div>';return;}
    el.innerHTML='<div class="cd-data-rows">'+payments.map(function(p){
      return'<div class="cd-data-row"><span>'+fdate(p.created_at)+'</span><strong style="color:#16a34a">'+money(p.amount)+'</strong></div>';
    }).join('')+'</div>';
  }).catch(function(e){el.innerHTML='<div class="cd-empty" style="color:#dc2626">'+esc(e.message)+'</div>';});
}

/* ── Profile ─────────────────────────────────────────────────────────── */
function cdLoadProfile(){
  ajax('nas_get_profile',{}).then(function(d){
    ['name','email','phone','city','address'].forEach(function(f){var el=document.getElementById('pf-'+f);if(el)el.value=d[f==='name'?'display_name':f]||'';});
    var gst=document.getElementById('pf-gst');if(gst)gst.value=d.gst_number||'';
  }).catch(function(){});
}
window.cdSaveProfile=function(btn){
  setBtnLoading(btn,true);
  ajax('nas_update_profile',{
    display_name:document.getElementById('pf-name')?.value||'',
    email:document.getElementById('pf-email')?.value||'',
    phone:document.getElementById('pf-phone')?.value||'',
    city:document.getElementById('pf-city')?.value||'',
    address:document.getElementById('pf-address')?.value||'',
    new_password:document.getElementById('pf-pass')?.value||'',
    gst_number:document.getElementById('pf-gst')?.value||''
  }).then(function(d){
    setBtnLoading(btn,false);
    cdShowModal({
      type:'success', icon:'<i class="fa-solid fa-user"></i>', title:'Profile Updated',
      subtitle:'Your profile information has been saved successfully.',
      rows:[{label:'Status',value:'All changes saved ✓'}],
      actions:[{label:'Done', primary:true, onclick:'cdCloseModal()'}]
    });
  }).catch(function(e){setBtnLoading(btn,false);toast(e.message||'Failed to save','error','Save Failed');});
};

/* ── Init ────────────────────────────────────────────────────────────── */

/* ── Wallet ── */
/* ── Wallet — full implementation ── */
var cdWalletAllTxns = [];

function cdLoadWallet() {
  var balEl   = document.getElementById('cd-wallet-balance');
  var txnsEl  = document.getElementById('cd-wallet-txns');
  var emptyEl = document.getElementById('cd-wallet-empty');
  if (balEl) balEl.innerHTML = '<span class="cd-spinner" style="border-color:rgba(255,255,255,.3);border-top-color:#fff;width:24px;height:24px;display:inline-block"></span>';

  ajax('nas_get_wallet', {}).then(function(d) {
    var balance = parseFloat(d.balance || 0);
    var txns    = d.transactions || [];
    cdWalletAllTxns = txns;

    // Balance
    if (balEl) balEl.textContent = money(balance);

    // Count badge
    var countEl = document.getElementById('cd-wallet-txn-count');
    if (countEl) countEl.textContent = txns.length + ' transaction' + (txns.length !== 1 ? 's' : '');

    // Summary stats
    var totalCredit = 0, totalDebit = 0;
    txns.forEach(function(t) {
      var a = parseFloat(t.amount || 0);
      if (t.type === 'credit') totalCredit += a;
      else totalDebit += a;
    });
    var tcEl = document.getElementById('cd-wallet-total-credit');
    var tdEl = document.getElementById('cd-wallet-total-debit');
    var ttEl = document.getElementById('cd-wallet-txn-total');
    if (tcEl) tcEl.textContent = money(totalCredit);
    if (tdEl) tdEl.textContent = money(totalDebit);
    if (ttEl) ttEl.textContent = txns.length;

    // Render transactions
    cdRenderWalletTxns(txns);

  }).catch(function(e) {
    if (balEl) balEl.textContent = '—';
    if (txnsEl) txnsEl.innerHTML = '<div style="padding:24px;text-align:center;color:#dc2626;font-size:13px"><i class="fa-solid fa-triangle-exclamation"></i> Failed to load wallet. Please refresh.</div>';
  });
}

function cdRenderWalletTxns(txns) {
  var txnsEl  = document.getElementById('cd-wallet-txns');
  var emptyEl = document.getElementById('cd-wallet-empty');

  if (!txns || !txns.length) {
    if (txnsEl)  txnsEl.style.display  = 'none';
    if (emptyEl) emptyEl.style.display = '';
    return;
  }
  if (txnsEl)  txnsEl.style.display  = '';
  if (emptyEl) emptyEl.style.display = 'none';

  txnsEl.innerHTML = txns.map(function(t) {
    var isCredit = t.type === 'credit';
    var typeIcon = isCredit ? '<i class="fa-solid fa-plus"></i>' : '<i class="fa-solid fa-minus"></i>';
    var amtCls   = isCredit ? 'credit' : 'debit';
    var iconCls  = isCredit ? 'credit' : 'debit';
    var sign     = isCredit ? '+' : '−';
    return '<div class="cd-wallet-txn-item">' +
      '<div class="cd-wallet-txn-icon ' + iconCls + '">' + typeIcon + '</div>' +
      '<div class="cd-wallet-txn-desc">' +
      '<div class="cd-wallet-txn-desc-title">' + esc(t.description || (isCredit ? 'Credit' : 'Debit')) + '</div>' +
      '<div class="cd-wallet-txn-desc-date">' + fdate(t.created_at) +
      (t.booking_id ? ' · Order #' + esc(t.booking_id) : '') + '</div>' +
      '</div>' +
      '<div class="cd-wallet-txn-amount ' + amtCls + '">' + sign + money(t.amount) + '</div>' +
      '</div>';
  }).join('');
}

window.cdFilterWallet = function() {
  var filter = document.getElementById('cd-wallet-filter')?.value || '';
  var filtered = filter ? cdWalletAllTxns.filter(function(t){ return t.type === filter; }) : cdWalletAllTxns;
  cdRenderWalletTxns(filtered);
};

/* ── Support Tickets — full implementation ── */
var cdOpenTicketId = null;

function cdLoadTickets() {
  var listEl = document.getElementById('cd-tickets-list');
  if (!listEl) return;
  listEl.innerHTML = '<div style="padding:40px;text-align:center"><span class="cd-spinner"></span></div>';

  ajax('nas_get_my_tickets', {}).then(function(d) {
    var tickets = d.tickets || [];
    if (!tickets.length) {
      listEl.innerHTML =
        '<div style="text-align:center;padding:60px 24px">' +
        '<div style="font-size:48px;margin-bottom:16px;color:#D7DBFF"><i class="fa-solid fa-headset"></i></div>' +
        '<div style="font-size:17px;font-weight:700;color:#0f172a;margin-bottom:8px">No support tickets yet</div>' +
        '<div style="font-size:13px;color:#64748b;max-width:280px;margin:0 auto 20px;line-height:1.6">If you have an issue with your booking, payment, or ad proof — open a ticket above.</div>' +
        '</div>';
      return;
    }

    var statusConfig = {
      open:           {color:'#2563eb', label:'Open',           dot:'#2563eb'},
      in_progress:    {color:'#d97706', label:'In Progress',    dot:'#d97706'},
      waiting_client: {color:'#7c3aed', label:'Awaiting You',   dot:'#7c3aed'},
      resolved:       {color:'#059669', label:'Resolved',       dot:'#059669'},
      closed:         {color:'#94a3b8', label:'Closed',         dot:'#94a3b8'},
    };

    listEl.innerHTML = tickets.map(function(t) {
      var sc   = statusConfig[t.status] || statusConfig.open;
      var catIcons = {general:'<i class="fa-solid fa-comments"></i>',billing:'<i class="fa-solid fa-credit-card"></i>',booking:'<i class="fa-solid fa-list-check"></i>',proof:'<i class="fa-solid fa-palette"></i>',publication:'<i class="fa-solid fa-newspaper"></i>',technical:'<i class="fa-solid fa-screwdriver-wrench"></i>',refund:'<i class="fa-solid fa-rotate-left"></i>',other:'<i class="fa-solid fa-thumbtack"></i>'};
      var catIcon = catIcons[t.category] || '<i class="fa-solid fa-comments"></i>';

      return '<div class="cd-ticket-card" id="tkt-card-' + t.id + '">' +
        '<div class="cd-ticket-card-head" onclick="cdToggleTicket(' + t.id + ',this)">' +
        '<div class="cd-ticket-status-dot" style="background:' + sc.dot + ';margin-top:6px"></div>' +
        '<div style="flex:1;min-width:0">' +
        '<div class="cd-ticket-uid">' + catIcon + ' ' + esc(t.ticket_uid) + '</div>' +
        '<div class="cd-ticket-subject">' + esc(t.subject) + '</div>' +
        '<div class="cd-ticket-meta">' + fdate(t.created_at) + ' · ' + esc((t.category||'general').replace(/_/g,' ')) +
        (t.booking_id ? ' · Order #' + esc(t.booking_id) : '') + '</div>' +
        '</div>' +
        '<div style="display:flex;align-items:center;gap:8px;flex-shrink:0">' +
        '<span class="cd-ticket-status-badge" style="background:' + sc.color + '18;color:' + sc.color + '">' +
        '<span style="width:6px;height:6px;border-radius:50%;background:' + sc.color + '"></span>' + esc(sc.label) + '</span>' +
        '<i class="fa-solid fa-chevron-down" style="font-size:11px;color:#94a3b8;transition:transform .2s" id="tkt-chevron-' + t.id + '"></i>' +
        '</div></div>' +
        '<div class="cd-ticket-drawer" id="tkt-drawer-' + t.id + '">' +
        '<div class="cd-ticket-thread" id="tkt-thread-' + t.id + '">' +
        '<div style="text-align:center;padding:20px;color:#94a3b8;font-size:13px">Loading messages…</div>' +
        '</div>' +
        '<div class="cd-ticket-reply-bar">' +
        '<textarea class="cd-ticket-reply-input" id="tkt-reply-' + t.id + '" rows="2" placeholder="Type your reply…" onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();cdSendTicketReply(' + t.id + ')}"></textarea>' +
        '<button class="cd-ticket-reply-btn" onclick="cdSendTicketReply(' + t.id + ')"><i class="fa-solid fa-paper-plane"></i> Send</button>' +
        '</div>' +
        (t.status === 'resolved' || t.status === 'closed'
          ? '<div style="padding:10px 16px;background:#f0fdf4;border-top:1px solid #bbf7d0;font-size:12px;color:#166534;text-align:center"><i class="fa-solid fa-circle-check"></i> This ticket is ' + esc(sc.label) + '</div>'
          : '') +
        '</div>' +
        '</div>';
    }).join('');

  }).catch(function() {
    listEl.innerHTML = '<div style="padding:24px;text-align:center;color:#dc2626;font-size:13px"><i class="fa-solid fa-triangle-exclamation"></i> Failed to load tickets. Please refresh.</div>';
  });
}

window.cdToggleTicket = function(id, headEl) {
  var drawer  = document.getElementById('tkt-drawer-' + id);
  var chevron = document.getElementById('tkt-chevron-' + id);
  var isOpen  = drawer.classList.contains('open');

  // Close others
  document.querySelectorAll('.cd-ticket-drawer.open').forEach(function(d){ d.classList.remove('open'); });
  document.querySelectorAll('[id^="tkt-chevron-"]').forEach(function(c){ c.style.transform = ''; });

  if (!isOpen) {
    drawer.classList.add('open');
    if (chevron) chevron.style.transform = 'rotate(180deg)';
    cdLoadTicketThread(id);
    cdOpenTicketId = id;
  } else {
    cdOpenTicketId = null;
  }
};

function cdLoadTicketThread(id) {
  var threadEl = document.getElementById('tkt-thread-' + id);
  if (!threadEl) return;
  ajax('nas_get_ticket_detail', {ticket_id: id}).then(function(d) {
    var replies = d.replies || [];
    var ticket  = d.ticket  || {};

    if (!replies.length && !ticket.description) {
      threadEl.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8;font-size:13px">No messages yet. Send a message below.</div>';
      return;
    }

    // First message = original description
    var msgs = [];
    if (ticket.description) {
      msgs.push({sender_type:'client', message: ticket.description, created_at: ticket.created_at, is_first: true});
    }
    replies.forEach(function(r){ msgs.push(r); });

    threadEl.innerHTML = msgs.map(function(r) {
      var isClient = r.sender_type === 'client';
      return '<div class="cd-ticket-msg ' + (isClient ? 'client' : 'agent') + '">' +
        (r.is_first ? '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;opacity:.6;margin-bottom:4px">Your original message</div>' : '') +
        esc(r.message) +
        '<div class="cd-ticket-msg-meta">' +
        (isClient ? 'You' : 'Support Team') + ' · ' + fdate(r.created_at) +
        '</div></div>';
    }).join('');
    threadEl.scrollTop = threadEl.scrollHeight;
  }).catch(function() {
    threadEl.innerHTML = '<div style="padding:16px;color:#dc2626;font-size:13px;text-align:center">Failed to load messages.</div>';
  });
}

window.cdSendTicketReply = function(id) {
  var inp = document.getElementById('tkt-reply-' + id);
  var msg = inp?.value.trim();
  if (!msg) return;
  inp.value = '';
  inp.disabled = true;
  ajax('nas_reply_ticket', {ticket_id: id, message: msg}).then(function() {
    inp.disabled = false;
    cdLoadTicketThread(id);
    toast('Reply sent', 'success', 'Message Sent');
  }).catch(function(e) {
    inp.disabled = false;
    inp.value = msg; // restore
    toast(e.message || 'Failed to send reply', 'error', 'Error');
  });
};

window.cdToggleTicketForm = function() {
  var form = document.getElementById('cd-new-ticket-form');
  var btn  = document.getElementById('cd-new-ticket-btn');
  var isOpen = form.style.display !== 'none';
  form.style.display = isOpen ? 'none' : '';
  if (btn) {
    btn.innerHTML = isOpen
      ? '<i class="fa-solid fa-plus"></i> Open New Ticket'
      : '<i class="fa-solid fa-xmark"></i> Cancel';
  }
  if (!isOpen) {
    setTimeout(function(){ document.getElementById('tkt-category')?.focus(); }, 100);
  }
};

// Keep backward compat
window.cdOpenNewTicket = window.cdToggleTicketForm;

window.cdSubmitTicket = function() {
  var subject    = document.getElementById('tkt-subject')?.value.trim();
  var message    = document.getElementById('tkt-message')?.value.trim();
  var category   = document.getElementById('tkt-category')?.value;
  var bookingUid = document.getElementById('tkt-booking-uid')?.value.trim();
  var btn        = document.getElementById('tkt-submit-btn');

  if (!subject) { toast('Please enter a subject', 'error', 'Missing Subject'); return; }
  if (!message || message.length < 10) { toast('Please describe your issue in at least 10 characters', 'error', 'Too Short'); return; }

  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting…'; }

  ajax('nas_submit_support_ticket', {
    subject: subject, message: message,
    category: category, booking_uid: bookingUid,
    name: '', email: ''
  }).then(function(d) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Ticket'; }

    // Close the form
    document.getElementById('cd-new-ticket-form').style.display = 'none';
    var formBtn = document.getElementById('cd-new-ticket-btn');
    if (formBtn) formBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Open New Ticket';

    // Clear fields
    ['tkt-subject','tkt-message','tkt-booking-uid'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) el.value = '';
    });

    // Show professional centered modal
    cdShowModal({
      type:     'success',
      icon:     '<i class="fa-solid fa-headset"></i>',
      title:    'Support Ticket Submitted!',
      subtitle: 'Our team will review your request and respond within 2–4 business hours.',
      rows: [
        {label: 'Ticket ID',    value: d.ticket_uid || '—'},
        {label: 'Category',     value: (category||'general').replace(/_/g,' ')},
        {label: 'Subject',      value: subject.length > 50 ? subject.slice(0,50)+'…' : subject},
        {label: 'Reply to',     value: 'Your registered email'},
        {label: 'Response time',value: '2–4 business hours'},
      ],
      actions: [
        {label:'View My Tickets', primary: true, icon:'headset', onclick:'cdCloseModal();cdShowPanel(\'tickets\',null);cdLoadTickets();'},
        {label:'Close', primary: false, onclick:'cdCloseModal()'},
      ]
    });

    cdLoadTickets();
  }).catch(function(e) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Ticket'; }
    toast(e.message || 'Submission failed', 'error', 'Error');
  });
};

window.cdViewTicket = function(id) {
  cdToggleTicket(id, null);
};

// Global error catcher — surfaces JS errors instead of silent freeze
window.addEventListener('error', function(e) {
  var el = document.getElementById('cd-bookings-list');
  if (el && el.querySelector('.cd-spinner')) {
    el.innerHTML = '<div class="cd-empty" style="color:#ef4444;padding:20px;text-align:center">'
      + '<p><strong>Script error — please reload the page</strong></p>'
      + '<p style="font-size:12px;opacity:.7;margin-top:4px">' + (e.message||'Unknown error') + ' (line ' + e.lineno + ')</p>'
      + '<button onclick="location.reload()" class="cd-btn" style="margin-top:12px">Reload Page</button>'
      + '</div>';
  }
});

document.addEventListener('DOMContentLoaded',function(){
  try {
    // Use server-injected data — no AJAX required for initial page load
    if (typeof INITIAL_STATS !== 'undefined') {
      var s = INITIAL_STATS;
      var set=function(id,v){var el=document.getElementById(id);if(el)el.textContent=v;};
      set('cd-s-total',  s.total_bookings     !== undefined ? s.total_bookings     : '—');
      set('cd-s-active', s.active_bookings    !== undefined ? s.active_bookings    : '—');
      set('cd-s-done',   s.completed_bookings !== undefined ? s.completed_bookings : '—');
      set('cd-s-spent',  money(s.total_spent  || 0));
    } else { cdLoadStats(); }

    if (typeof INITIAL_BOOKINGS !== 'undefined') {
      cdRenderBookings(INITIAL_BOOKINGS);
    } else { cdLoadBookings(1); }

  } catch(e) {
    var el = document.getElementById('cd-bookings-list');
    if (el) el.innerHTML = '<div class="cd-empty" style="color:#ef4444;padding:20px;text-align:center">'
      + '<p>' + esc(e.message||String(e)) + '</p>'
      + '<button onclick="location.reload()" class="cd-btn" style="margin-top:10px">Reload</button></div>';
  }
});
})();
</script>
