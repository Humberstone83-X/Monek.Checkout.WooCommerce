(function initializeMonekBlocksIntegration() {
  'use strict';

  const windowObject = window;
  const registry = windowObject.wc?.wcBlocksRegistry;
  const getSetting = windowObject.wc?.wcSettings?.getSetting;
  const element = windowObject.wp?.element;

  if (!registry || !getSetting || !element) {
    return;
  }

  const { registerPaymentMethod, registerExpressPaymentMethod } = registry;
  const { createElement, useEffect, useRef } = element;

  const settings = getSetting('monek-checkout_data', {}) || {};
  const supportedFeatures = resolveSupportedFeatures(settings);
  const paymentMethodLabel = settings.title || 'Monek Checkout';
  const paymentMethodDescription = settings.description || 'Pay securely with Monek.';
  const paymentMethodLogo = settings.logoUrl || '';

  function resolveSupportedFeatures(configuration) {
    if (Array.isArray(configuration.supports)) {
      return configuration.supports;
    }

    if (Array.isArray(configuration.supports?.features)) {
      return configuration.supports.features;
    }

    return ['products'];
  }

  function updateBlocksContext(context) {
    windowObject.monekBlocksCtx = context;
  }

  function extractBillingFromProps(properties) {
    const billingFromBlocks = properties?.billing?.billingAddress;
    if (billingFromBlocks) {
      return billingFromBlocks;
    }

    return properties?.billingAddress || {};
  }

  function extractContactFromProps(properties) {
    return properties?.billing?.contact || {};
  }

  function createContentElement(props) {
    const { eventRegistration, emitResponse, isEditor } = props || {};
    const onPaymentProcessing = eventRegistration?.onPaymentProcessing;
    const latestPropsRef = useRef(props);

    useEffect(() => {
      latestPropsRef.current = props;

      const latestBilling = extractBillingFromProps(latestPropsRef.current);
      const latestContact = extractContactFromProps(latestPropsRef.current);

      updateBlocksContext({
        getBilling: () => latestBilling,
        getEmail: () => {
          return (latestContact.email ?? latestBilling.email ?? '').trim();
        },
        getPhone: () => {
          return (latestContact.phone ?? latestBilling.phone ?? '').trim();
        },
      });
    }, [props]);

    useEffect(() => {
      if (isEditor) {
        return;
      }

      windowObject.monekCheckout?.mount?.();
    }, [isEditor]);

    useEffect(() => {
      if (!onPaymentProcessing) {
        return () => {};
      }

      const unsubscribe = onPaymentProcessing(async () => {
        const responseTypes = emitResponse?.responseTypes;

        const expressPaymentPayload = windowObject.__monekExpressPayload;
        if (expressPaymentPayload?.monek_reference) {
          try { delete windowObject.__monekExpressPayload; } catch (_) {}
          return {
            type: responseTypes.SUCCESS,
            meta: {
              paymentMethodData: {
                monek_reference: expressPaymentPayload.monek_reference,
                monek_mode: 'express',
              },
            },
          };
        }

        try {
          if (!windowObject.monekCheckout?.trigger) {
            throw new Error('Payment initialisation not ready. Please try again.');
          }

          const { token, sessionId, expiry } = await windowObject.monekCheckout.trigger();
          const paymentReference = windowObject.monekCheckout?.getClientPaymentRef?.();

          return {
            type: responseTypes.SUCCESS,
            meta: {
              paymentMethodData: {
                monek_token: token,
                monek_session: sessionId,
                monek_expiry: expiry,
                monek_reference: paymentReference,
                monek_mode: 'standard',
              },
            },
          };
        } catch (error) {
          return {
            type: responseTypes.ERROR,
            message: error?.message || 'There was a problem preparing your payment. Please try again.',
          };
        }
      });

      return () => {
        try {
          unsubscribe?.();
        } catch (error) {
          if (windowObject.console?.warn) {
            windowObject.console.warn('[monek] Failed to remove payment processing listener', error);
          }
        }
      };
    }, [onPaymentProcessing, emitResponse?.responseTypes]);

    return createElement(
      'div',
      { className: 'monek-checkout-wrapper', 'data-monek-block': 'true' },
      paymentMethodLogo
        ? createElement('img', {
            src: paymentMethodLogo,
            alt: `${paymentMethodLabel} logo`,
            className: 'monek-checkout-logo',
            loading: 'lazy',
          })
        : null,
      createElement('div', { id: 'monek-checkout-container', className: 'monek-sdk-surface', 'aria-live': 'polite' }),
      createElement('div', { id: 'monek-checkout-messages', className: 'monek-checkout-messages', role: 'alert', 'aria-live': 'polite' }),
      paymentMethodDescription ? createElement('p', { className: 'monek-checkout-description' }, paymentMethodDescription) : null,
    );
  }

  function createExpressContentElement(props) {
    const {
      buttonAttributes,
      onClose,
      onSubmit,
      setExpressPaymentError,
      isEditor,
      eventRegistration,
      emitResponse,
    } = props || {};

    const mountedRef = useRef(false);
    const listenersRegisteredRef = useRef(false);

    useEffect(() => {
      if (isEditor || mountedRef.current) {
        return;
      }

      mountedRef.current = true;
      windowObject.monekCheckout?.mountExpress?.('#monek-express-container');
    }, [isEditor]);

    useEffect(() => {
      windowObject.monekCheckout?.setExpressStyle?.(buttonAttributes);
    }, [buttonAttributes]);

    useEffect(() => {
      if (listenersRegisteredRef.current) {
        return () => {};
      }

      listenersRegisteredRef.current = true;

      const removeListeners = registerExpressSdkListeners(onSubmit, onClose, setExpressPaymentError);

      return () => {
        removeListeners();
        listenersRegisteredRef.current = false;
      };
    }, [onSubmit, onClose, setExpressPaymentError]);

    useEffect(() => {
      const register = eventRegistration?.onPaymentProcessing;
      const responseTypes = emitResponse?.responseTypes;

      if (!register || !responseTypes) {
        return () => {};
      }

      const unsubscribe = register(async () => {
        const paymentReference = windowObject.monekCheckout?.getClientPaymentRef?.();
        if (!paymentReference) {
          return {
            type: responseTypes.ERROR,
            message: 'Payment reference missing.',
          };
        }

        return {
          type: responseTypes.SUCCESS,
          meta: {
            paymentMethodData: {
              monek_reference: paymentReference,
              monek_mode: 'express',
            },
          },
        };
      });

      return () => {
        try {
          unsubscribe?.();
        } catch (error) {
          if (windowObject.console?.warn) {
            windowObject.console.warn('[monek] Failed to remove express processing listener', error);
          }
        }
      };
    }, [eventRegistration?.onPaymentProcessing, emitResponse?.responseTypes]);

    return createElement(
      'div',
      { className: 'monek-express-wrapper', 'data-monek-block': 'true' },
      paymentMethodLogo
        ? createElement('img', {
            src: paymentMethodLogo,
            alt: `${paymentMethodLabel} logo`,
            className: 'monek-checkout-logo',
            loading: 'lazy',
          })
        : null,
      createElement('div', { id: 'monek-express-container', className: 'monek-sdk-surface', 'aria-live': 'polite' })
    );
  }

  function registerExpressSdkListeners(onSubmit, onClose, setExpressPaymentError) {
    function handleSuccess() {
      const paymentReference = windowObject.monekCheckout?.getClientPaymentRef?.();

      if (paymentReference) {
        windowObject.__monekExpressPayload = { monek_reference: paymentReference, monek_mode: 'express' };
      }

      if (typeof onSubmit === 'function') {
        onSubmit();
      }
    }

    function handleCancel() {
      if (typeof setExpressPaymentError === 'function') {
        setExpressPaymentError('Payment cancelled.');
      }

      if (typeof onClose === 'function') {
        onClose();
      }
    }

    function handleError() {
      if (typeof setExpressPaymentError === 'function') {
        setExpressPaymentError('Payment failed. Please try another method.');
      }

      if (typeof onClose === 'function') {
        onClose();
      }
    }

    windowObject.addEventListener('monek:express:success', handleSuccess);
    windowObject.addEventListener('monek:express:cancel', handleCancel);
    windowObject.addEventListener('monek:express:error', handleError);

    return () => {
      windowObject.removeEventListener('monek:express:success', handleSuccess);
      windowObject.removeEventListener('monek:express:cancel', handleCancel);
      windowObject.removeEventListener('monek:express:error', handleError);
    };
  }

  if (windowObject.console?.log) {
    windowObject.console.log('[monek] registerPaymentMethod', { settings, features: supportedFeatures });
  }

  registerPaymentMethod({
    name: 'monek-checkout',
    paymentMethodId: 'monek-checkout',
    label: paymentMethodLabel,
    ariaLabel: paymentMethodLabel,
    content: createElement(createContentElement, null),
    edit: createElement(createContentElement, { isEditor: true }),
    canMakePayment: () => true,
    supports: { features: supportedFeatures },
  });

  registerExpressPaymentMethod({
    name: 'monek-checkout-express',
    paymentMethodId: 'monek-checkout-express',
    label: 'Monek Express',
    content: createElement(createExpressContentElement, null),
    edit: createElement(createExpressContentElement, { isEditor: true }),
    canMakePayment: () => true,
    supports: { features: ['products'], style: ['height', 'borderRadius'] },
  });
})();
