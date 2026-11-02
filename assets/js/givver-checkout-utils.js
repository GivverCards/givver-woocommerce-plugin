(function () {
  "use strict";

  window.GivverCheckoutUtils = {
    formatExpiryDate: function (value) {
      // Remove non-digit characters
      var digits = value.replace(/\D/g, "");
      // Limit to 4 digits
      var limitedDigits = digits.slice(0, 4);
      // Add slash after first 2 digits
      if (limitedDigits.length > 2) {
        return limitedDigits.slice(0, 2) + "/" + limitedDigits.slice(2);
      }
      return limitedDigits;
    },

    formatCardNumber: function (value) {
      // Remove non-digit characters
      var digits = value.replace(/\D/g, "");
      // Limit to 16 digits
      var limitedDigits = digits.slice(0, 19);
      // Add spaces every 4 digits
      var formatted = limitedDigits.replace(/(\d{4})(?=\d)/g, "$1 ");
      return formatted;
    },
  };
})();
