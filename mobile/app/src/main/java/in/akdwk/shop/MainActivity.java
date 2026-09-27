package in.akdwk.shop;

import android.annotation.SuppressLint;
import android.os.Build;
import android.os.Bundle;
import android.view.KeyEvent;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.app.Activity;

/**
 * The whole app is the web page in assets/www — HTML, CSS and JavaScript that
 * live INSIDE the apk. Nothing is downloaded to open a screen, so the app
 * starts and works with the phone in flight mode.
 *
 * This class exists to do four things a web page cannot do for itself:
 *   - hold the page in a WebView with its own storage (IndexedDB) turned on,
 *   - keep that storage alive across restarts, which is where every unsent
 *     bill is sitting,
 *   - make the phone's Back button behave (go back a screen, not shut the app
 *     on the first press — closing a half-written bill by accident is exactly
 *     the kind of loss this app is meant to prevent),
 *   - let the page reach the shop's server and nothing else.
 */
public class MainActivity extends Activity {

    private static final String HOME = "file:///android_asset/www/index.html";

    private WebView web;

    /**
     * The shop's own web address, learned rather than compiled in - the owner
     * types it at login and it is nobody's business but theirs.
     *
     * It is learned from the one place it can be trusted: the handover link
     * the app itself asks the API for, which is always "<shop>/login.php?app=".
     * That link can only have come from a server this app already holds a
     * token for. From then on that host's pages open inside the app and every
     * other address is handed to the phone.
     */
    private String shopHost = null;

    private void shopHostSeen(String host) {
        if (host != null && !host.isEmpty()) shopHost = host;
    }

    private boolean isShopUrl(String url) {
        if (url == null) return false;
        if (!url.startsWith("http://") && !url.startsWith("https://")) return false;
        android.net.Uri u = android.net.Uri.parse(url);
        String host = u.getHost();
        if (host == null) return false;
        if (shopHost != null && shopHost.equalsIgnoreCase(host)) return true;
        // the handover link itself - the only way a host becomes "the shop"
        String path = u.getPath() == null ? "" : u.getPath();
        return path.endsWith("/login.php") || path.equals("login.php")
               ? u.getQueryParameter("app") != null : false;
    }

    @SuppressLint("SetJavaScriptEnabled")
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
        // the page is ours and is bundled; nothing else may be loaded into it
        s.setAllowFileAccessFromFileURLs(false);
        s.setAllowUniversalAccessFromFileURLs(false);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            // the page is served from file://, the API over https - the
            // WebView blocks that mix by default
            s.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        }

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest rq) {
                String url = rq.getUrl().toString();
                // our own bundled page: the app itself
                if (url.startsWith("file:///android_asset/")) return false;
                // the shop's own website: the eighty screens the app does not
                // do itself open HERE, inside the app, already logged in, and
                // Back brings the owner home. Sending them to Chrome instead
                // would mean logging in again in a different browser.
                if (isShopUrl(url)) { shopHostSeen(rq.getUrl().getHost()); return false; }
                // anything else - WhatsApp, a payment link, a stranger's site -
                // is somebody else's job
                try {
                    startActivity(new android.content.Intent(android.content.Intent.ACTION_VIEW, rq.getUrl()));
                } catch (Exception ignored) { }
                return true;
            }

            @Override
            public void onReceivedError(WebView v, WebResourceRequest rq, WebResourceError err) {
                // A failed API call is NOT a broken app: the page handles it
                // and queues the work. Never replace the screen with an
                // error page - that would look like the bill was lost.
            }
        });

        // the website sets cookies for the handover session; without this the
        // page opens and immediately asks to log in again
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            android.webkit.CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);
        }
        android.webkit.CookieManager.getInstance().setAcceptCookie(true);

        web.loadUrl(HOME);
        setContentView(web);
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
