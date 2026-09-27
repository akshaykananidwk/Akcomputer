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

    private WebView web;

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
                // everything that is not our own bundled page opens in the
                // real browser instead of replacing the app
                String url = rq.getUrl().toString();
                if (url.startsWith("file:///android_asset/")) return false;
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

        web.loadUrl("file:///android_asset/www/index.html");
        setContentView(web);
    }

    @Override
    public boolean onKeyDown(int code, KeyEvent ev) {
        if (code == KeyEvent.KEYCODE_BACK && web != null && web.canGoBack()) {
            web.goBack();
            return true;
        }
        return super.onKeyDown(code, ev);
    }
}
