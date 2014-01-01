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
#include "web_page_replay.h"
#include "wpt_driver_core.h"
#include "zlib/contrib/minizip/unzip.h"
#include <Wtsapi32.h>
#include <D3D9.h>

const TCHAR * BROWSERS[] = {
  _T("chrome.exe"),
  _T("firefox.exe"),
  _T("iexplore.exe")
};

const TCHAR * DIALOG_WHITELIST[] = { 
  _T("urlblast")
  , _T("url blast")
  , _T("task manager")
  , _T("aol pagetest")
  , _T("shut down windows")
};

const DWORD SOFTWARE_INSTALL_RETRY_DELAY = 30000; // try every 30 seconds
const DWORD HOUSEKEEPING_INTERVAL = 500;

WptDriverCore * global_core = NULL;
extern HINSTANCE hInst;

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
WptDriverCore::WptDriverCore(WptStatus &status):
  _status(status)
  ,_webpagetest(_settings, _status)
  ,_browser(NULL)
  ,_exit(false)
  ,_work_thread(NULL)
  ,housekeeping_timer_(NULL)
  ,has_gpu_(false)
  ,watchdog_started_(false)
  ,_settings(status) {
  global_core = this;
  _testing_mutex = CreateMutex(NULL, FALSE, _T("Global\\WebPagetest"));
  has_gpu_ = DetectGPU();
  _webpagetest.has_gpu_ = has_gpu_;
}


/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
WptDriverCore::~WptDriverCore(void) {
  global_core = NULL;
  CloseHandle(_testing_mutex);
}

/*-----------------------------------------------------------------------------
  Stub entry point for the background work thread
-----------------------------------------------------------------------------*/
static unsigned __stdcall WorkThreadProc(void* arg) {
  WptDriverCore * core = (WptDriverCore *)arg;
  if( core )
    core->WorkThread();
    
  return 0;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void __stdcall DoHouseKeeping(PVOID lpParameter, BOOLEAN TimerOrWaitFired) {
  if( lpParameter )
    ((WptDriverCore *)lpParameter)->DoHouseKeeping();
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void WptDriverCore::Start(void){
  _status.Set(_T("Starting..."));

  if( _settings.Load() ){
    // boost our priority
    SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS);

    WaitForSingleObject(_testing_mutex, INFINITE);
    SetupScreen();
    ReleaseMutex(_testing_mutex);

    // start a background thread to do all of the actual test management
    _work_thread = (HANDLE)_beginthreadex(0, 0, ::WorkThreadProc, this, 0, 0);
  }else{
    _status.Set(_T("Error loading settings from wptdriver.ini"));
  }
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void WptDriverCore::Stop(void) {
  _status.Set(_T("Stopping..."));

  // kill the watchdog
  HWND watchdog = FindWindow(_T("WPT_Watchdog"), NULL);
  if (watchdog)
    SendMessageTimeout(watchdog, WM_CLOSE, 0, 0, 0, 10000, NULL);

  _exit = true;
  _webpagetest._exit = true;
  if (_work_thread) {
    WaitForSingleObject(_work_thread, EXIT_TIMEOUT);
    CloseHandle(_work_thread);
    _work_thread = NULL;
  }

  _status.Set(_T("Exiting..."));
}

/*-----------------------------------------------------------------------------
  Main thread for processing work
-----------------------------------------------------------------------------*/
void WptDriverCore::WorkThread(void) {
  Sleep(_settings._startup_delay * SECONDS_TO_MS);

  WaitForSingleObject(_testing_mutex, INFINITE);
  Init();  // do initialization and machine configuration
  ReleaseMutex(_testing_mutex);

  _status.Set(_T("Running..."));
  while (!_exit) {
    WaitForSingleObject(_testing_mutex, INFINITE);
    _status.Set(_T("Checking for software updates..."));
    _settings.UpdateSoftware();
    _status.Set(_T("Checking for work..."));
    WptTestDriver test(_settings._timeout * SECONDS_TO_MS, has_gpu_);
    if (_webpagetest.GetTest(test)) {
      PreTest();
      test._run = test._specific_run ? test._specific_run : 1;
      _status.Set(_T("Starting test..."));
      if (_settings.SetBrowser(test._browser, test._browser_url,
                               test._browser_md5, test._client)) {
        WebBrowser browser(_settings, test, _status, _settings._browser, 
                           _ipfw);
        if (SetupWebPageReplay(test, browser) &&
            !TracerouteTest(test)) {
          test._index = test._specific_index ? test._specific_index : 1;
          for (test._run = 1; test._run <= test._runs; test._run++) {
            test._run_error.Empty();
            test._run = test._specific_run ? test._specific_run : test._run;
            test._clear_cache = true;
            BrowserTest(test, browser);
            if (!test._fv_only) {
              test._run_error.Empty();
              test._clear_cache = false;
              BrowserTest(test, browser);
            }
            if (test._specific_run)
              break;
            else if (test._discard > 0)
              test._discard--;
            else
              test._index++;
          }
        }
        test._run = test._specific_run ? test._specific_run : test._runs;
      } else {
        test._test_error = test._run_error =
            CStringA("Invalid Browser Selected: ") + CT2A(test._browser);
      }
      bool uploaded = false;
      for (int count = 0; count < UPLOAD_RETRY_COUNT && !uploaded;count++ ) {
        uploaded = _webpagetest.TestDone(test);
        if( !uploaded )
          Sleep(UPLOAD_RETRY_DELAY * SECONDS_TO_MS);
      }
      ReleaseMutex(_testing_mutex);
    } else {
      ReleaseMutex(_testing_mutex);
      _status.Set(_T("Waiting for work..."));
      int delay = _settings._polling_delay * SECONDS_TO_MS;
      while (!_exit && delay > 0) {
        Sleep(100);
        delay -= 100;
      }
    }
  }
  Cleanup();
}

/*-----------------------------------------------------------------------------
  Check to see if it is a traceroute test and run it
  returns true if it was a traceroute test
-----------------------------------------------------------------------------*/
bool WptDriverCore::TracerouteTest(WptTestDriver& test) {
  bool ret = false;

  if (!test._test_type.CompareNoCase(_T("traceroute"))) {
    ret = true;
    CTraceRoute trace_route(test);
    for (test._run = 1; test._run <= test._runs; test._run++) {
      test.SetFileBase();
      trace_route.Run();
    }
  }

  return ret;
}

/*-----------------------------------------------------------------------------
  Run a single iteration of a browser test (first or repeat view)
-----------------------------------------------------------------------------*/
bool WptDriverCore::BrowserTest(WptTestDriver& test, WebBrowser &browser) {
  bool ret = false;
  bool critical_error = false;
  int attempt = 0;

  WptTrace(loglevel::kFunction,_T("[wptdriver] WptDriverCore::BrowserTest\n"));

  do {
    attempt++;
    test.SetFileBase();
    if (test._clear_cache) {
      FlushDNS();
      browser.ClearUserData();
    }
    if (test._tcpdump)
      _winpcap.StartCapture( test._file_base + _T(".cap") );

    SetCursorPos(0,0);
    ShowCursor(FALSE);
    ret = browser.RunAndWait(critical_error);
    ShowCursor(TRUE);

    if (test._tcpdump)
      _winpcap.StopCapture();
    KillBrowsers();

    if (test._discard)
      _webpagetest.DeleteIncrementalResults(test);
    if (attempt < 2 && critical_error) {
      WptTrace(loglevel::kWarning, 
        _T("[wptdriver] Critical error, re-installing browser (attempt %d)\n"),
        attempt);
      _webpagetest.DeleteIncrementalResults(test);
      _settings.ReInstallBrowser();
    } else {
      if (test._upload_incremental_results && !test._discard)
        _webpagetest.UploadIncrementalResults(test);
      else
        _webpagetest.DeleteIncrementalResults(test);
    }
  } while (attempt < 2 && critical_error);

  WptTrace(loglevel::kFunction, 
            _T("[wptdriver] WptDriverCore::BrowserTest done\n"));

  return ret;
}

typedef HRESULT (STDAPICALLTYPE* DLLREG)(void);

/*-----------------------------------------------------------------------------
  Do any startup initialization (settings have already loaded)
-----------------------------------------------------------------------------*/
void WptDriverCore::Init(void){

  // Clear IE's caches
  LaunchProcess(_T("RunDll32.exe InetCpl.cpl,ClearMyTracksByProcess 6655"));

  // set the OS to not boost foreground processes
  HKEY hKey;
  if (SUCCEEDED(RegOpenKeyEx(HKEY_LOCAL_MACHINE, 
      _T("SYSTEM\\CurrentControlSet\\Control\\PriorityControl"), 0, 
      KEY_SET_VALUE, &hKey))) {
    DWORD val = 0x18;
    RegSetValueEx(hKey, _T("Win32PrioritySeparation"), 0, REG_DWORD, 
                  (LPBYTE)&val, sizeof(val));
    RegCloseKey(hKey);
  }

  ExtractZipFiles();

  // register the IE BHO if it is in the directory
  TCHAR path[MAX_PATH];
  if (GetModuleFileName(NULL, path, _countof(path)) ) 	{ 
    lstrcpy(PathFindFileName(path), _T("wptbho.dll") );
    HMODULE bho = LoadLibrary(path);
    if (bho) {
      DLLREG proc = (DLLREG)GetProcAddress(bho, "DllRegisterServer");
      if( proc )
        proc();
      FreeLibrary(bho);
    }
  }

  // Disable IE auto-updates
	if (SUCCEEDED(RegCreateKeyEx(HKEY_LOCAL_MACHINE,
      _T("SOFTWARE\\Microsoft\\Internet Explorer\\Setup\\9.0"), 0, 0, 0,
      KEY_READ | KEY_WRITE, NULL, &hKey, NULL))) {
		DWORD val = 1;
		RegSetValueEx(hKey, _T("DoNotAllowIE90"), 0, REG_DWORD,
                  (LPBYTE)&val, sizeof(val));
		RegCloseKey(hKey);
	}
	if (SUCCEEDED(RegCreateKeyEx(HKEY_LOCAL_MACHINE,
      _T("SOFTWARE\\Microsoft\\Internet Explorer\\Setup\\10.0"), 0, 0, 0,
      KEY_READ | KEY_WRITE, NULL, &hKey, NULL))) {
		DWORD val = 1;
		RegSetValueEx(hKey, _T("DoNotAllowIE10"), 0, REG_DWORD,
                  (LPBYTE)&val, sizeof(val));
		RegCloseKey(hKey);
	}
	if (SUCCEEDED(RegCreateKeyEx(HKEY_LOCAL_MACHINE,
      _T("SOFTWARE\\Microsoft\\Internet Explorer\\Setup\\11.0"), 0, 0, 0,
      KEY_READ | KEY_WRITE, NULL, &hKey, NULL))) {
		DWORD val = 1;
		RegSetValueEx(hKey, _T("DoNotAllowIE11"), 0, REG_DWORD,
                  (LPBYTE)&val, sizeof(val));
		RegCloseKey(hKey);
	}


  // Get WinPCap ready (install it if necessary)
  _winpcap.Initialize();

  KillBrowsers();

  // start the background timer that does our housekeeping
  CreateTimerQueueTimer(&housekeeping_timer_, NULL, ::DoHouseKeeping, this, 
      HOUSEKEEPING_INTERVAL, HOUSEKEEPING_INTERVAL, WT_EXECUTEDEFAULT);

  _status.Set(_T("Installing software..."));
  while( !_settings.UpdateSoftware() && !_exit ) {
    _status.Set(_T("Software install failed, waiting to try again..."));
    Sleep(SOFTWARE_INSTALL_RETRY_DELAY);
    _status.Set(_T("Installing software..."));
  }
}

/*-----------------------------------------------------------------------------
  Do our cleanup on exit
-----------------------------------------------------------------------------*/
void WptDriverCore::Cleanup(void){
  if (housekeeping_timer_) {
    DeleteTimerQueueTimer(NULL, housekeeping_timer_, NULL);
    housekeeping_timer_ = NULL;
  }
}

typedef int (CALLBACK* DNSFLUSHPROC)();

/*-----------------------------------------------------------------------------
  Empty the OS DNS cache
-----------------------------------------------------------------------------*/
void WptDriverCore::FlushDNS(void) {
  _status.Set(_T("Flushing DNS cache..."));

  bool flushed = false;
  HINSTANCE		hDnsDll;

  hDnsDll = LoadLibrary(_T("dnsapi.dll"));
  if (hDnsDll) {
    DNSFLUSHPROC pDnsFlushProc = (DNSFLUSHPROC)GetProcAddress(hDnsDll, 
                                                      "DnsFlushResolverCache");
    if (pDnsFlushProc) {
      int ret = pDnsFlushProc();
      if (ret == ERROR_SUCCESS) {
        flushed = true;
        _status.Set(_T("Successfully flushed the DNS resolved cache"));
      } else
        _status.Set(_T("DnsFlushResolverCache returned %d"), ret);
    } else
      _status.Set(_T("Failed to load dnsapi.dll"));

    FreeLibrary(hDnsDll);
  } else
    _status.Set(_T("Failed to load dnsapi.dll"));

  if (!flushed)
    LaunchProcess(_T("ipconfig.exe /flushdns"));
}

/*-----------------------------------------------------------------------------
  Set up Web Page Replay (Record the page then start playback for it.)
-----------------------------------------------------------------------------*/
bool WptDriverCore::SetupWebPageReplay(
    WptTestDriver& test, WebBrowser &browser) {
  bool ret = true;
  if (!_settings._web_page_replay_host.IsEmpty()) {
    if (WebPageReplaySetRecordMode(_settings._web_page_replay_host)) {
      test._clear_cache = true;
      ret = BrowserTest(test, browser);
      if (!test._fv_only) {
        test._clear_cache = false;
        ret = BrowserTest(test, browser);
      }
      WebPageReplaySetReplayMode(_settings._web_page_replay_host);
    } else {
      _status.Set(_T("Web Page Replay Record FAILED"));
      ret = false;
    }
  }
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void WptDriverCore::ExtractZipFiles() {
  TCHAR src_path[MAX_PATH];
  GetModuleFileName(NULL, src_path, MAX_PATH);
  *PathFindFileName(src_path) = 0;
  CString src = src_path;

  WIN32_FIND_DATA fd;
  HANDLE find_handle = FindFirstFile(src + _T("*.zip"), &fd);
  if (find_handle != INVALID_HANDLE_VALUE) {
    do {
      if (!(fd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY)) {
        if (ExtractZipFile(src + fd.cFileName))
          DeleteFile(src + fd.cFileName);
      }
    } while( FindNextFile(find_handle, &fd) );
    FindClose(find_handle);
  }
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
bool WptDriverCore::ExtractZipFile(CString file) {
  bool ret = false;

  unzFile zip_file_handle = unzOpen(CT2A(file));
  if (zip_file_handle) {
    ret = true;
    if (unzGoToFirstFile(zip_file_handle) == UNZ_OK) {
      TCHAR path[MAX_PATH];
      lstrcpy(path, (LPCTSTR)file);
      *PathFindFileName(path) = 0;
      CStringA dir = CT2A(path);
      DWORD len = 4096;
      LPBYTE buff = (LPBYTE)malloc(len);
      if (buff) {
        do {
          char file_name[MAX_PATH];
          unz_file_info info;
          if (unzGetCurrentFileInfo(zip_file_handle, &info, (char *)&file_name,
              _countof(file_name), 0, 0, 0, 0) == UNZ_OK) {
              CStringA dest_file_name = dir + file_name;

            // make sure the directory exists
            char szDir[MAX_PATH];
            lstrcpyA(szDir, (LPCSTR)dest_file_name);
            *PathFindFileNameA(szDir) = 0;
            if( lstrlenA(szDir) > 3 )
              SHCreateDirectoryExA(NULL, szDir, NULL);

            HANDLE dest_file = CreateFileA(dest_file_name, GENERIC_WRITE, 0, 
                                          NULL, CREATE_ALWAYS, 0, 0);
            if (dest_file != INVALID_HANDLE_VALUE) {
              if (unzOpenCurrentFile(zip_file_handle) == UNZ_OK) {
                int bytes = 0;
                DWORD written;
                do {
                  bytes = unzReadCurrentFile(zip_file_handle, buff, len);
                  if( bytes > 0 )
                    WriteFile( dest_file, buff, bytes, &written, 0);
                } while( bytes > 0 );
                unzCloseCurrentFile(zip_file_handle);
              }
              CloseHandle( dest_file );
            }
          }
        } while (unzGoToNextFile(zip_file_handle) == UNZ_OK);

        free(buff);
      }
    }

    unzClose(zip_file_handle);
  }

  return ret;
}

/*-----------------------------------------------------------------------------
  Kill any rogue browser processes that didn't go away on their own
  This is disabled in debug mode to make it easier to develop
-----------------------------------------------------------------------------*/
void WptDriverCore::KillBrowsers() {
  if (!_settings._debug) {
    WTS_PROCESS_INFO * proc = NULL;
    DWORD count = 0;
    DWORD browser_count = _countof(BROWSERS);
    if (WTSEnumerateProcesses(WTS_CURRENT_SERVER_HANDLE, 0, 1, &proc,&count)) {
      for (DWORD i = 0; i < count; i++) {
        bool terminate = false;

        for (DWORD browser = 0; browser < browser_count && !terminate; 
              browser++) {
          TCHAR * process = PathFindFileName(proc[i].pProcessName);
          if (!lstrcmpi(process, BROWSERS[browser]) )
            terminate = true;
        }

        if (terminate) {
          HANDLE process_handle = OpenProcess(PROCESS_TERMINATE, FALSE, 
                                                proc[i].ProcessId);
          if (process_handle) {
            TerminateProcess(process_handle, 0);
            CloseHandle(process_handle);
          }
        }
      }
    }
  }
}

/*-----------------------------------------------------------------------------
  Set the screen resolution if it is currently too low
-----------------------------------------------------------------------------*/
void WptDriverCore::SetupScreen(void) {
  DEVMODE mode;
  memset(&mode, 0, sizeof(mode));
  mode.dmSize = sizeof(mode);
  CStringA settings;
  DWORD x = 0, y = 0, bpp = 0;

  int index = 0;
  DWORD targetWidth = 1920;
  DWORD targetHeight = 1200;
  DWORD min_bpp = 15;
  while( EnumDisplaySettings( NULL, index, &mode) ) {
    index++;
    bool use_mode = false;
    if (x >= targetWidth && y >= targetHeight && bpp >= 24) {
      // we already have at least one suitable resolution.  
      // Make sure we didn't overshoot and pick too high of a resolution
      // or see if a higher bpp is available
      if (mode.dmPelsWidth >= targetWidth && mode.dmPelsWidth <= x &&
          mode.dmPelsHeight >= targetHeight && mode.dmPelsHeight <= y &&
          mode.dmBitsPerPel >= bpp)
        use_mode = true;
    } else {
      if (mode.dmPelsWidth == x && mode.dmPelsHeight == y) {
        if (mode.dmBitsPerPel >= bpp)
          use_mode = true;
      } else if ((mode.dmPelsWidth >= targetWidth ||
                  mode.dmPelsWidth >= x) &&
                 (mode.dmPelsHeight >= targetHeight ||
                  mode.dmPelsHeight >= y) && 
                 mode.dmBitsPerPel >= min_bpp) {
          use_mode = true;
      }
    }
    if (use_mode) {
        x = mode.dmPelsWidth;
        y = mode.dmPelsHeight;
        bpp = mode.dmBitsPerPel;
    }
  }

  // get the current settings
  if (x && y && bpp && 
    EnumDisplaySettings(NULL, ENUM_CURRENT_SETTINGS, &mode)) {
    if (mode.dmPelsWidth < x || 
        mode.dmPelsHeight < y || 
        mode.dmBitsPerPel < bpp) {
      DEVMODE newMode;
      memcpy(&newMode, &mode, sizeof(mode));
      
      newMode.dmFields = DM_BITSPERPEL | DM_PELSWIDTH | DM_PELSHEIGHT;
      newMode.dmBitsPerPel = bpp;
      newMode.dmPelsWidth = x;
      newMode.dmPelsHeight = y;
      ChangeDisplaySettings( &newMode, CDS_UPDATEREGISTRY | CDS_GLOBAL );
    }
  }
}

/*-----------------------------------------------------------------------------
  Run the dummynet initialization script if it is present
-----------------------------------------------------------------------------*/
void WptDriverCore::SetupDummynet(void) {
  _status.Set(_T("Configuring dummynet..."));
}

/*-----------------------------------------------------------------------------
  Take care of the periodic housekeeping tasks (closing dialogs,
  terminating processes)
-----------------------------------------------------------------------------*/
void WptDriverCore::DoHouseKeeping(void) {
  CloseDialogs();
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void WptDriverCore::CloseDialogs(void) {
  TCHAR szTitle[1025];
  // make sure wptdriver isn't doing a software install
  bool installing = false;
  HWND hWptDriver = ::FindWindow(_T("wptdriver_wnd"), NULL);
  if (hWptDriver) {
    if (::GetWindowText(hWptDriver, szTitle, _countof(szTitle))) {
      CString title = szTitle;
      title.MakeLower();
      if (title.Find(_T(" software")) >= 0)
        installing = true;
    }
  }

  // if there are any explorer windows open, disable this code
  // (for local debugging and other work)
  if (!installing && !::FindWindow(_T("CabinetWClass"), NULL )) {
    HWND hDesktop = ::GetDesktopWindow();
    HWND hWnd = ::GetWindow(hDesktop, GW_CHILD);
    TCHAR szClass[100];
    CAtlArray<HWND> hDlg;

    // build a list of dialogs to close
    while (hWnd) {
      if (hWnd != _status._wnd) {
        if (::IsWindowVisible(hWnd))
          if (::GetClassName(hWnd, szClass, 100))
            if (!lstrcmp(szClass,_T("#32770")) ||
                !lstrcmp(szClass,_T("Internet Explorer_Server"))) {
              bool bKill = true;

              // make sure it is not in our list of windows to keep
              if (::GetWindowText( hWnd, szTitle, 1024)) {
                _tcslwr_s(szTitle, _countof(szTitle));
                for (int i = 0; i < _countof(DIALOG_WHITELIST) && bKill; i++) {
                  if(_tcsstr(szTitle, DIALOG_WHITELIST[i]))
                    bKill = false;
                }
                
                // do we have to terminate the process that owns it?
                if (!lstrcmp(szTitle, _T("server busy"))) {
                  DWORD pid;
                  GetWindowThreadProcessId(hWnd, &pid);
                  HANDLE hProcess = OpenProcess(PROCESS_TERMINATE, FALSE, pid);
                  if (hProcess) {
                    TerminateProcess(hProcess, 0);
                    CloseHandle(hProcess);
                  }
                }
              }
            
              if(bKill)
                hDlg.Add(hWnd);	
            }
      }
      hWnd = ::GetWindow(hWnd, GW_HWNDNEXT);
    }

    for (size_t i = 0; i < hDlg.GetCount(); i++)
      ::PostMessage(hDlg[i],WM_CLOSE,0,0);
  }
}

/*-----------------------------------------------------------------------------
  See if a video adapter is present that supports hardware acceleration
-----------------------------------------------------------------------------*/
bool WptDriverCore::DetectGPU() {
  bool has_gpu = false;
  HMODULE dll = LoadLibrary(_T("d3d9.dll"));
  if (dll) {
    typedef IDirect3D9 *(__stdcall * LPDIRECT3DCREATE9)(UINT SDKVersion);
    LPDIRECT3DCREATE9 Direct3DCreate9_ =
        (LPDIRECT3DCREATE9)GetProcAddress(dll, "Direct3DCreate9");
    if (Direct3DCreate9_) {
      LPDIRECT3D9 d3d = Direct3DCreate9_(D3D_SDK_VERSION);
      if (d3d) {
        static const TCHAR windowName[] = TEXT("WPTDxDetect");
        static const TCHAR className[] = TEXT("STATIC");
        HWND wnd = CreateWindowEx(WS_EX_NOACTIVATE, className, windowName,
                                  WS_DISABLED | WS_POPUP, 0, 0, 1, 1,
                                  HWND_MESSAGE, NULL,
                                  GetModuleHandle(NULL), NULL);
        LPDIRECT3DDEVICE9 device = NULL;
        D3DPRESENT_PARAMETERS present_parameters; 
        ZeroMemory( &present_parameters, sizeof(present_parameters) );
        present_parameters.AutoDepthStencilFormat = D3DFMT_UNKNOWN;
        present_parameters.BackBufferCount = 1;
        present_parameters.BackBufferFormat = D3DFMT_UNKNOWN;
        present_parameters.BackBufferWidth = 1;
        present_parameters.BackBufferHeight = 1;
        present_parameters.EnableAutoDepthStencil = FALSE;
        present_parameters.Flags = 0;
        present_parameters.hDeviceWindow = wnd;
        present_parameters.MultiSampleQuality = 0;
        present_parameters.MultiSampleType = D3DMULTISAMPLE_NONE;
        present_parameters.PresentationInterval = D3DPRESENT_INTERVAL_DEFAULT;
        present_parameters.SwapEffect = D3DSWAPEFFECT_DISCARD;
        present_parameters.Windowed = TRUE;

        if (SUCCEEDED(d3d->CreateDevice(D3DADAPTER_DEFAULT, D3DDEVTYPE_HAL,
            wnd, D3DCREATE_FPU_PRESERVE | D3DCREATE_NOWINDOWCHANGES |
            D3DCREATE_SOFTWARE_VERTEXPROCESSING, &present_parameters,
            &device)) && device) {
          has_gpu = true;
          device->Release();
        } else if (SUCCEEDED(d3d->CreateDevice(D3DADAPTER_DEFAULT,
            D3DDEVTYPE_HAL, wnd,
            D3DCREATE_FPU_PRESERVE | D3DCREATE_NOWINDOWCHANGES |
            D3DCREATE_HARDWARE_VERTEXPROCESSING | D3DCREATE_PUREDEVICE,
            &present_parameters, &device)) && device) {
          has_gpu = true;
          device->Release();
        }
        if (wnd)
          DestroyWindow(wnd);
        d3d->Release();
      }
    }
    FreeLibrary(dll);
  }
  return has_gpu;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void WptDriverCore::PreTest() {
  // launch the watchdog just before executing the first test
  if (!watchdog_started_) {
    watchdog_started_ = true;
    TCHAR path[MAX_PATH];
    GetModuleFileName(NULL, path, MAX_PATH);
    lstrcpy(PathFindFileName(path), _T("wptwatchdog.exe"));
    CString watchdog;
    watchdog.Format(_T("\"%s\" %d"), path, GetCurrentProcessId());
    HANDLE process = NULL;
    LaunchProcess(watchdog, &process);
    if (process)
      CloseHandle(process);
  }
}
