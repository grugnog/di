/******************************************************************************
Copyright (c) 2011, Google Inc.
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice,
      this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice,
      this list of conditions and the following disclaimer in the documentation
      and/or other materials provided with the distribution.
    * Neither the name of the <ORGANIZATION> nor the names of its contributors
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

// hook_nspr.cc - Code for intercepting Nspr SSL API calls

#include "StdAfx.h"

#include "request.h"
#include "test_state.h"
#include "track_sockets.h"

#include "hook_nspr.h"

static NsprHook* g_hook = NULL;

// Save trampolined SSL_ImportFD for Chrome_SSL_ImportFD_Hook.
typedef PRFileDesc* (*PFN_Chrome_SSL_ImportFD)(void);
static PFN_Chrome_SSL_ImportFD g_ssl_importfd = NULL;

// Stub Functions

PRFileDesc* Chrome_SSL_ImportFD_Hook() {
  // This is a special hook for Chrome-only. Chrome optimizes the parameter
  // passing such that is it non-standard. Leave the parameters untouched.
  // We only care about the return value.
  PRFileDesc* fd = g_ssl_importfd();
  g_hook->SetSslFd(fd);
  return fd;
}

PRFileDesc* SSL_ImportFD_Hook(PRFileDesc *model, PRFileDesc *fd) {
  return g_hook->SSL_ImportFD(model, fd);
}

PRStatus PR_Close_Hook(PRFileDesc *fd) {
  return g_hook->PR_Close(fd);
}

PRInt32 PR_Write_Hook(PRFileDesc *fd, const void *buf, PRInt32 amount) {
  return g_hook->PR_Write(fd, buf, amount);
}

PRInt32 PR_Read_Hook(PRFileDesc *fd, void *buf, PRInt32 amount) {
  return g_hook->PR_Read(fd, buf, amount);
}

// end of C hook functions


NsprHook::NsprHook(TrackSockets& sockets, TestState& test_state) :
    _sockets(sockets),
    _test_state(test_state),
    _hook(NULL), 
    _SSL_ImportFD(NULL),
    _PR_Close(NULL),
    _PR_Read(NULL),
    _PR_Write(NULL),
    _PR_FileDesc2NativeHandle(NULL) {
}

NsprHook::~NsprHook() {
  if (g_hook == this) {
    g_hook = NULL;
  }
  delete _hook;  // remove all the hooks
}

void NsprHook::Init() {
  if (_hook || g_hook) {
    return;
  }
  _hook = new NCodeHookIA32();
  g_hook = this; 
  GetFunctionByName(
      "nspr4.dll", "PR_FileDesc2NativeHandle", _PR_FileDesc2NativeHandle);
  if (_PR_FileDesc2NativeHandle != NULL) {
    // Hook Firefox.
    WptTrace(loglevel::kProcess, _T("[wpthook] NsprHook::Init()\n"));
    _SSL_ImportFD = _hook->createHookByName(
        "ssl3.dll", "SSL_ImportFD", SSL_ImportFD_Hook);
    _PR_Close = _hook->createHookByName(
        "nspr4.dll", "PR_Close", PR_Close_Hook);
    _PR_Write = _hook->createHookByName(
        "nspr4.dll", "PR_Write", PR_Write_Hook);
    _PR_Read = _hook->createHookByName(
        "nspr4.dll", "PR_Read", PR_Read_Hook);
  }
}

void NsprHook::SetSslFd(PRFileDesc *fd) {
  _sockets.SetSslFd(fd);
}

// NSPR hooks
PRFileDesc* NsprHook::SSL_ImportFD(PRFileDesc *model, PRFileDesc *fd) {
  PRFileDesc* ret = NULL;
  if (_SSL_ImportFD) {
    ret = _SSL_ImportFD(model, fd);
    if (ret != NULL) {
      _sockets.SetSslFd(ret);
      if (_PR_FileDesc2NativeHandle) {
        _sockets.SetSslSocket(_PR_FileDesc2NativeHandle(ret));
      }
    }
  }
  return ret;
}

PRStatus NsprHook::PR_Close(PRFileDesc *fd) {
  PRStatus ret = PR_FAILURE;
  if (_PR_Close) {
    ret = _PR_Close(fd);
    _sockets.ClearSslFd(fd);
  }
  return ret;
}

/*-----------------------------------------------------------------------------
  For SSL connections on Firefox, PR_Write fails for the first few calls while
  SSL handshake takes place. During the handshake, PR_FileDesc2NativeHandle
  returns a different number that is not the SSL socket number. The workaround
  is to keep a mapping of file descriptors to SSL socket numbers.
-----------------------------------------------------------------------------*/
PRInt32 NsprHook::PR_Write(PRFileDesc *fd, const void *buf, PRInt32 amount) {
  PRInt32 ret = -1;
  if (_PR_Write) {
    DataChunk chunk((LPCSTR)buf, amount);
    PRInt32 original_amount = amount;
    SOCKET s = INVALID_SOCKET;
    if (buf && !_test_state._exit && _sockets.SslSocketLookup(fd, s)) {
      _sockets.ModifyDataOut(s, chunk, true);
    }
    ret = _PR_Write(fd, chunk.GetData(), chunk.GetLength());
    if (ret > 0 && s != INVALID_SOCKET) {
      _sockets.DataOut(s, chunk, true);
      ret = original_amount;
    }
  }
  return ret;
}

PRInt32 NsprHook::PR_Read(PRFileDesc *fd, void *buf, PRInt32 amount) {
  PRInt32 ret = -1;
  if (_PR_Read) {
    ret = _PR_Read(fd, buf, amount);
    if (ret > 0 && buf && !_test_state._exit) {
      SOCKET s = INVALID_SOCKET;
      if (_sockets.SslSocketLookup(fd, s)) {
        _sockets.DataIn(s, DataChunk((LPCSTR)buf, ret), true);
      }
    }
  }
  return ret;
}

template <typename U>
void NsprHook::GetFunctionByName(
    const string& dll_name, const string& function_name, U& function_ptr) {
  HMODULE dll = LoadLibraryA(dll_name.c_str());
  if (dll) {
    function_ptr = (U)GetProcAddress(dll, function_name.c_str());
    FreeLibrary(dll);
  } else {
    function_ptr = NULL;
  }
}
