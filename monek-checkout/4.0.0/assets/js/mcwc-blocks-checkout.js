(function () {
  const registry   = window.wc?.wcBlocksRegistry;
  const getSetting = window.wc?.wcSettings?.getSetting;
  const element    = window.wp?.element;

  if (!registry || !getSetting || !element) return;

  const { registerPaymentMethod, registerExpressPaymentMethod } = registry;
  const h = element.createElement;
  const { useEffect, useRef } = element;

  const settings = getSetting('monek-checkout_data', {}) || {};
  const features = Array.isArray(settings.supports) ? settings.supports
               : Array.isArray(settings.supports?.features) ? settings.supports.features
               : ['products'];

  const label = settings.title || 'Monek Checkout';
  const description = settings.description || 'Pay securely with Monek.';

  function setBlocksCtx(ctx){
    window.mcwcBlocksCtx = ctx;
  }

  function Content(props) {
    const { eventRegistration, emitResponse, isEditor } = props || {};
    const { onPaymentProcessing } = eventRegistration || {};
    const lastPropsRef = useRef(props);

    // Cache latest props so SDK callbacks can read fresh values.
    useEffect(() => {
      lastPropsRef.current = props;
      const p = lastPropsRef.current || {};
      const billingObj =
        p.billing?.billingAddress ??
        p.billingAddress ?? {};
      const contact = p.billing?.contact || {};

      setBlocksCtx({
        getBilling: () => billingObj,
        getEmail:   () => (contact.email ?? billingObj.email ?? '').trim(),
        getPhone:   () => (contact.phone ?? billingObj.phone ?? '').trim(),
      });
    }, [props]);

    // Mount SDK surfaces (card)
    useEffect(() => {
      if (!isEditor) window.mcwcCheckout?.mount?.();
    }, [isEditor]);

    // Hook the Blocks processing lifecycle (CARD)
    useEffect(() => {
      if (!onPaymentProcessing) return () => {};

      const unsubscribe = onPaymentProcessing(async () => {
        const rt = emitResponse?.responseTypes;

        // 🔸 Normal card path
        try {
          if (!window.mcwcCheckout?.trigger) {
            throw new Error('Payment initialisation not ready. Please try again.');
          }
          const { token, sessionId, expiry } = await window.mcwcCheckout.trigger();
          const paymentReference = window.mcwcCheckout?.getClientPaymentRef?.();

          return {
            type: rt.SUCCESS,
            meta: {
              paymentMethodData: {
                mcwc_token: token,
                mcwc_session: sessionId,
                mcwc_expiry: expiry,
                mcwc_reference: paymentReference,
                mcwc_mode: 'standard',
              },
            },
          };
        } catch (err) {
          return {
            type: rt.ERROR,
            message: err?.message || 'There was a problem preparing your payment. Please try again.',
          };
        }
      });

      return () => { try { unsubscribe?.(); } catch (_) {} };
    }, [onPaymentProcessing, emitResponse?.responseTypes]);

    // Minimal UI (SDK mounts real surfaces)
    return h(
      'div',
      { className: 'mcwc-checkout-wrapper', 'data-mcwc-block': 'true' },
      h('div', { id: 'mcwc-checkout-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' }),
      h('div', { id: 'mcwc-checkout-messages', className: 'mcwc-checkout-messages', role: 'alert', 'aria-live': 'polite' }),
      description ? h('p', { className: 'mcwc-checkout-description' }, description) : null,
    );
  }

  // Mount once with the right style (EXPRESS)
  function ExpressContent(props) {
    const {
      buttonAttributes,
      onClose,
      onSubmit,
      setExpressPaymentError,
      isEditor,
      eventRegistration,
      emitResponse,
    } = props || {};

    const h = wp.element.createElement;
    const { useEffect, useRef } = wp.element;

    const mountedRef = useRef(false);
    const listenersRef = useRef(false);

    // 1) Mount the express surface once
    useEffect(() => {
      if (!isEditor && !mountedRef.current) {
        mountedRef.current = true;
        window.mcwcCheckout?.mountExpress?.('#mcwc-express-container');
      }
    }, [isEditor]);

    // 2) Update style dynamically
    useEffect(() => {
      window.mcwcCheckout?.setExpressStyle?.(buttonAttributes);
    }, [buttonAttributes]);

    // 3) Listen for SDK result events once
    useEffect(() => {
      if (listenersRef.current) return;
      listenersRef.current = true;

      const onSuccess = () => {
        
        const ref = window.mcwcCheckout?.getClientPaymentRef?.();
        if (ref) {
          window.__mcwcExpressPayload = { mcwc_reference: ref, mcwc_mode: 'express' };
        }

        // Will cause Blocks to call the card onPaymentProcessing handler
        onSubmit?.();
      };
      const onCancel  = () => { setExpressPaymentError?.('Payment cancelled.'); onClose?.(); };
      const onError   = () => { setExpressPaymentError?.('Payment failed. Please try another method.'); onClose?.(); };

      window.addEventListener('mcwc:express:success', onSuccess);
      window.addEventListener('mcwc:express:cancel',  onCancel);
      window.addEventListener('mcwc:express:error',   onError);

      return () => {
        window.removeEventListener('mcwc:express:success', onSuccess);
        window.removeEventListener('mcwc:express:cancel',  onCancel);
        window.removeEventListener('mcwc:express:error',   onError);
        listenersRef.current = false;
      };
    }, [onSubmit, onClose, setExpressPaymentError]);

    +  // 4) Register express onPaymentProcessing so Blocks posts our payload
    useEffect(() => {
        const register = eventRegistration?.onPaymentProcessing;
        const responseTypes = emitResponse?.responseTypes;
        if (!register || !responseTypes) return;

        const unsubscribe = register(async () => {
        const ref = window.mcwcCheckout?.getClientPaymentRef?.();
        if (!ref) {
            return { type: responseTypes.ERROR, message: 'Payment reference missing.' };
        }
        return {
            type: responseTypes.SUCCESS,
            meta: { paymentMethodData: { mcwc_reference: ref, mcwc_mode: 'express' } },
        };
    });

    return () => { try { unsubscribe?.(); } catch (_) {} };
  }, [eventRegistration?.onPaymentProcessing, emitResponse?.responseTypes]);


    return h(
      'div',
      { className: 'mcwc-express-wrapper', 'data-mcwc-block': 'true' },
      h('div', { id: 'mcwc-express-container', className: 'mcwc-sdk-surface', 'aria-live': 'polite' })
    );
  }

  console.log('[mcwc] registerPaymentMethod', { settings, features });

  registerPaymentMethod({
    name: 'monek-checkout',
    paymentMethodId: 'monek-checkout',
    label,
    ariaLabel: label,
    content: h(Content, null),
    edit: h(Content, { isEditor: true }),
    canMakePayment: () => true,
    supports: { features },
  });

  registerExpressPaymentMethod({
    name: 'monek-checkout-express',
    paymentMethodId: 'monek-checkout-express',
    label: 'Monek Express',
    content:  h(ExpressContent, null),
    edit: h(ExpressContent, { isEditor: true }),
    canMakePayment: () => true,
    supports: { features: ['products'], style: ['height', 'borderRadius'] },
  });
})();
