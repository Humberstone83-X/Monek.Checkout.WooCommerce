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
    const label = config.title || 'Monek Checkout';
    const description = config.description || 'Pay securely with Monek.';
    const errorMessage = config.errorMessage || 'We were unable to prepare your payment. Please try again.';

    function Content(props) {
        const { eventRegistration, emitResponse, isEditor } = props || {};

        useEffect(() => {
            if (isEditor) {
                return;
            }

            if (window.mcwcCheckoutController?.ensureMounted) {
                window.mcwcCheckoutController.ensureMounted();
            }
        }, [isEditor]);

        useEffect(() => {
            if (isEditor) {
                return undefined;
            }

            if (!eventRegistration?.onPaymentProcessing || !emitResponse?.responseTypes) {
                return undefined;
            }

            const unregister = eventRegistration.onPaymentProcessing(async () => {
                const { responseTypes } = emitResponse;

                if (!window.mcwcCheckoutController?.requestPaymentToken) {
                    const message = errorMessage;
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
                    } catch (err) {
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
                    const message = error?.message || errorMessage;
                    window.mcwcCheckoutController?.displayError?.(message);
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
        }, [eventRegistration, emitResponse, isEditor]);

        return h(
            'div',
            { className: 'mcwc-checkout-wrapper', 'data-mcwc-block': 'true' },
            h('div', { id: 'mcwc-express-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-checkout-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-checkout-messages', className: 'mcwc-checkout-messages', role: 'alert', 'aria-live': 'polite' }),
            h('input', { type: 'hidden', name: 'monek_payment_token', id: 'monek_payment_token' }),
            h('input', { type: 'hidden', name: 'monek_checkout_context', id: 'monek_checkout_context' }),
            h('input', { type: 'hidden', name: 'monek_checkout_session_id', id: 'monek_checkout_session_id' }),
            description ? h('p', { className: 'mcwc-checkout-description' }, description) : null,
        );
    }

    registry.registerPaymentMethod({
        name: 'monek-checkout',
        label,
        ariaLabel: label,
        content: h(Content, null),
        edit: h(Content, { isEditor: true }),
        canMakePayment: () => true,
        supports: { features: ['products'] },
    });
})();
