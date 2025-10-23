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

    function Content() {
        useEffect(() => {
            if (window.mcwcCheckoutController?.ensureMounted) {
                window.mcwcCheckoutController.ensureMounted();
            }
        });

        return h('div', { 'data-mcwc-checkout-block': 'true', className: 'mcwc-checkout-wrapper' }, [
            h('div', { id: 'mcwc-checkout-messages', className: 'mcwc-checkout-messages', role: 'alert', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-express-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-checkout-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('input', { type: 'hidden', name: 'monek_payment_token', id: 'monek_payment_token' }),
            h('input', { type: 'hidden', name: 'monek_checkout_context', id: 'monek_checkout_context' }),
            h('p', { className: 'mcwc-block-description' }, description),
        ]);
    }

    registry.registerPaymentMethod({
        name: 'monek-checkout',
        label: config.title || 'Credit/Debit Card',
        ariaLabel: 'Monek',
        content: h(Content, {}),
        edit: h(Content, {}),
        canMakePayment: () => true,
        supports: { features: (config.supports && config.supports.features) || ['products'] },
    });
})();
