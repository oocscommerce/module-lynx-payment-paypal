<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentPayPal\Provider\Express;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Paypal\Model\Express\Checkout as ExpressCheckout;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\AddressFactory;
use Magento\Store\Model\ScopeInterface;
use OOCS\LynxCheckout\Provider\Checkout\QuoteAddress;
use OOCS\LynxComponent\Api\SampleProviderInterface;
use OOCS\LynxComponent\Model\AbstractComponentProvider;

/**
 * Feeds the PayPal Express review page component (Components/Express/Review.vue)
 * Agreements come from OOCS_LynxCheckoutAgreements.
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Review extends AbstractComponentProvider implements SampleProviderInterface
{
    // What the component's addressLines() reads
    private const PRINTED_ADDRESS_FIELDS = [
        'firstname', 'lastname', 'street0', 'street1', 'city', 'region', 'postcode', 'country_id', 'telephone',
    ];

    /**
     * Checkout session, quote address, price currency, scope config, url builder, address factory
     *
     * @param CheckoutSession $checkoutSession
     * @param QuoteAddress $quoteAddress
     * @param PriceCurrencyInterface $priceCurrency
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     * @param AddressFactory $addressFactory
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteAddress $quoteAddress,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly AddressFactory $addressFactory,
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
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getId()) {
            // A stray visit: the controller redirects it to the cart before this page ever renders
            return $props;
        }

        $billing = $quote->getBillingAddress();

        try {
            $methodTitle = (string) $quote->getPayment()->getMethodInstance()->getTitle();
        } catch (\Exception $e) {
            $methodTitle = (string) __('PayPal Express Checkout');
        }

        $props['payment'] = [
            'title'       => $methodTitle,
            'payer_email' => (string) $billing->getEmail(),
        ];
        $props['billing_address'] = $this->printed($this->quoteAddress->asForm($billing));
        $props['shipping'] = $this->getShipping($quote);
        $props['items'] = $this->getItems($quote);
        $props['totals'] = $this->getTotals($quote);
        $props['urls'] = [
            'save_shipping' => $this->urlBuilder->getUrl('paypal/express/saveShippingMethod', ['_secure' => true]),
            'place_order'   => $this->urlBuilder->getUrl('paypal/express/placeOrder', ['_secure' => true]),
            'edit'          => $this->urlBuilder->getUrl('paypal/express/edit'),
            'cart'          => $this->urlBuilder->getUrl('checkout/cart'),
        ];
        $props['labels'] = $this->getLabels();

        return $props;
    }

    /**
     * A quote PayPal has just approved: two items, Flat Rate chosen, every endpoint '#'
     *
     * @param array $resolved
     * @param array $props
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolveSample(array $resolved, array $props): array
    {
        // Through a quote address, the way resolve() builds it
        $address = $this->printed($this->quoteAddress->asForm($this->addressFactory->create()->setData([
            'firstname'  => 'Sample',
            'lastname'   => 'Customer',
            'street'     => '123 Sample Street',
            'city'       => 'Sample City',
            'region'     => 'California',
            'postcode'   => '90001',
            'country_id' => 'US',
            'telephone'  => '555-0100',
            'email'      => 'sample@example.com',
        ])));
        $flatRate = $this->priceCurrency->format(5, false);

        $resolved['payment'] = [
            'title'       => (string) __('PayPal Express Checkout'),
            'payer_email' => 'sample@example.com',
        ];
        $resolved['billing_address'] = $address;
        $resolved['shipping'] = [
            'address'          => $address,
            'can_edit_address' => true,
            'can_edit_method'  => true,
            'current_method'   => 'flatrate_flatrate',
            'current_label'    => (string) __('Flat Rate') . ' — ' . __('Fixed') . ' — ' . $flatRate,
            'groups'           => [[
                'carrier' => (string) __('Flat Rate'),
                'options' => [[
                    'value'    => 'flatrate_flatrate',
                    'label'    => (string) __('Fixed') . ' — ' . $flatRate,
                    'disabled' => false,
                ]],
            ]],
        ];
        $resolved['items'] = [
            ['name' => 'Sample Mug', 'qty' => 1.0, 'row_total' => $this->priceCurrency->format(12, false)],
            ['name' => 'Sample Notebook', 'qty' => 1.0, 'row_total' => $this->priceCurrency->format(7, false)],
        ];
        $resolved['totals'] = [
            [
                'code'  => 'subtotal',
                'label' => (string) __('Subtotal'),
                'value' => $this->priceCurrency->format(19, false),
            ],
            ['code' => 'shipping', 'label' => (string) __('Shipping & Handling'), 'value' => $flatRate],
            [
                'code'  => 'grand_total',
                'label' => (string) __('Grand Total'),
                'value' => $this->priceCurrency->format(24, false),
            ],
        ];
        $resolved['urls'] = ['save_shipping' => '#', 'place_order' => '#', 'edit' => '#', 'cart' => '#'];
        $resolved['labels'] = $this->getLabels();

        return $resolved;
    }

    /**
     * Every string the page shows, translated here: the storefront's language is a server-side matter
     *
     * @return array<string,string>
     */
    private function getLabels(): array
    {
        return [
            'required'        => (string) __('This is a required field.'),
            'reviewOrder'     => (string) __('Review Order'),
            'paymentMethod'   => (string) __('Payment Method'),
            'editPayment'     => (string) __('Edit Payment Information'),
            'billingAddress'  => (string) __('Billing Address'),
            'shippingAddress' => (string) __('Shipping Address'),
            'editShipping'    => (string) __('Edit Shipping Address'),
            'shippingMethod'  => (string) __('Shipping Method'),
            'pleaseSelect'    => (string) __('Please select'),
            'orderSummary'    => (string) __('Order Summary'),
            'placingOrder'    => (string) __('Submitting order information...'),
            'placeOrder'      => (string) __('Place Order'),
            'backToCart'      => (string) __('Back to Shopping Cart'),
        ];
    }

    /**
     * Only the address fields the page prints
     *
     * @param array<string,string> $form
     * @return array<string,string>
     */
    private function printed(array $form): array
    {
        return array_intersect_key($form, array_flip(self::PRINTED_ADDRESS_FIELDS));
    }

    /**
     * The shipping half of the page, null on a virtual quote
     *
     * @param Quote $quote
     * @return array<string, mixed>|null
     */
    private function getShipping(Quote $quote): ?array
    {
        if ($quote->getIsVirtual()) {
            return null;
        }

        $address = $quote->getShippingAddress();
        $currentCode = (string) $address->getShippingMethod();
        $currentLabel = '';
        $groups = [];

        foreach ($address->getGroupedAllShippingRates() as $carrierCode => $rates) {
            $carrierTitle = (string) ($this->scopeConfig->getValue(
                "carriers/{$carrierCode}/title",
                ScopeInterface::SCOPE_STORE
            ) ?: $carrierCode);

            $options = [];
            foreach ($rates as $rate) {
                if ($rate->getErrorMessage()) {
                    $options[] = ['value' => '', 'label' => (string) $rate->getErrorMessage(), 'disabled' => true];
                    continue;
                }

                $price = $this->priceCurrency->format((float) $rate->getPrice(), false);
                $label = $rate->getMethodTitle() . ' — ' . $price;
                $options[] = ['value' => (string) $rate->getCode(), 'label' => $label, 'disabled' => false];

                if ($rate->getCode() === $currentCode) {
                    $currentLabel = $carrierTitle . ' — ' . $label;
                }
            }

            $groups[] = ['carrier' => $carrierTitle, 'options' => $options];
        }

        return [
            'address'          => $this->printed($this->quoteAddress->asForm($address)),
            'can_edit_address' => (bool) $quote->getMayEditShippingAddress()
                && (int) $quote->getPayment()->getAdditionalInformation(ExpressCheckout::PAYMENT_INFO_BUTTON) === 1,
            'can_edit_method'  => (bool) $quote->getMayEditShippingMethod() || $currentLabel === '',
            'current_method'   => $currentCode,
            'current_label'    => $currentLabel,
            'groups'           => $groups,
        ];
    }

    /**
     * The order lines, for the summary: name, quantity, row total
     *
     * @param Quote $quote
     * @return array<int, array<string, mixed>>
     */
    private function getItems(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'name'      => (string) $item->getName(),
                'qty'       => (float) $item->getQty(),
                'row_total' => $this->priceCurrency->format((float) $item->getRowTotal(), false),
            ];
        }

        return $items;
    }

    /**
     * The totals rows, already formatted: every collected segment with a value, in the collector's order
     *
     * @param Quote $quote
     * @return array<int, array<string, string>>
     */
    private function getTotals(Quote $quote): array
    {
        $rows = [];
        foreach ($quote->getTotals() as $code => $total) {
            $value = $total->getValue();
            $alwaysShown = \in_array($code, ['subtotal', 'grand_total'], true);
            if (!is_numeric($value) || ((float) $value === 0.0 && !$alwaysShown)) {
                continue;
            }

            $rows[] = [
                'code'  => (string) $code,
                'label' => (string) $total->getTitle(),
                'value' => $this->priceCurrency->format((float) $value, false),
            ];
        }

        return $rows;
    }
}
