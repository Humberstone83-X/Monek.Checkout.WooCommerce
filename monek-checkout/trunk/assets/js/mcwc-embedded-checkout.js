(function (window, document, $) {
    const config = window.mcwcCheckoutConfig || {};
    const gatewayId = config.gatewayId || 'monek-checkout';

    if (!config.publishableKey) {
        return;
    }

    const selectors = {
        wrapper: '#mcwc-checkout-wrapper',
        messages: '#mcwc-checkout-messages',
        express: '#mcwc-express-container',
        checkout: '#mcwc-checkout-container',
    };

    const state = {
        sdkPromise: null,
        checkoutComponent: null,
        expressComponent: null,
        mountingPromise: null,
    };

    function getString(key, fallback) {
        const strings = config.strings || {};
        if (typeof strings[key] === 'string' && strings[key].length) {
            return strings[key];
        }
        return fallback;
    }

    function displayError(message) {
        const container = document.querySelector(selectors.messages);
        const finalMessage = message || getString('token_error', 'There was a problem preparing your payment. Please try again.');

        if (container) {
            container.textContent = finalMessage;
            container.style.display = '';
        }

        $(document.body).trigger('checkout_error', [finalMessage]);
    }

    function clearError() {
        const container = document.querySelector(selectors.messages);
        if (container) {
            container.textContent = '';
        }
    }

    function readField(name) {
        const field = document.querySelector('[name="' + name + '"]');
        if (!field) {
            return '';
        }
        return field.value ? String(field.value).trim() : '';
    }

    function shippingEnabled() {
        const checkbox = document.querySelector('#ship-to-different-address-checkbox');
        if (!checkbox) {
            return false;
        }
        return checkbox.checked;
    }

    function normaliseAmount(text) {
        if (!text) {
            return NaN;
        }

        let cleaned = text.replace(/[^0-9,.-]/g, '');
        if (!cleaned) {
            return NaN;
        }

        const commaCount = (cleaned.match(/,/g) || []).length;
        const dotCount = (cleaned.match(/\./g) || []).length;

        if (commaCount && dotCount) {
            cleaned = cleaned.replace(/,/g, '');
        } else if (commaCount && !dotCount) {
            cleaned = cleaned.replace(',', '.');
        }

        return parseFloat(cleaned);
    }

    function getOrderTotalMinor() {
        const selectorsToCheck = [
            '.order-total .amount',
            '.order-total .woocommerce-Price-amount',
            '.wc-block-components-totals-item--order-total .wc-block-components-totals-item__value',
        ];

        for (const selector of selectorsToCheck) {
            const el = document.querySelector(selector);
            if (!el) {
                continue;
            }
            const parsed = normaliseAmount(el.textContent || '');
            if (!Number.isNaN(parsed)) {
                return Math.round(parsed * Math.pow(10, config.currencyDecimals || 2));
            }
        }

        if (typeof config.initialAmountMinor === 'number') {
            return config.initialAmountMinor;
        }

        return 0;
    }

    function buildAddress(prefix) {
        return {
            addressLine1: readField(prefix + '_address_1'),
            addressLine2: readField(prefix + '_address_2'),
            city: readField(prefix + '_city'),
            postcode: readField(prefix + '_postcode'),
            country: readField(prefix + '_country') || config.countryNumeric || '826',
            state: readField(prefix + '_state'),
        };
    }

    function buildOptions(isExpress) {
        const callbacks = {
            getAmount: () => ({
                minor: getOrderTotalMinor(),
                currency: config.currencyNumeric || '826',
            }),
            getDescription: () => config.orderDescription || 'Order',
            getCardholderDetails: () => ({
                name: [readField('billing_first_name'), readField('billing_last_name')].filter(Boolean).join(' ').trim(),
                email: readField('billing_email'),
                HomePhone: readField('billing_phone'),
                billingAddress: buildAddress('billing'),
                shippingAddress: shippingEnabled() ? buildAddress('shipping') : undefined,
            }),
        };

        return {
            callbacks,
            countryCode: config.countryNumeric || '826',
            intent: 'Purchase',
            order: 'Checkout',
            settlementType: 'Auto',
            cardEntry: 'ECommerce',
            storeCardDetails: false,
            challenge: config.challenge || { display: 'popup', size: 'medium' },
            completion: { mode: 'server' },
            debug: !!config.debug,
            styling: config.styling || { theme: config.theme || 'light' },
            ...(isExpress ? { surface: 'express' } : {}),
        };
    }

    function waitForSdk() {
        if (state.sdkPromise) {
            return state.sdkPromise;
        }

        state.sdkPromise = new Promise((resolve, reject) => {
            let attempts = 0;
            const maxAttempts = 40;
            const timer = window.setInterval(() => {
                attempts += 1;
                if (typeof window.Monek === 'function') {
                    window.clearInterval(timer);
                    try {
                        const maybePromise = window.Monek(config.publishableKey);
                        Promise.resolve(maybePromise).then(resolve).catch(reject);
                    } catch (err) {
                        reject(err);
                    }
                    return;
                }

                if (attempts >= maxAttempts) {
                    window.clearInterval(timer);
                    reject(new Error('Monek Checkout SDK not available.'));
                }
            }, 250);
        });

        return state.sdkPromise;
    }

    async function mountComponents() {
        if (state.mountingPromise) {
            return state.mountingPromise;
        }

        const checkoutContainer = document.querySelector(selectors.checkout);
        if (!checkoutContainer) {
            return false;
        }

        state.mountingPromise = waitForSdk()
            .then(async (sdk) => {
                if (!state.checkoutComponent) {
                    const checkout = sdk.createComponent('checkout', buildOptions(false));
                    await checkout.mount(selectors.checkout);
                    state.checkoutComponent = checkout;
                }

                if (config.showExpress !== false && !state.expressComponent) {
                    try {
                        const express = sdk.createComponent('express', buildOptions(true));
                        await express.mount(selectors.express);
                        state.expressComponent = express;
                    } catch (err) {
                        if (window.console && typeof window.console.warn === 'function') {
                            window.console.warn('Monek express surface failed to mount', err);
                        }
                    }
                }

                return true;
            })
            .catch((error) => {
                displayError(error && error.message ? error.message : getString('token_error', 'There was a problem preparing your payment. Please try again.'));
                return false;
            })
            .finally(() => {
                state.mountingPromise = null;
            });

        return state.mountingPromise;
    }

    function shouldMount() {
        const method = $('input[name="payment_method"]:checked').val();
        return method === gatewayId;
    }

    function maybeMount() {
        if (shouldMount()) {
            mountComponents();
        }
    }

    $(document.body).on('payment_method_selected updated_checkout', maybeMount);
    $(document).ready(maybeMount);

    window.mcwcCheckoutController = {
        ensureMounted: mountComponents,
        displayError,
        clearError,
    };
})(window, document, window.jQuery);
