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

var child_process = require('child_process');
var fs = require('fs');
var logger = require('logger');
var system_commands = require('system_commands');
var webdriver = require('webdriver');


/**
 * Represents a process, as parsed from ps command output.
 *
 * @param  {String} psLine a line in the format: "<ppid> <pid> <command...>".
 *     See setSystemCommands for explanation why this specific order.
 */
exports.ProcessInfo = function(psLine) {
  'use strict';
  var splitLine = psLine.trim().split(/\s+/);
  this.ppid = splitLine.shift();
  this.pid = splitLine.shift();
  this.command = splitLine.join(' ');
};

function newProcessInfo(psLine) {
  'use strict';
  return new exports.ProcessInfo(psLine);
}

/**
 * Kills given processes.
 *
 * @param {ProcessInfo[]} processInfos an array of process info's to be killed.
 * @param {Function} [callback] what to call when done.
 */
exports.killProcesses = function(processInfos, callback) {
  'use strict';
  var queue = processInfos.slice();  // Copy, don't modify original.
  logger.debug('killProcesses: %j', queue);

  function processQueue() {
    var processInfo = queue.pop();
    if (processInfo) {
      logger.warn(
          'Killing PID %s Command: %s', processInfo.pid, processInfo.command);
      var command = system_commands.get('kill', [processInfo.pid]);
      child_process.exec(command, processQueue);
    } else {  // Done
      if (callback) {
        callback();
      }
    }
  }
  processQueue();
};

/**
 * Kills given processes and their children, recursively.
 *
 * @param {ProcessInfo[]} processInfos top processes to kill.
 * @param {Function} [callback] what to call when done.
 */
exports.killProcessTrees = function(processInfos, callback) {
  'use strict';
  var stack = processInfos.slice();  // Copy, don't change the original.
  var traversedProcessInfos = [];

  function processStack() {
    var processInfo = stack.pop();
    if (processInfo) {
      traversedProcessInfos.push(processInfo);
      try {
        var command = system_commands.get('find children', [processInfo.pid]);
        child_process.exec(command, function(error, stdout, stderr) {
          if (error && '' !== stderr) {
            logger.error(
                'Command "%s" failed: %s, stderr: %s', command, error, stderr);
          } else if ('' !== stdout) {
            var childProcessInfos =
                stdout.trim().split('\n').map(newProcessInfo);
            logger.debug('killProcessTrees children of %s: %j',
                processInfo.pid, childProcessInfos);
            stack.push.apply(stack, childProcessInfos);  // Push all
          } else {
            logger.debug('Process %s has no children', processInfo.pid);
          }
          processStack();
        });
      } catch (e) {
        // We get an Error on Windows because there is no 'find children',
        // see setSystemCommands(). Just keep going through the top ones.
        processStack();
      }
    } else {  // Stack is empty, we are done
      exports.killProcesses(traversedProcessInfos, callback);
    }
  }
  processStack();
};

/**
 * killDanglingProcesses will search for any processes of type selenium,
 * chromedriver, or wd_server and will call killChildTree on it to kill the
 * entire process tree before killing itself.
 */
exports.killDanglingProcesses = function(callback) {
  'use strict';
  // Find PIDs of any *orphaned* Java WD server and chromedriver processes
  var command = system_commands.get('dangling pids');
  child_process.exec(command, function(error, stdout, stderr) {
    logger.debug('dangling ps: %s', stdout);
    if (error && stderr) {  // An error with no stderr means we found nothing
      logger.error(
          'Command "%s" failed: %s, stderr: %s', command, error, stderr);
      callback();
    } else if ('' !== stdout) {
      var processInfos =
          stdout.trim().split('\n').map(newProcessInfo);
      exports.killProcessTrees(processInfos, callback);
    } else {
      logger.debug('No dangling processes found');
      callback();
    }
  });
};

/**
 * Schedule a command exec on the webdriver promise manager.
 *
 * @param {String} command the command to run.
 * @param {boolean} [requireZeroExit] if true, fail on nonzero exit code.
 * @return {webdriver.promise.Promise} scheduled promise.
 */
exports.scheduleExec = function(command, requireZeroExit) {
  'use strict';
  var app = webdriver.promise.Application.getInstance();
  return app.schedule(command, function() {
    var done = new webdriver.promise.Deferred();
    child_process.exec(command, function(e, stdout, stderr) {
      // child_process.exec passes e even on normal exit with non-zero code.
      if (e && (e.signal || (requireZeroExit && 0 !== e.code))) {
        logger.error('Command "%s" error %j, stderr: %s', command, e, stderr);
        done.reject(e, stdout, stderr);
      } else {
        done.resolve(stdout, stderr);
      }
    });
    return done.promise;
  });
};

/**
 * Waits for a running process to exit, or kills it after a given timeout.
 *
 * Waits for the child process to die by itself and resolves the promise.
 * If it doesn't, kills the process and rejects the promise.
 *
 * Sets up the process wait and the timeout countdown *immediately* --
 * i.e. when time comes for this scheduled operation to run, if the process
 * already exited or timed out, we would already know and not block.
 *
 * @param {ChildProcess} p the process.
 * @param {String} name the process name for logging.
 * @param {Number} timeout how long to wait before killing.
 * @return {!webdriver.promise.Promise} scheduled promise, rejects on timeout.
 */
exports.scheduleExitWaitOrKill = function(p, name, timeout) {
  'use strict';
  var exited = new webdriver.promise.Deferred();
  var exitTimerId = global.setTimeout(function() {
    exited.reject();
  }, timeout);
  p.on('exit', function(code, signal) {
    logger.info('%s exited with code %s signal %s', name, code, signal);
    // Exit event will fire even if we time out and kill the process,
    // in which case the promise is already rejected -- check for that.
    if (exited.isPending()) {
      exited.resolve(code, signal);
    }
  });
  exited.then(function(/*code, signal*/) {
    global.clearTimeout(exitTimerId);
  }, function() {
    logger.error('Timed out waiting for %s to exit, killing', name);
    try {
      p.kill();
    } catch (e) {
      logger.error('Error killing %s: %s', name, e);
    }
  });
  var app = webdriver.promise.Application.getInstance();
  return app.schedule('Wait for ' + name + ' exit', function() {
    return exited.promise;
  });
};

/**
 * Injects logging of processed tasks into a {!webdriver.promise.Application}.
 */
function injectWdAppLogging(appName, app) {
  'use strict';
  if (!app.isLoggingInjected) {
    var realGetNextTask = app.getNextTask_;
    app.getNextTask_ = function() {
      var task = realGetNextTask.apply(app, arguments);
      if (task) {
        logger.extra('%s: %s', appName, task.getDescription());
      } else {
        logger.extra('%s: no next task', appName);
      }
      return task;
    };

    var realSchedule = app.schedule;
    app.schedule = function(description) {
      logger.extra('%s: %s', appName, description);
      return realSchedule.apply(app, arguments);
    };

    app.isLoggingInjected = true;
  }
}
exports.injectWdAppLogging = injectWdAppLogging;

/**
 * Returns an errback that logs the error with the given description.
 *
 * Works very similar to a logging catch that drops the exception.
 * @param {String} description description string for logging.
 * @return {Function} the errback for use in Application.schedule.
 */
exports.getLoggingErrback = function(description) {
  'use strict';
  return function(e) {
    logger.error('Exception from "%s": %s', description, e);
    logger.debug('%s', e.stack);
  };
};

/**
 * Shedules an action and drops&logs any exceptions.
 */
exports.scheduleNoFault = function(app, description, f) {
  'use strict';
  return app.schedule(description, f).addErrback(
      exports.getLoggingErrback(description));
};

/**
 * Node.js is a multiplatform framework, however because we are making native
 * system calls, it becomes platform dependent. To reduce dependencies
 * throughout the code, all native commands are set here and when a command is
 * called through system_commands.get, the correct command for the current
 * platform is returned
 */
exports.setSystemCommands = function() {
  'use strict';
  system_commands.set(
      'dangling pids',
      // Ordered ppid,pid because on Windows PID is second,
      // and we only use the PID on Windows (never deal with children).
      'ps ax -o ppid,pid,command ' +
      '| egrep "chromedriver|selenium|wd_server" ' +
      '| egrep -v "agent_main|egrep"',
      'unix');
  // Windows columns: Image Name, PID, Session Name, Session#, Mem Usage.
  // We will parse Image Name as ppid, but we don't use ppid on Windows,
  // so we will still work ok since we parse the right pid, we would just
  // log a bad command name.
  // TODO: Figure out a better cross platform way to handle ps output.
  system_commands.set(
      'dangling pids',
      'tasklist | find "selenium"',
      'win32');

  system_commands.set(
      'find children',
      // Ordered ppid,pid for the same format as 'dangling pids' output.
      'ps -o ppid,pid,command | grep "^ *$0 "',
      'unix');
  // Windows doesn't need 'find children' because "taskkill /T" kills
  // the entire process tree.

  system_commands.set('kill', 'kill -9 $0', 'unix');
  system_commands.set('kill', 'taskkill /F /PID $0 /T', 'win32');
};

