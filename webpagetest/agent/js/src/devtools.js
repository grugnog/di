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
/*jslint nomen:false */

var events = require('events');
var http = require('http');
var url = require('url');
var util = require('util');
var logger = require('logger');
var WebSocket = require('ws');

exports.PREFIX_NETWORK = 'Network.';
exports.PREFIX_PAGE = 'Page.';
exports.PREFIX_TIMELINE = 'Timeline.';


function processResponse(response, callback) {
  'use strict';
  var responseBody = '';
  response.setEncoding('utf8');
  response.on('data', function(chunk) {
    responseBody += chunk;
  });
  response.on('end', function() {
    logger.extra('Got response: ' + responseBody);
    if (callback) {
      callback(responseBody);
    }
  });
  response.on('error', function() {
    throw new Error('Bad HTTP response: ' + JSON.stringify(response));
  });
}
exports.ProcessResponse = processResponse;

function DevTools(devToolsUrl) {
  'use strict';
  this.devToolsUrl_ = devToolsUrl;
  this.debuggerUrl_ = undefined;
  this.ws_ = undefined;
  this.commandId_ = 0;
  this.commandCallbacks_ = {};
}
util.inherits(DevTools, events.EventEmitter);
exports.DevTools = DevTools;

DevTools.prototype.connect = function() {
  'use strict';
  var self = this;  // For closure
  http.get(url.parse(self.devToolsUrl_), function(response) {
    exports.ProcessResponse(response, function(responseBody) {
      var devToolsJson = JSON.parse(responseBody);
      try {
        self.debuggerUrl_ = devToolsJson[0].webSocketDebuggerUrl;
      } catch (e) {
        throw new Error('DevTools response at ' + self.devToolsUrl_ +
            ' does not contain webSocketDebuggerUrl: ' + responseBody);
      }
      self.connectDebugger_();
    });
  });
};

DevTools.prototype.connectDebugger_ = function() {
  'use strict';
  var self = this;  // For closure
  // TODO(klm): do we actually need origin?
  var ws = new WebSocket(this.debuggerUrl_, {'origin': 'WebPageTest'});

  ws.on('error', function(e) {
    throw e;
  });

  ws.on('open', function() {
    logger.extra('WebSocket connected: ' + JSON.stringify(ws));
    self.ws_ = ws;
    self.emit('connect');
  });

  ws.on('message', function(data, flags) {
    // flags.binary will be set if a binary data is received
    // flags.masked will be set if the data was masked
    var callbackErrback;
    if (!flags.binary) {
      var messageJson = JSON.parse(data);
      if (messageJson.result && messageJson.id) {
        callbackErrback = self.commandCallbacks_[messageJson.id];
        if (callbackErrback) {
          delete self.commandCallbacks_[messageJson.id];
          if (callbackErrback.callback) {
            callbackErrback.callback(messageJson.result);
          }
        }
        self.emit('result', messageJson.id, messageJson.result);
      } else if (messageJson.error && messageJson.id) {
        callbackErrback = self.commandCallbacks_[messageJson.id];
        if (callbackErrback) {
          delete self.commandCallbacks_[messageJson.id];
          if (callbackErrback.errback) {
            callbackErrback.errback(messageJson.result);
          }
        }
        //self.emit('error', messageJson.id, messageJson.error);
      } else {
        self.emit('message', messageJson);
      }
    } else {
      throw new Error('Unexpected binary WebSocket message');
    }
  });
};

DevTools.prototype.command = function(command, callback, errback) {
  'use strict';
  this.commandId_ += 1;
  command.id = this.commandId_;
  if (callback || errback) {
    this.commandCallbacks_[command.id] = {
        callback: callback,
        errback: errback
    };
  }
  this.ws_.send(JSON.stringify(command));
  return command.id;
};

DevTools.prototype.networkMethod = function(method, callback, errback) {
  'use strict';
  this.command({method: exports.PREFIX_NETWORK + method}, callback, errback);
};

DevTools.prototype.pageMethod = function(method, callback, errback) {
  'use strict';
  this.command({method: exports.PREFIX_PAGE + method}, callback, errback);
};

DevTools.prototype.timelineMethod = function(method, callback, errback) {
  'use strict';
  this.command({method: exports.PREFIX_TIMELINE + method}, callback, errback);
};

exports.isNetworkMessage = function(message) {
  'use strict';
  return (message.method &&
      message.method.slice(0, exports.PREFIX_NETWORK.length) ===
          exports.PREFIX_NETWORK);
};

exports.isPageMessage = function(message) {
  'use strict';
  return (message.method &&
      message.method.slice(0, exports.PREFIX_PAGE.length) ===
          exports.PREFIX_PAGE);
};

exports.isTimelineMessage = function(message) {
  'use strict';
  return (message.method &&
      message.method.slice(0, exports.PREFIX_TIMELINE.length) ===
          exports.PREFIX_TIMELINE);
};
