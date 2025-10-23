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

    function Content(props) {
        const { isEditor } = props || {};

        useEffect(() => {
            if (isEditor) {
                return;
            }

            if (window.mcwcCheckoutController?.ensureMounted) {
                window.mcwcCheckoutController.ensureMounted();
            }
        }, [isEditor]);

        return h(
            'div',
            { className: 'mcwc-checkout-wrapper', 'data-mcwc-block': 'true' },
            h('div', { id: 'mcwc-express-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-checkout-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
            h('div', { id: 'mcwc-checkout-messages', className: 'mcwc-checkout-messages', role: 'alert', 'aria-live': 'polite' }),
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
