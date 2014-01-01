/******************************************************************************
Copyright (c) 2010, Google Inc.
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

#pragma once
#include "request.h"

class TestState;
class TrackSockets;
class TrackDns;
class WptTest;

class BrowserRequestData {
public:
  BrowserRequestData(CString url):url_(url){}
  BrowserRequestData(const BrowserRequestData& src){*this = src;}
  ~BrowserRequestData(){}
  const BrowserRequestData& operator=(const BrowserRequestData& src) {
    url_ = src.url_;
    initiator_ = src.initiator_;
    initiator_line_ = src.initiator_line_;
    initiator_column_ = src.initiator_column_;
    return src;
  }

  CString  url_;
  CString  initiator_;
  CString  initiator_line_;
  CString  initiator_column_;
  long   connection_;
  LARGE_INTEGER end_timestamp_;
  double  end_time_;
  double  start_time_;
  double  first_byte_;
  long  dns_start_;
  long  dns_end_;
  long  connect_start_;
  long  connect_end_;
  long  ssl_start_;
  long  ssl_end_;

};

class Requests {
public:
  Requests(TestState& test_state, TrackSockets& sockets, TrackDns& dns,
            WptTest& test);
  ~Requests(void);

  void SocketClosed(DWORD socket_id);
  void DataIn(DWORD socket_id, DataChunk& chunk);
  bool ModifyDataOut(DWORD socket_id, DataChunk& chunk);
  void DataOut(DWORD socket_id, DataChunk& chunk);
  bool HasActiveRequest(DWORD socket_id);
  void ProcessBrowserRequest(CString request_data);
  void Lock();
  void Unlock();
  void Reset();
  bool GetBrowserRequest(BrowserRequestData &data, bool remove = true);

  CAtlList<Request *>       _requests;        // all requests
  CAtlMap<DWORD, Request *> _active_requests; // requests indexed by socket
  CAtlMap<DWORD, bool>      connections_;     // Connection IDs

private:
  CRITICAL_SECTION  cs;
  TestState&        _test_state;
  TrackSockets&     _sockets;
  TrackDns&         _dns;
  WptTest&          _test;
  CAtlList<BrowserRequestData>  browser_request_data_;

  bool IsHttpRequest(const DataChunk& chunk) const;
  bool IsSpdyRequest(const DataChunk& chunk) const;

  // GetOrCreateRequest must be called within a critical section.
  Request * GetOrCreateRequest(DWORD socket_id, const DataChunk& chunk);
  Request * NewRequest(DWORD socket_id, bool is_spdy);
  Request * GetActiveRequest(DWORD socket_id);
};
