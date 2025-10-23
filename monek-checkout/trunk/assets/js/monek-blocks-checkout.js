(function () {
    const registry = window.wc?.wcBlocksRegistry;
    const getSetting = window.wc?.wcSettings?.getSetting;
    const element = window.wp?.element;

    if (!registry || !getSetting || !element) {
        return;
    }

    const h = element.createElement;
    const { useEffect } = element;

    const config = getSetting('monek-checkout_data', {});
    const description = config.description || 'Pay securely with Monek.';

    function Content(props) {
        const { eventRegistration, emitResponse } = props || {};

        useEffect(() => {
            if (window.mcwcCheckoutController?.ensureMounted) {
                window.mcwcCheckoutController.ensureMounted();
            }
        }, []);

        useEffect(() => {
            if (!eventRegistration || !emitResponse || !eventRegistration.onPaymentProcessing) {
                return undefined;
            }

            const unregister = eventRegistration.onPaymentProcessing(async () => {
                const responseTypes = emitResponse?.responseTypes || {};

                if (!window.mcwcCheckoutController?.requestPaymentToken) {
                    const message = 'The Monek checkout form is not available. Please refresh and try again.';
                    window.mcwcCheckoutController?.displayError?.(message);
                    return {
                        type: responseTypes.ERROR || 'ERROR',
                        message,
                    };
                }

                try {
                    const result = await window.mcwcCheckoutController.requestPaymentToken();
                    let contextString = '';
                    try {
                        contextString = JSON.stringify(result.context || {});
                    } catch (stringifyError) {
                        contextString = '';
                    }

                    return {
                        type: responseTypes.SUCCESS || 'SUCCESS',
                        meta: {
                            paymentMethodData: {
                                monek_payment_token: result.token,
                                monek_checkout_context: contextString,
                                monek_checkout_session_id: result.sessionId || '',
                            },
                        },
                    };
                } catch (error) {
                    const message = (error && error.message)
                        ? error.message
                        : 'We were unable to prepare your payment method. Please try again.';

                    if (window.mcwcCheckoutController?.displayError) {
                        window.mcwcCheckoutController.displayError(message);
                    }

                    return {
                        type: responseTypes.ERROR || 'ERROR',
                        message,
                    };
                }
            });

            return () => {
                if (typeof unregister === 'function') {
                    unregister();
                }
            };
        }, [eventRegistration, emitResponse]);

        return h(
            'div',
            { 'data-mcwc-checkout-block': 'true', className: 'mcwc-checkout-wrapper' },
            h('div', { id: 'mcwc-checkout-messages', className: 'mcwc-checkout-messages', role: 'alert', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-express-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-checkout-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('input', { type: 'hidden', name: 'monek_payment_token', id: 'monek_payment_token' }),
            h('input', { type: 'hidden', name: 'monek_checkout_context', id: 'monek_checkout_context' }),
            h('input', { type: 'hidden', name: 'monek_checkout_session_id', id: 'monek_checkout_session_id' }),
            h('p', { className: 'mcwc-block-description' }, description),
        );
    }

    registry.registerPaymentMethod({
        name: 'monek-checkout',
        label: config.title || 'Credit/Debit Card',
        ariaLabel: 'Monek',
        content: (props) => h(Content, props),
        edit: (props) => h(Content, props),
        canMakePayment: () => true,
        supports: { features: (config.supports && config.supports.features) || ['products'] },
    });
})();
