/**
 * Rejestracja bramki Tpay w formularzach GiveWP (Visual Form Builder).
 * Bramka działa przez przekierowanie, więc w formularzu pokazujemy tylko informację.
 * Ten plik nie zawiera i nie otrzymuje żadnych kluczy API.
 */
(function () {
  if (!window.givewp || !window.givewp.gateways || !window.wp || !window.wp.element) {
    return;
  }
  var el = window.wp.element.createElement;
  var settings = {};

  window.givewp.gateways.register({
    id: 'ostoja-tpay',
    initialize: function () {
      settings = this.settings || {};
    },
    Fields: function () {
      return el(
        'div',
        { className: 'ostoja-tpay-info' },
        el('p', { style: { marginBottom: 0 } }, settings.message || '')
      );
    },
  });
})();
