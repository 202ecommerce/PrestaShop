<?php
/**
 * 2007-2020 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\PrestaShop\Adapter\Presenter\Cart;

use Cart;
use CartRule;
use Configuration;
use Context;
use Hook;
use PrestaShop\PrestaShop\Adapter\CartRule\LegacyDiscountApplicationType;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\PresenterInterface;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Product\ProductPresentationSettings;
use Product;
use Symfony\Component\Translation\TranslatorInterface;
use TaxConfiguration;
use Tools;

class CartPresenter implements PresenterInterface
{
    /**
     * @var PriceFormatter
     */
    private $priceFormatter;

    /**
     * @var \Link
     */
    private $link;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var ImageRetriever
     */
    private $imageRetriever;

    /**
     * @var TaxConfiguration
     */
    private $taxConfiguration;

    public function __construct()
    {
        $context = Context::getContext();
        $this->priceFormatter = new PriceFormatter();
        $this->link = $context->link;
        $this->translator = $context->getTranslator();
        $this->imageRetriever = new ImageRetriever($this->link);
        $this->taxConfiguration = new TaxConfiguration();
    }

    /**
     * @return bool
     */
    private function includeTaxes()
    {
        return $this->taxConfiguration->includeTaxes();
    }

    /**
     * @param array $rawProduct
     *
     * @return \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductLazyArray|\PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingLazyArray
     */
    private function presentProduct(array $rawProduct)
    {
        $settings = new ProductPresentationSettings();

        $settings->catalog_mode = Configuration::isCatalogMode();
        $settings->catalog_mode_with_prices = (int) Configuration::get('PS_CATALOG_MODE_WITH_PRICES');
        $settings->include_taxes = $this->includeTaxes();
        $settings->allow_add_variant_to_cart_from_listing = (int) Configuration::get('PS_ATTRIBUTE_CATEGORY_DISPLAY');
        $settings->stock_management_enabled = Configuration::get('PS_STOCK_MANAGEMENT');
        $settings->showPrices = Configuration::showPrices();

        if (isset($rawProduct['attributes']) && is_string($rawProduct['attributes'])) {
            $rawProduct['attributes'] = $this->getAttributesArrayFromString($rawProduct['attributes']);
        }
        $rawProduct['remove_from_cart_url'] = $this->link->getRemoveFromCartURL(
            $rawProduct['id_product'],
            $rawProduct['id_product_attribute']
        );

        $rawProduct['up_quantity_url'] = $this->link->getUpQuantityCartURL(
            $rawProduct['id_product'],
            $rawProduct['id_product_attribute']
        );

        $rawProduct['down_quantity_url'] = $this->link->getDownQuantityCartURL(
            $rawProduct['id_product'],
            $rawProduct['id_product_attribute']
        );

        $rawProduct['update_quantity_url'] = $this->link->getUpdateQuantityCartURL(
            $rawProduct['id_product'],
            $rawProduct['id_product_attribute']
        );

        $resetFields = [
            'ecotax_rate',
            'specific_prices',
            'customizable',
            'online_only',
            'reduction',
            'reduction_without_tax',
            'new',
            'condition',
            'pack',
        ];
        foreach ($resetFields as $field) {
            if (!array_key_exists($field, $rawProduct)) {
                $rawProduct[$field] = '';
            }
        }

        if ($this->includeTaxes()) {
            $rawProduct['price_amount'] = $rawProduct['price_wt'];
            $rawProduct['price'] = $this->priceFormatter->format($rawProduct['price_wt']);
        } else {
            $rawProduct['price_amount'] = $rawProduct['price'];
            $rawProduct['price'] = $rawProduct['price_tax_exc'] = $this->priceFormatter->format($rawProduct['price']);
        }

        if ($rawProduct['price_amount'] && $rawProduct['unit_price_ratio'] > 0) {
            $rawProduct['unit_price'] = $rawProduct['price_amount'] / $rawProduct['unit_price_ratio'];
        }

        $rawProduct['total'] = $this->priceFormatter->format(
            $this->includeTaxes() ?
            $rawProduct['total_wt'] :
            $rawProduct['total']
        );

        $rawProduct['quantity_wanted'] = $rawProduct['cart_quantity'];

        $presenter = new ProductListingPresenter(
            $this->imageRetriever,
            $this->link,
            $this->priceFormatter,
            new ProductColorsRetriever(),
            $this->translator
        );

        return $presenter->present(
            $settings,
            $rawProduct,
            Context::getContext()->language
        );
    }

    /**
     * @param array $products
     * @param Cart $cart
     *
     * @return array
     */
    public function addCustomizedData(array $products, Cart $cart)
    {
        return array_map(function ($product) use ($cart) {
            $customizations = [];

            $data = Product::getAllCustomizedDatas($cart->id, null, true, null, (int) $product['id_customization']);

            if (!$data) {
                $data = [];
            }
            $id_product = (int) $product['id_product'];
            $id_product_attribute = (int) $product['id_product_attribute'];
            if (array_key_exists($id_product, $data)) {
                if (array_key_exists($id_product_attribute, $data[$id_product])) {
                    foreach ($data[$id_product] as $byAddress) {
                        foreach ($byAddress as $byAddressCustomizations) {
                            foreach ($byAddressCustomizations as $customization) {
                                $presentedCustomization = [
                                    'quantity' => $customization['quantity'],
                                    'fields' => [],
                                    'id_customization' => null,
                                ];

                                foreach ($customization['datas'] as $byType) {
                                    foreach ($byType as $data) {
                                        $field = [];
                                        switch ($data['type']) {
                                            case Product::CUSTOMIZE_FILE:
                                                $field['type'] = 'image';
                                                $field['image'] = $this->imageRetriever->getCustomizationImage(
                                                    $data['value']
                                                );

                                                break;
                                            case Product::CUSTOMIZE_TEXTFIELD:
                                                $field['type'] = 'text';
                                                $field['text'] = $data['value'];

                                                break;
                                            default:
                                                $field['type'] = null;
                                        }
                                        $field['label'] = $data['name'];
                                        $field['id_module'] = $data['id_module'];
                                        $presentedCustomization['id_customization'] = $data['id_customization'];
                                        $presentedCustomization['fields'][] = $field;
                                    }
                                }

                                $product['up_quantity_url'] = $this->link->getUpQuantityCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );
                                $product['down_quantity_url'] = $this->link->getDownQuantityCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );
                                $product['remove_from_cart_url'] = $this->link->getRemoveFromCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );
                                $product['update_quantity_url'] = $this->link->getUpdateQuantityCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );

                                $presentedCustomization['up_quantity_url'] = $this->link->getUpQuantityCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );

                                $presentedCustomization['down_quantity_url'] = $this->link->getDownQuantityCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );

                                $presentedCustomization['remove_from_cart_url'] = $this->link->getRemoveFromCartURL(
                                    $product['id_product'],
                                    $product['id_product_attribute'],
                                    $presentedCustomization['id_customization']
                                );

                                $presentedCustomization['update_quantity_url'] = $product['update_quantity_url'];

                                $customizations[] = $presentedCustomization;
                            }
                        }
                    }
                }
            }

            usort($customizations, function (array $a, array $b) {
                if (
                    $a['quantity'] > $b['quantity']
                    || count($a['fields']) > count($b['fields'])
                    || $a['id_customization'] > $b['id_customization']
                ) {
                    return -1;
                } else {
                    return 1;
                }
            });

            $product['customizations'] = $customizations;

            return $product;
        }, $products);
    }

    /**
     * @param Cart $cart
     * @param bool $shouldSeparateGifts
     *
     * @return array
     *
     * @throws \Exception
     */
    public function present($cart, $shouldSeparateGifts = false)
    {
        if (!is_a($cart, 'Cart')) {
            throw new \Exception('CartPresenter can only present instance of Cart');
        }

        if ($shouldSeparateGifts) {
            $rawProducts = $cart->getProductsWithSeparatedGifts();
        } else {
            $rawProducts = $cart->getProducts(true);
        }

        $products = array_map([$this, 'presentProduct'], $rawProducts);
        $products = $this->addCustomizedData($products, $cart);
        $subtotals = [];

        $productsTotalExcludingTax = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
        $total_excluding_tax = $cart->getOrderTotal(false);
        $total_including_tax = $cart->getOrderTotal(true);
        $total_discount = $cart->getDiscountSubtotalWithoutGifts($this->includeTaxes());
        $totalCartAmount = $cart->getOrderTotal($this->includeTaxes(), Cart::ONLY_PRODUCTS);

        $subtotals['products'] = [
            'type' => 'products',
            'label' => $this->translator->trans('Subtotal', [], 'Shop.Theme.Checkout'),
            'amount' => $totalCartAmount,
            'value' => $this->priceFormatter->format($totalCartAmount),
        ];

        if ($total_discount) {
            $subtotals['discounts'] = [
                'type' => 'discount',
                'label' => $this->translator->trans('Discount(s)', [], 'Shop.Theme.Checkout'),
                'amount' => $total_discount,
                'value' => $this->priceFormatter->format($total_discount),
            ];
        } else {
            $subtotals['discounts'] = null;
        }

        if ($cart->gift) {
            $giftWrappingPrice = ($cart->getGiftWrappingPrice($this->includeTaxes()) != 0)
                ? $cart->getGiftWrappingPrice($this->includeTaxes())
                : 0;

            $subtotals['gift_wrapping'] = [
                'type' => 'gift_wrapping',
                'label' => $this->translator->trans('Gift wrapping', [], 'Shop.Theme.Checkout'),
                'amount' => $giftWrappingPrice,
                'value' => ($giftWrappingPrice > 0)
                    ? $this->priceFormatter->convertAndFormat($giftWrappingPrice)
                    : $this->translator->trans('Free', [], 'Shop.Theme.Checkout'),
            ];
        }

        if (!$cart->isVirtualCart()) {
            $shippingCost = $cart->getTotalShippingCost(null, $this->includeTaxes());
        } else {
            $shippingCost = 0;
        }
        $subtotals['shipping'] = [
            'type' => 'shipping',
            'label' => $this->translator->trans('Shipping', [], 'Shop.Theme.Checkout'),
            'amount' => $shippingCost,
            'value' => $this->getShippingDisplayValue($cart, $shippingCost),
        ];

        $subtotals['tax'] = null;
        if (Configuration::get('PS_TAX_DISPLAY')) {
            $taxAmount = $total_including_tax - $total_excluding_tax;
            $subtotals['tax'] = [
                'type' => 'tax',
                'label' => ($this->includeTaxes())
                    ? $this->translator->trans('Included taxes', [], 'Shop.Theme.Checkout')
                    : $this->translator->trans('Taxes', [], 'Shop.Theme.Checkout'),
                'amount' => $taxAmount,
                'value' => $this->priceFormatter->format($taxAmount),
            ];
        }

        $totals = [
            'total' => [
                'type' => 'total',
                'label' => $this->translator->trans('Total', [], 'Shop.Theme.Checkout'),
                'amount' => $this->includeTaxes() ? $total_including_tax : $total_excluding_tax,
                'value' => $this->priceFormatter->format(
                    $this->includeTaxes() ? $total_including_tax : $total_excluding_tax
                ),
            ],
            'total_including_tax' => [
                'type' => 'total',
                'label' => $this->translator->trans('Total (tax incl.)', [], 'Shop.Theme.Checkout'),
                'amount' => $total_including_tax,
                'value' => $this->priceFormatter->format($total_including_tax),
            ],
            'total_excluding_tax' => [
                'type' => 'total',
                'label' => $this->translator->trans('Total (tax excl.)', [], 'Shop.Theme.Checkout'),
                'amount' => $total_excluding_tax,
                'value' => $this->priceFormatter->format($total_excluding_tax),
            ],
        ];

        $products_count = array_reduce($products, function ($count, $product) {
            return $count + $product['quantity'];
        }, 0);

        $summary_string = $products_count === 1 ?
            $this->translator->trans('1 item', [], 'Shop.Theme.Checkout') :
            $this->translator->trans('%count% items', ['%count%' => $products_count], 'Shop.Theme.Checkout');

        $minimalPurchase = $this->priceFormatter->convertAmount((float) Configuration::get('PS_PURCHASE_MINIMUM'));

        Hook::exec('overrideMinimalPurchasePrice', [
            'minimalPurchase' => &$minimalPurchase,
        ]);

        // TODO: move it to a common parent, since it's copied in OrderPresenter and ProductPresenter
        $labels = [
            'tax_short' => ($this->includeTaxes())
                ? $this->translator->trans('(tax incl.)', [], 'Shop.Theme.Global')
                : $this->translator->trans('(tax excl.)', [], 'Shop.Theme.Global'),
            'tax_long' => ($this->includeTaxes())
                ? $this->translator->trans('(tax included)', [], 'Shop.Theme.Global')
                : $this->translator->trans('(tax excluded)', [], 'Shop.Theme.Global'),
        ];

        $discounts = $cart->getDiscounts();
        $vouchers = $this->getTemplateVarVouchers($cart);

        $cartRulesIds = array_flip(array_map(
            function ($voucher) {
                return $voucher['id_cart_rule'];
            },
            $vouchers['added']
        ));

        $discounts = array_filter($discounts, function ($discount) use ($cartRulesIds) {
            return !array_key_exists($discount['id_cart_rule'], $cartRulesIds);
        });

        $result = [
            'products' => $products,
            'totals' => $totals,
            'subtotals' => $subtotals,
            'products_count' => $products_count,
            'summary_string' => $summary_string,
            'labels' => $labels,
            'id_address_delivery' => $cart->id_address_delivery,
            'id_address_invoice' => $cart->id_address_invoice,
            'is_virtual' => $cart->isVirtualCart(),
            'vouchers' => $vouchers,
            'discounts' => $discounts,
            'minimalPurchase' => $minimalPurchase,
            'minimalPurchaseRequired' => ($productsTotalExcludingTax < $minimalPurchase) ?
                $this->translator->trans(
                    'A minimum shopping cart total of %amount% (tax excl.) is required to validate your order. Current cart total is %total% (tax excl.).',
                    [
                        '%amount%' => $this->priceFormatter->format($minimalPurchase),
                        '%total%' => $this->priceFormatter->format($productsTotalExcludingTax),
                    ],
                    'Shop.Theme.Checkout'
                ) :
                '',
        ];

        Hook::exec('actionPresentCart',
            ['presentedCart' => &$result]
        );


        return $result;
    }

    /**
     * Accepts a cart object with the shipping cost amount and formats the shipping cost display value accordingly.
     * If the shipping cost is 0, then we must check if this is because of a free carrier and thus display 'Free' or
     * simply because the system was unable to determine shipping cost at this point and thus send an empty string to hide the shipping line.
     *
     * @param Cart $cart
     * @param float $shippingCost
     *
     * @return string
     */
    private function getShippingDisplayValue($cart, $shippingCost)
    {
        $shippingDisplayValue = '';

        // if one of the applied cart rules have free shipping, then the shipping display value is 'Free'
        foreach ($cart->getCartRules() as $rule) {
            if ($rule['free_shipping'] && !$rule['carrier_restriction']) {
                return $this->translator->trans('Free', [], 'Shop.Theme.Checkout');
            }
        }

        if ($shippingCost != 0) {
            $shippingDisplayValue = $this->priceFormatter->format($shippingCost);
        } else {
            $defaultCountry = null;

            if (isset(Context::getContext()->cookie->id_country)) {
                $defaultCountry = new Country(Context::getContext()->cookie->id_country);
            }

            $deliveryOptionList = $cart->getDeliveryOptionList($defaultCountry);

            if (isset($deliveryOptionList) && count($deliveryOptionList) > 0) {
                foreach ($deliveryOptionList as $option) {
                    foreach ($option as $currentCarrier) {
                        if (isset($currentCarrier['is_free']) && $currentCarrier['is_free'] > 0) {
                            $shippingDisplayValue = $this->translator->trans('Free', [], 'Shop.Theme.Checkout');
                            break 2;
                        }
                    }
                }
            }
        }

        return $shippingDisplayValue;
    }

    private function getTemplateVarVouchers(Cart $cart)
    {
        $cartVouchers = $cart->getCartRules();
        $vouchers = [];

        $cartHasTax = null === $cart->id ? false : $cart::getTaxesAverageUsed($cart);
        $freeShippingAlreadySet = false;
        foreach ($cartVouchers as $cartVoucher) {
            $vouchers[$cartVoucher['id_cart_rule']]['id_cart_rule'] = $cartVoucher['id_cart_rule'];
            $vouchers[$cartVoucher['id_cart_rule']]['name'] = $cartVoucher['name'];
            $vouchers[$cartVoucher['id_cart_rule']]['code'] = $cartVoucher['code'];
            $vouchers[$cartVoucher['id_cart_rule']]['reduction_percent'] = $cartVoucher['reduction_percent'];
            $vouchers[$cartVoucher['id_cart_rule']]['reduction_currency'] = $cartVoucher['reduction_currency'];

            // Voucher reduction depending of the cart tax rule
            // if $cartHasTax & voucher is tax excluded, set amount voucher to tax included
            if ($cartHasTax && $cartVoucher['reduction_tax'] == '0') {
                $cartVoucher['reduction_amount'] = $cartVoucher['reduction_amount'] * (1 + $cartHasTax / 100);
            }

            $vouchers[$cartVoucher['id_cart_rule']]['reduction_amount'] = $cartVoucher['reduction_amount'];

            if (array_key_exists('gift_product', $cartVoucher) && $cartVoucher['gift_product']) {
                $cartVoucher['reduction_amount'] = $cartVoucher['value_real'];
            }

            $shippingReduction = $amountReduction = $percentageReduction = 0;
            $freeShippingOnly = false;

            if ($this->cartVoucherHasFreeShipping($cartVoucher)) {
                if (!$freeShippingAlreadySet) {
                    $shippingReduction = $cart->getTotalShippingCost(null, $this->includeTaxes());
                    $freeShippingAlreadySet = true;
                }
                $freeShippingOnly = true;
            }
            if ($this->cartVoucherHasPercentReduction($cartVoucher)) {
                $products = $cart->getProducts();
                if ($this->cartVoucherHasPercentReductionOnSelectedProducts($cartVoucher)) {
                    $selected_products = $cartVoucher['obj']->checkProductRestrictionsFromCart($cart, true);
                    if (is_array($selected_products)) {
                        foreach ($products as $key => $product) {
                            // Check if product is in selected product for applying the voucher
                            // Check if voucher applies on already discounted products (and if it applies)
                            if ((in_array($product['id_product'] . '-' . $product['id_product_attribute'], $selected_products)
                                    || in_array($product['id_product'] . '-0', $selected_products))
                                && (($cartVoucher['reduction_exclude_special'] && !$product['reduction_applies'])
                                    || !$cartVoucher['reduction_exclude_special'])) {
                                continue;
                            }
                            unset($products[$key]);
                        }
                    }
                }
                $productsTotalExcludingTax = $cart->getOrderTotal($this->includeTaxes(), Cart::ONLY_PRODUCTS, $products);
                $percentageReduction = ($productsTotalExcludingTax / 100) * $cartVoucher['reduction_percent'];
                $freeShippingOnly = false;
            } elseif ($this->cartVoucherHasAmountReduction($cartVoucher)) {
                $amountReduction = $this->includeTaxes() ? $cartVoucher['reduction_amount'] : $cartVoucher['value_tax_exc'];
                $currencyFrom = new \Currency($cartVoucher['reduction_currency']);
                $currencyTo = new \Currency($cart->id_currency);
                if ($currencyFrom->conversion_rate == 0) {
                    $amountReduction = 0;
                } else {
                    // convert to default currency
                    $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
                    $amountReduction /= $currencyFrom->conversion_rate;
                    if ($defaultCurrencyId == $currencyTo->id) {
                        // convert to destination currency
                        $amountReduction *= $currencyTo->conversion_rate;
                    }
                }
                $freeShippingOnly = false;
            }
            // when a voucher has only a shipping reduction, the value displayed must be "Free Shipping"
            if ($freeShippingOnly) {
                $cartVoucher['reduction_formatted'] = $this->translator->trans(
                    'Free shipping',
                    [],
                    'Admin.Shipping.Feature'
                );
            } else {
                // In all other cases, the value displayed should be the total of applied reductions for the current voucher
                $totalCartVoucherReduction = $shippingReduction + $amountReduction + $percentageReduction;
                $cartVoucher['reduction_formatted'] = '-' . $this->priceFormatter->format($totalCartVoucherReduction);
            }

            $vouchers[$cartVoucher['id_cart_rule']]['reduction_formatted'] = $cartVoucher['reduction_formatted'];
            $vouchers[$cartVoucher['id_cart_rule']]['delete_url'] = $this->link->getPageLink(
                'cart',
                true,
                null,
                [
                    'deleteDiscount' => $cartVoucher['id_cart_rule'],
                    'token' => Tools::getToken(false),
                ]
            );
        }

        return [
            'allowed' => (int) CartRule::isFeatureActive(),
            'added' => $vouchers,
        ];
    }

    /**
     * @param array $cartVoucher
     *
     * @return bool
     */
    private function cartVoucherHasFreeShipping($cartVoucher)
    {
        return !empty($cartVoucher['free_shipping']);
    }

    /**
     * @param array $cartVoucher
     *
     * @return bool
     */
    private function cartVoucherHasPercentReduction($cartVoucher)
    {
        return isset($cartVoucher['reduction_percent'])
            && $cartVoucher['reduction_percent'] > 0
            && $cartVoucher['reduction_amount'] == '0.00';
    }

    /**
     * @param array $cartVoucher
     *
     * @return bool
     */
    private function cartVoucherHasPercentReductionOnSelectedProducts($cartVoucher)
    {
        return $this->cartVoucherHasPercentReduction($cartVoucher)
            && (int) $cartVoucher['reduction_product'] == LegacyDiscountApplicationType::SELECTED_PRODUCTS;
    }

    /**
     * @param array $cartVoucher
     *
     * @return bool
     */
    private function cartVoucherHasAmountReduction($cartVoucher)
    {
        return isset($cartVoucher['reduction_amount']) && $cartVoucher['reduction_amount'] > 0;
    }

    /**
     * Receives a string containing a list of attributes affected to the product and returns them as an array.
     *
     * @param string $attributes
     *
     * @return array Converted attributes in an array
     */
    protected function getAttributesArrayFromString($attributes)
    {
        $separator = Configuration::get('PS_ATTRIBUTE_ANCHOR_SEPARATOR');
        $pattern = '/(?>(?P<attribute>[^:]+:[^:]+)' . $separator . '+(?!' . $separator . '([^:' . $separator . '])+:))/';
        $attributesArray = [];
        $matches = [];
        if (!preg_match_all($pattern, $attributes . $separator, $matches)) {
            return $attributesArray;
        }

        foreach ($matches['attribute'] as $attribute) {
            list($key, $value) = explode(':', $attribute);
            $attributesArray[trim($key)] = ltrim($value);
        }

        return $attributesArray;
    }
}
