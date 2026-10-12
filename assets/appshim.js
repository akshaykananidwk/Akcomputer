// Inside the phone app (an Android WebView), a few things a browser does by
// itself are missing: the printer dialog, voice typing, Web Bluetooth, saving
// a file made in the page. The app lends its own versions through window.AKApp
// and this file plugs them in where the website already calls the browser's,
// so no screen has to know whether it is in Chrome or in the app.
//
// Loaded only when the page is inside the app (header.php checks the app's
// user agent; the app also adds it to pages that have no header).
(function () {
  if (window.__akshim || !window.AKApp) return;
  window.__akshim = 1;

  // --- voice typing: the website's 🎤 buttons use SpeechRecognition. A WebView
  // HAS that object but it never hears anything, so it is replaced outright ----
  if (AKApp.canListen && AKApp.canListen()) {
    var live = {};
    var SR = function () { this.lang = 'en-IN'; this.interimResults = false; this.maxAlternatives = 1; };
    SR.prototype.start = function () {
      var id = 'sr' + Date.now() + Math.random().toString(36).slice(2, 6);
      live[id] = this;
      AKApp.listen(id, this.lang || '');
    };
    SR.prototype.stop = SR.prototype.abort = function () {};
    window.__akSpeech = function (id, text) {
      var r = live[id]; delete live[id];
      if (!r) return;
      if (text && r.onresult) r.onresult({ resultIndex: 0, results: [[{ transcript: text, confidence: 1 }]] });
      else if (!text && r.onerror) r.onerror({ error: 'no-speech' });
      if (r.onend) r.onend();
    };
    window.webkitSpeechRecognition = window.SpeechRecognition = SR;
  }

  // --- print: Android's own print screen (PDF or any installed printer) ------
  window.print = function () { AKApp.print(document.title || 'Print'); };

  // --- a new tab: there are no tabs here; open it in place (a download stays a
  // download - the page does not move for one) -------------------------------
  window.open = function (url) {
    if (url) location.href = new URL(url, location.href).href;
    return null;
  };

  // --- a file the page made itself (a CSV export): hand it to the phone ------
  var saveBlob = function (a) {
    fetch(a.href).then(function (r) { return r.blob(); }).then(function (b) {
      var fr = new FileReader();
      fr.onload = function () {
        AKApp.saveFile(a.getAttribute('download') || 'file', b.type || 'application/octet-stream', String(fr.result).split(',')[1] || '');
      };
      fr.readAsDataURL(b);
    });
  };
  var isMade = function (a) { return a && /^(blob|data):/.test(a.getAttribute('href') || ''); };
  var click = HTMLAnchorElement.prototype.click;
  HTMLAnchorElement.prototype.click = function () { if (isMade(this)) { saveBlob(this); return; } return click.apply(this, arguments); };
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (isMade(a)) { e.preventDefault(); saveBlob(a); return; }
    // logging out of the website from inside the app means "back to the app"
    if (a && /(^|\/)logout\.php/.test(a.getAttribute('href') || '')) { e.preventDefault(); AKApp.home(); }
  }, true);

  var late = function () {
    // --- a Bluetooth receipt printer: the phone's own Bluetooth, which also
    // reaches the classic (non-BLE) printers Web Bluetooth never could --------
    if (typeof window.btPrint === 'function' && typeof window.btBytes === 'function' && AKApp.btPrint) {
      window.btPrint = function (text, say, drawer) {
        say = say || function () {};
        var bytes = btBytes(text, drawer), s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        say('Printing…');
        var res = String(AKApp.btPrint(btoa(s)));
        say(res);
        return Promise.resolve(res.charAt(0) === '✔');
      };
    }
    // --- the way back to the app, where the website's menu button is ---------
    var bar = document.querySelector('.topbar');
    if (bar && !document.getElementById('akHome')) {
      var b = document.createElement('button');
      b.id = 'akHome'; b.type = 'button'; b.className = 'menu-btn no-print';
      b.setAttribute('aria-label', 'Back to the app'); b.title = 'Back to the app';
      b.textContent = '⌂';
      b.style.fontSize = '22px';
      b.addEventListener('click', function () { AKApp.home(); });
      bar.insertBefore(b, bar.firstChild);
    }
  };
  // the app adds this file to some pages only after they have loaded
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', late); else late();
})();
