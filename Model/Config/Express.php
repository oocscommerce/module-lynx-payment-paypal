<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentPayPal\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Paypal\Model\SmartButtonConfig;
use Magento\Store\Model\ScopeInterface;
use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;

/**
 * Client config of the paypal_express renderer (Components/Express.vue)
 */
class Express implements PaymentMethodRendererConfigInterface
{
    /**
     * Smart button config, url builder, scope config
     *
     * @param SmartButtonConfig $smartButtonConfig
     * @param UrlInterface $urlBuilder
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly SmartButtonConfig $smartButtonConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Get Config
     *
     * @return array
     */
    public function getConfig(): array
    {
        $buttonConfig = $this->smartButtonConfig->getConfig('checkout');

        return [
            'sdkUrl'         => (string) ($buttonConfig['sdkUrl'] ?? ''),
            'styles'         => $buttonConfig['styles'] ?? [],
            'dataAttributes' => $buttonConfig['dataAttributes'] ?? [],
            'getTokenUrl'    => $this->urlBuilder->getUrl('paypal/express/getTokenData'),
            'onAuthorizeUrl' => $this->urlBuilder->getUrl('paypal/express/onAuthorization'),
            'startUrl'       => $this->urlBuilder->getUrl('paypal/express/start'),
            'inContext'      => $this->scopeConfig->isSetFlag(
                'payment/paypal_express/in_context',
                ScopeInterface::SCOPE_STORE
            ),
            // Every string the renderer shows, translated here
            'labels' => $this->labels(),
        ];
    }

    /**
     * Labels
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'sdkFailed'          => (string) __('Something went wrong with your request. Please try again later.'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'unavailable'        => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            'startFailed'        => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed'      => (string) __('Error processing payment. Please try again later.'),
            'loadFailed'         => (string) __('Something went wrong with your request. Please try again later.'),
            'redirectNote'       => (string) __('You will be redirected to the PayPal website.'),
            'continueTo'         => (string) __('Continue to PayPal'),
        ];
    }
}
