/* global jQuery */
(function (window, document, $) {
  // -----------------------------
  // Config / constants
  // -----------------------------
const wcSettings = window.wc?.wcSettings;
const configFromBlocks = wcSettings?.getSetting?.('monek-checkout_data', {}) || {};
const legacyConfig = window.mcwcCheckoutConfig || {};
const config = { ...legacyConfig, ...configFromBlocks }; // Blocks keys win
const gatewayId = config.gatewayId || 'monek-checkout';
if (!config.publishableKey) return;

  const selectors = {
    wrapper:  "#mcwc-checkout-wrapper",
    messages: "#mcwc-checkout-messages",
    express:  "#mcwc-express-container",
    checkout: "#mcwc-checkout-container",
  };

  // -----------------------------
  // State
  // -----------------------------
  const state = {
    sdkPromise: null,
    checkoutComponent: null,
    expressComponent: null,
    mountingPromise: null,
    expressStyle: null,
    expressResult: null,
    clientPaymentRef: null,
  };

  function emit(name, detail) {
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch (_) {}
}
let resolveCompletionOnce = null;
function waitForCompletionOnce() {
  return new Promise((resolve) => { resolveCompletionOnce = resolve; });
}

function setExpressStyle(style) {
  // style: { height?: number|string, borderRadius?: number|string }
  state.expressStyle = style || null;
}



function makeClientPaymentRef() {
  try {
    if (window.crypto?.randomUUID) return `MNK-${crypto.randomUUID()}`;
  } catch {}
  return `MNK-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function ensureClientPaymentRef() {
  if (!state.clientPaymentRef) state.clientPaymentRef = makeClientPaymentRef();
  return state.clientPaymentRef;
}

function getClientPaymentRef() {
  return ensureClientPaymentRef();
}

  // -----------------------------
  // Country helpers for SDK
  // -----------------------------
  const ISO_ALPHA2_TO_NUMERIC = {
    GB:"826", US:"840", IE:"372", NL:"528", DE:"276", FR:"250", ES:"724", IT:"380", PT:"620",
    BE:"056", SE:"752", NO:"578", DK:"208", FI:"246", IS:"352", AT:"040", CH:"756", PL:"616",
    CZ:"203", SK:"703", HU:"348", RO:"642", BG:"100", GR:"300", CY:"196", MT:"470", EE:"233",
    LV:"428", LT:"440", LU:"442", SI:"705", HR:"191", CA:"124", AU:"036", NZ:"554", JP:"392",
    KR:"410", CN:"156", HK:"344", SG:"702", MY:"458", TH:"764", VN:"704", PH:"608", ID:"360",
    IN:"356", BR:"076", AR:"032", CL:"152", MX:"484", CO:"170", PE:"604", ZA:"710", TR:"792",
    IL:"376", SA:"682", AE:"784", QA:"634", KW:"414", BH:"048", OM:"512"
  };
  function toIso3166Numeric(code) {
    if (!code) return null;
    const c = String(code).trim();
    if (/^\d{3}$/.test(c)) return c;
    const upper = c.toUpperCase();
    if (upper === "UK") return "826";
    return ISO_ALPHA2_TO_NUMERIC[upper] || null;
  }

  // -----------------------------
  // UX helpers
  // -----------------------------
  function displayError(message) {
    const container = document.querySelector(selectors.messages);
    const msg = message || (config.strings?.token_error || "There was a problem preparing your payment. Please try again.");
    if (container) {
      container.textContent = msg;
      container.style.display = "";
    }
    try { $(document.body).trigger("checkout_error", [msg]); } catch (_) {}
  }
  function clearError() {
    const container = document.querySelector(selectors.messages);
    if (container) container.textContent = "";
  }

  // -----------------------------
  // Woo Blocks helpers (live values)
  // -----------------------------
function blocksSelect(ns) {
  return window.wp?.data?.select?.(ns);
}

function blocksStores() {
  return {
    cart: blocksSelect("wc/store/cart"),
  };
}

  // Live totals (minor units) for SDK amount
  function getOrderTotalMinor() {
    const { cart } = blocksStores();
    const totals = cart?.getCartTotals?.();
    return totals?.total_price || 0;
  }

  
// --- props-first customer read (from mcwc-blocks-checkout.js) ---
function readCustomerFromPropsCtx() {
  const ctx = window.mcwcBlocksCtx;
  if (!ctx) return null;

  const billing = (typeof ctx.getBilling === 'function') ? (ctx.getBilling() || {}) : {};
  const email   = (typeof ctx.getEmail === 'function') ? (ctx.getEmail() || '') : '';
  const phone   = (typeof ctx.getPhone === 'function') ? (ctx.getPhone() || '') : '';

  return { billing, email, phone };
}


  // SDK cardholder details (uses live checkout store)
  function buildCardholderDetails() {
  const propsCustomer = readCustomerFromPropsCtx();
  const c = propsCustomer || {};
  const b = c.billing || {};

  const first = b.first_name ?? b.firstName ?? '';
  const last  = b.last_name  ?? b.lastName  ?? '';
  const detailsFromProps = {
    name: [first, last].filter(Boolean).join(' ').trim(),
    email: c.email || '',
    HomePhone: c.phone || b.phone || '',
    billingAddress: {
      addressLine1: b.address_1 ?? b.address1 ?? '',
      addressLine2: b.address_2 ?? b.address2 ?? '',
      city:        b.city ?? '',
      postcode:    b.postcode ?? b.postalCode ?? '',
      country:     toIso3166Numeric(b.country ?? b.countryCode) || (config.countryNumeric || '826'),
      state:       b.state ?? b.region ?? '',
    },
  };
  return detailsFromProps;
}


  // -----------------------------
  // SDK boot + options
  // -----------------------------
  function waitForSdk() {
    if (state.sdkPromise) return state.sdkPromise;
    state.sdkPromise = new Promise((resolve, reject) => {
      let tries = 0;
      const t = setInterval(() => {
        tries++;
        if (typeof window.Monek === "function") {
          clearInterval(t);
          try {
            Promise.resolve(window.Monek(config.publishableKey)).then(resolve).catch(reject);
          } catch (e) { reject(e); }
          return;
        }
        if (tries >= 40) {
          clearInterval(t);
          reject(new Error("Monek Checkout SDK not available."));
        }
      }, 250);
    });
    return state.sdkPromise;
  }

function buildOptions(isExpress) {

  const paymentReference = ensureClientPaymentRef();

  const callbacks = {
    getAmount: () => ({ minor: getOrderTotalMinor(), currency: config.currencyNumeric || "826" }),
    getDescription: () => config.orderDescription || "Order",
    getCardholderDetails: buildCardholderDetails,
  };

  const base = {
    callbacks,
    paymentReference,
    countryCode: toIso3166Numeric(config.countryNumeric || "GB"),
    intent: "Purchase",
    order: "Checkout",
    settlementType: "Auto",
    cardEntry: "ECommerce",
    storeCardDetails: false,
    challenge: config.challenge || { display: "popup", size: "medium" },
    completion: {
      mode: "none",
      onSuccess: (ctx /*, helpers */) => {
        // try to read values from the component if available
        const token     = state.checkoutComponent?.getCardTokenId?.() || state.expressComponent?.getCardTokenId?.() || null;
        const sessionId = state.checkoutComponent?.getSessionId?.()   || state.expressComponent?.getSessionId?.()   || null;
        const expiry    = state.checkoutComponent?.getCardExpiry?.()   || state.expressComponent?.getCardExpiry?.()   || null;

        const detail = { status: 'success', ctx, token, sessionId, expiry };
        state.expressResult = detail;
        emit('mcwc:express:success', detail);
        resolveCompletionOnce?.(detail);
      },
      onError: (ctx, helpers) => {
        const detail = { status: 'error', ctx };
        state.expressResult = detail;
        emit('mcwc:express:error', detail);
        helpers?.reenable?.();
        resolveCompletionOnce?.(detail);
      },
      onCancel: (ctx, helpers) => {
        const detail = { status: 'cancel', ctx };
        state.expressResult = detail;
        emit('mcwc:express:cancel', detail);
        helpers?.reenable?.();
        resolveCompletionOnce?.(detail);
      },
    },
    debug: !!config.debug,
    styling: config.styling || { theme: config.theme || "light" },
  };

  if (isExpress) {
    const style = state.expressStyle || {};
    base.styling = {
      ...(base.styling || {}),
      express: { height: style.height, borderRadius: style.borderRadius },
    };
    base.surface = "express";
  }

  return base;
}

  // -----------------------------
  // Mounting (Blocks only)
  // -----------------------------
  async function mountExpress(selector) {
  console.log('[mcwc] mountExpress →', selector);
  if (!selector) return false;

  const el = document.querySelector(selector);
  if (!el) {
    console.warn('[mcwc] express container not found:', selector);
    return false;
  }

  if (state.expressComponent) {
    console.log('[mcwc] express already mounted');
    return true;
  }

  try {
    const sdk = await waitForSdk();              // ✅ await
    const express = sdk.createComponent('express', buildOptions(true));
    await express.mount(selector);
    state.expressComponent = express;
    console.log('[mcwc] express mounted');
    return true;
  } catch (e) {
    console.error('[mcwc] express mount failed:', e);
    return false;
  }
}

  async function mountComponents() {
    if (state.mountingPromise) return state.mountingPromise;
    const checkoutContainer = document.querySelector(selectors.checkout);
    if (!checkoutContainer) return false;

    state.mountingPromise = waitForSdk()
      .then(async (sdk) => {
        if (!state.checkoutComponent) {
          const checkout = sdk.createComponent("checkout", buildOptions(false));
          await checkout.mount(selectors.checkout);
          state.checkoutComponent = checkout;
        }
        //if (config.showExpress !== false && !state.expressComponent) {
        //  try {
        //    const express = sdk.createComponent("express", buildOptions(true));
        //    await express.mount(selectors.express);
        //    state.expressComponent = express;
        //  } catch (err) {
        //    if (window.console?.warn) console.warn("[mcwc] express mount skipped:", err);
        //  }
        //}
        return true;
      })
      .catch((err) => {
        displayError(err?.message || "There was a problem preparing your payment. Please try again.");
        return false;
      })
      .finally(() => { state.mountingPromise = null; });

    return state.mountingPromise;
  }

  // -----------------------------
  // Public trigger (no interception)
  // -----------------------------
  async function trigger() {
    //await mountComponents();
    if (!state.checkoutComponent) throw new Error("Checkout component not ready.");

    await state.checkoutComponent.triggerSubmission();
    const token = state.checkoutComponent.getCardTokenId?.() || state.checkoutComponent.getCardTokenId;
    const sessionId = state.checkoutComponent.getSessionId?.() || state.checkoutComponent.getSessionId;
    const expiry = state.checkoutComponent.getCardExpiry?.() || state.checkoutComponent.getCardExpiry;
    if (!token || !sessionId) throw new Error("Card details not ready.");

    if (config.debug) console.log("[mcwc] trigger() → token/session", { token, sessionId });
    return { token, sessionId, expiry };
  }

  // -----------------------------
  // Public API
  // -----------------------------
  window.mcwcCheckout = {
    mount: mountComponents,
    setExpressStyle,       
    mountExpress,
    trigger,       // call from the Blocks lifecycle to get token/session
    displayError,
    clearError,
    getClientPaymentRef,
  };
})(window, document, window.jQuery);
