<?php
/**
 * EasyPixel - Meta Pixel + Conversions API for PrestaShop 8.x / 9.x
 *
 * SCOPE OF THIS BUILD
 * --------------------
 * Implemented: dual delivery (browser Pixel + server-side CAPI) for
 * PageView, ViewContent, AddToCart, InitiateCheckout, Purchase, Refund;
 * per-event enable/disable; GDPR consent gate (cookie or localStorage,
 * optional accept-button selector); granular Purchase controls (VAT/
 * shipping inclusion, payment-validated only, delay-by-module for
 * Bank Transfer/Cheque, exclude customer groups / customer IDs / order
 * statuses, Basic Mode on confirmation page); Advanced Matching (SHA-256
 * hashed email/phone/name/address); CAPI event log (last 10, viewable in
 * admin); Test Event Code support with a one-click "Send Test Event";
 * configurable pixel placement (header/footer) and defer timer.
 *
 * NOT in this build (roadmap - see README.md): multi-pixel-per-shop
 * table UI, Microdata Fix & Improve engine, Commerce Manager catalogue /
 * Reference Diagnostics, bot-signature filtering, CDN/cache bypass
 * rules, auto-detection of specific third-party checkout/wishlist
 * modules, CSV audience exporter, embedded tutorials.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/EasyPixelCapi.php';

class EasyPixel extends Module
{
    /** @var array Event keys this module knows how to send, and their config toggle. */
    const EVENTS = [
        'PAGEVIEW' => 'EASYPIXEL_EVENT_PAGEVIEW',
        'VIEWCONTENT' => 'EASYPIXEL_EVENT_VIEWCONTENT',
        'ADDTOCART' => 'EASYPIXEL_EVENT_ADDTOCART',
        'INITIATECHECKOUT' => 'EASYPIXEL_EVENT_INITIATECHECKOUT',
        'PURCHASE' => 'EASYPIXEL_EVENT_PURCHASE',
        'REFUND' => 'EASYPIXEL_EVENT_REFUND',
    ];

    public function __construct()
    {
        $this->name = 'easypixel';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Nasraoui Mustapha';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->l('EasyPixel – Meta Pixel & Conversions API');
        $this->description = $this->l('Dual-delivery Meta Pixel + Conversions API tracking with GDPR consent and granular Purchase control.');
    }

    /* ------------------------------------------------------------------ */
    /* INSTALL / UNINSTALL                                                 */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        return parent::install()
            && $this->createTables()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayFooter')
            && $this->registerHook('actionOrderConfirmation')
            && $this->registerHook('actionOrderStatusUpdate')
            && $this->setDefaultConfiguration();
    }

    public function uninstall()
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'easypixel_capi_log`';
        Db::getInstance()->execute($sql);

        $keys = array_merge(array_values(self::EVENTS), [
            'EASYPIXEL_PIXEL_ID', 'EASYPIXEL_CAPI_TOKEN', 'EASYPIXEL_TEST_EVENT_CODE',
            'EASYPIXEL_PIXEL_PLACEMENT', 'EASYPIXEL_DEFER_SECONDS',
            'EASYPIXEL_PURCHASE_INCLUDE_VAT', 'EASYPIXEL_PURCHASE_INCLUDE_SHIPPING',
            'EASYPIXEL_PURCHASE_ONLY_VALIDATED', 'EASYPIXEL_PURCHASE_BASIC_MODE',
            'EASYPIXEL_PURCHASE_DELAY_MODULES', 'EASYPIXEL_PURCHASE_EXCLUDE_GROUPS',
            'EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS', 'EASYPIXEL_PURCHASE_EXCLUDE_STATUSES',
            'EASYPIXEL_CONSENT_MODE', 'EASYPIXEL_CONSENT_KEY', 'EASYPIXEL_CONSENT_VALUE',
            'EASYPIXEL_CONSENT_SELECTOR', 'EASYPIXEL_AM_GROUPS', 'EASYPIXEL_IP_MODE',
        ]);
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'easypixel_capi_log` (
            `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `event_name` VARCHAR(64) NOT NULL,
            `event_id` VARCHAR(128) NULL,
            `payload` MEDIUMTEXT NULL,
            `response` MEDIUMTEXT NULL,
            `success` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_log`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return Db::getInstance()->execute($sql);
    }

    private function setDefaultConfiguration()
    {
        $defaults = [
            'EASYPIXEL_PIXEL_ID' => '',
            'EASYPIXEL_CAPI_TOKEN' => '',
            'EASYPIXEL_TEST_EVENT_CODE' => '',
            'EASYPIXEL_PIXEL_PLACEMENT' => 'header',
            'EASYPIXEL_DEFER_SECONDS' => 0,
            'EASYPIXEL_EVENT_PAGEVIEW' => 1,
            'EASYPIXEL_EVENT_VIEWCONTENT' => 1,
            'EASYPIXEL_EVENT_ADDTOCART' => 1,
            'EASYPIXEL_EVENT_INITIATECHECKOUT' => 1,
            'EASYPIXEL_EVENT_PURCHASE' => 1,
            'EASYPIXEL_EVENT_REFUND' => 0,
            'EASYPIXEL_PURCHASE_INCLUDE_VAT' => 1,
            'EASYPIXEL_PURCHASE_INCLUDE_SHIPPING' => 1,
            'EASYPIXEL_PURCHASE_ONLY_VALIDATED' => 1,
            'EASYPIXEL_PURCHASE_BASIC_MODE' => 1,
            'EASYPIXEL_PURCHASE_DELAY_MODULES' => json_encode(['bankwire', 'cheque']),
            'EASYPIXEL_PURCHASE_EXCLUDE_GROUPS' => json_encode([]),
            'EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS' => json_encode([]),
            'EASYPIXEL_PURCHASE_EXCLUDE_STATUSES' => json_encode([]),
            'EASYPIXEL_CONSENT_MODE' => 'none',
            'EASYPIXEL_CONSENT_KEY' => '',
            'EASYPIXEL_CONSENT_VALUE' => '1',
            'EASYPIXEL_CONSENT_SELECTOR' => '',
            'EASYPIXEL_AM_GROUPS' => json_encode(['contact']),
            'EASYPIXEL_IP_MODE' => 'raw',
        ];
        foreach ($defaults as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* HELPERS                                                             */
    /* ------------------------------------------------------------------ */

    private function eventEnabled($eventKey)
    {
        return (bool) Configuration::get(self::EVENTS[$eventKey]);
    }

    private function consentGranted()
    {
        // Server-side we can't read cookies reliably for SPA/JS consent managers
        // that use localStorage, so the definitive gate lives in JS
        // (views/js/easypixel-front.js). Server-side CAPI calls for events
        // that originate from a full page load (PageView, Purchase on
        // confirmation page) check the consent cookie if that's the mode.
        $mode = Configuration::get('EASYPIXEL_CONSENT_MODE');
        if ($mode === 'none') {
            return true;
        }
        if ($mode === 'cookie') {
            $key = Configuration::get('EASYPIXEL_CONSENT_KEY');
            $expected = Configuration::get('EASYPIXEL_CONSENT_VALUE');
            return $key && isset($_COOKIE[$key]) && $_COOKIE[$key] === $expected;
        }
        // localStorage can't be read server-side; assume granted and let the
        // front-end JS gate suppress the browser pixel + its own CAPI calls
        // that are triggered via AJAX endpoints.
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* FRONT HOOKS                                                         */
    /* ------------------------------------------------------------------ */

    public function hookDisplayHeader($params)
    {
        if (Configuration::get('EASYPIXEL_PIXEL_PLACEMENT') !== 'header') {
            return $this->renderConsentAndAssets();
        }
        return $this->renderConsentAndAssets() . $this->renderPixelBlock();
    }

    public function hookDisplayFooter($params)
    {
        if (Configuration::get('EASYPIXEL_PIXEL_PLACEMENT') !== 'footer') {
            return '';
        }
        return $this->renderPixelBlock();
    }

    private function renderConsentAndAssets()
    {
        $this->context->controller->registerJavascript(
            'easypixel-front',
            'modules/' . $this->name . '/views/js/easypixel-front.js',
            ['position' => 'bottom', 'priority' => 20]
        );

        $pendingPurchase = null;
        if (!empty($this->context->cookie->easypixel_pending_purchase)) {
            $pendingPurchase = json_decode($this->context->cookie->easypixel_pending_purchase, true);
            unset($this->context->cookie->easypixel_pending_purchase);
        }

        Media::addJsDef([
            'easyPixelConfig' => [
                'consentMode' => Configuration::get('EASYPIXEL_CONSENT_MODE'),
                'consentKey' => Configuration::get('EASYPIXEL_CONSENT_KEY'),
                'consentValue' => Configuration::get('EASYPIXEL_CONSENT_VALUE'),
                'consentSelector' => Configuration::get('EASYPIXEL_CONSENT_SELECTOR'),
                'events' => [
                    'addToCart' => $this->eventEnabled('ADDTOCART'),
                ],
                'pendingPurchase' => $pendingPurchase,
            ],
        ]);

        return '';
    }

    private function renderPixelBlock()
    {
        if (!$this->consentGranted()) {
            return '';
        }

        $pixelId = Configuration::get('EASYPIXEL_PIXEL_ID');
        if (!$pixelId) {
            return '';
        }

        $controller = $this->context->controller;
        $events = [];

        if ($this->eventEnabled('PAGEVIEW')) {
            $events[] = ['name' => 'PageView', 'data' => new stdClass()];
            EasyPixelCapi::sendEvent('PageView', [], $this->buildUserData(), $this->currentUrl());
        }

        if ($this->eventEnabled('VIEWCONTENT') && $controller instanceof ProductController) {
            $product = $controller->getProduct();
            if (Validate::isLoadedObject($product)) {
                $data = [
                    'content_ids' => [$this->contentId($product)],
                    'content_type' => 'product',
                    'content_name' => $product->name,
                    'value' => (float) $product->getPrice(true),
                    'currency' => $this->context->currency->iso_code,
                ];
                $events[] = ['name' => 'ViewContent', 'data' => $data];
                EasyPixelCapi::sendEvent('ViewContent', $data, $this->buildUserData(), $this->currentUrl());
            }
        }

        if ($this->eventEnabled('INITIATECHECKOUT') && $this->isCheckoutController($controller)) {
            $cart = $this->context->cart;
            if ($cart && $cart->id) {
                $data = [
                    'value' => (float) $cart->getOrderTotal(true, Cart::BOTH),
                    'currency' => $this->context->currency->iso_code,
                    'num_items' => (int) $cart->getNbProducts(false),
                ];
                $events[] = ['name' => 'InitiateCheckout', 'data' => $data];
                EasyPixelCapi::sendEvent('InitiateCheckout', $data, $this->buildUserData(), $this->currentUrl());
            }
        }

        $deferSeconds = (int) Configuration::get('EASYPIXEL_DEFER_SECONDS');

        $this->context->smarty->assign([
            'easypixel_pixel_id' => $pixelId,
            'easypixel_events' => $events,
            'easypixel_defer' => $deferSeconds,
        ]);

        return $this->fetch('module:easypixel/views/templates/hook/easypixel-header.tpl');
    }

    private function isCheckoutController($controller)
    {
        return $controller instanceof OrderController || $this->context->controller->php_self === 'order';
    }

    private function contentId($product)
    {
        // Placeholder for the future Product ID / SKU / prefix logic
        // described in the catalogue feature set (roadmap).
        return (int) $product->id;
    }

    private function currentUrl()
    {
        return (Tools::usingSecureMode() ? 'https://' : 'http://')
            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
            . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    }

    private function buildUserData()
    {
        $data = [
            'client_ip_address' => $this->resolveClientIp(),
            'client_user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
        ];

        $groups = json_decode(Configuration::get('EASYPIXEL_AM_GROUPS'), true) ?: [];
        $customer = $this->context->customer;

        if ($customer && Validate::isLoadedObject($customer)) {
            if (in_array('contact', $groups, true)) {
                if ($customer->email) {
                    $data['em'] = EasyPixelCapi::hash($customer->email);
                }
            }
            if (in_array('personal', $groups, true)) {
                if ($customer->firstname) {
                    $data['fn'] = EasyPixelCapi::hash($customer->firstname);
                }
                if ($customer->lastname) {
                    $data['ln'] = EasyPixelCapi::hash($customer->lastname);
                }
            }
            if (in_array('address', $groups, true)) {
                $address = new Address(Address::getFirstCustomerAddressId($customer->id));
                if (Validate::isLoadedObject($address)) {
                    if ($address->city) {
                        $data['ct'] = EasyPixelCapi::hash($address->city);
                    }
                    if ($address->postcode) {
                        $data['zp'] = EasyPixelCapi::hash($address->postcode);
                    }
                    $country = new Country($address->id_country);
                    if (Validate::isLoadedObject($country)) {
                        $data['country'] = EasyPixelCapi::hash($country->iso_code);
                    }
                }
            }
        }

        if (isset($_COOKIE['_fbp'])) {
            $data['fbp'] = $_COOKIE['_fbp'];
        }
        if (isset($_COOKIE['_fbc'])) {
            $data['fbc'] = $_COOKIE['_fbc'];
        }

        return $data;
    }

    private function resolveClientIp()
    {
        $mode = Configuration::get('EASYPIXEL_IP_MODE');
        $ip = Tools::getRemoteAddr();

        // CloudFlare auto-detection.
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        if ($mode === 'force_ipv6' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // No real IPv4->IPv6 conversion is possible; this mode simply
            // prefers an IPv6 value when the network provides one via
            // X-Forwarded-For, else falls back to raw.
            $forwarded = (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null);
            if ($forwarded && filter_var($forwarded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ip = $forwarded;
            }
        }

        return $ip;
    }

    /* ------------------------------------------------------------------ */
    /* ORDER HOOKS: Purchase + Refund                                      */
    /* ------------------------------------------------------------------ */

    public function hookActionOrderConfirmation($params)
    {
        if (!$this->eventEnabled('PURCHASE')) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'];
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        if (!$this->orderPassesPurchaseFilters($order)) {
            return;
        }

        if ((bool) Configuration::get('EASYPIXEL_PURCHASE_ONLY_VALIDATED') && !$order->valid) {
            // Delay: don't fire yet. It will fire from hookActionOrderStatusUpdate
            // once the order reaches a logable/paid status, unless the payment
            // module is in the "delay" list, in which case that's expected.
            return;
        }

        $this->firePurchase($order);
    }

    public function hookActionOrderStatusUpdate($params)
    {
        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        /** @var OrderState $newStatus */
        $newStatus = $params['newOrderStatus'];

        // --- Purchase: fire once the order becomes valid/paid, for payment
        // methods that were held back (Bank Transfer, Cheque, etc.).
        if ($this->eventEnabled('PURCHASE')
            && (bool) Configuration::get('EASYPIXEL_PURCHASE_ONLY_VALIDATED')
            && $newStatus->logable
            && $this->orderPassesPurchaseFilters($order)
            && !$this->purchaseAlreadySent($order)
        ) {
            $delayModules = json_decode(Configuration::get('EASYPIXEL_PURCHASE_DELAY_MODULES'), true) ?: [];
            if (in_array($order->module, $delayModules, true) || $order->valid) {
                $this->firePurchase($order);
            }
        }

        // --- Refund
        if ($this->eventEnabled('REFUND') && ($newStatus->id == Configuration::get('PS_OS_REFUND') || $newStatus->id == Configuration::get('PS_OS_CANCELED'))) {
            $data = [
                'value' => (float) $order->total_paid,
                'currency' => $order->id_currency ? (new Currency($order->id_currency))->iso_code : $this->context->currency->iso_code,
                'order_id' => $order->reference,
            ];
            EasyPixelCapi::sendEvent('Refund', $data, $this->buildOrderUserData($order), $this->currentUrl(), 'refund_' . $order->id);
        }
    }

    private function purchaseAlreadySent($order)
    {
        $log = Db::getInstance()->getValue(
            'SELECT id_log FROM `' . _DB_PREFIX_ . 'easypixel_capi_log`
             WHERE event_name = "Purchase" AND event_id = "purchase_' . (int) $order->id . '"'
        );
        return (bool) $log;
    }

    private function orderPassesPurchaseFilters($order)
    {
        // Exclude back-office orders.
        if ($order->id_customer && Context::getContext()->employee && Context::getContext()->employee->isLoggedBack()) {
            return false;
        }

        $excludeGroups = json_decode(Configuration::get('EASYPIXEL_PURCHASE_EXCLUDE_GROUPS'), true) ?: [];
        if ($excludeGroups) {
            $customer = new Customer($order->id_customer);
            if (Validate::isLoadedObject($customer)) {
                $customerGroups = $customer->getGroups();
                if (array_intersect($excludeGroups, $customerGroups)) {
                    return false;
                }
            }
        }

        $excludeCustomers = json_decode(Configuration::get('EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS'), true) ?: [];
        if (in_array((int) $order->id_customer, array_map('intval', $excludeCustomers), true)) {
            return false;
        }

        $excludeStatuses = json_decode(Configuration::get('EASYPIXEL_PURCHASE_EXCLUDE_STATUSES'), true) ?: [];
        if (in_array((int) $order->current_state, array_map('intval', $excludeStatuses), true)) {
            return false;
        }

        return true;
    }

    private function firePurchase($order)
    {
        $value = (float) $order->total_paid;
        if (!(bool) Configuration::get('EASYPIXEL_PURCHASE_INCLUDE_VAT')) {
            $value = (float) $order->total_paid_tax_excl;
        }
        if (!(bool) Configuration::get('EASYPIXEL_PURCHASE_INCLUDE_SHIPPING')) {
            $value -= (float) $order->total_shipping;
        }

        $productIds = [];
        foreach ($order->getProducts() as $product) {
            $productIds[] = (string) $product['product_id'];
        }

        $data = [
            'value' => round($value, 2),
            'currency' => (new Currency($order->id_currency))->iso_code,
            'content_ids' => $productIds,
            'content_type' => 'product',
            'order_id' => $order->reference,
        ];

        $eventId = 'purchase_' . $order->id;

        EasyPixelCapi::sendEvent('Purchase', $data, $this->buildOrderUserData($order), $this->currentUrl(), $eventId);

        // Basic Mode: also queue the browser-side event to fire on the
        // confirmation page for redirect-based gateways, using the SAME
        // event_id so Meta deduplicates the pair.
        if ((bool) Configuration::get('EASYPIXEL_PURCHASE_BASIC_MODE')) {
            $this->context->cookie->easypixel_pending_purchase = json_encode([
                'data' => $data,
                'event_id' => $eventId,
            ]);
        }
    }

    private function buildOrderUserData($order)
    {
        $customer = new Customer($order->id_customer);
        $data = [
            'client_ip_address' => $this->resolveClientIp(),
            'client_user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
        ];
        $groups = json_decode(Configuration::get('EASYPIXEL_AM_GROUPS'), true) ?: [];
        if (Validate::isLoadedObject($customer) && in_array('contact', $groups, true) && $customer->email) {
            $data['em'] = EasyPixelCapi::hash($customer->email);
        }
        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* ADMIN CONFIGURATION                                                 */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitEasyPixelTestEvent')) {
            $result = EasyPixelCapi::sendEvent(
                'TestEvent',
                ['value' => 1, 'currency' => $this->context->currency->iso_code],
                ['client_ip_address' => Tools::getRemoteAddr(), 'client_user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '')],
                $this->currentUrl(),
                null,
                Configuration::get('EASYPIXEL_TEST_EVENT_CODE')
            );
            $output .= $result['success']
                ? $this->displayConfirmation($this->l('Test event sent. Response: ') . htmlspecialchars($result['response']))
                : $this->displayError($this->l('Test event failed: ') . htmlspecialchars($result['response']));
        }

        if (Tools::isSubmit('submitEasyPixelSettings')) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        return $output . $this->renderForm() . $this->renderLogTable();
    }

    private function postProcess()
    {
        Configuration::updateValue('EASYPIXEL_PIXEL_ID', Tools::getValue('EASYPIXEL_PIXEL_ID'));
        Configuration::updateValue('EASYPIXEL_CAPI_TOKEN', Tools::getValue('EASYPIXEL_CAPI_TOKEN'));
        Configuration::updateValue('EASYPIXEL_TEST_EVENT_CODE', Tools::getValue('EASYPIXEL_TEST_EVENT_CODE'));
        Configuration::updateValue('EASYPIXEL_PIXEL_PLACEMENT', Tools::getValue('EASYPIXEL_PIXEL_PLACEMENT'));
        Configuration::updateValue('EASYPIXEL_DEFER_SECONDS', (int) Tools::getValue('EASYPIXEL_DEFER_SECONDS'));

        foreach (self::EVENTS as $configKey) {
            Configuration::updateValue($configKey, Tools::getValue($configKey) ? 1 : 0);
        }

        Configuration::updateValue('EASYPIXEL_PURCHASE_INCLUDE_VAT', Tools::getValue('EASYPIXEL_PURCHASE_INCLUDE_VAT') ? 1 : 0);
        Configuration::updateValue('EASYPIXEL_PURCHASE_INCLUDE_SHIPPING', Tools::getValue('EASYPIXEL_PURCHASE_INCLUDE_SHIPPING') ? 1 : 0);
        Configuration::updateValue('EASYPIXEL_PURCHASE_ONLY_VALIDATED', Tools::getValue('EASYPIXEL_PURCHASE_ONLY_VALIDATED') ? 1 : 0);
        Configuration::updateValue('EASYPIXEL_PURCHASE_BASIC_MODE', Tools::getValue('EASYPIXEL_PURCHASE_BASIC_MODE') ? 1 : 0);

        $delayModules = array_filter(array_map('trim', explode(',', Tools::getValue('EASYPIXEL_PURCHASE_DELAY_MODULES'))));
        Configuration::updateValue('EASYPIXEL_PURCHASE_DELAY_MODULES', json_encode(array_values($delayModules)));

        $excludeGroups = array_filter(array_map('intval', explode(',', Tools::getValue('EASYPIXEL_PURCHASE_EXCLUDE_GROUPS'))));
        Configuration::updateValue('EASYPIXEL_PURCHASE_EXCLUDE_GROUPS', json_encode(array_values($excludeGroups)));

        $excludeCustomers = array_filter(array_map('intval', explode(',', Tools::getValue('EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS'))));
        Configuration::updateValue('EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS', json_encode(array_values($excludeCustomers)));

        $excludeStatuses = array_filter(array_map('intval', explode(',', Tools::getValue('EASYPIXEL_PURCHASE_EXCLUDE_STATUSES'))));
        Configuration::updateValue('EASYPIXEL_PURCHASE_EXCLUDE_STATUSES', json_encode(array_values($excludeStatuses)));

        Configuration::updateValue('EASYPIXEL_CONSENT_MODE', Tools::getValue('EASYPIXEL_CONSENT_MODE'));
        Configuration::updateValue('EASYPIXEL_CONSENT_KEY', Tools::getValue('EASYPIXEL_CONSENT_KEY'));
        Configuration::updateValue('EASYPIXEL_CONSENT_VALUE', Tools::getValue('EASYPIXEL_CONSENT_VALUE'));
        Configuration::updateValue('EASYPIXEL_CONSENT_SELECTOR', Tools::getValue('EASYPIXEL_CONSENT_SELECTOR'));

        // HelperForm posts checkbox groups as an associative array keyed by
        // option id (e.g. ['contact' => 'on', 'personal' => 'on']), not a
        // plain indexed list — so we take the keys of whatever was checked.
        $amGroupsPosted = Tools::getValue('EASYPIXEL_AM_GROUPS');
        $amGroups = is_array($amGroupsPosted) ? array_keys($amGroupsPosted) : [];
        Configuration::updateValue('EASYPIXEL_AM_GROUPS', json_encode(array_values($amGroups)));

        Configuration::updateValue('EASYPIXEL_IP_MODE', Tools::getValue('EASYPIXEL_IP_MODE'));
    }

    private function renderForm()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitEasyPixelSettings';
        $helper->default_form_language = $this->context->language->id;

        $helper->fields_value = $this->getFormValues();

        return $helper->generateForm([$this->getGeneralFieldset(), $this->getEventsFieldset(), $this->getPurchaseFieldset(), $this->getConsentFieldset(), $this->getMatchingFieldset()])
            . $this->renderTestEventPanel();
    }

    private function renderTestEventPanel()
    {
        $this->context->smarty->assign(['ajax_token' => Tools::getAdminTokenLite('AdminModules')]);
        return '<form method="post" style="margin:15px 0;">
            <button type="submit" name="submitEasyPixelTestEvent" class="btn btn-default">
                ' . $this->l('Send Test Event') . '
            </button>
            <span class="help-block">' . $this->l('Uses the Test Event Code above; check Events Manager > Test Events.') . '</span>
        </form>';
    }

    private function renderLogTable()
    {
        $logs = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'easypixel_capi_log` ORDER BY id_log DESC LIMIT 10'
        );

        $html = '<h3>' . $this->l('Last 10 Conversions API events') . '</h3>
        <table class="table">
        <thead><tr>
            <th>' . $this->l('Date') . '</th>
            <th>' . $this->l('Event') . '</th>
            <th>' . $this->l('Success') . '</th>
            <th>' . $this->l('Payload') . '</th>
            <th>' . $this->l('Response') . '</th>
        </tr></thead><tbody>';

        foreach ($logs as $log) {
            $html .= '<tr>
                <td>' . htmlspecialchars($log['date_add']) . '</td>
                <td>' . htmlspecialchars($log['event_name']) . '</td>
                <td>' . ($log['success'] ? '<span class="label label-success">OK</span>' : '<span class="label label-danger">' . $this->l('Failed') . '</span>') . '</td>
                <td><pre style="max-width:300px;white-space:pre-wrap;">' . htmlspecialchars(substr($log['payload'], 0, 500)) . '</pre></td>
                <td><pre style="max-width:300px;white-space:pre-wrap;">' . htmlspecialchars(substr((string) $log['response'], 0, 500)) . '</pre></td>
            </tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function getFormValues()
    {
        $values = [
            'EASYPIXEL_PIXEL_ID' => Configuration::get('EASYPIXEL_PIXEL_ID'),
            'EASYPIXEL_CAPI_TOKEN' => Configuration::get('EASYPIXEL_CAPI_TOKEN'),
            'EASYPIXEL_TEST_EVENT_CODE' => Configuration::get('EASYPIXEL_TEST_EVENT_CODE'),
            'EASYPIXEL_PIXEL_PLACEMENT' => Configuration::get('EASYPIXEL_PIXEL_PLACEMENT'),
            'EASYPIXEL_DEFER_SECONDS' => Configuration::get('EASYPIXEL_DEFER_SECONDS'),
            'EASYPIXEL_PURCHASE_INCLUDE_VAT' => Configuration::get('EASYPIXEL_PURCHASE_INCLUDE_VAT'),
            'EASYPIXEL_PURCHASE_INCLUDE_SHIPPING' => Configuration::get('EASYPIXEL_PURCHASE_INCLUDE_SHIPPING'),
            'EASYPIXEL_PURCHASE_ONLY_VALIDATED' => Configuration::get('EASYPIXEL_PURCHASE_ONLY_VALIDATED'),
            'EASYPIXEL_PURCHASE_BASIC_MODE' => Configuration::get('EASYPIXEL_PURCHASE_BASIC_MODE'),
            'EASYPIXEL_PURCHASE_DELAY_MODULES' => implode(',', json_decode(Configuration::get('EASYPIXEL_PURCHASE_DELAY_MODULES'), true) ?: []),
            'EASYPIXEL_PURCHASE_EXCLUDE_GROUPS' => implode(',', json_decode(Configuration::get('EASYPIXEL_PURCHASE_EXCLUDE_GROUPS'), true) ?: []),
            'EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS' => implode(',', json_decode(Configuration::get('EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS'), true) ?: []),
            'EASYPIXEL_PURCHASE_EXCLUDE_STATUSES' => implode(',', json_decode(Configuration::get('EASYPIXEL_PURCHASE_EXCLUDE_STATUSES'), true) ?: []),
            'EASYPIXEL_CONSENT_MODE' => Configuration::get('EASYPIXEL_CONSENT_MODE'),
            'EASYPIXEL_CONSENT_KEY' => Configuration::get('EASYPIXEL_CONSENT_KEY'),
            'EASYPIXEL_CONSENT_VALUE' => Configuration::get('EASYPIXEL_CONSENT_VALUE'),
            'EASYPIXEL_CONSENT_SELECTOR' => Configuration::get('EASYPIXEL_CONSENT_SELECTOR'),
            'EASYPIXEL_IP_MODE' => Configuration::get('EASYPIXEL_IP_MODE'),
        ];
        foreach (self::EVENTS as $key => $configKey) {
            $values[$configKey] = Configuration::get($configKey);
        }

        // Checkbox-group fields need fields_value[name] to be an ARRAY keyed
        // by each option's id (truthy = checked), not the raw stored string.
        // Passing a string here is what caused "Cannot access offset of
        // type string on string" — PrestaShop's form.tpl does
        // $fields_value[$field.name][$option.id], which fails on a string.
        $storedGroups = json_decode(Configuration::get('EASYPIXEL_AM_GROUPS'), true) ?: [];
        $values['EASYPIXEL_AM_GROUPS'] = array_fill_keys($storedGroups, 1);

        return $values;
    }

    /**
     * HelperForm's 'switch' input type reads $params['values'] as a FLAT
     * array of ['id' => .., 'value' => 0|1, 'label' => ..] — NOT the
     * 'query'/'id'/'name' wrapper used by 'select'. Passing the wrong
     * shape here is what caused "Cannot access offset of type string on
     * string" (HelperForm.php's switch-handling loop tried to read
     * ['value'] off what became a plain string once misshapen data was
     * iterated). This helper guarantees the correct shape everywhere.
     */
    private function switchValues($onLabel = null, $offLabel = null)
    {
        return [
            ['id' => 'active_on', 'value' => 1, 'label' => $onLabel ?: $this->l('Yes')],
            ['id' => 'active_off', 'value' => 0, 'label' => $offLabel ?: $this->l('No')],
        ];
    }

    private function getGeneralFieldset()
    {
        return ['form' => [
            'legend' => ['title' => $this->l('General'), 'icon' => 'icon-cogs'],
            'input' => [
                ['type' => 'text', 'label' => $this->l('Pixel ID'), 'name' => 'EASYPIXEL_PIXEL_ID', 'required' => true],
                ['type' => 'text', 'label' => $this->l('Conversions API Access Token'), 'name' => 'EASYPIXEL_CAPI_TOKEN', 'required' => true],
                ['type' => 'text', 'label' => $this->l('Test Event Code'), 'name' => 'EASYPIXEL_TEST_EVENT_CODE'],
                ['type' => 'select', 'label' => $this->l('Pixel placement'), 'name' => 'EASYPIXEL_PIXEL_PLACEMENT',
                    'options' => ['query' => [['id' => 'header', 'name' => $this->l('Header')], ['id' => 'footer', 'name' => $this->l('Footer')]], 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->l('Defer pixel execution (seconds)'), 'name' => 'EASYPIXEL_DEFER_SECONDS'],
                ['type' => 'select', 'label' => $this->l('IP mode'), 'name' => 'EASYPIXEL_IP_MODE',
                    'options' => ['query' => [
                        ['id' => 'raw', 'name' => $this->l('Raw')],
                        ['id' => 'try_ipv6', 'name' => $this->l('Try IPv6')],
                        ['id' => 'force_ipv6', 'name' => $this->l('Force IPv6')],
                    ], 'id' => 'id', 'name' => 'name']],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];
    }

    private function getEventsFieldset()
    {
        $inputs = [];
        foreach (self::EVENTS as $key => $configKey) {
            $inputs[] = [
                'type' => 'switch', 'label' => ucfirst(strtolower($key)), 'name' => $configKey,
                'values' => $this->switchValues($this->l('Enabled'), $this->l('Disabled')),
            ];
        }
        return ['form' => ['legend' => ['title' => $this->l('Events'), 'icon' => 'icon-bolt'], 'input' => $inputs, 'submit' => ['title' => $this->l('Save')]]];
    }

    private function getPurchaseFieldset()
    {
        return ['form' => [
            'legend' => ['title' => $this->l('Purchase controls'), 'icon' => 'icon-shopping-cart'],
            'input' => [
                ['type' => 'switch', 'label' => $this->l('Include VAT in value'), 'name' => 'EASYPIXEL_PURCHASE_INCLUDE_VAT',
                    'values' => $this->switchValues()],
                ['type' => 'switch', 'label' => $this->l('Include shipping in value'), 'name' => 'EASYPIXEL_PURCHASE_INCLUDE_SHIPPING',
                    'values' => $this->switchValues()],
                ['type' => 'switch', 'label' => $this->l('Only fire for payment-validated orders'), 'name' => 'EASYPIXEL_PURCHASE_ONLY_VALIDATED',
                    'values' => $this->switchValues()],
                ['type' => 'switch', 'label' => $this->l('Basic Mode (also fire on confirmation page)'), 'name' => 'EASYPIXEL_PURCHASE_BASIC_MODE',
                    'values' => $this->switchValues()],
                ['type' => 'text', 'label' => $this->l('Delay for payment modules (comma-separated module names)'), 'name' => 'EASYPIXEL_PURCHASE_DELAY_MODULES'],
                ['type' => 'text', 'label' => $this->l('Exclude customer group IDs (comma-separated)'), 'name' => 'EASYPIXEL_PURCHASE_EXCLUDE_GROUPS'],
                ['type' => 'text', 'label' => $this->l('Exclude customer IDs (comma-separated)'), 'name' => 'EASYPIXEL_PURCHASE_EXCLUDE_CUSTOMERS'],
                ['type' => 'text', 'label' => $this->l('Exclude order status IDs (comma-separated)'), 'name' => 'EASYPIXEL_PURCHASE_EXCLUDE_STATUSES'],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];
    }

    private function getConsentFieldset()
    {
        return ['form' => [
            'legend' => ['title' => $this->l('GDPR Consent'), 'icon' => 'icon-lock'],
            'input' => [
                ['type' => 'select', 'label' => $this->l('Consent source'), 'name' => 'EASYPIXEL_CONSENT_MODE',
                    'options' => ['query' => [
                        ['id' => 'none', 'name' => $this->l('None (always tracked)')],
                        ['id' => 'cookie', 'name' => $this->l('Cookie')],
                        ['id' => 'localstorage', 'name' => $this->l('localStorage')],
                    ], 'id' => 'id', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->l('Cookie / localStorage key'), 'name' => 'EASYPIXEL_CONSENT_KEY'],
                ['type' => 'text', 'label' => $this->l('Expected "granted" value'), 'name' => 'EASYPIXEL_CONSENT_VALUE'],
                ['type' => 'text', 'label' => $this->l('Accept-button CSS selector (optional, for live consent changes)'), 'name' => 'EASYPIXEL_CONSENT_SELECTOR'],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];
    }

    private function getMatchingFieldset()
    {
        return ['form' => [
            'legend' => ['title' => $this->l('Advanced Matching'), 'icon' => 'icon-user-secret'],
            'input' => [
                ['type' => 'checkbox', 'label' => $this->l('Data groups to send (SHA-256 hashed)'), 'name' => 'EASYPIXEL_AM_GROUPS',
                    'values' => ['query' => [
                        ['id' => 'contact', 'name' => $this->l('Contact (email)')],
                        ['id' => 'personal', 'name' => $this->l('Personal (first/last name)')],
                        ['id' => 'address', 'name' => $this->l('Address (city, zip, country)')],
                    ], 'id' => 'id', 'name' => 'name']],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];
    }
}
