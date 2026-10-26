<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentPayPal\Provider\Express;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Paypal\Model\Config as PaypalConfig;
use Magento\Paypal\Model\ConfigFactory as PaypalConfigFactory;
use Magento\Paypal\Model\SmartButtonConfig;
use Magento\Store\Model\ScopeInterface;
use OOCS\LynxComponent\Helper\LocalizedText;
use OOCS\LynxComponent\Model\AbstractComponentProvider;

/**
 * Feeds the PayPal Express shortcut button (Components/Express/Shortcut.vue)
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class Shortcut extends AbstractComponentProvider
{
    /**
     * The start action core's own shortcut points at, button flag and all
     * (Magento_Paypal etc/di.xml). Kept verbatim rather than assembled, so the URL the
     * redirect branch leaves for is the one core documents.
     */
    private const START_ACTION = 'paypal/express/start/button/1';

    private const STYLE_KEYS = ['layout', 'color', 'shape', 'label'];

    /**
     * Smart button config, url builder, scope config, payment helper, checkout session, paypal config factory
     *
     * @param SmartButtonConfig $smartButtonConfig
     * @param UrlInterface $urlBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param PaymentHelper $paymentHelper
     * @param CheckoutSession $checkoutSession
     * @param PaypalConfigFactory $paypalConfigFactory
     * @param LocaleResolver $localeResolver
     * @param Request $request
     * @param LocalizedText $localizedText
     */
    public function __construct(
        private readonly SmartButtonConfig $smartButtonConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PaymentHelper $paymentHelper,
        private readonly CheckoutSession $checkoutSession,
        private readonly PaypalConfigFactory $paypalConfigFactory,
        private readonly LocaleResolver $localeResolver,
        private readonly Request $request,
        private readonly LocalizedText $localizedText,
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
        $props['label'] = $this->localizedText->get($props['label'] ?? '');

        $props['enabled'] = $this->isEnabled();

        if (!$props['enabled']) {
            return $props;
        }

        $props['inContext'] = $this->scopeConfig->isSetFlag(
            'payment/paypal_express/in_context',
            ScopeInterface::SCOPE_STORE
        );
        // Every string the button shows, translated here: the storefront's language is a server-side matter
        $props['labels'] = $this->labels();

        if (!$props['inContext']) {
            $props['startUrl'] = $this->urlBuilder->getUrl(self::START_ACTION);
            // The image button is PayPal's own image rather than one of ours
            if (($props['button_style'] ?? '') === 'image') {
                $props['imageUrl'] = $this->getButtonImageUrl();
            }

            return $props;
        }

        // The admin's button styling for the page this is on, and the SDK url core builds from the client id
        $buttonConfig = $this->smartButtonConfig->getConfig($this->smartButtonPage());

        return $props + [
            'sdkUrl'         => (string) ($buttonConfig['sdkUrl'] ?? ''),
            // Only the keys the button passes on to the SDK
            'styles'         => array_intersect_key($buttonConfig['styles'] ?? [], array_flip(self::STYLE_KEYS)),
            'dataAttributes' => $buttonConfig['dataAttributes'] ?? [],
            'getTokenUrl'    => $this->urlBuilder->getUrl('paypal/express/getTokenData'),
            'onAuthorizeUrl' => $this->urlBuilder->getUrl('paypal/express/onAuthorization'),
        ];
    }

    /**
     * Whether paypal_express may be used at all, asked of the method itself
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        try {
            $method = $this->paymentHelper->getMethodInstance(PaypalConfig::METHOD_EXPRESS);
        } catch (\Exception $e) {
            return false;
        }

        $quote = $this->checkoutSession->getQuote();

        return $method->isAvailable($quote->getId() ? $quote : null);
    }

    /**
     * Which of PayPal's per-page style sets applies
     *
     * @return string
     */
    private function smartButtonPage(): string
    {
        return $this->request->getFullActionName() === 'checkout_cart_index' ? 'cart' : 'checkout';
    }

    /**
     * PayPal's own image for this shortcut, as Block\Express\Shortcut resolves it when there is no quote to price the
     *
     * @return string
     */
    private function getButtonImageUrl(): string
    {
        $config = $this->paypalConfigFactory->create();
        $config->setMethod(PaypalConfig::METHOD_EXPRESS);

        return (string) $config->getExpressCheckoutShortcutImageUrl($this->localeResolver->getLocale());
    }

    /**
     * Labels
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'sdkFailed'     => (string) __('Something went wrong with your request. Please try again later.'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'unavailable'   => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            'startFailed'   => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed' => (string) __('Error processing payment. Please try again later.'),
            'loadFailed'    => (string) __('Something went wrong with your request. Please try again later.'),
            // Core's own wording for this button (express/shortcut.phtml)
            'checkoutWith'  => (string) __('Checkout with PayPal'),
        ];
    }
}
