<?php
/**
 * NAS Admin — Blog Posts
 * Restored from the orphaned templates/admin/dashboard.php.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-blog"></i> Blog Posts</h1>
  <button class="nas-btn nas-btn-primary" onclick="blOpenEditor(null)">+ New Post</button>
</div>

<div class="nas-card">
  <div id="bl-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
</div>

<div id="bl-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content" style="max-width:720px">
  <div class="nas-modal-header">
    <h3 id="bl-modal-title">New Post</h3>
    <button class="nas-modal-close" onclick="document.getElementById('bl-modal').style.display='none'">✕</button>
  </div>
  <div class="nas-modal-body">
    <input type="hidden" id="bl-id">
    <div class="nas-modal-grid-2">
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Title</label><input type="text" id="bl-title" class="nas-input" oninput="blAutoSlug()"></div>
      <div class="nas-form-row"><label class="nas-label">Slug</label><input type="text" id="bl-slug" class="nas-input" autocomplete="off"></div>
      <div class="nas-form-row"><label class="nas-label">Category</label>
        <select id="bl-category" class="nas-select">
          <option value="news">News</option><option value="tips">Tips & Guides</option>
          <option value="industry">Industry</option><option value="updates">Updates</option>
        </select>
      </div>
      <div class="nas-form-row"><label class="nas-label">Status</label>
        <select id="bl-status" class="nas-select"><option value="draft">Draft</option><option value="published">Published</option></select>
      </div>
      <div class="nas-form-row"><label class="nas-label">Featured Image URL</label><input type="url" id="bl-featured-image" class="nas-input" placeholder="https://..."></div>
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Tags (comma separated)</label><input type="text" id="bl-tags" class="nas-input"></div>
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Excerpt</label><textarea id="bl-excerpt" class="nas-textarea" rows="2"></textarea></div>
      <div class="nas-form-row nas-form-row--full"><label class="nas-label">Content (HTML supported)</label><textarea id="bl-content" class="nas-textarea" rows="10"></textarea></div>
      <div class="nas-form-row"><label class="nas-label">SEO Title</label><input type="text" id="bl-seo-title" class="nas-input"></div>
      <div class="nas-form-row"><label class="nas-label">SEO Description</label><input type="text" id="bl-seo-desc" class="nas-input"></div>
    </div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('bl-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" id="bl-save-btn" onclick="blSave()">Save Post</button>
  </div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
var blPosts = [];

function post(action, data) {
  var fd = new FormData();
  fd.append('action', action); fd.append('nonce', C.nonce);
  Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
  var ctrl = new AbortController(); setTimeout(function(){ ctrl.abort(); }, 30000);
  return fetch(C.ajaxUrl, { method:'POST', body:fd, signal:ctrl.signal }).then(function(r){ return r.json(); });
}
function esc(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function load() {
  var el = document.getElementById('bl-list');
  post('nas_admin_get_posts', {}).then(function(res){
    if (!res.success) { el.innerHTML = '<p style="color:#dc2626">Could not load posts.</p>'; return; }
    blPosts = res.data.posts || [];
    if (!blPosts.length) { el.innerHTML = '<div class="nas-empty">No posts yet. Click "+ New Post" to create one.</div>'; return; }
    var html = '<table class="nas-table"><thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Views</th><th>Published</th><th>Actions</th></tr></thead><tbody>';
    blPosts.forEach(function(p){
      var sc = p.status==='published' ? '#16a34a' : (p.status==='draft' ? '#d97706' : '#94a3b8');
      html += '<tr><td>'+esc(p.title)+'</td><td>'+esc(p.category)+'</td>'+
        '<td><span style="color:'+sc+';font-weight:700;font-size:12px">'+esc((p.status||'').toUpperCase())+'</span></td>'+
        '<td>'+(p.views||0)+'</td>'+
        '<td>'+(p.published_at ? new Date(p.published_at).toLocaleDateString('en-IN') : '—')+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-xs nas-btn-primary" onclick="blOpenEditor('+p.id+')">Edit</button>'+
        '<button class="nas-btn nas-btn-xs" style="background:#fee2e2;color:#dc2626" onclick="blDelete('+p.id+',\''+esc(p.title)+'\')">Delete</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  }).catch(function(){ el.innerHTML = '<p style="color:#dc2626">Network error.</p>'; });
}

window.blAutoSlug = function() {
  if (document.getElementById('bl-id').value) return; // don't reslug existing posts
  var t = document.getElementById('bl-title').value;
  document.getElementById('bl-slug').value = t.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
};

window.blOpenEditor = function(id) {
  var p = id ? (blPosts.find(function(x){ return x.id == id; }) || {}) : {};
  document.getElementById('bl-modal-title').textContent = id ? 'Edit Post' : 'New Post';
  document.getElementById('bl-id').value = p.id || '';
  document.getElementById('bl-title').value = p.title || '';
  document.getElementById('bl-slug').value = p.slug || '';
  document.getElementById('bl-category').value = p.category || 'news';
  document.getElementById('bl-status').value = p.status || 'draft';
  document.getElementById('bl-featured-image').value = p.featured_image || '';
  document.getElementById('bl-tags').value = p.tags || '';
  document.getElementById('bl-excerpt').value = p.excerpt || '';
  document.getElementById('bl-content').value = p.content || '';
  document.getElementById('bl-seo-title').value = p.seo_title || '';
  document.getElementById('bl-seo-desc').value = p.seo_desc || '';
  document.getElementById('bl-modal').style.display = 'flex';
};

window.blSave = function() {
  var btn = document.getElementById('bl-save-btn'); var orig = btn.textContent;
  var title = document.getElementById('bl-title').value.trim();
  if (!title) { nasAdminToast('Title is required.', 'error'); return; }
  btn.disabled = true; btn.textContent = 'Saving…';
  post('nas_admin_save_post', {
    id: document.getElementById('bl-id').value,
    title: title,
    slug: document.getElementById('bl-slug').value,
    category: document.getElementById('bl-category').value,
    status: document.getElementById('bl-status').value,
    featured_image: document.getElementById('bl-featured-image').value,
    tags: document.getElementById('bl-tags').value,
    excerpt: document.getElementById('bl-excerpt').value,
    content: document.getElementById('bl-content').value,
    seo_title: document.getElementById('bl-seo-title').value,
    seo_desc: document.getElementById('bl-seo-desc').value,
  }).then(function(res){
    btn.disabled = false; btn.textContent = orig;
    if (res.success) {
      nasAdminToast(res.data?.message || 'Post saved!', 'success');
      document.getElementById('bl-modal').style.display = 'none';
      load();
    } else {
      nasAdminToast(res.data?.message || 'Save failed.', 'error');
    }
  }).catch(function(){ btn.disabled=false; btn.textContent=orig; nasAdminToast('Network error.', 'error'); });
};

window.blDelete = function(id, title) {
  if (!confirm('Delete post "'+title+'"? This cannot be undone.')) return;
  post('nas_admin_delete_post', { id: id }).then(function(res){
    if (res.success) { nasAdminToast('Post deleted.', 'success'); load(); }
    else nasAdminToast(res.data?.message || 'Delete failed.', 'error');
  });
};

document.addEventListener('DOMContentLoaded', load);
})();
</script>
