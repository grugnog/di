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
#include "test_state.h"
#include "results.h"
#include "screen_capture.h"
#include "shared_mem.h"
#include "../wptdriver/util.h"
#include "cximage/ximage.h"
#include <Mmsystem.h>
#include "wpt_test_hook.h"

// TODO: Keep the test running till aft timeout.
static const DWORD AFT_TIMEOUT = 10 * 1000;
static const DWORD ON_LOAD_GRACE_PERIOD = 1000;
static const DWORD SCREEN_CAPTURE_INCREMENTS = 20;
static const DWORD DATA_COLLECTION_INTERVAL = 100;
static const DWORD START_RENDER_MARGIN = 30;
static const DWORD MS_IN_SEC = 1000;
static const DWORD SCRIPT_TIMEOUT_MULTIPLIER = 10;

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
TestState::TestState(Results& results, ScreenCapture& screen_capture, 
                      WptTestHook &test):
  _results(results)
  ,_screen_capture(screen_capture)
  ,_frame_window(NULL)
  ,_document_window(NULL)
  ,_render_check_thread(NULL)
  ,_exit(false)
  ,_data_timer(NULL)
  ,_test(test)
  ,no_gdi_(false)
  ,gdi_only_(false) {
  QueryPerformanceFrequency(&_ms_frequency);
  _ms_frequency.QuadPart = _ms_frequency.QuadPart / 1000;
  _check_render_event = CreateEvent(NULL, FALSE, FALSE, NULL);
  InitializeCriticalSection(&_data_cs);
  FindBrowserNameAndVersion();
  paint_msg_ = RegisterWindowMessage(_T("WPT Browser Paint"));
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
TestState::~TestState(void) {
  Done(true);
  DeleteCriticalSection(&_data_cs);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::Init() {
  Reset(false);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::Reset(bool cascade) {
  EnterCriticalSection(&_data_cs);
  _step_start.QuadPart = 0;
  _dom_content_loaded_event_start = 0;
  _dom_content_loaded_event_end = 0;
  _load_event_start = 0;
  _load_event_end = 0;
  _on_load.QuadPart = 0;
  if (cascade && _test._combine_steps) {
    LARGE_INTEGER now;
    QueryPerformanceCounter(&now);
    _last_activity.QuadPart = now.QuadPart;
  } else {
    _active = false;
    _capturing_aft = false;
    _next_document = 1;
    _current_document = 0;
    _doc_requests = 0;
    _requests = 0;
    _doc_bytes_in = 0;
    _bytes_in = 0;
    _bytes_in_bandwidth = 0;
    _doc_bytes_out = 0;
    _bytes_out = 0;
    _last_bytes_in = 0;
    _screen_updated = false;
    _last_data.QuadPart = 0;
    _video_capture_count = 0;
    _start.QuadPart = 0;
    _on_load.QuadPart = 0;
    _dom_content_loaded_event_start = 0;
    _dom_content_loaded_event_end = 0;
    _load_event_start = 0;
    _load_event_end = 0;
    _first_navigate.QuadPart = 0;
    _dom_elements_time.QuadPart = 0;
    _render_start.QuadPart = 0;
    _first_activity.QuadPart = 0;
    _last_activity.QuadPart = 0;
    _first_byte.QuadPart = 0;
    _last_video_time.QuadPart = 0;
    _last_cpu_idle.QuadPart = 0;
    _last_cpu_kernel.QuadPart = 0;
    _last_cpu_user.QuadPart = 0;
    _progress_data.RemoveAll();
    _test_result = 0;
    _title_time.QuadPart = 0;
    _aft_time_ms = 0;
    _title.Empty();
    _user_agent = _T("WebPagetest");
    _timeline_events.RemoveAll();
    _console_log_messages.RemoveAll();
    _navigated = false;
    GetSystemTime(&_start_time);
  }
  LeaveCriticalSection(&_data_cs);

  if (cascade && !_test._combine_steps)
    _results.Reset();
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
static unsigned __stdcall RenderCheckThread( void* arg ) {
  TestState * test_state = (TestState *)arg;
  if( test_state )
    test_state->RenderCheckThread();
    
  return 0;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void __stdcall CollectData(PVOID lpParameter, BOOLEAN TimerOrWaitFired) {
  if( lpParameter )
    ((TestState *)lpParameter)->CollectData();
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::Start() {
  WptTrace(loglevel::kFunction, _T("[wpthook] TestState::Start()\n"));
  Reset();
  QueryPerformanceCounter(&_step_start);
  GetSystemTime(&_start_time);
  if (!_start.QuadPart)
    _start.QuadPart = _step_start.QuadPart;
  _active = true;
  if( _test._aft )
    _capturing_aft = true;
  UpdateBrowserWindow();  // the document window may not be available yet

  if (!_render_check_thread) {
    _exit = false;
    ResetEvent(_check_render_event);
    _render_check_thread = (HANDLE)_beginthreadex(0, 0, ::RenderCheckThread, 
                                                                   this, 0, 0);
  }

  if (!_data_timer) {
    timeBeginPeriod(1);
    CreateTimerQueueTimer(&_data_timer, NULL, ::CollectData, this, 
        DATA_COLLECTION_INTERVAL, DATA_COLLECTION_INTERVAL, WT_EXECUTEDEFAULT);
  }
  GrabVideoFrame(true);
  CollectData();
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::ActivityDetected() {
  if (_active) {
    WptTrace(loglevel::kFunction, _T("[wpthook] TestState::ActivityDetected()\n"));
    QueryPerformanceCounter(&_last_activity);
    if (!_first_activity.QuadPart)
      _first_activity.QuadPart = _last_activity.QuadPart;
  }
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::OnNavigate() {
  if (_active) {
    WptTrace(loglevel::kFunction, _T("[wpthook] TestState::OnNavigate()\n"));
    UpdateBrowserWindow();
    _dom_content_loaded_event_start = 0;
    _dom_content_loaded_event_end = 0;
    _load_event_start = 0;
    _load_event_end = 0;
    _dom_elements_time.QuadPart = 0;
    _on_load.QuadPart = 0;
    _navigated = true;
    if (!_current_document) {
      _current_document = _next_document;
      _next_document++;
    }
    if (!_first_navigate.QuadPart)
      QueryPerformanceCounter(&_first_navigate);
    ActivityDetected();
  }
}

/*-----------------------------------------------------------------------------
  We do some sanity checking here to make sure the value reported 
  from the extension is sane.
-----------------------------------------------------------------------------*/
void TestState::RecordTime(CString name, DWORD time, LARGE_INTEGER *out_time) {
  QueryPerformanceCounter(out_time);
  DWORD elapsed_time = 0;
  if (_step_start.QuadPart && out_time->QuadPart >= _step_start.QuadPart) {
    elapsed_time = (DWORD)((out_time->QuadPart - _step_start.QuadPart) /
                           _ms_frequency.QuadPart);
  }
  if (time > 0 && time <= elapsed_time) {
    WptTrace(loglevel::kFrequentEvent, 
             _T("[wpthook] - Record %s from extension: %dms\n"), name, time);
    out_time->QuadPart = _step_start.QuadPart;
    out_time->QuadPart += _ms_frequency.QuadPart * time;
  } else {
    WptTrace(loglevel::kFrequentEvent,
             _T("[wpthook] - Record %s from hook: %dms (instead of %dms)\n"),
             name, elapsed_time, time);
  }
}

/*-----------------------------------------------------------------------------
  Save web timings for DOMContentLoaded event.
-----------------------------------------------------------------------------*/
void TestState::SetDomContentLoadedEvent(DWORD start, DWORD end) {
  if (_active) {
    _dom_content_loaded_event_start = start;
    _dom_content_loaded_event_end = end;
  }
}

/*-----------------------------------------------------------------------------
  Save web timings for load event.
-----------------------------------------------------------------------------*/
void TestState::SetLoadEvent(DWORD start, DWORD end) {
  if (_active) {
    _load_event_start = start;
    _load_event_end = end;
  }
}

/*-----------------------------------------------------------------------------
  Notification from the extension that the page has finished loading.
-----------------------------------------------------------------------------*/
void TestState::OnLoad() {
  if (_active) {
    QueryPerformanceCounter(&_on_load);
    ActivityDetected();
    _screen_capture.Capture(_document_window,
                            CapturedImage::DOCUMENT_COMPLETE);
    _current_document = 0;
  }
}

/*-----------------------------------------------------------------------------
  Notification from the extension that all dom elements are loaded.
-----------------------------------------------------------------------------*/
void TestState::OnAllDOMElementsLoaded(DWORD load_time) {
  if (_active) {
    QueryPerformanceCounter(&_dom_elements_time);
    RecordTime(_T("_dom_elements_time"), load_time, &_dom_elements_time);
    _test._dom_element_check = false;
    WptTrace(loglevel::kFrequentEvent, 
      _T("[wpthook] - TestState::OnAllDOMElementsLoaded() Resetting dom element check state. "));
  }
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
bool TestState::IsDone() {
  bool is_done = false;
  LARGE_INTEGER now;
  QueryPerformanceCounter(&now);
  DWORD test_ms = ElapsedMs(_step_start, now);
  if (_active) {
    bool is_page_done = false;
    CString done_reason;
    if (test_ms >= _test._minimum_duration) {
      DWORD load_ms = ElapsedMs(_on_load, now);
      DWORD inactive_ms = ElapsedMs(_last_activity, now);
      WptTrace(loglevel::kFunction,
               _T("[wpthook] - TestState::IsDone()? ")
               _T("Test: %dms, load: %dms, inactive: %dms, test timeout:%d\n"),
               test_ms, load_ms, inactive_ms, _test._measurement_timeout);
      bool is_loaded = (load_ms > ON_LOAD_GRACE_PERIOD && 
                      !_test._dom_element_check);
      if (_test_result) {
        is_page_done = true;
        done_reason = _T("Page Error");
      } else if (is_loaded && _test._doc_complete) {
        is_page_done = true;
        done_reason = _T("Stop at document complete (i.e. onload).");
      } else if (is_loaded && inactive_ms > _test._activity_timeout) {
        // This is the default done criteria: onload is done and at least
        // 2 more seconds have elapsed since the last network activity.
        is_page_done = true;
        done_reason = _T("No network activity detected.");
      } else if (test_ms > _test._measurement_timeout) {
        _test_result = TEST_RESULT_TIMEOUT;
        is_page_done = true;
        done_reason = _T("Test timed out.");
      }
    }
    if (is_page_done) {
      if (_capturing_aft && !_test_result) {
        // Continue AFT video capture by only marking the test as inactive.
        WptTrace(loglevel::kFrequentEvent,
                 _T("[wpthook] - TestState::IsDone() -> false (capturing AFT);")
                 _T(" page done: %s"), done_reason);
        _active = false;
      } else {
        WptTrace(loglevel::kFrequentEvent,
                 _T("[wpthook] - TestState::IsDone() -> true; %s"),
                 done_reason);
        Done();
        is_done = true;
      }
    }
  } else if (_capturing_aft && test_ms > AFT_TIMEOUT) {
    WptTrace(loglevel::kFrequentEvent,
             _T("[wpthook] - TestState::IsDone() -> true; ")
             _T("AFT timed out."));
    Done();
    is_done = true;
  }
  return is_done;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::Done(bool force) {
  WptTrace(loglevel::kFunction, _T("[wpthook] - **** TestState::Done()\n"));
  if (_active || _capturing_aft) {
    _screen_capture.Capture(_document_window, CapturedImage::FULLY_LOADED);

    if (force || !_test._combine_steps) {
      // kill the timer that was collecting periodic data (cpu, video, etc)
      if (_data_timer) {
        DeleteTimerQueueTimer(NULL, _data_timer, NULL);
        _data_timer = NULL;
        timeEndPeriod(1);
      }

      // clean up the background thread that was doing the timer checks
      if (_render_check_thread) {
        _exit = true;
        SetEvent(_check_render_event);
        WaitForSingleObject(_render_check_thread, INFINITE);
        CloseHandle(_render_check_thread);
        _render_check_thread = NULL;
      }
    }

    _active = false;
    _capturing_aft = false;
  }
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
BOOL CALLBACK MakeTopmost(HWND hwnd, LPARAM lParam) {
  TCHAR class_name[1024];
  if (IsWindowVisible(hwnd) && 
    GetClassName(hwnd, class_name, _countof(class_name))) {
    _tcslwr(class_name);
    if (_tcsstr(class_name, _T("chrome")) || 
        _tcsstr(class_name, _T("mozilla"))) {
      ::SetWindowPos(hwnd, HWND_TOPMOST, 0, 0, 0, 0, 
          SWP_NOACTIVATE | SWP_NOSIZE | SWP_NOMOVE);
      ::UpdateWindow(hwnd);
    }
  }
  return TRUE;
}

/*-----------------------------------------------------------------------------
    Find the browser window that we are going to capture
-----------------------------------------------------------------------------*/
void TestState::UpdateBrowserWindow() {
  DWORD browser_process_id = GetCurrentProcessId();
  if (no_gdi_) {
    browser_process_id = GetParentProcessId(browser_process_id);
  }
  HWND old_frame = _frame_window;
  if (::FindBrowserWindow(browser_process_id, _frame_window, 
                          _document_window)) {
    WptTrace(loglevel::kFunction, 
              _T("[wpthook] - Frame Window: %08X, Document Window: %08X\n"), 
              _frame_window, _document_window);
    if (!_document_window)
      _document_window = _frame_window;
  }
  // position the browser window
  if (_frame_window && old_frame != _frame_window) {
    DWORD browser_width = _test._browser_width;
    DWORD browser_height = _test._browser_height;
    ::ShowWindow(_frame_window, SW_RESTORE);
    if (_test._viewport_width && _test._viewport_height) {
      ::UpdateWindow(_frame_window);
      FindViewport();
      RECT browser;
      GetWindowRect(_frame_window, &browser);
      RECT viewport = {0,0,0,0};
      if (_screen_capture.IsViewportSet()) {
        memcpy(&viewport, &_screen_capture._viewport, sizeof(RECT));
      } else {
        if (_document_window) {
          GetWindowRect(_document_window, &viewport);
        }
      }
      int vp_width = abs(viewport.right - viewport.left);
      int vp_height = abs(viewport.top - viewport.bottom);
      int br_width = abs(browser.right - browser.left);
      int br_height = abs(browser.top - browser.bottom);
      if (vp_width && vp_height && br_width && br_height && 
        br_width >= vp_width && br_height >= vp_height) {
        browser_width = _test._viewport_width + (br_width - vp_width);
        browser_height = _test._viewport_height + (br_height - vp_height);
      }
      _screen_capture.ClearViewport();
    }
    ::SetWindowPos(_frame_window, HWND_TOPMOST, 0, 0, 
                    browser_width, browser_height, SWP_NOACTIVATE);
    ::UpdateWindow(_frame_window);
    EnumWindows(::MakeTopmost, (LPARAM)this);
    FindViewport();
  }
}

/*-----------------------------------------------------------------------------
    Change the document window
-----------------------------------------------------------------------------*/
void TestState::SetDocument(HWND wnd) {
  WptTrace(loglevel::kFunction, _T("[wpthook] - TestState::SetDocument(%d)"), wnd);
  if (wnd != _document_window) {
    _document_window = wnd;
    _frame_window = GetAncestor(_document_window, GA_ROOTOWNER);
    if (_document_window == _frame_window) {
      FindViewport();
    }
  }
}

/*-----------------------------------------------------------------------------
    Grab a video frame if it is appropriate
-----------------------------------------------------------------------------*/
void TestState::GrabVideoFrame(bool force) {
  if ((_active || _capturing_aft) && _document_window && _test._video) {
    if (force || (_screen_updated && _render_start.QuadPart)) {
      // use a falloff on the resolution with which we capture video
      bool grab_video = false;
      LARGE_INTEGER now;
      QueryPerformanceCounter(&now);
      if (!_last_video_time.QuadPart)
        grab_video = true;
      else {
        DWORD interval = DATA_COLLECTION_INTERVAL;
        if (_video_capture_count > SCREEN_CAPTURE_INCREMENTS * 2)
          interval *= 50;
        else if (_video_capture_count > SCREEN_CAPTURE_INCREMENTS)
          interval *= 10;
        LARGE_INTEGER min_time;
        min_time.QuadPart = _last_video_time.QuadPart + 
                              (interval * _ms_frequency.QuadPart);
        if (now.QuadPart >= min_time.QuadPart)
          grab_video = true;
      }
      if (grab_video) {
        _screen_updated = false;
        _last_video_time.QuadPart = now.QuadPart;
        _video_capture_count++;
        _screen_capture.Capture(_document_window, CapturedImage::VIDEO);
      }
    }
  }
}

/*-----------------------------------------------------------------------------
    See if anything has been rendered to the screen
-----------------------------------------------------------------------------*/
void TestState::CheckStartRender() {
  if (!_render_start.QuadPart && _screen_updated && _document_window) {
    GdiFlush();
    SetEvent(_check_render_event);
  }
}

/*-----------------------------------------------------------------------------
    Background thread to check to see if rendering has started
    (this way we don't block the browser itself)
-----------------------------------------------------------------------------*/
void TestState::RenderCheckThread() {
  while (!_render_start.QuadPart && !_exit) {
    WaitForSingleObject(_check_render_event, INFINITE);
    if (!_exit) {
      _screen_capture.Lock();
      _screen_updated = false;
      LARGE_INTEGER now;
      QueryPerformanceCounter((LARGE_INTEGER *)&now);

      // grab a screen shot
      bool found = false;
      CapturedImage captured_img = _screen_capture.CaptureImage(
                                _document_window, CapturedImage::START_RENDER);
      CxImage img;
      if (captured_img.Get(img) && 
          img.GetWidth() > START_RENDER_MARGIN * 2 &&
          img.GetHeight() > START_RENDER_MARGIN * 2) {
        int bpp = img.GetBpp();
        if (bpp >= 15) {
          int height = img.GetHeight();
          int width = img.GetWidth();
          // 24-bit gets a fast-path where we can just compare full rows
          if (bpp <= 24 ) {
            DWORD row_bytes = img.GetEffWidth();
            DWORD compare_bytes = (bpp>>3) * (width-(START_RENDER_MARGIN * 2));
            char * background = (char *)malloc(compare_bytes);
            if (background) {
              char * image_bytes = (char *)img.GetBits(START_RENDER_MARGIN)
                                     + START_RENDER_MARGIN * (bpp >> 3);
              memcpy(background, image_bytes, compare_bytes);
              for (DWORD row = START_RENDER_MARGIN; 
                    row < height - START_RENDER_MARGIN && !found; row++) {
                if (memcmp(image_bytes, background, compare_bytes))
                  found = true;
                else
                  image_bytes += row_bytes;
              }
              free (background);
            }
          } else {
            for (DWORD row = START_RENDER_MARGIN; 
                    row < height - START_RENDER_MARGIN && !found; row++) {
              for (DWORD x = START_RENDER_MARGIN; 
                    x < width - START_RENDER_MARGIN && !found; x++) {
                RGBQUAD pixel = img.GetPixelColor(x, row, false);
                if (pixel.rgbBlue != 255 || pixel.rgbRed != 255 || 
                    pixel.rgbGreen != 255)
                  found = true;
              }
            }
          }
        }
      }

      if (found) {
        _render_start.QuadPart = now.QuadPart;
        _screen_capture._captured_images.AddTail(captured_img);
      }
      else
        captured_img.Free();

      _screen_capture.Unlock();
    }
  }
}

/*-----------------------------------------------------------------------------
    Collect the periodic system stats like cpu/memory/bandwidth.
-----------------------------------------------------------------------------*/
void TestState::CollectSystemStats(LARGE_INTEGER &now) {
  ProgressData data;
  data._time.QuadPart = now.QuadPart;
  DWORD msElapsed = 0;
  if (_last_data.QuadPart) {
    msElapsed = (DWORD)((now.QuadPart - _last_data.QuadPart) / 
                            _ms_frequency.QuadPart);
  }

  // figure out the bandwidth
  if (msElapsed) {
    double bits = (_bytes_in_bandwidth - _last_bytes_in) * 8;
    double sec = (double)msElapsed / (double)MS_IN_SEC;
    data._bpsIn = (DWORD)(bits / sec);
  }
  _last_bytes_in = _bytes_in_bandwidth;

  // calculate CPU utilization
  FILETIME idle_time, kernel_time, user_time;
  if (GetSystemTimes(&idle_time, &kernel_time, &user_time)) {
    ULARGE_INTEGER k, u, i;
    k.LowPart = kernel_time.dwLowDateTime;
    k.HighPart = kernel_time.dwHighDateTime;
    u.LowPart = user_time.dwLowDateTime;
    u.HighPart = user_time.dwHighDateTime;
    i.LowPart = idle_time.dwLowDateTime;
    i.HighPart = idle_time.dwHighDateTime;
    if(_last_cpu_idle.QuadPart || _last_cpu_kernel.QuadPart || 
      _last_cpu_user.QuadPart) {
      __int64 idle = i.QuadPart - _last_cpu_idle.QuadPart;
      __int64 kernel = k.QuadPart - _last_cpu_kernel.QuadPart;
      __int64 user = u.QuadPart - _last_cpu_user.QuadPart;
      if (kernel || user) {
        int cpu_utilization = (int)((((kernel + user) - idle) * 100) 
                                      / (kernel + user));
        data._cpu = max(min(cpu_utilization, 100), 0);
      }
    }
    _last_cpu_idle.QuadPart = i.QuadPart;
    _last_cpu_kernel.QuadPart = k.QuadPart;
    _last_cpu_user.QuadPart = u.QuadPart;
  }

  // get the memory use (working set - task-manager style)
  if (msElapsed) {
    PROCESS_MEMORY_COUNTERS mem;
    mem.cb = sizeof(mem);
    if( GetProcessMemoryInfo(GetCurrentProcess(), &mem, sizeof(mem)) )
      data._mem = mem.WorkingSetSize / 1024;

    _progress_data.AddTail(data);
  }
}

/*-----------------------------------------------------------------------------
  Collect various performance data and screen capture.
    - See if anything has been rendered to the screen
    - Collect the CPU/memory/BW information
-----------------------------------------------------------------------------*/
void TestState::CollectData() {
  EnterCriticalSection(&_data_cs);
  if (_active) {
    LARGE_INTEGER now;
    QueryPerformanceCounter(&now);
    if (now.QuadPart > _last_data.QuadPart || !_last_data.QuadPart) {
      GrabVideoFrame();
      CollectSystemStats(now);
      _last_data.QuadPart = now.QuadPart;
    }
  }
  LeaveCriticalSection(&_data_cs);
}

/*-----------------------------------------------------------------------------
  Keep track of the page title and when it was first set (first title only)
-----------------------------------------------------------------------------*/
void TestState::TitleSet(CString title) {
  if (_active) {
    CString new_title = title.Trim();
    // trim the browser off of the title ( - Chrome, etc)
    int pos = new_title.ReverseFind(_T('-'));
    if (pos > 0)
      new_title = new_title.Left(pos).Trim();
    if (!_title_time.QuadPart || new_title.Compare(_title)) {
      QueryPerformanceCounter(&_title_time);
      _title = new_title;
      WptTrace(loglevel::kFunction, _T("[wpthook] TestState::TitleSet(%s)\n"),
                _title);
    }
  }
}

/*-----------------------------------------------------------------------------
  Find the portion of the document window that represents the document
-----------------------------------------------------------------------------*/
void TestState::FindViewport() {
  if (_document_window == _frame_window && !_screen_capture.IsViewportSet()) {
    CapturedImage captured = _screen_capture.CaptureImage(_document_window);
    CxImage image;
    if (captured.Get(image)) {
      // start in the middle of the image and go in each direction 
      // until we get a pixel of a different color
      DWORD width = image.GetWidth();
      DWORD height = image.GetHeight();
      if (width > 100 && height > 100) {
        DWORD x = width / 2;
        DWORD y = height / 2;
        RECT viewport = {0,0,0,0}; 
        viewport.right = width - 1;
        DWORD row_bytes = image.GetEffWidth();
        unsigned char * middle = image.GetBits(y);
        if (middle) {
          middle += row_bytes / 2;
          unsigned char background[3];
          memcpy(background, middle, 3);
          // find the top
          unsigned char * pixel = middle;
          while (y < height - 1 && !viewport.top) {
            if (memcmp(background, pixel, 3))
              viewport.top = height - y;
            pixel += row_bytes;
            y++;
          }
          // find the top
          y = height / 2;
          pixel = middle;
          while (y && !viewport.bottom) {
            if (memcmp(background, pixel, 3))
              viewport.bottom = height - y;
            pixel -= row_bytes;
            y--;
          }
          if (!viewport.bottom)
            viewport.bottom = height - 1;
        }
        if (viewport.right - viewport.left > (long)width / 2 &&
          viewport.bottom - viewport.top > (long)height / 2) {
          _screen_capture.SetViewport(viewport);
        }
      }
    }
    captured.Free();
  }
}

/*-----------------------------------------------------------------------------
  Browser status message
-----------------------------------------------------------------------------*/
void TestState::OnStatusMessage(CString status) {
  StatusMessage stat(status);
  _status_messages.AddTail(stat);
}

/*-----------------------------------------------------------------------------
  Convert |time| to the number of milliseconds since the start.
-----------------------------------------------------------------------------*/
DWORD TestState::ElapsedMsFromStart(LARGE_INTEGER end) const {
  return ElapsedMs(_start, end);
}

DWORD TestState::ElapsedMs(LARGE_INTEGER start, LARGE_INTEGER end) const {
  DWORD elapsed_ms = 0;
  if (start.QuadPart && end.QuadPart > start.QuadPart) {
    elapsed_ms = static_cast<DWORD>(
        (end.QuadPart - start.QuadPart) / _ms_frequency.QuadPart);
  }
  return elapsed_ms;
}

/*-----------------------------------------------------------------------------
  Find the browser name and version.
-----------------------------------------------------------------------------*/
void TestState::FindBrowserNameAndVersion() {
  TCHAR file_name[MAX_PATH];
  if (GetModuleFileName(NULL, file_name, _countof(file_name))) {
    CString exe(file_name);
    exe.MakeLower();
    if (exe.Find(_T("webkit2webprocess.exe")) >= 0) {
      no_gdi_ = true;
      _browser_name = _T("Safari");
    } else if (exe.Find(_T("safari.exe")) >= 0) {
      gdi_only_ = true;
    }
    DWORD unused;
    DWORD info_size = GetFileVersionInfoSize(file_name, &unused);
    if (info_size) {
      LPBYTE version_info = new BYTE[info_size];
      if (GetFileVersionInfo(file_name, 0, info_size, version_info)) {
        VS_FIXEDFILEINFO *file_info = NULL;
        UINT size = 0;
        if (VerQueryValue(version_info, _T("\\"), (LPVOID*)&file_info, &size) &&
            file_info) {
          _browser_version.Format(_T("%d.%d.%d.%d"),
                                  HIWORD(file_info->dwFileVersionMS),
                                  LOWORD(file_info->dwFileVersionMS),
                                  HIWORD(file_info->dwFileVersionLS),
                                  LOWORD(file_info->dwFileVersionLS));
        }

        // Structure used to store enumerated languages and code pages.
        struct LANGANDCODEPAGE {
          WORD language;
          WORD code_page;
        } *translate;
        // Read the list of languages and code pages.
        if (_browser_name.IsEmpty() &&
            VerQueryValue(version_info, TEXT("\\VarFileInfo\\Translation"),
                          (LPVOID*)&translate, &size)) {
          // Use the first language/code page.
          CString key;
          key.Format(_T("\\StringFileInfo\\%04x%04x\\FileDescription"),
                     translate[0].language, translate[0].code_page);
          LPTSTR file_desc = NULL;
          if (VerQueryValue(version_info, key, (LPVOID*)&file_desc, &size)) {
            _browser_name = file_desc;
          }
        }
      }
      delete[] version_info;
    }
    if (_browser_name.IsEmpty()) {
      PathRemoveExtension(file_name);
      PathStripPath(file_name);
      _browser_name = file_name;
    }
  }
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::AddConsoleLogMessage(CString message) {
  EnterCriticalSection(&_data_cs);
  _console_log_messages.AddTail(message);
  LeaveCriticalSection(&_data_cs);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void TestState::AddTimelineEvent(CString timeline_event) {
  EnterCriticalSection(&_data_cs);
  _timeline_events.AddTail(timeline_event);
  LeaveCriticalSection(&_data_cs);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
CString TestState::GetConsoleLogJSON() {
  CString ret;
  EnterCriticalSection(&_data_cs);
  if (!_console_log_messages.IsEmpty()) {
    ret = _T("[");
    bool first = true;
    POSITION pos = _console_log_messages.GetHeadPosition();
    while (pos) {
      CString entry = _console_log_messages.GetNext(pos);
      if (entry.GetLength()) {
        if (!first)
          ret += _T(",");
        ret += entry;
        first = false;
      }
    }
    ret += _T("]");
  }
  LeaveCriticalSection(&_data_cs);
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
CString TestState::GetTimelineJSON() {
  CString ret;
  EnterCriticalSection(&_data_cs);
  if (!_timeline_events.IsEmpty()) {
    ret = _T("[\"");
    ret += _user_agent + _T("\"");
    POSITION pos = _timeline_events.GetHeadPosition();
    while (pos) {
      CString entry = _timeline_events.GetNext(pos);
      if (entry.GetLength()) {
        ret += _T(",");
        ret += entry;
      }
    }
    ret += _T("]");
  }
  LeaveCriticalSection(&_data_cs);
  return ret;
}

