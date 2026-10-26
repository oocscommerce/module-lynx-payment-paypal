<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentPayPal\Provider\Express;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Paypal\Model\Config as PaypalConfig;
use Magento\Store\Model\ScopeInterface;
use OOCS\LynxComponent\Model\AbstractComponentProvider;

/**
 * Feeds the PayPal Credit button (Components/Express/Bml.vue)
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class Bml extends AbstractComponentProvider
{
    /** The start action core's own Credit shortcut points at, button flag and all. */
    private const START_ACTION = 'paypal/bml/start/button/1';

    /** The button image, as Block\Bml\Shortcut::SHORTCUT_IMAGE names it. */
    private const BUTTON_IMAGE = 'https://www.paypalobjects.com/webstatic/en_US/i/buttons/ppcredit-logo-medium.png';

    /** The "what's this" pair core renders under the button. */
    private const INFO_IMAGE = 'https://www.paypalobjects.com/webstatic/en_US/btn/btn_bml_text.png';
    private const INFO_URL = 'https://www.securecheckout.billmelater.com/paycapture-content/'
        . 'fetch?hash=AU826TU8&content=/bmlweb/ppwpsiw.html';

    /**
     * Url builder, scope config, payment helper, checkout session
     *
     * @param UrlInterface $urlBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param PaymentHelper $paymentHelper
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PaymentHelper $paymentHelper,
        private readonly CheckoutSession $checkoutSession,
    ) {
    }

    /**
     * Resolve
     *
     * @param array $props
     * @return array
     */
    public function resolve(array $props): array
    {
        $props['enabled'] = $this->isEnabled();

        if (!$props['enabled']) {
            return $props;
        }

        return $props + [
            'startUrl'     => $this->urlBuilder->getUrl(self::START_ACTION),
            'imageUrl'     => self::BUTTON_IMAGE,
            'infoImageUrl' => self::INFO_IMAGE,
            'infoUrl'      => self::INFO_URL,
            // Every string the button shows, translated here: the storefront's language is a server-side matter
            'labels' => [
                'checkoutWith' => (string) __('Checkout with PayPal'),
                'creditName'   => (string) __('PayPal Credit'),
            ],
        ];
    }

    /**
     * Whether this page gets a Credit button: the method itself says whether it may be used at all
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        if ($this->scopeConfig->isSetFlag('payment/paypal_express/in_context', ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        try {
            $method = $this->paymentHelper->getMethodInstance(PaypalConfig::METHOD_WPP_BML);
        } catch (\Exception $e) {
            return false;
        }

        $quote = $this->checkoutSession->getQuote();

        return $method->isAvailable($quote->getId() ? $quote : null);
    }
}
