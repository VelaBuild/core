    <script>document.addEventListener('error',function(e){var t=e.target;if(t.tagName==='IMG'&&t.src.indexOf('/imgp/')!==-1){t.src=t.src.replace('/imgp/','/imgr/');t.srcset=t.srcset?t.srcset.replace(/\/imgp\//g,'/imgr/'):''}},true)</script>
    <script>document.addEventListener('click',function(e){document.querySelectorAll('details.js-click-away[open]').forEach(function(d){if(!d.contains(e.target))d.removeAttribute('open')})})</script>
    <script>document.addEventListener('click',function(e){var t=e.target.closest('[data-toggle-target]');if(!t)return;e.preventDefault();var sel=t.getAttribute('data-toggle-target');var target=sel&&document.querySelector(sel);if(!target)return;var open=!target.classList.contains('is-open');target.classList.toggle('is-open',open);t.setAttribute('aria-expanded',open?'true':'false')})</script>
@if(\VelaBuild\Core\Models\VelaConfig::where('key', 'pwa_enabled')->value('value') !== '0')
    @include('vela::partials.pwa-registration')
@endif
    @include('vela::partials.cookie-consent')
