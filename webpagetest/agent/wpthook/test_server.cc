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

#include "StdAfx.h"
#include "test_server.h"
#include "wpthook.h"
#include "wpt_test_hook.h"
#include "mongoose/mongoose.h"
#include "test_state.h"
#include "requests.h"
#include <atlutil.h>

static TestServer * _globaltest__server = NULL;

// definitions
static const DWORD RESPONSE_OK = 200;
static const char * RESPONSE_OK_STR = "OK";

static const DWORD RESPONSE_ERROR_NOtest_ = 404;
static const char * RESPONSE_ERROR_NOtest__STR = "ERROR: No Test";

static const DWORD RESPONSE_ERROR_NOT_IMPLEMENTED = 403;
static const char * RESPONSE_ERROR_NOT_IMPLEMENTED_STR = 
                                                      "ERROR: Not Implemented";
static const char * BLANK_HTML = "HTTP/1.1 200 OK\r\n"
    "Cache: no-cache\r\n"
    "Content-Type:text/html\r\n"
    "\r\n"
    "<html><head><title>Blank</title>\r\n"
    "<style type=\"text/css\">\r\n"
    "body {background-color: #FFF;}\r\n"
    "</style>\r\n"
    "<script type=\"text/javascript\">\r\n"
    "var dummy=1;\r\n"
    "</script>\r\n"
    "</head><body></body></html>";

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
TestServer::TestServer(WptHook& hook, WptTestHook &test, TestState& test_state,
                        Requests& requests)
  :mongoose_context_(NULL)
  ,hook_(hook)
  ,test_(test)
  ,test_state_(test_state)
  ,requests_(requests) {
  InitializeCriticalSection(&cs);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
TestServer::~TestServer(void){
  DeleteCriticalSection(&cs);
}

/*-----------------------------------------------------------------------------
  Stub callback to trampoline into the class instance
-----------------------------------------------------------------------------*/
static void *MongooseCallbackStub(enum mg_event event,
                           struct mg_connection *conn,
                           const struct mg_request_info *request_info) {
  void *processed = "yes";

  if (_globaltest__server)
    _globaltest__server->MongooseCallback(event, conn, request_info);

  return processed;
}

/*-----------------------------------------------------------------------------
  Start the local HTTP server
-----------------------------------------------------------------------------*/
bool TestServer::Start(void){
  bool ret = false;

  _globaltest__server = this;

  static const char *options[] = {
    "listening_ports", "127.0.0.1:8888",
    "num_threads", "5",
    NULL
  };

  mongoose_context_ = mg_start(&MongooseCallbackStub, options);
  if (mongoose_context_)
    ret = true;

  return ret;
}

/*-----------------------------------------------------------------------------
  Stop the local HTTP server
-----------------------------------------------------------------------------*/
void TestServer::Stop(void){
  if (mongoose_context_) {
    mg_stop(mongoose_context_);
    mongoose_context_ = NULL;
  }
  _globaltest__server = NULL;
}

/*-----------------------------------------------------------------------------
  We received a request that we need to respond to
-----------------------------------------------------------------------------*/
void TestServer::MongooseCallback(enum mg_event event,
                      struct mg_connection *conn,
                      const struct mg_request_info *request_info){

  EnterCriticalSection(&cs);
  if (event == MG_NEW_REQUEST) {
    WptTrace(loglevel::kFrequentEvent, _T("[wpthook] HTTP Request: %s\n"), 
                    (LPCTSTR)CA2T(request_info->uri));
    WptTrace(loglevel::kFrequentEvent, _T("[wpthook] HTTP Query String: %s\n"), 
                    (LPCTSTR)CA2T(request_info->query_string));
    if (strcmp(request_info->uri, "/task") == 0) {
      CStringA task;
      bool record = false;
      test_.GetNextTask(task, record);
      if (record)
        hook_.Start();
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, task);
    } else if (strcmp(request_info->uri, "/event/load") == 0) {
      // Browsers may get "/event/window_timing" to set "onload" time.
      hook_.OnLoad();
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/window_timing") == 0) {
      DWORD start = GetDwordParam(request_info->query_string,
                                  "domContentLoadedEventStart");
      DWORD end = GetDwordParam(request_info->query_string,
                                "domContentLoadedEventEnd");
      hook_.SetDomContentLoadedEvent(start, end);

      // To set "onload" time, browsers may request "/event/load".
      start = GetDwordParam(request_info->query_string, "loadEventStart");
      end = GetDwordParam(request_info->query_string, "loadEventEnd");
      hook_.SetLoadEvent(start, end);
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/navigate") == 0) {
      hook_.OnNavigate();
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/navigate_error") == 0) {
      CString err_str = GetUnescapedParam(request_info->query_string, "str");
      test_state_.OnStatusMessage(CString(_T("Navigation Error: ")) + err_str);
      test_state_._test_result = GetDwordParam(request_info->query_string, 
                                                "error");
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri,"/event/all_dom_elements_loaded")==0) {
      DWORD load_time = GetDwordParam(request_info->query_string, "load_time");
      hook_.OnAllDOMElementsLoaded(load_time);
      // TODO: Log the all dom elements loaded time into its metric.
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/dom_element") == 0) {
      DWORD time = GetDwordParam(request_info->query_string, "load_time");
      CString dom_element = GetUnescapedParam(request_info->query_string,
                                               "name_value");
      // TODO: Store the dom element loaded time.
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/title") == 0) {
      CString title = GetParam(request_info->query_string, "title");
      if (!title.IsEmpty()) {
        test_state_.TitleSet(title);
      }
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/status") == 0) {
      CString status = GetParam(request_info->query_string, "status");
      if (!status.IsEmpty()) {
        test_state_.OnStatusMessage(status);
      }
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/request_data") == 0) {
      if (test_state_._active) {
        test_state_.ActivityDetected();
        CString body = GetPostBody(conn, request_info);
        requests_.ProcessBrowserRequest(body);
      }
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/console_log") == 0) {
      if (test_state_._active) {
        CString body = GetPostBody(conn, request_info);
        test_state_.AddConsoleLogMessage(body);
      }
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/timeline") == 0) {
      if (test_state_._active) {
        CString body = GetPostBody(conn, request_info);
        test_state_.AddTimelineEvent(body);
      }
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/event/trace") == 0) {
      CString body = GetPostBody(conn, request_info);
      OutputDebugString(body);
      SendResponse(conn, request_info, RESPONSE_OK, RESPONSE_OK_STR, "");
    } else if (strcmp(request_info->uri, "/blank.html") == 0) {
      mg_printf(conn, BLANK_HTML);
    } else {
        // unknown command fall-through
        SendResponse(conn, request_info, RESPONSE_ERROR_NOT_IMPLEMENTED, 
                    RESPONSE_ERROR_NOT_IMPLEMENTED_STR, "");
    }
  }
  LeaveCriticalSection(&cs);
}

/*-----------------------------------------------------------------------------
  Send a JSON/JSONP response back to the caller
-----------------------------------------------------------------------------*/
void TestServer::SendResponse(struct mg_connection *conn,
                  const struct mg_request_info *request_info,
                  DWORD response_code,
                  CStringA response_code_string,
                  CStringA response_data){

  CStringA callback;
  CStringA request_id;

  // process the query parameters
  if (request_info->query_string) {
    size_t query_len = strlen(request_info->query_string);
    if (query_len) {
      char param[1024];
      const char *qs = request_info->query_string;

      // see if it is a jsonp call
      mg_get_var(qs, query_len, "callback", param, sizeof(param));
      if (param[0] != '\0') {
        callback = param;
      }

      // get the request ID if it was specified
      mg_get_var(qs, query_len, "r", param, sizeof(param));
      if (param[0] != '\0') {
        request_id = param;
      }
    }
  }

  // start with the HTTP Header
  CStringA response = "HTTP/1.1 200 OK\r\n"
    "Server: wptdriver\r\n"
    "Cache: no-cache\r\n"
    "Pragma: no-cache\r\n"
    "Content-Type: application/json\r\n";

  if (!callback.IsEmpty())
    response += callback + "(";

  // now the standard REST container
  CStringA buff;
  CStringA data = "";
  buff.Format("{\"statusCode\":%d,\"statusText\":\"%s\"", response_code, 
    (LPCSTR)response_code_string);
  data += buff;
  if (request_id.GetLength())
    data += CStringA(",\"requestId\":\"") + request_id + "\"";

  // and the actual data
  if (response_data.GetLength()) {
    data += ",\"data\":";
    data += response_data;
  }

  // close it out
  data += "}";
  if (!callback.IsEmpty())
    data += ");";

  DWORD len = data.GetLength();
  buff.Format("Content-Length: %d\r\n", len);
  response += buff;
  response += "\r\n";
  response += data;

  // and finally, send it
  mg_write(conn, (LPCSTR)response, response.GetLength());
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
CString TestServer::GetParam(const CString query_string, 
                             const CString key) const {
  CString value;
  int pos = 0;
  CString token = query_string.Tokenize(_T("&"), pos);
  bool is_found = false;
  while (pos >= 0 && !is_found) {
    int split = token.Find('=');
    if (split > 0) {
      CString k = token.Left(split).Trim();
      CString v = token.Mid(split + 1).Trim();
      if (!key.CompareNoCase(k)) {
        is_found = true;
        value = v;
      }
    }
    token = query_string.Tokenize(_T("&"), pos);
  }
  return value;
}

DWORD TestServer::GetDwordParam(const CString query_string,
                                const CString key) const {
  return _ttoi(GetParam(query_string, key));
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
CString TestServer::GetUnescapedParam(const CString query_string,
                                      const CString key) const {
  CString value = GetParam(query_string, key);
  DWORD len;
  TCHAR buff[4096];
  AtlUnescapeUrl((LPCTSTR)value, buff, &len, _countof(buff));
  value = CStringA(buff);
  return value;
}

/*-----------------------------------------------------------------------------
  Process the body of a post and return it as a string
-----------------------------------------------------------------------------*/
CString TestServer::GetPostBody(struct mg_connection *conn,
                      const struct mg_request_info *request_info){
  CString body;
  const char * length_string = mg_get_header(conn, "Content-Length");
  if (length_string) {
    int length = atoi(length_string);
    if (length) {
      char * buff = (char *)malloc(length + 1);
      if (buff) {
        while (length) {
          int bytes = mg_read(conn, buff, length);
          if (bytes && bytes <= length) {
            buff[bytes] = 0;
            body += CA2T(buff);
            length -= bytes;
          }
        }
        free(buff);
      }
    }
  }

  return body;
}