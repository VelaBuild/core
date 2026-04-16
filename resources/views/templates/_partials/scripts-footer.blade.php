<script>document.addEventListener('error',function(e){var t=e.target;if(t.tagName==='IMG'&&t.src.indexOf('/imgp/')!==-1){t.src=t.src.replace('/imgp/','/imgr/');t.srcset=t.srcset?t.srcset.replace(/\/imgp\//g,'/imgr/'):''}},true)</script>
@if(\VelaBuild\Core\Models\VelaConfig::where('key', 'pwa_enabled')->value('value') !== '0')
    @include('vela::partials.pwa-registration')
@endif
