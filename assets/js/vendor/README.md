# assets/js/vendor/

Third-party scripts served from this project instead of from a CDN.

## jsQR.js (QR decoding, used by Admin -> Scan QR)

The scanner tries this folder **first** and only falls back to a CDN if the
file is not here. Saving it makes the scanner work with no internet at all,
which matters if the marking machine is offline or the network blocks CDNs.

### Download it

PowerShell, run from the project root:

```powershell
mkdir assets\js\vendor -Force
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" -OutFile "assets\js\vendor\jsQR.js"
```

Or just open <https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js> in a browser,
press Ctrl+S, and save it here as `jsQR.js`.

### Check it worked

The file should be roughly 80-100 KB and start with a line like
`(function (global, factory) {`. Then reload **Admin -> Scan QR**: the
warning box disappears and the *Start Camera* button becomes clickable.

jsQR is MIT licensed. Source: <https://github.com/cozmo/jsQR>
