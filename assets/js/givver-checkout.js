(function ($) {
  "use strict";

  if (typeof givver_wc_params === "undefined") {
    return;
  }

  var GivverCardHandler = {
    processing: false,

    threeDSEnabled: false,
    threeDSMountSelector: null,
    gateway3DS: null,
    threeDS: null,
    threeDSUI: null,
    threeDSChallenged: false,
    paymentRedirectionFlow: false,

    init: function () {
      // Hook into WooCommerce checkout JS lifecycle for the card gateway.
      $("form.checkout, form#order_review").on(
        "checkout_place_order_" + givver_wc_params.card_gateway_id,
        GivverCardHandler.onCheckoutPlaceOrder
      );

      $(document.body).on("checkout_error", function () {
        console.log("[XCardHandler] form checkout_error, cleanup");
        $("#givver-opaque-data").val("");
      });

      function attachExpiryListener() {
        $("#givver-card-expiry").on("input", function () {
          const formatted = GivverCheckoutUtils.formatExpiryDate($(this).val());
          $(this).val(formatted);
        });
      }
      $("body").on("init_checkout", attachExpiryListener);
      $("body").on("updated_checkout", attachExpiryListener);

      function attachCardNumberListener() {
        $("#givver-card-number").on("input", function () {
          const formatted = GivverCheckoutUtils.formatCardNumber($(this).val());
          $(this).val(formatted);
        });
      }
      $("body").on("init_checkout", attachCardNumberListener);
      $("body").on("updated_checkout", attachCardNumberListener);

      console.log("[XCardHandler] init");

      GivverCardHandler.threeDSEnabled =
        givver_wc_params["enable_3ds"] === "yes";
      GivverCardHandler.threeDSMountSelector =
        givver_wc_params["3ds_mount_element_selector"];

      console.log(
        "[3DS] [XCardHandler] threeDSEnabled",
        GivverCardHandler.threeDSEnabled,
        GivverCardHandler.threeDSMountSelector
      );

      GivverCardHandler.paymentRedirectionFlow =
        givver_wc_params["payment_redirection_flow"] === "yes";

      GivverCardHandler.apiEndpoint = givver_wc_params["api_endpoint"];

      console.log(
        "[GivverCardHandler.apiEndpoint]",
        GivverCardHandler.apiEndpoint
      );
    },

    getBillingAddress: function () {
      const v = (name) =>
        document.querySelector(`[name="${name}"]`)?.value?.trim() || "";

      return {
        first_name: v("billing_first_name"),
        last_name: v("billing_last_name"),
        company: v("billing_company"),
        address_1: v("billing_address_1"),
        address_2: v("billing_address_2"),
        city: v("billing_city"),
        state: v("billing_state"),
        postcode: v("billing_postcode"),
        country: v("billing_country"),
        phone: v("billing_phone"),
        email: v("billing_email"),
      };
    },

    onCheckoutPlaceOrder: function (e) {
      console.log("[XCardHandler] onCheckoutPlaceOrder", e);

      const opaqueData = $("#givver-opaque-data").val();

      console.log(
        "[XCardHandler] onCheckoutPlaceOrder: processing",
        GivverCardHandler.processing
      );
      console.log(
        "[XCardHandler] onCheckoutPlaceOrder opaqueData",
        !!opaqueData
      );

      console.log(
        "[XCardHandler] onCheckoutPlaceOrder: paymentRedirectionFlow",
        GivverCardHandler.paymentRedirectionFlow
      );

      if (!GivverCardHandler.processing && opaqueData) {
        // All data is here, continue with normal form submission to backend
        console.log("[XCardHandler] onCheckoutPlaceOrder SUBMIT!");
        return true; // true means do submit
      }

      if (GivverCardHandler.threeDSChallenged) {
        // if 3DS is visible - skip submission
        console.log("[XCardHandler] threeDSChallenged, skip");
        return false;
      }

      // Redirect flow has no card fields on this page. Let WooCommerce submit
      // the checkout so the server can create the payment request and redirect.
      if (GivverCardHandler.paymentRedirectionFlow) {
        return true;
      }

      e.preventDefault();

      var form = $(this);

      // Basic validation.
      var cardNumber = $("[data-givver-card-number]").val().replace(/\s+/g, "");
      var exp = $("[data-givver-card-expiry]").val().replace(/\s+/g, "");
      var cvc = $("[data-givver-card-cvc]").val().trim();

      if (!cardNumber || !exp || !cvc) {
        window.wc_checkout_form &&
          window.wc_checkout_form.submit_error(
            '<ul class="woocommerce-error"><li>' +
              givver_wc_params.i18n.card_required +
              "</li></ul>"
          );
        return false;
      }

      var expParts = exp.split("/");
      if (expParts.length !== 2) {
        window.wc_checkout_form &&
          window.wc_checkout_form.submit_error(
            '<ul class="woocommerce-error"><li>' +
              givver_wc_params.i18n.card_required +
              "</li></ul>"
          );
        return false;
      }

      var month = expParts[0].trim();
      var year = expParts[1].trim();
      if (year.length === 2) {
        // Convert YY to YYYY (simple heuristic, not perfect).
        var now = new Date().getFullYear();
        var base = Math.floor(now / 100) * 100;
        year = base + parseInt(year, 10);
      }

      GivverCardHandler.processing = true;

      GivverCardHandler.tokenizeCard(cardNumber, month, year, cvc)
        .then(function (opaqueData) {
          console.log(
            "[3DS] tokenizeCard done, threeDSEnabled",
            GivverCardHandler.threeDSEnabled
          );

          $("#givver-opaque-data").val(JSON.stringify(opaqueData));

          // show 3DS UI
          if (GivverCardHandler.threeDSEnabled) {
            const cartTotal = givver_wc_params.cart_total || 0;
            const amount = cartTotal.toFixed
              ? cartTotal.toFixed(2)
              : String(cartTotal);

            const billing = GivverCardHandler.getBillingAddress();
            console.log("[3DS] billing", billing);

            const options = {
              paymentToken: opaqueData.token,
              currency: givver_wc_params.currency,
              amount,
              firstName: billing.first_name,
              lastName: billing.last_name,
              email: billing.email,
              address1: billing.address_1,
              city: billing.city,
              state: billing.state,
              country: billing.country,
              postalCode: billing.postcode,
              phone: billing.phone,
            };
            GivverCardHandler.threeDSUI =
              GivverCardHandler.threeDS.createUI(options);

            console.log("[3DS] start", GivverCardHandler.threeDSMountSelector);

            GivverCardHandler.threeDSUI.start(
              GivverCardHandler.threeDSMountSelector
            );

            // listen for 3ds callbacks
            // Listen for the 'challenge' callback to ask the user for a password
            GivverCardHandler.threeDSUI.on("challenge", (e) => {
              console.log("[3DS] Challenged", e);
              GivverCardHandler.threeDSChallenged = true;
            });
            // Listen for the 'complete' callback to provide all the needed 3DS data
            GivverCardHandler.threeDSUI.on("complete", (e) => {
              console.log("[3DS] complete", e);

              const threeDSData = {
                cavv: e.cavv,
                xid: e.xid,
                eci: e.eci,
                cardHolderAuth: e.cardHolderAuth,
                threeDsVersion: e.threeDsVersion,
                directoryServerId: e.directoryServerId,
              };
              $("#givver-3ds-data").val(JSON.stringify(threeDSData));

              // Now submit the form "for real".
              GivverCardHandler.processing = false;
              GivverCardHandler.threeDSChallenged = false;
              form.trigger("submit"); // triggers WC checkout again, but now with flag above.
            });
            GivverCardHandler.threeDSUI.on("error", (e) => {
              GivverCardHandler.processing = false;
              GivverCardHandler.threeDSChallenged = false;

              console.error("[3DS] error", e);

              // unmount so a user can retry
              try {
                GivverCardHandler.threeDSUI.unmount();
              } catch (e) {
                console.warn("threeDSUI.unmount error", e);
              }

              // TODO: maybe we should fail with error?
              //
              // The card does NOT participate in 3DS
              //
              // Now submit the form "for real".
              form.trigger("submit"); // triggers WC checkout again, but now with flag above.
            });
            // Listen for the 'failure' callback to indicate that the customer has failed to authenticate
            GivverCardHandler.threeDSUI.on("failure", (e) => {
              GivverCardHandler.processing = false;
              GivverCardHandler.threeDSChallenged = false;

              console.error("[3DS] failure", e);

              // unmount so a user can retry
              try {
                GivverCardHandler.threeDSUI.unmount();
              } catch (e) {
                console.warn("threeDSUI.unmount error", e);
              }

              if (e.code === "TRANSACTION_STATUS_U") {
                if (
                  e.cardHolderInfo?.toLowerCase() ===
                  "challenge cancelled by user"
                ) {
                  // User clicked on Cancel button on challenge -> fail
                  $("#givver-opaque-data").val(""); // reset opaqueData

                  alert(
                    "Verification was cancelled. Your payment wasn't completed. Please try again"
                  );
                } else {
                  // Authentication unavailable / not enrolled
                  // The card does NOT participate in 3DS
                  // → ✅ Proceed with payment WITHOUT liability shift

                  // Now submit the form "for real".
                  form.trigger("submit"); // triggers WC checkout again, but now with flag above.
                }
              } else if (
                e.code === "TRANSACTION_STATUS_N" ||
                e.code === "TRANSACTION_STATUS_R"
              ) {
                // hard stop, show error message
                // R — Rejected
                // Issuer rejected authentication
                // High fraud signal
                // ❌ Do not fall back
                // N — Not authenticated / Failed
                // Authentication attempted but failed
                // ❌ Do not fall back

                $("#givver-opaque-data").val(""); // reset opaqueData

                alert(
                  "Error while doing 3DS verification, Issuer rejected authentication: " +
                    e.message
                );
              } else {
                // Unknown Error
                // Timeout Error
                // Error on Authentication

                $("#givver-opaque-data").val(""); // reset opaqueData

                alert("Error while doing 3DS verification: " + e.message);
              }
            });
            // Listen for any errors that might occur
            GivverCardHandler.gateway3DS.on("error", (e) => {
              console.error("[3DS] gateway general error", e);

              $("#givver-opaque-data").val(""); // reset opaqueData
              GivverCardHandler.processing = false;
              GivverCardHandler.threeDSChallenged = false;
            });
          } else {
            // Now submit the form "for real".
            GivverCardHandler.processing = false;
            GivverCardHandler.threeDSChallenged = false;
            form.trigger("submit"); // triggers WC checkout again, but now with flag above.
          }
        })
        .catch(function (error) {
          GivverCardHandler.processing = false;
          GivverCardHandler.threeDSChallenged = false;

          const msg =
            error && error.message
              ? error.message
              : givver_wc_params.i18n.card_error;
          window.wc_checkout_form &&
            window.wc_checkout_form.submit_error(
              '<ul class="woocommerce-error"><li>' + msg + "</li></ul>"
            );
        });

      return false;
    },

    tokenizeCard: function (cardNumber, month, year, cvc) {
      return new Promise(function (resolve, reject) {
        var publishable = givver_wc_params.card_publishable;
        if (!publishable) {
          return reject(
            new Error("Givver publishable API key is not configured.")
          );
        }

        function replaceHostIfPort9000(url) {
          return url.replace(/^https?:\/\/[^/]+:9000$/, (m) =>
            m.replace(/\/\/[^/]+:9000$/, "//localhost:9000")
          );
        }
        // Step 1: pretransact to get cardTokenRequestUrl and params.
        fetch(`${replaceHostIfPort9000(GivverCardHandler.apiEndpoint)}/pretransact`, {
          method: "GET",
          headers: {
            "x-publishable-api-key": publishable,
            Accept: "application/json",
          },
        })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            if (
              !data ||
              !data.cardTokenRequestUrl ||
              !data.cardTokenRequestParams
            ) {
              throw new Error("Invalid pretransact response from Givver.");
            }

            // enable 3DS
            if (GivverCardHandler.threeDSEnabled) {
              console.log("[3DS] enable");
              if (!GivverCardHandler.gateway3DS) {
                GivverCardHandler.gateway3DS = Gateway.create(
                  data.gatewayPublicKey
                );
              }
              if (!GivverCardHandler.threeDS) {
                GivverCardHandler.threeDS =
                  GivverCardHandler.gateway3DS.get3DSecure();
              }
            }

            var tokenUrl = data.cardTokenRequestUrl;
            var tokenParams = data.cardTokenRequestParams;

            const expirationDate =
              ((month + "").length === 1 ? "0" + month : month) +
              (year + "").slice(-2);

            // Replace stubs.
            var paramsStr = JSON.stringify(tokenParams)
              .replace(/#cardNumber#/g, cardNumber)
              .replace(/#expirationDate#/g, expirationDate)
              .replace(/#cardCode#/g, cvc);

            return fetch(tokenUrl, {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
              },
              body: paramsStr,
            });
          })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            if (!data || !(data.opaqueData || data.token)) {
              throw new Error(
                "Invalid card tokenization response from processor."
              );
            }
            resolve(data.opaqueData || { token: data.token });
          })
          .catch(function (err) {
            reject(err);
          });
      });
    },
  };

  $(function () {
    GivverCardHandler.init();
  });
})(jQuery);
