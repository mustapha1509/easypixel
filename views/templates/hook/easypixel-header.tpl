{* Base Meta Pixel + queued browser-side events. Server-side CAPI calls for
   these same events already happened in PHP before this template rendered
   (see EasyPixel::renderPixelBlock), using the same logical event so Meta
   can deduplicate the pair. *}
<script>
(function () {
  function loadPixel() {
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');

    fbq('init', '{$easypixel_pixel_id|escape:'javascript'}');

    {foreach from=$easypixel_events item=evt}
      fbq('track', '{$evt.name|escape:'javascript'}', {$evt.data|@json_encode});
    {/foreach}

    window.easyPixelFbqReady = true;
  }

  if (window.easyPixelConsentBlocked) {
    // The front-end consent gate (easypixel-front.js) will call
    // window.easyPixelLoadPixel() once consent is granted.
    window.easyPixelLoadPixel = loadPixel;
    return;
  }

  {if $easypixel_defer > 0}
    setTimeout(loadPixel, {$easypixel_defer * 1000});
  {else}
    loadPixel();
  {/if}
})();
</script>
<noscript>
  <img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id={$easypixel_pixel_id|escape:'javascript'}&ev=PageView&noscript=1"/>
</noscript>
