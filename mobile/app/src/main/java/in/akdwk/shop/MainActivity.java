package in.akdwk.shop;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.bluetooth.BluetoothAdapter;
import android.bluetooth.BluetoothDevice;
import android.bluetooth.BluetoothSocket;
import android.content.ClipData;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.speech.RecognizerIntent;
import android.util.Base64;
import android.view.KeyEvent;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import java.io.OutputStream;
import java.util.ArrayList;
import java.util.Set;
import java.util.UUID;

/**
 * The whole app is the web page in assets/www — HTML, CSS and JavaScript that
 * live INSIDE the apk. Nothing is downloaded to open a screen, so the app
 * starts and works with the phone in flight mode.
 *
 * This class does what a web page cannot do for itself:
 *   - hold the page in a WebView with its own storage (IndexedDB) turned on,
 *     which is where every unsent bill is sitting,
 *   - make the phone's Back button go back a screen, not shut the app,
 *   - open the shop's own website screens inside the app, already logged in,
 *     and give THOSE pages what Chrome would have given them: the confirm
 *     boxes, choosing a photo or taking one, downloads, printing, the camera
 *     and microphone, location, voice typing and a Bluetooth receipt printer.
 *     Without these a website screen opens in the app and then quietly does
 *     nothing when its Delete button asks "are you sure?".
 */
public class MainActivity extends Activity {

    private static final String HOME = "file:///android_asset/www/index.html";
    private static final int RC_FILE = 11, RC_SPEECH = 12, RC_PERMS = 13;
    /** Every Bluetooth serial printer answers on this one service id. */
    private static final UUID SPP = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB");

    private WebView web;

    /**
     * The shop's own web address, learned rather than compiled in. It is
     * learned from the one place it can be trusted: the handover link the app
     * itself asks the API for, which is always "<shop>/login.php?app=". From
     * then on that host's pages open inside the app and every other address
     * is handed to the phone.
     */
    private String shopHost = null;
    /** The last shop page asked for - reopened after a website session expires. */
    private String lastShopUrl = null;
    private long lastReopen = 0;
    /** What the page currently showing is (the JS bridge only serves the shop). */
    private volatile String pageUrl = "";

    private ValueCallback<Uri[]> fileCb;
    private Uri cameraUri;
    private PermissionRequest pendingWebPerm;
    private Runnable afterPerms;
    private String speechId;

    private void shopHostSeen(String host) {
        if (host != null && !host.isEmpty()) shopHost = host;
    }

    private boolean isShopUrl(String url) {
        if (url == null) return false;
        if (!url.startsWith("http://") && !url.startsWith("https://")) return false;
        Uri u = Uri.parse(url);
        String host = u.getHost();
        if (host == null) return false;
        if (shopHost != null && shopHost.equalsIgnoreCase(host)) return true;
        // the handover link itself - the only way a host becomes "the shop"
        String path = u.getPath() == null ? "" : u.getPath();
        return (path.endsWith("/login.php") || path.equals("login.php")) && u.getQueryParameter("app") != null;
    }

    /** The website's login page, reached without a handover key: its session ran out. */
    private boolean isBareLogin(String url) {
        if (!isShopUrl(url)) return false;
        Uri u = Uri.parse(url);
        String path = u.getPath() == null ? "" : u.getPath();
        return path.endsWith("/login.php") && u.getQueryParameter("app") == null;
    }

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);

        web = new WebView(this);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        // IndexedDB lives here. Without this the app forgets every bill the
        // moment it is closed.
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setGeolocationEnabled(true);
        // the page is ours and is bundled; nothing else may be loaded into it
        s.setAllowFileAccessFromFileURLs(false);
        s.setAllowUniversalAccessFromFileURLs(false);
        // the page is served from file://, the API over https - the WebView
        // blocks that mix by default
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        // the website lends its pages the app's printer, voice typing and
        // file saving when it sees this (helpers.php: in_phone_app())
        s.setUserAgentString(s.getUserAgentString() + " AKShopApp/" + BuildConfig.VERSION_NAME);

        web.addJavascriptInterface(new Bridge(), "AKApp");

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest rq) {
                String url = rq.getUrl().toString();
                // our own bundled page: the app itself
                if (url.startsWith("file:///android_asset/")) return false;
                if (isShopUrl(url)) {
                    shopHostSeen(rq.getUrl().getHost());
                    if (isBareLogin(url)) { reopenAfterExpiry(); return true; }
                    lastShopUrl = url;
                    return false;
                }
                openOutside(url);
                return true;
            }

            @Override
            public void onPageStarted(WebView v, String url, android.graphics.Bitmap icon) {
                pageUrl = url == null ? "" : url;
                // a redirect to the login page does not always pass through
                // shouldOverrideUrlLoading; catch it here too
                if (isBareLogin(url)) { v.stopLoading(); reopenAfterExpiry(); }
            }

            @Override
            public void onPageFinished(WebView v, String url) {
                pageUrl = url == null ? "" : url;
                // pages without the website's header (a receipt, a print view)
                // still get the app's printer and file saving
                if (isShopUrl(url)) {
                    v.evaluateJavascript("if(!window.__akshim){var s=document.createElement('script');s.src='"
                        + "assets/appshim.js?app=1';document.head&&document.head.appendChild(s);}", null);
                }
            }

            @Override
            public void onReceivedError(WebView v, WebResourceRequest rq, WebResourceError err) {
                // A failed API call is NOT a broken app: the page handles it
                // and queues the work. Never replace the screen with an
                // error page - that would look like the bill was lost.
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            // (alert / confirm / prompt: the default WebChromeClient shows the
            // phone's own dialog. With NO chrome client, as before, confirm()
            // silently answered "no" and every Delete button did nothing.)

            @Override
            public boolean onShowFileChooser(WebView v, ValueCallback<Uri[]> cb, FileChooserParams params) {
                if (fileCb != null) fileCb.onReceiveValue(null);
                fileCb = cb;
                final Intent pick = params.createIntent();
                pick.addCategory(Intent.CATEGORY_OPENABLE);
                if (params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE) pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                boolean wantsImage = false;
                for (String a : params.getAcceptTypes()) if (a != null && (a.startsWith("image") || a.isEmpty())) wantsImage = true;
                final boolean camera = wantsImage;
                withPerms(camera ? new String[]{Manifest.permission.CAMERA} : new String[0], () -> {
                    Intent chooser = Intent.createChooser(pick, "Choose a file");
                    if (camera && has(Manifest.permission.CAMERA)) {
                        // a bill photo is usually taken there and then
                        Intent shot = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
                        cameraUri = ShareProvider.uriFor(MainActivity.this, "photo_" + System.currentTimeMillis() + ".jpg");
                        shot.putExtra(MediaStore.EXTRA_OUTPUT, cameraUri);
                        shot.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION | Intent.FLAG_GRANT_READ_URI_PERMISSION);
                        chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{shot});
                    } else cameraUri = null;
                    try { startActivityForResult(chooser, RC_FILE); }
                    catch (Exception e) { fileCb.onReceiveValue(null); fileCb = null; }
                });
                return true;
            }

            /** The website's barcode scanner and voice notes: camera / microphone. */
            @Override
            public void onPermissionRequest(final PermissionRequest rq) {
                runOnUiThread(() -> {
                    if (!isShopUrl(rq.getOrigin().toString())) { rq.deny(); return; }
                    ArrayList<String> need = new ArrayList<>();
                    for (String r : rq.getResources()) {
                        if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) need.add(Manifest.permission.CAMERA);
                        if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r)) need.add(Manifest.permission.RECORD_AUDIO);
                    }
                    pendingWebPerm = rq;
                    withPerms(need.toArray(new String[0]), () -> {
                        boolean all = true;
                        for (String p : need) if (!has(p)) all = false;
                        if (all) rq.grant(rq.getResources()); else rq.deny();
                        pendingWebPerm = null;
                    });
                });
            }

            /** Attendance and deliveries record where they were done. */
            @Override
            public void onGeolocationPermissionsShowPrompt(final String origin, final GeolocationPermissions.Callback cb) {
                if (!isShopUrl(origin)) { cb.invoke(origin, false, false); return; }
                withPerms(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION},
                          () -> cb.invoke(origin, has(Manifest.permission.ACCESS_FINE_LOCATION) || has(Manifest.permission.ACCESS_COARSE_LOCATION), false));
            }
        });

        // A PDF bill, an Excel report, a GST JSON, a backup: saved to the
        // phone's Downloads with the website's own login cookie, so the file
        // is the real one and not a login page.
        web.setDownloadListener((url, userAgent, disposition, mime, length) -> {
            if (!isShopUrl(url)) { openOutside(url); return; }
            // Android 9 and older want permission to write into Downloads
            withPerms(Build.VERSION.SDK_INT < 29 ? new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE} : new String[0],
                      () -> download(url, userAgent, disposition, mime));
        });

        // the website sets cookies for the handover session; without this the
        // page opens and immediately asks to log in again
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);

        web.loadUrl(HOME);
        setContentView(web);
    }

    private void download(String url, String userAgent, String disposition, String mime) {
            try {
                String name = URLUtil.guessFileName(url, disposition, mime);
                DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
                r.addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url));
                r.addRequestHeader("User-Agent", userAgent);
                r.setMimeType(mime);
                r.setTitle(name);
                r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
                ((DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE)).enqueue(r);
                Toast.makeText(this, "Downloading " + name + " — it opens from the notification", Toast.LENGTH_LONG).show();
            } catch (Exception e) {
                Toast.makeText(this, "Could not download: " + e.getMessage(), Toast.LENGTH_LONG).show();
            }
    }

    /** Anything that is not the shop - WhatsApp, a payment link, a phone
     *  number, a stranger's site - is somebody else's job. */
    private void openOutside(String url) {
        try {
            Intent i = url.startsWith("intent:") ? Intent.parseUri(url, Intent.URI_INTENT_SCHEME)
                                                 : new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            startActivity(i);
        } catch (Exception e) {
            Toast.makeText(this, "No app on this phone opens that link", Toast.LENGTH_SHORT).show();
        }
    }

    /** The website's own session ended (idle logout, a password change).
     *  The app still holds its token, so it asks for a fresh handover and
     *  reopens the same page - once; a second expiry in a row goes home. */
    private void reopenAfterExpiry() {
        long now = System.currentTimeMillis();
        if (now - lastReopen < 30000 || lastShopUrl == null) { web.loadUrl(HOME); return; }
        lastReopen = now;
        Uri u = Uri.parse(lastShopUrl);
        String path = (u.getPath() == null ? "" : u.getPath().replaceFirst("^.*/", "")) + (u.getQuery() == null ? "" : "?" + u.getQuery());
        web.loadUrl(HOME + "#reopen=" + Uri.encode(path));
    }

    // --- permissions ---------------------------------------------------------
    private boolean has(String p) { return checkSelfPermission(p) == PackageManager.PERMISSION_GRANTED; }

    private void withPerms(String[] perms, Runnable then) {
        ArrayList<String> ask = new ArrayList<>();
        for (String p : perms) if (!has(p)) ask.add(p);
        if (ask.isEmpty()) { then.run(); return; }
        afterPerms = then;
        requestPermissions(ask.toArray(new String[0]), RC_PERMS);
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] perms, int[] res) {
        if (code == RC_PERMS && afterPerms != null) { Runnable r = afterPerms; afterPerms = null; r.run(); }
    }

    @Override
    protected void onActivityResult(int code, int result, Intent data) {
        if (code == RC_FILE && fileCb != null) {
            Uri[] out = null;
            if (result == RESULT_OK) {
                if (data != null && data.getClipData() != null) {
                    ClipData c = data.getClipData();
                    out = new Uri[c.getItemCount()];
                    for (int i = 0; i < out.length; i++) out[i] = c.getItemAt(i).getUri();
                } else if (data != null && data.getData() != null) {
                    out = new Uri[]{data.getData()};
                } else if (cameraUri != null) {
                    out = new Uri[]{cameraUri};      // the photo just taken
                }
            }
            fileCb.onReceiveValue(out);
            fileCb = null;
            return;
        }
        if (code == RC_SPEECH && speechId != null) {
            String text = "";
            if (result == RESULT_OK && data != null) {
                ArrayList<String> r = data.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS);
                if (r != null && !r.isEmpty()) text = r.get(0);
            }
            String id = speechId; speechId = null;
            web.evaluateJavascript("window.__akSpeech&&window.__akSpeech(" + jsStr(id) + "," + jsStr(text) + ")", null);
            return;
        }
        super.onActivityResult(code, result, data);
    }

    private static String jsStr(String s) {
        StringBuilder b = new StringBuilder("\"");
        for (char c : s.toCharArray()) {
            if (c == '"' || c == '\\') b.append('\\').append(c);
            else if (c < 0x20 || c == 0x2028 || c == 0x2029) b.append(String.format("\\u%04x", (int) c));
            else b.append(c);
        }
        return b.append('"').toString();
    }

    // --- what the website's pages may ask of the phone ------------------------
    /** Lent to the shop's own pages only: every call checks the page showing. */
    private class Bridge {
        private boolean ok() { return pageUrl.startsWith("file:///android_asset/") || isShopUrl(pageUrl); }

        @JavascriptInterface
        public void home() { runOnUiThread(() -> web.loadUrl(HOME)); }

        /** Android's own print screen: save as PDF, or any printer the phone knows. */
        @JavascriptInterface
        public void print(final String title) {
            if (!ok()) return;
            runOnUiThread(() -> {
                PrintManager pm = (PrintManager) getSystemService(Context.PRINT_SERVICE);
                String t = (title == null || title.isEmpty()) ? "Print" : title;
                pm.print(t, web.createPrintDocumentAdapter(t), new PrintAttributes.Builder().build());
            });
        }

        /** A file the page made itself (a CSV export): offered to share or save. */
        @JavascriptInterface
        public void saveFile(String name, String mime, String b64) {
            if (!ok()) return;
            try {
                String safe = name.replaceAll("[^A-Za-z0-9._ -]", "_");
                Uri uri = ShareProvider.uriFor(MainActivity.this, safe);
                try (OutputStream o = getContentResolver().openOutputStream(uri)) { o.write(Base64.decode(b64, Base64.DEFAULT)); }
                Intent send = new Intent(Intent.ACTION_SEND);
                send.setType(mime == null || mime.isEmpty() ? "application/octet-stream" : mime);
                send.putExtra(Intent.EXTRA_STREAM, uri);
                send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                runOnUiThread(() -> startActivity(Intent.createChooser(send, "Save or send " + safe)));
            } catch (Exception e) {
                runOnUiThread(() -> Toast.makeText(MainActivity.this, "Could not save the file", Toast.LENGTH_LONG).show());
            }
        }

        @JavascriptInterface
        public boolean canListen() {
            return !getPackageManager().queryIntentActivities(new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH), 0).isEmpty();
        }

        /** Voice typing through the phone's own speech service (WebView has none). */
        @JavascriptInterface
        public void listen(final String id, final String lang) {
            if (!ok()) return;
            runOnUiThread(() -> {
                speechId = id;
                Intent i = new Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH);
                i.putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM);
                if (lang != null && !lang.isEmpty()) i.putExtra(RecognizerIntent.EXTRA_LANGUAGE, lang);
                try { startActivityForResult(i, RC_SPEECH); }
                catch (Exception e) { onActivityResult(RC_SPEECH, RESULT_CANCELED, null); }
            });
        }

        /**
         * A receipt on a Bluetooth thermal printer, over the phone's classic
         * Bluetooth (the cheap 58/80mm printers are classic, which Chrome's
         * Web Bluetooth cannot reach at all). The printer must be paired once
         * in Android's Bluetooth settings; the first paired printer-looking
         * device is used and remembered. Returns a sentence for the page.
         */
        @JavascriptInterface
        public String btPrint(String b64) {
            if (!ok()) return "Not allowed here";
            if (Build.VERSION.SDK_INT >= 31 && !has(Manifest.permission.BLUETOOTH_CONNECT)) {
                runOnUiThread(() -> withPerms(new String[]{Manifest.permission.BLUETOOTH_CONNECT}, () -> {}));
                return "Allow Bluetooth for the app, then press print again";
            }
            BluetoothAdapter bt = BluetoothAdapter.getDefaultAdapter();
            if (bt == null) return "This phone has no Bluetooth";
            if (!bt.isEnabled()) return "Turn Bluetooth on, then press print again";
            SharedPreferences pref = getSharedPreferences("app", MODE_PRIVATE);
            String want = pref.getString("printer", "");
            BluetoothDevice dev = null;
            try {
                Set<BluetoothDevice> paired = bt.getBondedDevices();
                for (BluetoothDevice d : paired) if (d.getAddress().equals(want)) dev = d;
                if (dev == null) for (BluetoothDevice d : paired) {
                    int major = d.getBluetoothClass() == null ? 0 : d.getBluetoothClass().getMajorDeviceClass();
                    String n = d.getName() == null ? "" : d.getName().toLowerCase();
                    if (major == android.bluetooth.BluetoothClass.Device.Major.IMAGING
                        || n.contains("print") || n.contains("pos") || n.contains("rpp") || n.contains("mtp") || n.contains("bt-")) { dev = d; break; }
                }
                if (dev == null) return "Pair the printer once in the phone's Bluetooth settings, then press print again";
                bt.cancelDiscovery();
                try (BluetoothSocket sock = dev.createRfcommSocketToServiceRecord(SPP)) {
                    sock.connect();
                    OutputStream o = sock.getOutputStream();
                    o.write(Base64.decode(b64, Base64.DEFAULT));
                    o.flush();
                    Thread.sleep(400);   // let the printer take it all before the line drops
                }
                pref.edit().putString("printer", dev.getAddress()).apply();
                return "✔ Printed on " + dev.getName();
            } catch (SecurityException e) {
                return "Allow Bluetooth for the app, then press print again";
            } catch (Exception e) {
                return "The printer did not answer (" + (dev != null ? dev.getName() : "?") + "). Is it on?";
            }
        }
    }

    @Override
    public boolean onKeyDown(int code, KeyEvent ev) {
        if (code == KeyEvent.KEYCODE_BACK && web != null) {
            if (web.canGoBack()) { web.goBack(); return true; }
            // on a website page with nothing behind it: go back to the app
            // rather than shutting down - the owner pressed Back to leave the
            // report, not to leave the shop
            String url = web.getUrl();
            if (url != null && !url.startsWith("file:///android_asset/")) {
                web.loadUrl(HOME);
                return true;
            }
        }
        return super.onKeyDown(code, ev);
    }
}
