(function ($) {
    const settings = window.mcwcCheckoutSettings || {};
    const gatewayId = settings.gatewayId || 'monek-checkout';
    const selectors = {
        wrapper: '#mcwc-checkout-wrapper',
        message: '#mcwc-checkout-messages',
        checkoutContainer: '#mcwc-checkout-container',
        expressContainer: '#mcwc-express-container',
        tokenInput: '#monek_payment_token',
        contextInput: '#monek_checkout_context',
        sessionInput: '#monek_checkout_session_id',
    };

    if (!settings.publishableKey) {
        return;
    }

    const state = {
        sdk: null,
        sdkPromise: null,
        checkout: null,
        express: null,
        mounting: null,
        checkoutMounted: false,
    };

    const decimalsFactor = Math.pow(10, settings.currencyDecimals || 2);

    function getString(key, fallback) {
        if (settings.strings && typeof settings.strings[key] !== 'undefined') {
            return settings.strings[key];
        }
        return fallback;
    }

    function setWrapperLoading(isLoading) {
        const wrapper = document.querySelector(selectors.wrapper);
        if (!wrapper) {
            return;
        }

        if (isLoading) {
            wrapper.setAttribute('data-loading', 'true');
            wrapper.querySelectorAll('.mcwc-sdk-surface').forEach((surface) => {
                surface.setAttribute('data-loading-text', getString('initialising', 'Loading…'));
            });
        } else {
            wrapper.removeAttribute('data-loading');
            wrapper.querySelectorAll('.mcwc-sdk-surface').forEach((surface) => {
                surface.removeAttribute('data-loading-text');
            });
        }
    }

    function clearError() {
        const messageEl = document.querySelector(selectors.message);
        if (messageEl) {
            messageEl.textContent = '';
        }
    }

    function displayError(message) {
        const fallback = getString('tokenError', 'There was a problem preparing your payment. Please try again.');
        const finalMessage = message || fallback;
        const messageEl = document.querySelector(selectors.message);
        if (messageEl) {
            messageEl.textContent = finalMessage;
        }
        $(document.body).trigger('checkout_error', [finalMessage]);
    }

    function waitForSdk() {
        if (state.sdk) {
            return Promise.resolve(state.sdk);
        }

        if (state.sdkPromise) {
            return state.sdkPromise;
        }

        state.sdkPromise = new Promise((resolve, reject) => {
            let attempts = 0;
            const maxAttempts = 40;
            const interval = setInterval(() => {
                attempts += 1;
                if (typeof window.Monek === 'function') {
                    clearInterval(interval);
                    try {
                        const maybePromise = window.Monek(settings.publishableKey);
                        Promise.resolve(maybePromise).then((sdkInstance) => {
                            state.sdk = sdkInstance;
                            resolve(state.sdk);
                        }).catch(reject);
                    } catch (err) {
                        reject(err);
                    }
                    return;
                }
                if (attempts >= maxAttempts) {
                    clearInterval(interval);
                    reject(new Error('Monek Checkout SDK not available.'));
                }
            }, 250);
        });

        return state.sdkPromise;
    }

    function formValue(name) {
        const el = document.querySelector('[name="' + name + '"]');
        if (!el) {
            return '';
        }
        return el.value || '';
    }

    function isShipDifferent() {
        const checkbox = document.querySelector('#ship-to-different-address-checkbox');
        if (!checkbox) {
            return false;
        }
        return checkbox.checked;
    }

    function buildBillingAddress() {
        return {
            addressLine1: formValue('billing_address_1'),
            addressLine2: formValue('billing_address_2'),
            city: formValue('billing_city'),
            postcode: formValue('billing_postcode'),
            country: formValue('billing_country') || settings.countryCode || '826',
            state: formValue('billing_state'),
        };
    }

    function buildShippingAddress() {
        if (!isShipDifferent()) {
            return null;
        }
        return {
            addressLine1: formValue('shipping_address_1'),
            addressLine2: formValue('shipping_address_2'),
            city: formValue('shipping_city'),
            postcode: formValue('shipping_postcode'),
            country: formValue('shipping_country') || settings.countryCode || '826',
            state: formValue('shipping_state'),
        };
    }

    function getOrderTotalMinor() {
        const selectorsToCheck = [
            '.order-total .woocommerce-Price-amount',
            '.order-total .amount',
            '.wc-block-components-totals-item--order-total .wc-block-components-totals-item__value',
        ];

        for (const selector of selectorsToCheck) {
            const el = document.querySelector(selector);
            if (!el) {
                continue;
            }
            const text = el.textContent || '';
            const parsed = parseAmount(text);
            if (typeof parsed === 'number' && !Number.isNaN(parsed)) {
                return Math.round(parsed * decimalsFactor);
            }
        }

        return settings.initialAmountMinor || 0;
    }

    function parseAmount(text) {
        if (!text) {
            return null;
        }
        let normalised = text.replace(/[^0-9,.-]/g, '');
        if (!normalised) {
            return null;
        }
        const commaCount = (normalised.match(/,/g) || []).length;
        const dotCount = (normalised.match(/\./g) || []).length;
        if (commaCount && dotCount) {
            normalised = normalised.replace(/,/g, '');
        } else if (commaCount && !dotCount) {
            normalised = normalised.replace(',', '.');
        }
        const value = parseFloat(normalised);
        return Number.isFinite(value) ? value : null;
    }

    function buildOptions(isExpress) {
        return {
            callbacks: {
                getAmount: () => ({
                    minor: getOrderTotalMinor(),
                    currency: settings.currencyNumeric,
                }),
                getDescription: () => settings.basketSummary || 'Order',
                getCardholderDetails: () => {
                    const firstName = formValue('billing_first_name');
                    const lastName = formValue('billing_last_name');
                    const address = buildBillingAddress();
                    const shipping = buildShippingAddress();

                    const details = {
                        name: (firstName + ' ' + lastName).trim(),
                        email: formValue('billing_email'),
                        HomePhone: formValue('billing_phone'),
                        billingAddress: address,
                    };

                    if (shipping) {
                        details.shippingAddress = shipping;
                    }

                    return details;
                },
            },
            countryCode: settings.countryCode || '826',
            intent: 'Purchase',
            order: 'Checkout',
            settlementType: 'Auto',
            cardEntry: 'ECommerce',
            storeCardDetails: false,
            debug: !!settings.testMode,
            completion: {
                mode: 'server',
            },
            challenge: {
                display: 'popup',
            },
        };
    }

    async function mountCheckoutComponents() {
        if (state.mounting) {
            return state.mounting;
        }

        const checkoutContainer = document.querySelector(selectors.checkoutContainer);
        if (!checkoutContainer || !document.body.contains(checkoutContainer)) {
            return Promise.resolve(false);
        }

        setWrapperLoading(true);

        state.mounting = waitForSdk()
            .then(async (sdk) => {
                if (state.checkout) {
                    return true;
                }

                const checkout = sdk.createComponent('checkout', buildOptions(false));
                await checkout.mount(selectors.checkoutContainer);
                state.checkout = checkout;
                state.checkoutMounted = true;

                if (settings.showExpress) {
                    try {
                        const express = sdk.createComponent('express', buildOptions(true));
                        await express.mount(selectors.expressContainer);
                        state.express = express;
                    } catch (expressErr) {
                        // Express surface is optional; log and continue silently.
                        // eslint-disable-next-line no-console
                        console.warn('Monek express surface failed to mount', expressErr);
                    }
                }

                return true;
            })
            .catch((error) => {
                displayError(error && error.message ? error.message : getString('tokenError', 'There was a problem preparing your payment. Please try again.'));
                return false;
            })
            .finally(() => {
                setWrapperLoading(false);
                state.mounting = null;
            });

        return state.mounting;
    }

    function extractToken(result) {
        if (!result) {
            return '';
        }
        if (typeof result === 'string') {
            return result;
        }
        if (result.paymentToken) {
            return result.paymentToken;
        }
        if (result.token) {
            return result.token;
        }
        if (result.id) {
            return result.id;
        }
        if (result.payment && result.payment.token) {
            return result.payment.token;
        }
        if (result.paymentMethod && result.paymentMethod.token) {
            return result.paymentMethod.token;
        }
        if (Array.isArray(result) && result.length) {
            return extractToken(result[0]);
        }
        return '';
    }

    async function requestPaymentTokenInternal() {
        await mountCheckoutComponents();

        if (!state.checkout) {
            throw new Error('Payment form not ready.');
        }

        let tokenisationResult;
        if (typeof state.checkout.tokenise === 'function') {
            tokenisationResult = await state.checkout.tokenise();
        } else if (typeof state.checkout.tokenize === 'function') {
            tokenisationResult = await state.checkout.tokenize();
        } else if (typeof state.checkout.createPayment === 'function') {
            tokenisationResult = await state.checkout.createPayment();
        } else {
            throw new Error('This version of the Checkout SDK does not support server completion.');
        }

        const token = extractToken(tokenisationResult);
        if (!token) {
            throw new Error('The payment processor did not return a token.');
        }

        let context = {};

        if (tokenisationResult && typeof tokenisationResult === 'object') {
            try {
                context = JSON.parse(JSON.stringify(tokenisationResult));
            } catch (jsonError) {
                if (tokenisationResult.context && typeof tokenisationResult.context === 'object') {
                    Object.assign(context, tokenisationResult.context);
                }
            }
        }

        const sessionId =
            (tokenisationResult && typeof tokenisationResult === 'object' && tokenisationResult.sessionId)
                ? tokenisationResult.sessionId
                : '';

        if (sessionId && (!context.sessionId || context.sessionId !== sessionId)) {
            context.sessionId = sessionId;
        }

        if (
            tokenisationResult &&
            typeof tokenisationResult === 'object' &&
            tokenisationResult.payment &&
            tokenisationResult.payment.id &&
            (!context.paymentId || context.paymentId !== tokenisationResult.payment.id)
        ) {
            context.paymentId = tokenisationResult.payment.id;
        }

        return {
            token,
            sessionId,
            context,
        };
    }

    function updateHiddenInputs(result) {
        const tokenInput = document.querySelector(selectors.tokenInput);
        if (tokenInput) {
            tokenInput.value = result.token;
        }

        const contextInput = document.querySelector(selectors.contextInput);
        if (contextInput) {
            try {
                contextInput.value = JSON.stringify(result.context || {});
            } catch (e) {
                contextInput.value = '';
            }
        }

        const sessionInput = document.querySelector(selectors.sessionInput);
        if (sessionInput) {
            sessionInput.value = result.sessionId || '';
        }
    }

    function isGatewaySelected() {
        const classicSelected = $('input[name="payment_method"]:checked').val();
        if (classicSelected === gatewayId) {
            return true;
        }

        // Blocks checkout places the gateway content directly inside the payment method container.
        if (document.querySelector('[data-mcwc-checkout-block]')) {
            return true;
        }

        return false;
    }

    async function handlePlaceOrder() {
        if (!isGatewaySelected()) {
            return true;
        }

        try {
            clearError();
            const result = await requestPaymentTokenInternal();
            updateHiddenInputs(result);
            return true;
        } catch (error) {
            displayError(error && error.message ? error.message : getString('tokenError', 'There was a problem preparing your payment. Please try again.'));
            return false;
        }
    }

    function monitorMounting() {
        if (isGatewaySelected()) {
            mountCheckoutComponents();
        }
    }

    $(document.body).on('updated_checkout payment_method_selected', monitorMounting);
    $(document).ready(monitorMounting);

    $(document.body).on('checkout_place_order_' + gatewayId, function () {
        const result = handlePlaceOrder();
        if (result && typeof result.then === 'function') {
            return result;
        }
        return result;
    });

    window.mcwcCheckoutController = {
        ensureMounted: mountCheckoutComponents,
        requestPaymentToken: async () => {
            const result = await requestPaymentTokenInternal();
            updateHiddenInputs(result);
            return result;
        },
        displayError,
        clearError,
    };
})(window.jQuery);
