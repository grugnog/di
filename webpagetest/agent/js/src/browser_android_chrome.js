/******************************************************************************
Copyright (c) 2012, Google Inc.
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice,
      this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.
    * Neither the name of Google, Inc. nor the names of its contributors
      may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
******************************************************************************/

var adb = require('adb');
var browser_base = require('browser_base');
var fs = require('fs');
var logger = require('logger');
var packet_capture_android = require('packet_capture_android');
var path = require('path');
var process_utils = require('process_utils');
var util = require('util');
var video_hdmi = require('video_hdmi');
var webdriver = require('selenium-webdriver');
var webdriver_proxy = require('selenium-webdriver/proxy');

var DEVTOOLS_SOCKET = 'localabstract:chrome_devtools_remote';
var PAC_PORT = 80;

var CHROME_FLAGS = [
    // Stabilize Chrome performance.
    '--disable-fre', '--enable-benchmarking', '--metrics-recording-only',
    // Suppress location JS API to avoid a location prompt obscuring the page.
    '--disable-geolocation'
  ];


/**
 * Constructs a Chrome Mobile controller for Android.
 *
 * @param {webdriver.promise.ControlFlow} app the ControlFlow for scheduling.
 * @param {Object.<string>} args browser options with string values:
 *     runNumber test run number. Install the apk on run 1.
 *     deviceSerial the device to drive.
 *     [runTempDir] the directory to store per-run files like screenshots,
 *         defaults to ''.
 *     [chrome] Chrome.apk to install, defaults to None.
 *     [devToolsPort] DevTools port, defaults to dynamic selection.
 *     [pac] PAC content, defaults to None.
 *     [captureDir] capture script dir, defaults to ''.
 *     [videoCard] the video card identifier, defaults to None.
 *     [chromePackage] package, defaults to
 *         'com.google.android.apps.chrome_dev'.
 *     [chromeActivity] activity without the '.Main' suffix, defaults to
 *         'com.google.android.apps.chrome'.
 * @constructor
 */
function BrowserAndroidChrome(app, args) {
  'use strict';
  browser_base.BrowserBase.call(this, app);
  logger.info('BrowserAndroidChrome(%j)', args);
  if (!args.deviceSerial) {
    throw new Error('Missing deviceSerial');
  }
  this.deviceSerial_ = args.deviceSerial;
  this.shouldInstall_ = (1 === parseInt(args.runNumber || '1', 10));
  this.chrome_ = args.chrome;  // Chrome.apk.
  this.chromedriver_ = args.chromedriver;
  this.chromePackage_ = args.chromePackage || 'com.android.chrome';
  this.chromeActivity_ =
      args.chromeActivity || 'com.google.android.apps.chrome';
  this.devToolsPort_ = args.devToolsPort;
  this.devtoolsPortLock_ = undefined;
  this.devToolsUrl_ = undefined;
  this.serverPort_ = args.serverPort;
  this.serverPortLock_ = undefined;
  this.serverUrl_ = undefined;
  this.pac_ = args.pac;
  this.pacFile_ = undefined;
  this.pacServer_ = undefined;
  this.videoCard_ = args.videoCard;
  function toDir(s) {
    return (s ? (s[s.length - 1] === '/' ? s : s + '/') : '');
  }
  var captureDir = toDir(args.captureDir);
  this.adb_ = new adb.Adb(this.app_, this.deviceSerial_);
  this.video_ = new video_hdmi.VideoHdmi(this.app_, captureDir + 'capture');
  this.pcap_ = new packet_capture_android.PacketCaptureAndroid(this.app_, args);
  this.runTempDir_ = args.runTempDir || '';
  this.useXvfb_ = undefined;
}
util.inherits(BrowserAndroidChrome, browser_base.BrowserBase);
/** Public class. */
exports.BrowserAndroidChrome = BrowserAndroidChrome;

/**
 * Start chromedriver, 2.x required.
 *
 * @param {Object} browserCaps capabilities to be passed to Builder.build():
 *    #param {string} browserName must be 'chrome'.
 */
BrowserAndroidChrome.prototype.startWdServer = function(browserCaps) {
  'use strict';
  var requestedBrowserName = browserCaps[webdriver.Capability.BROWSER_NAME];
  if (webdriver.Browser.CHROME !== requestedBrowserName) {
    throw new Error('BrowserLocalChrome called with unexpected browser ' +
        requestedBrowserName);
  }
  if (!this.chromedriver_) {
    throw new Error('Must set chromedriver before starting it');
  }
  browserCaps.chromeOptions = {
    args: CHROME_FLAGS.slice(),
    androidPackage: this.chromePackage_,
    androidDeviceSerial: this.deviceSerial_
  };
  if (this.pac_) {
    if (PAC_PORT !== 80) {
      logger.warn('Non-standard PAC port might not work: ' + PAC_PORT);
      browserCaps.chromeOptions.args.push(
          '--explicitly-allowed-ports=' + PAC_PORT);
    }
    browserCaps[webdriver.Capability.PROXY] = webdriver_proxy.pac(
        'http://127.0.0.1:' + PAC_PORT + '/from_netcat');
  }
  this.kill();
  this.scheduleInstallIfNeeded_();
  this.scheduleConfigureServerPort_();
  // Must be scheduled, since serverPort_ is assigned in a scheduled function.
  this.scheduleNeedsXvfb_().then(function(useXvfb) {
    var cmd = this.chromedriver_;
    var args = ['-port=' + this.serverPort_];
    if (logger.isLogging('extra')) {
      args.push('--verbose');
    }
    if (useXvfb) {
      // Use a fake X display, otherwise a scripted "sendKeys" fails with:
      //   an X display is required for keycode conversions, consider using Xvfb
      // TODO(wrightt) submit a crbug; Android shouldn't use the host's keymap!
      args.splice(0, 0, '-a', cmd);
      cmd = 'xvfb-run';
    }
    this.startChildProcess(cmd, args, 'WD server');
    // Make sure we set serverUrl_ only after the child process start success.
    this.app_.schedule('Set DevTools URL', function() {
      this.serverUrl_ = 'http://localhost:' + this.serverPort_;
    }.bind(this));
  }.bind(this));
};

/**
 * Launches the browser with about:blank, enables DevTools.
 */
BrowserAndroidChrome.prototype.startBrowser = function() {
  'use strict';
  // Stop Chrome at the start of each run.
  // TODO(wrightt): could keep the devToolsPort and pacServer up
  this.kill();
  this.scheduleInstallIfNeeded_();
  this.scheduleStartPacServer_();
  this.scheduleSetStartupFlags_();
  // Delete the prior run's tab(s) and start with "about:blank".
  //
  // If we only set "-d about:blank", Chrome will create a new tab.
  // If we only remove the tab files, Chrome will load the
  //   "Mobile bookmarks" page
  //
  // We also tried a Page.navigate to "data:text/html;charset=utf-8,", which
  // helped but was insufficient by itself.
  this.adb_.su(['rm',
      '/data/data/' + this.chromePackage_ + '/files/tab*']);
  // Flush the DNS cache
  this.adb_.su(['ndc', 'resolver', 'flushdefaultif']);
  var activity = this.chromePackage_ + '/' + this.chromeActivity_ + '.Main';
  this.adb_.shell(['am', 'start', '-n', activity, '-d', 'about:blank']);
  // TODO(wrightt): check start error
  this.scheduleConfigureDevToolsPort_();
};

/**
 * Callback when the child chromedriver process exits.
 * @override
 */
BrowserAndroidChrome.prototype.onChildProcessExit = function() {
  'use strict';
  logger.info('chromedriver exited, resetting WD server URL');
  this.serverUrl_ = undefined;
};

/**
 * Installs Chrome apk if this is the first run, and the apk was provided.
 * @private
 */
BrowserAndroidChrome.prototype.scheduleInstallIfNeeded_ = function() {
  'use strict';
  if (this.shouldInstall_ && this.chrome_) {
    // Explicitly uninstall, as "install -r" fails if the installed package
    // was signed differently than the new apk being installed.
    this.adb_.adb(['uninstall', this.chromePackage_]).addErrback(function() {
      logger.debug('Ignoring failed uninstall');
    }.bind(this));
    // Chrome install on an emulator takes a looong time.
    this.adb_.adb(['install', '-r', this.chrome_], {}, /*timeout=*/120000);
  }
  // TODO(wrightt): use `pm list packages` to check pkg
};

/**
 * Test if we have a host-side display.
 *
 * @return {webdriver.promise.Promise} resolve({boolean} useXvfb).
 * @private
 */
BrowserAndroidChrome.prototype.scheduleNeedsXvfb_ = function() {
  'use strict';
  if (undefined === this.useXvfb_) {
    if (process.platform !== 'linux') {
      this.useXvfb_ = false;
    } else {
      process_utils.scheduleExec(this.app_, 'xset', ['q']).then(
          function() {
        this.useXvfb_ = false;
      }.bind(this), function(e) {
        this.useXvfb_ = true;
        if (!(/unable to open|no such file/i).test(e.message)) {
          throw e;
        }
      }.bind(this));
    }
  }
  return this.app_.schedule('needsXvfb', function() {
    return this.useXvfb_;
  }.bind(this));
};

/**
 * Sets the Chrome command-line flags.
 *
 * @private
 */
BrowserAndroidChrome.prototype.scheduleSetStartupFlags_ = function() {
  'use strict';
  var flagsFile = '/data/local/chrome-command-line';
  var flags = CHROME_FLAGS.concat('--enable-remote-debugging');
  if (this.pac_) {
    flags.push('--proxy-pac-url=http://127.0.0.1:' + PAC_PORT + '/from_netcat');
    if (PAC_PORT !== 80) {
      logger.warn('Non-standard PAC port might not work: ' + PAC_PORT);
      flags.push('--explicitly-allowed-ports=' + PAC_PORT);
    }
  }
  this.adb_.su(['echo \\"chrome ' + flags.join(' ') + '\\" > ' + flagsFile]);
};

/**
 * Selects the chromedriver port.
 *
 * @private
 */
BrowserAndroidChrome.prototype.scheduleConfigureServerPort_ = function() {
  'use strict';
  this.app_.schedule('Configure WD port', function() {
    if (this.serverPort_) {
      return;
    }
    process_utils.scheduleAllocatePort(this.app_, 'Select WD port').then(
        function(alloc) {
      logger.debug('Selected WD port ' + alloc.port);
      this.serverPortLock_ = alloc;
      this.serverPort_ = alloc.port;
    }.bind(this));
  }.bind(this));
};

/**
 * Releases the DevTools port.
 *
 * @private
 */
BrowserAndroidChrome.prototype.releaseServerPortIfNeeded_ = function() {
  'use strict';
  if (this.serverPortLock_) {
    logger.debug('Releasing WD port ' + this.serverPort_);
    this.serverPort_ = undefined;
    this.serverPortLock_.release();
    this.serverPortLock_ = undefined;
  }
};

/**
 * Selects the DevTools port.
 *
 * @private
 */
BrowserAndroidChrome.prototype.scheduleConfigureDevToolsPort_ = function() {
  'use strict';
  this.app_.schedule('Configure DevTools port', function() {
    if (!this.devToolsPort_) {
      process_utils.scheduleAllocatePort(this.app_, 'Select DevTools port')
          .then(function(alloc) {
        logger.debug('Selected DevTools port ' + alloc.port);
        this.devtoolsPortLock_ = alloc;
        this.devToolsPort_ = alloc.port;
      }.bind(this));
    }
    // The following must be done even if devToolsPort_ is fixed, not allocated.
    if (!this.devToolsUrl_) {
      // The adb call must be scheduled, because devToolsPort_ is only assigned
      // when the above scheduled port allocation completes.
      this.app_.schedule('Forward DevTools socket to local port', function() {
        this.adb_.adb(
            ['forward', 'tcp:' + this.devToolsPort_, DEVTOOLS_SOCKET]);
      }.bind(this));
      // Make sure we set devToolsUrl_ only after the adb forward succeeds.
      this.app_.schedule('Set DevTools URL', function() {
        this.devToolsUrl_ = 'http://localhost:' + this.devToolsPort_ + '/json';
      }.bind(this));
    }
  }.bind(this));
};

/**
 * Releases the DevTools port.
 *
 * @private
 */
BrowserAndroidChrome.prototype.releaseDevToolsPortIfNeeded_ = function() {
  'use strict';
  if (this.devtoolsPortLock_) {
    logger.debug('Releasing DevTools port ' + this.devToolsPort_);
    this.devToolsPort_ = undefined;
    this.devtoolsPortLock_.release();
    this.devtoolsPortLock_ = undefined;
  }
};

/**
 * Starts the PAC server.
 *
 * @private
 */
BrowserAndroidChrome.prototype.scheduleStartPacServer_ = function() {
  'use strict';
  if (!this.pac_) {
    return;
  }
  // We use netcat to serve the PAC HTTP from on the device:
  //   adb shell ... nc -l PAC_PORT < pacFile
  // Several other options were considered:
  //   1) 'data://' + base64-encoded-pac isn't supported
  //   2) 'file://' + path-on-device isn't supported
  //   3) 'http://' + desktop http.createServer assumes a route from the
  //       device to the desktop, which won't work in general
  //
  // We copy our HTTP response to the device as a "pacFile".  Ideally we'd
  // avoid this temp file, but the following alternatives don't work:
  //   a) `echo RESPONSE | nc -l PAC_PORT` doesn't close the socket
  //   b) `cat <<EOF | nc -l PAC_PORT\nRESPONSE\nEOF` can't create a temp
  //      file; see http://stackoverflow.com/questions/15283220
  //
  // We must use port 80, otherwise Chrome silently blocks the PAC.
  // This can be seen by visiting the proxy URL on the device, which displays:
  //   Error 312 (net::ERR_UNSAFE_PORT): Unknown error.
  //
  // Lastly, to verify that the proxy was set, visit:
  //   chrome://net-internals/proxyservice.config#proxy
  var localPac = this.deviceSerial_ + '.pac_body';
  this.pacFile_ = '/data/local/tmp/pac_body';
  var response = 'HTTP/1.1 200 OK\n' +
      'Content-Length: ' + this.pac_.length + '\n' +
      'Content-Type: application/x-ns-proxy-autoconfig\n' +
      '\n' + this.pac_;
  process_utils.scheduleFunction(this.app_, 'Write local PAC file',
      fs.writeFile, localPac, response);
  this.adb_.adb(['push', localPac, this.pacFile_]);
  // Start netcat server
  logger.debug('Starting pacServer on device port %s', PAC_PORT);
  this.adb_.spawnSu(['while true; do nc -l ' + PAC_PORT + ' < ' +
       this.pacFile_ + '; done']).then(function(proc) {
    this.pacServer_ = proc;
    proc.on('exit', function(code) {
      if (this.pacServer_) {
        logger.error('Unexpected pacServer exit: ' + code);
        this.pacServer_ = undefined;
      }
    }.bind(this));
  }.bind(this));
};

/**
 * Stops the PAC server.
 *
 * @private
 */
BrowserAndroidChrome.prototype.stopPacServerIfNeeded_ = function() {
  'use strict';
  if (this.pacServer_) {
    var proc = this.pacServer_;
    this.pacServer_ = undefined;
    process_utils.scheduleKillTree(this.app_, 'Kill PAC server', proc);
  }
  if (this.pacFile_) {
    var file = this.pacFile_;
    this.pacFile_ = undefined;
    this.adb_.shell(['rm', file]);
  }
};

/**
 * Kills the browser and cleans up.
 */
BrowserAndroidChrome.prototype.kill = function() {
  'use strict';
  this.killChildProcessIfNeeded();
  this.devToolsUrl_ = undefined;
  this.serverUrl_ = undefined;
  this.releaseDevToolsPortIfNeeded_();
  this.releaseServerPortIfNeeded_();
  this.stopPacServerIfNeeded_();
  this.adb_.shell(['am', 'force-stop', this.chromePackage_]);
};

/**
 * @return {boolean}
 */
BrowserAndroidChrome.prototype.isRunning = function() {
  'use strict';
  return browser_base.BrowserBase.prototype.isRunning.call(this) ||
      !!this.devToolsUrl_ || !!this.serverUrl_;
};

/**
 * @return {string} WebDriver URL.
 */
BrowserAndroidChrome.prototype.getServerUrl = function() {
  'use strict';
  return this.serverUrl_;
};

/**
 * @return {string} DevTools URL.
 */
BrowserAndroidChrome.prototype.getDevToolsUrl = function() {
  'use strict';
  return this.devToolsUrl_;
};

/**
 * @return {Object.<string>} browser capabilities.
 */
BrowserAndroidChrome.prototype.scheduleGetCapabilities = function() {
  'use strict';
  return this.video_.scheduleIsSupported().then(function(isSupported) {
    return {
        webdriver: false,
        'wkrdp.Page.captureScreenshot': false, // TODO(klm): check before-26.
        'wkrdp.Network.clearBrowserCache': true,
        'wkrdp.Network.clearBrowserCookies': true,
        videoRecording: isSupported,
        takeScreenshot: true
      };
  }.bind(this));
};

/**
 * @param {string} fileNameNoExt filename without the '.png' suffix.
 * @return {webdriver.promise.Promise} resolve(diskPath) of the written file.
 */
BrowserAndroidChrome.prototype.scheduleTakeScreenshot =
    function(fileNameNoExt) {
  'use strict';
  return this.adb_.getStoragePath().then(function(storagePath) {
    var localPath = path.join(this.runTempDir_, fileNameNoExt + '.png');
    var devicePath = storagePath + '/wpt_screenshot.png';
    this.adb_.shell(['screencap', '-p', devicePath]);
    return this.adb_.adb(['pull', devicePath, localPath]).then(function() {
      return localPath;
    }.bind(this));
  }.bind(this));
};

/**
 * @param {string} filename The local filename to write to.
 * @param {Function=} onExit Optional exit callback, as noted in video_hdmi.
 */
BrowserAndroidChrome.prototype.scheduleStartVideoRecording = function(
    filename, onExit) {
  'use strict';
  // The video record command needs to know device type for cropping etc.
  this.adb_.shell(['getprop', 'ro.product.device']).then(
      function(stdout) {
    this.video_.scheduleStartVideoRecording(filename, this.deviceSerial_,
        stdout.trim(), this.videoCard_, onExit);
  }.bind(this));
};

/**
 * Stops the video recording.
 */
BrowserAndroidChrome.prototype.scheduleStopVideoRecording = function() {
  'use strict';
  this.video_.scheduleStopVideoRecording();
};

/**
 * Starts packet capture.
 *
 * @param {string} filename  local file where to copy the pcap result.
 */
BrowserAndroidChrome.prototype.scheduleStartPacketCapture = function(filename) {
  'use strict';
  this.pcap_.scheduleStart(filename);
};

/**
 * Stops packet capture and copies the result to a local file.
 */
BrowserAndroidChrome.prototype.scheduleStopPacketCapture = function() {
  'use strict';
  this.pcap_.scheduleStop();
};
