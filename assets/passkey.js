// 👆 Fingerprint / face login in the browser (WebAuthn). The phone asks for
// the finger or face itself; only a signature comes back to the shop.
var Passkey = {
  b64u: function (buf) { return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); },
  unb64u: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; return Uint8Array.from(atob(s), function (c) { return c.charCodeAt(0); }); },
  post: function (data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('webauthn.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  },
  supported: function () { return !!(window.PublicKeyCredential && navigator.credentials); },
  register: function (name, csrf) {
    var self = this;
    return fetch('webauthn.php?do=reg_options', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (o) {
      return navigator.credentials.create({ publicKey: {
        challenge: self.unb64u(o.challenge), rp: o.rp,
        user: { id: self.unb64u(o.user.id), name: o.user.name, displayName: o.user.displayName },
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
        authenticatorSelection: { userVerification: 'required', residentKey: 'preferred' }, timeout: 60000, attestation: 'none',
        excludeCredentials: o.exclude.map(function (id) { return { type: 'public-key', id: self.unb64u(id) }; })
      } });
    }).then(function (c) {
      return self.post({ do: 'reg_verify', csrf: csrf, name: name, attestation: self.b64u(c.response.attestationObject), client: self.b64u(c.response.clientDataJSON) });
    });
  },
  login: function (username, csrf) {
    var self = this;
    return fetch('webauthn.php?do=login_options&u=' + encodeURIComponent(username), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (o) {
      if (!o.ok) throw new Error(o.msg);
      return navigator.credentials.get({ publicKey: { challenge: self.unb64u(o.challenge), rpId: o.rpId, userVerification: 'required', timeout: 60000,
        allowCredentials: o.allow.map(function (id) { return { type: 'public-key', id: self.unb64u(id) }; }) } });
    }).then(function (a) {
      return self.post({ do: 'login_verify', csrf: csrf, id: self.b64u(a.rawId), authData: self.b64u(a.response.authenticatorData),
                         client: self.b64u(a.response.clientDataJSON), signature: self.b64u(a.response.signature) });
    });
  }
};
