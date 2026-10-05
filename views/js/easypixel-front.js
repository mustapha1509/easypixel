/**
 * EasyPixel front-end runtime.
 * - GDPR consent gate (cookie or localStorage + optional accept-button listener)
 * - AddToCart tracking via PrestaShop's `updateCart` JS event
 * - Flushes a pending Purchase event queued server-side for Basic Mode
 * - Strips known sensitive URL params before any fbq() call fires
 */
(function () {
  if (typeof easyPixelConfig === 'undefined') {
    return;
  }

  var cfg = easyPixelConfig;

  function readConsentCookie(key) {
    var match = document.cookie.match('(^|;)\\s*' + key + '\\s*=\\s*([^;]+)');
    return match ? decodeURIComponent(match.pop()) : null;
  }

  function hasConsent() {
    if (cfg.consentMode === 'none' || !cfg.consentKey) {
      return true;
    }
    var value = null;
    if (cfg.consentMode === 'cookie') {
      value = readConsentCookie(cfg.consentKey);
    } else if (cfg.consentMode === 'localstorage') {
      try {
        value = window.localStorage.getItem(cfg.consentKey);
      } catch (e) {
        value = null;
      }
    }
    return value === cfg.consentValue;
  }

  function cleanUrl(url) {
    var sensitiveParams = ['email', 'phone', 'token', 'password', 'id_customer'];
    try {
      var u = new URL(url, window.location.origin);
      sensitiveParams.forEach(function (p) { u.searchParams.delete(p); });
      return u.toString();
    } catch (e) {
      return url;
    }
  }

  window.easyPixelConsentBlocked = !hasConsent();

  function grantConsentAndLoad() {
    if (window.easyPixelFbqReady) {
      return;
    }
    window.easyPixelConsentBlocked = false;
    if (typeof window.easyPixelLoadPixel === 'function') {
      window.easyPixelLoadPixel();
    }
  }

  // Optional listener for consent-manager accept buttons that don't
  // reload the page after consent is granted.
  if (cfg.consentSelector) {
    document.addEventListener('click', function (evt) {
      if (evt.target.closest && evt.target.closest(cfg.consentSelector)) {
        // Give the consent manager a tick to write its cookie/localStorage
        // value before we re-check it.
        setTimeout(function () {
          if (hasConsent()) {
            grantConsentAndLoad();
          }
        }, 150);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Flush a Basic Mode pending Purchase (queued by the server on the
    // order-confirmation page for redirect-based payment gateways).
    if (cfg.pendingPurchase && window.fbq) {
      fbq('track', 'Purchase', cfg.pendingPurchase.data, { eventID: cfg.pendingPurchase.event_id });
    }

    if (!cfg.events || !cfg.events.addToCart) {
      return;
    }
    if (typeof prestashop === 'undefined') {
      return;
    }

    prestashop.on('updateCart', function (event) {
      if (window.easyPixelConsentBlocked || typeof fbq === 'undefined') {
        return;
      }
      if (!event || !event.reason || event.reason.linkAction !== 'add-to-cart') {
        return;
      }

      var idProduct = event.reason.idProduct;
      var cartProducts = (event.reason.cart && event.reason.cart.products) || [];
      var addedProduct = cartProducts.filter(function (p) {
        return String(p.id) === String(idProduct);
      })[0];

      fbq('track', 'AddToCart', {
        content_ids: [String(idProduct)],
        content_type: 'product',
        value: addedProduct ? addedProduct.total_wt : undefined,
        currency: (prestashop.currency && prestashop.currency.iso_code) || undefined,
      });
    });
  });

  // Expose for debugging / the Microdata Scanner roadmap item.
  window.easyPixelCleanUrl = cleanUrl;
})();
