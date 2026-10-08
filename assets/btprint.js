// 🔵 Print a plain-text receipt on a Bluetooth thermal printer (ESC/POS),
// straight from Chrome on Android or a computer. The printer is picked once;
// Chrome remembers it for this site. iPhones have no Web Bluetooth - there
// the normal Print button (AirPrint / the printer's own app) is the way.
var BT_SERVICES = [0x18f0, 0xff00, 0xffe0, 0xfee7, 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', '49535343-fe7d-4ae5-8fa9-9fafd205e455'];
var _btChar = null;

function btBytes(text) {
  var clean = String(text).replace(/₹/g, 'Rs').replace(/[^\x0A\x20-\x7E]/g, '');
  var body = Array.from(clean, function (ch) { return ch.charCodeAt(0); });
  return new Uint8Array([0x1B, 0x40].concat(body, [0x0A, 0x0A, 0x0A, 0x1D, 0x56, 0x01]));   // init ... feed, cut
}

function btPrint(text, say) {
  say = say || function () {};
  if (!navigator.bluetooth) { say('This phone/browser has no Bluetooth printing — use 🖨️ Print.'); return Promise.resolve(false); }
  var getChar = _btChar ? Promise.resolve(_btChar) :
    navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BT_SERVICES })
      .then(function (dev) { say('Connecting to ' + (dev.name || 'printer') + '…'); return dev.gatt.connect(); })
      .then(function (srv) { return srv.getPrimaryServices(); })
      .then(function (svcs) {
        return Promise.all(svcs.map(function (s) { return s.getCharacteristics().catch(function () { return []; }); }));
      })
      .then(function (lists) {
        var all = [].concat.apply([], lists);
        var c = all.filter(function (x) { return x.properties.writeWithoutResponse || x.properties.write; })[0];
        if (!c) throw new Error('This device does not take printing.');
        return (_btChar = c);
      });
  return getChar.then(function (c) {
    var data = btBytes(text), i = 0;
    say('Printing…');
    var next = function () {
      if (i >= data.length) { say('Printed ✔'); return true; }
      var part = data.slice(i, i + 100); i += 100;
      return (c.properties.writeWithoutResponse ? c.writeValueWithoutResponse(part) : c.writeValue(part)).then(next);
    };
    return next();
  }).catch(function (e) { _btChar = null; say(e && e.name === 'NotFoundError' ? 'No printer chosen.' : 'Could not print: ' + (e.message || e)); return false; });
}
