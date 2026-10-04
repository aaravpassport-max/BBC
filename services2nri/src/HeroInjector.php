<?php
/**
 * HeroInjector — Service Page Enhancement
 */

class HeroInjector {

    public static function renderServiceEnhancement( string $slug, array $global_settings ): string {
        $brand = esc_attr( $global_settings['primary_color'] ?? '#4A6FA5' );
        ob_start();
        ?>
<script>
(function() {
    var data = window.S2NRI_SERVICE_DATA;
    if (!data) return;
    var hero    = data.hero_settings    || {};
    var marquee = data.marquee_settings || {};
    var applied = false;

    function applyOverrides() {
        var svcGrid = document.querySelector('.svc-grid');
        if (!svcGrid || applied) return;
        var leftCol  = svcGrid.children[0];
        if (!leftCol) return;
        var heroCard = leftCol.children[0];
        if (!heroCard) return;
        applied = true;

        if (hero.image_url) {
            var img = heroCard.querySelector('img');
            if (img) img.src = hero.image_url;
        }
        if (hero.overlay_opacity !== undefined) {
            var ov = heroCard.querySelector('div[style*="gradient"]');
            if (ov) {
                var op = Math.min(100, Math.max(0, parseInt(hero.overlay_opacity))) / 100;
                ov.style.background = 'linear-gradient(to top,rgba(0,0,0,'+op+') 0%,transparent 50%)';
            }
        }
        if (hero.title) {
            var t = heroCard.querySelector('h1,h2,[style*="fontWeight: 900"]');
            if (t) t.textContent = hero.title;
        }
        if (marquee.enabled && marquee.text) {
            if (!document.getElementById('s2nri-svc-marquee')) {
                var speed   = parseInt(marquee.speed) || 30;
                var bgColor = marquee.bg_color  || '<?php echo $brand; ?>';
                var txColor = marquee.text_color || '#ffffff';
                var mq = document.createElement('div');
                mq.id = 's2nri-svc-marquee';
                mq.style.cssText = 'background:'+bgColor+';overflow:hidden;padding:10px 0;';
                var track = document.createElement('div');
                track.style.cssText = 'display:flex;width:max-content;animation:s2nriScroll '+speed+'s linear infinite;';
                if (marquee.pause_hover !== false) {
                    track.onmouseenter = function(){this.style.animationPlayState='paused';};
                    track.onmouseleave = function(){this.style.animationPlayState='running';};
                }
                for (var r=0;r<4;r++){
                    var sp = document.createElement('span');
                    sp.style.cssText = 'white-space:nowrap;padding:0 60px;font-size:13px;font-weight:700;color:'+txColor+';';
                    sp.textContent = '\u2756 '+marquee.text;
                    track.appendChild(sp);
                }
                mq.appendChild(track);
                if (!document.getElementById('s2nri-svc-marquee-css')){
                    var st = document.createElement('style');
                    st.id  = 's2nri-svc-marquee-css';
                    st.textContent = '@keyframes s2nriScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}';
                    document.head.appendChild(st);
                }
                if (heroCard.nextSibling) leftCol.insertBefore(mq, heroCard.nextSibling);
                else leftCol.appendChild(mq);
            }
        }
    }

    var ob = new MutationObserver(function(){
        if (document.querySelector('.svc-grid') && !applied) applyOverrides();
    });
    ob.observe(document.body, {childList:true, subtree:true});
    setTimeout(applyOverrides, 500);
    setTimeout(applyOverrides, 1500);
})();
</script>
        <?php
        return ob_get_clean();
    }

    /** Disabled — public service pages must not show the legacy in-page section sidebar. */
    public static function renderSectionNav( string $slug, array $global_settings ): string {
        unset( $slug, $global_settings );
        return '';
    }
}
