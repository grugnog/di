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
#include "wpt_settings.h"
#include "wpt_status.h"
#include <WinInet.h>
#include "zlib/contrib/minizip/unzip.h"

bool Unzip(CString file, CStringA dir);

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
WptSettings::WptSettings(WptStatus &status):
  _timeout(DEFAULT_TEST_TIMEOUT)
  ,_startup_delay(DEFAULT_STARTUP_DELAY)
  ,_polling_delay(DEFAULT_POLLING_DELAY)
  ,_debug(0)
  ,_status(status)
  ,_software_update(status) {
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
WptSettings::~WptSettings(void) {
}

/*-----------------------------------------------------------------------------
  Load the settings file
-----------------------------------------------------------------------------*/
bool WptSettings::Load(void) {
  bool ret = false;

  TCHAR buff[10240];
  TCHAR iniFile[MAX_PATH];
  TCHAR logFile[MAX_PATH];
  iniFile[0] = 0;
  GetModuleFileName(NULL, iniFile, _countof(iniFile));
  lstrcpy( PathFindFileName(iniFile), _T("wptdriver.ini") );
  _ini_file = iniFile;
  lstrcpy(logFile, iniFile);
  lstrcpy( PathFindFileName(logFile), _T("wpt.log") );
  DeleteFile(logFile);

  if (SUCCEEDED(SHGetFolderPath(NULL, CSIDL_APPDATA | CSIDL_FLAG_CREATE,
                                NULL, SHGFP_TYPE_CURRENT, buff))) {
    PathAppend(buff, _T("webpagetest_clients"));
    _clients_directory = buff;
    SHCreateDirectoryEx(NULL, _clients_directory, NULL);
    _clients_directory += _T("\\");
  }

  // Load the server settings (WebPagetest Web Server)
  if (GetPrivateProfileString(_T("WebPagetest"), _T("Url"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _server = buff;
    if( _server.Right(1) != '/' )
      _server += "/";
  }

  if (GetPrivateProfileString(_T("WebPagetest"), _T("Location"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _location = buff;
  }

  if (GetPrivateProfileString(_T("WebPagetest"), _T("Key"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _key = buff;
  }

  #ifdef DEBUG
  _debug = 9;
  #else
  _debug = GetPrivateProfileInt(_T("WebPagetest"), _T("Debug"),_debug,iniFile);
  #endif
  SetDebugLevel(_debug, logFile);

  // load the test parameters
  _timeout = GetPrivateProfileInt(_T("WebPagetest"), _T("Time Limit"),
                                  _timeout, iniFile);

  // load the Web Page Replay host
  if (GetPrivateProfileString(
      _T("WebPagetest"), _T("web_page_replay_host"), _T(""), buff,
      _countof(buff), iniFile )) {
    _web_page_replay_host = buff;
  }

  // see if we need to load settings from EC2 (server and location)
  if (GetPrivateProfileInt(_T("WebPagetest"), _T("ec2"), 0, iniFile)) {
    LoadFromEC2();
  }

  SetTestTimeout(_timeout * SECONDS_TO_MS);
  if (_server.GetLength() && _location.GetLength())
    ret = true;

  _software_update.LoadSettings(iniFile);

  return ret;
}

/*-----------------------------------------------------------------------------
  Load the settings from EC2 User Data
  We have to support the old "urlblast" format settings because both may
  be running on the same machine
-----------------------------------------------------------------------------*/
void WptSettings::LoadFromEC2(void) {

  CString userData;
  if (GetUrlText(_T("http://169.254.169.254/latest/user-data"), userData)) {
    int pos = 0;
    do {
      CString token = userData.Tokenize(_T(" &"), pos).Trim();
      if (token.GetLength()) {
        int split = token.Find(_T('='), 0);
        if (split > 0) {
          CString key = token.Left(split).Trim();
          CString value = token.Mid(split + 1).Trim();

          if (key.GetLength() && value.GetLength()) {
            if (!key.CompareNoCase(_T("wpt_server")))
              _server = CString(_T("http://")) + value + _T("/");
            else if (!key.CompareNoCase(_T("wpt_loc")))
              _location = value; 
            else if (_location.IsEmpty() &&
                     !key.CompareNoCase(_T("wpt_location")))
              _location = value + _T("_wptdriver"); 
            else if (!key.CompareNoCase(_T("wpt_key")) )
              _key = value; 
            else if (!key.CompareNoCase(_T("wpt_timeout")))
              _timeout = _ttol(value); 
          }
        }
      }
    } while (pos > 0);
    if (_location.IsEmpty()) {
      CString zone;
      if (GetUrlText(_T("http://169.254.169.254/latest/meta-data")
                     _T("/placement/availability-zone"), zone)) {
        int pos = zone.Find('-');
        if (pos > 0) {
          pos = zone.Find('-', pos + 1);
          if (pos > 0)
            _location = CString(_T("ec2-")) + zone.Left(pos).Trim();
        }
      }
    }
  }

  GetUrlText(_T("http://169.254.169.254/latest/meta-data/instance-id"), 
    _ec2_instance);
  _ec2_instance = _ec2_instance.Trim();
}

/*-----------------------------------------------------------------------------
  Get a string response from the given url
-----------------------------------------------------------------------------*/
bool WptSettings::GetUrlText(CString url, CString &response)
{
  bool ret = false;
  response.Empty();

  HINTERNET internet = InternetOpen(_T("WebPagetest Driver"), 
                                    INTERNET_OPEN_TYPE_PRECONFIG,
                                    NULL, NULL, 0);
  if (internet) {
    HINTERNET http_request = InternetOpenUrl(internet, url, NULL, 0, 
                                INTERNET_FLAG_NO_CACHE_WRITE | 
                                INTERNET_FLAG_NO_UI | 
                                INTERNET_FLAG_PRAGMA_NOCACHE | 
                                INTERNET_FLAG_RELOAD, NULL);
    if (http_request) {
      ret = true;
      char buff[4097];
      DWORD bytes_read;
      HANDLE file = INVALID_HANDLE_VALUE;
      while (InternetReadFile(http_request, buff, sizeof(buff) - 1, 
              &bytes_read) && bytes_read) {
        // NULL-terminate it and add it to our response string
        buff[bytes_read] = 0;
        response += CA2T(buff);
      }
      if (file != INVALID_HANDLE_VALUE)
        CloseHandle(file);
      InternetCloseHandle(http_request);
    }
    InternetCloseHandle(internet);
  }

  return ret;
}


/*-----------------------------------------------------------------------------
  Load the settings for the specified browser 
  (this will be done on every test run in order to support 
  multi-browser testing)
-----------------------------------------------------------------------------*/
bool WptSettings::SetBrowser(CString browser, CString url,
                             CString md5, CString client) {
  bool ret = false;

  _browser.CleanupCustomBrowsers(browser);

  if (!url.IsEmpty() && !md5.IsEmpty()) {
    // we are running a custom chrome browser
    ret = _browser.Install(browser, url, md5);
  } else {
    // try loading the settings for the specified browser
    TCHAR buff[1024];
    if (!browser.GetLength()) {
      browser = _T("chrome");  // default to "chrome" to support older ini file
      if (GetPrivateProfileString(_T("WebPagetest"), _T("browser"), _T(""), buff,
        _countof(buff), _ini_file )) {
        browser = buff;
      }
    }
    ret = _browser.Load(browser, _ini_file, client);
  }
  return ret;
}

/*-----------------------------------------------------------------------------
  Update the various browsers
-----------------------------------------------------------------------------*/
bool WptSettings::UpdateSoftware() {
  return _software_update.UpdateSoftware();
}

/*-----------------------------------------------------------------------------
  Re-install the current browser
-----------------------------------------------------------------------------*/
bool WptSettings::ReInstallBrowser() {
  return _software_update.ReInstallBrowser(_browser._browser);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
bool BrowserSettings::Load(const TCHAR * browser, const TCHAR * iniFile,
                           CString client) {
  bool ret = false;
  TCHAR buff[10240];
  _browser = browser;
  _template = _browser;
  _exe.Empty();
  _exe_directory.Empty();
  _options.Empty();

  AtlTrace(_T("Loading settings for %s"), (LPCTSTR)browser);

  GetModuleFileName(NULL, buff, _countof(buff));
  *PathFindFileName(buff) = NULL;
  _wpt_directory = buff;
  _wpt_directory.Trim(_T("\\"));

  GetStandardDirectories();

  // create a profile directory for the given browser
  _profile_directory = _wpt_directory + _T("\\profiles\\");
  if (!app_data_dir_.IsEmpty()) {
    lstrcpy(buff, app_data_dir_);
    PathAppend(buff, _T("webpagetest_profiles\\"));
    _profile_directory = buff;
  }
  if (client.GetLength())
    _profile_directory += client + _T("-client-");
  _profile_directory += browser;
  if (GetPrivateProfileString(browser, _T("cache"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _profile_directory = buff;
    _profile_directory.Trim();
    _profile_directory.Replace(_T("%WPTDIR%"), _wpt_directory);
  }

  if (GetPrivateProfileString(browser, _T("template"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _template = buff;
    _template.Trim();
  }

  if (GetPrivateProfileString(browser, _T("exe"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _exe = buff;
    _exe.Replace(_T("%PROGRAM_FILES%"), program_files_dir_);
    _exe.Trim(_T("\""));

    lstrcpy(buff, _exe);
    *PathFindFileName(buff) = NULL;
    _exe_directory = buff;
    _exe_directory.Trim(_T("/\\"));
    ret = true;
  }

  if (GetPrivateProfileString(browser, _T("options"), _T(""), buff, 
    _countof(buff), iniFile )) {
    _options = buff;
    _options.Trim(_T("\""));
    _options.Replace(_T("%WPTDIR%"), _wpt_directory);
    _options.Replace(_T("%PROFILE%"), _profile_directory);
  }

  // set up some browser-specific settings
  CString exe(_exe);
  exe.MakeLower();
  if (exe.Find(_T("safari.exe")) >= 0) {
    _profile_directory = app_data_dir_ + _T("\\Apple Computer");
    if (_template.IsEmpty()) {
      _template = _T("Safari");
    }
    if (_cache_directory.IsEmpty()) {
      _cache_directory = local_app_data_dir_ + _T("\\Apple Computer\\Safari");
    }
  }

  return ret;
}

/*-----------------------------------------------------------------------------
  Download and install a custom Chrome build
-----------------------------------------------------------------------------*/
bool BrowserSettings::Install(CString browser, CString url, CString md5) {
  bool ret = false;
  TCHAR buff[10240];
  _browser = browser;
  _template = _browser;
  _exe.Empty();
  _exe_directory.Empty();
  _options.Empty();

  AtlTrace(_T("Checking custom browser: %s"), (LPCTSTR)browser);

  GetModuleFileName(NULL, buff, _countof(buff));
  *PathFindFileName(buff) = NULL;
  _wpt_directory = buff;
  _wpt_directory.Trim(_T("\\"));
  CString browsers_directory = _wpt_directory + CString(_T("\\browsers"));
  CreateDirectory(browsers_directory, NULL);
  _exe_directory = browsers_directory + CString(_T("\\")) + browser;
  CreateDirectory(_exe_directory, NULL);
  _exe = _exe_directory + _T("\\chrome.exe");

  GetStandardDirectories();

  // create a profile directory for the given browser
  _profile_directory = _wpt_directory + _T("\\profiles\\");
  if (!app_data_dir_.IsEmpty()) {
    lstrcpy(buff, app_data_dir_);
    PathAppend(buff, _T("webpagetest_profiles\\"));
    _profile_directory = buff;
  }
  _profile_directory += browser;

  _options = _T("--load-extension=\"%WPTDIR%\\extension\" ")
             _T("--user-data-dir=\"%PROFILE%\" ")
             _T("--no-proxy-server");
  _options.Replace(_T("%WPTDIR%"), _wpt_directory);
  _options.Replace(_T("%PROFILE%"), _profile_directory);

  if (FileExists(_exe)) {
    // update the last used time for the custom browser
    HANDLE hFile = CreateFile(_exe_directory, FILE_WRITE_ATTRIBUTES,
        FILE_SHARE_READ | FILE_SHARE_WRITE, 0, OPEN_EXISTING, 0, 0);
    if (hFile != INVALID_HANDLE_VALUE) {
      FILETIME ft;
      GetSystemTimeAsFileTime(&ft);
      SetFileTime(hFile, NULL, NULL, &ft);
      CloseHandle(hFile);
    }
    ret = true;
  } else {
    CString browser_zip = _exe_directory + _T(".zip");
    if (HttpSaveFile(url, browser_zip) &&
        !HashFileMD5(browser_zip).CompareNoCase(md5) &&
        Unzip(browser_zip, (LPCSTR)CT2A(_exe_directory)) &&
        FileExists(_exe))
      ret = true;
    else
      DeleteDirectory(_exe_directory);
    DeleteFile(browser_zip);
  }

  return ret;
}

/*-----------------------------------------------------------------------------
  Check all of the custom browsers and delete any that haven't been used
  recently (except for the specified browser)
-----------------------------------------------------------------------------*/
void BrowserSettings::CleanupCustomBrowsers(CString browser) {
  TCHAR buff[10240];
  GetModuleFileName(NULL, buff, _countof(buff));
  *PathFindFileName(buff) = NULL;
  _wpt_directory = buff;
  _wpt_directory.Trim(_T("\\"));
  CString browsers_directory = _wpt_directory + CString(_T("\\browsers"));
  WIN32_FIND_DATA fd;
  HANDLE hFind = FindFirstFile(browsers_directory + _T("\\*.*"), &fd);
  FILETIME now;
  GetSystemTimeAsFileTime(&now);
  if (hFind != INVALID_HANDLE_VALUE) {
    do {
      if (lstrcmp(fd.cFileName, _T(".")) &&
          lstrcmp(fd.cFileName, _T("..")) &&
          lstrcmp(fd.cFileName, browser) &&
          fd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) {
        if (ElapsedFileTimeSeconds(fd.ftLastWriteTime, now) > 86400)
          DeleteDirectory(browsers_directory +
                          CString(_T("\\")) + fd.cFileName);
      } else if (!CString(fd.cFileName).Right(4).CompareNoCase(_T(".zip"))) {
        // delete all of the zip files
        DeleteFile(browsers_directory + CString(_T("\\")) + fd.cFileName);
      }
    } while(FindNextFile(hFind, &fd));
  }
}

/*-----------------------------------------------------------------------------
  Reset the browser user profile (nuke the directory, copy the template over)
-----------------------------------------------------------------------------*/
void BrowserSettings::ResetProfile(bool clear_certs) {
  // clear the browser-specific profile directory
  if (_cache_directory.GetLength()) {
    DeleteDirectory(_cache_directory, false);
  }
  if (_profile_directory.GetLength() ) {
    SHCreateDirectoryEx(NULL, _profile_directory, NULL);
    DeleteDirectory(_profile_directory, false);
    CopyDirectoryTree(_wpt_directory + CString(_T("\\templates\\"))+_template,
                      _profile_directory);
  }

  // flush the certificate revocation caches
  if (clear_certs) {
    LaunchProcess(_T("certutil.exe -urlcache * delete"));
    LaunchProcess(
        _T("certutil.exe -setreg chain\\ChainCacheResyncFiletime @now"));
  }

  // Clear the various IE caches that we know about
  CString match = _exe;
  if (match.MakeLower().Find(_T("iexplore.exe")) >= 0) {
    DeleteRegKey(HKEY_CURRENT_USER, 
        _T("Software\\Microsoft\\Internet Explorer\\LowRegistry\\DOMStorage"),
        false);
    DeleteRegKey(HKEY_CURRENT_USER,
        _T("Software\\Microsoft\\Internet Explorer\\DOMStorage"),
        false);
    DeleteDirectory(cookies_dir_, false);
    DeleteDirectory(history_dir_, false);
    DeleteDirectory(dom_storage_dir_, false);
    DeleteDirectory(temp_files_dir_, false);
    DeleteDirectory(temp_dir_, false);
    DeleteDirectory(silverlight_dir_, false);
    DeleteDirectory(recovery_dir_, false);
    DeleteDirectory(flash_dir_, false);
    DeleteDirectory(windows_dir_ + _T("\\temp"), false);
    ClearWinInetCache();
    ClearWebCache();
  }

  // delete any .tmp files in our directory or the root directory of the drive.
  // Not sure where they are coming from but they collect over time.
  WIN32_FIND_DATA fd;
  HANDLE find = FindFirstFile(_wpt_directory + _T("\\*.tmp"), &fd);
  if (find != INVALID_HANDLE_VALUE) {
    do {
      DeleteFile(_wpt_directory + CString(_T("\\")) + fd.cFileName);
    } while(FindNextFile(find, &fd));
    FindClose(find);
  }
  find = FindFirstFile(_T("C:\\*.tmp"), &fd);
  if (find != INVALID_HANDLE_VALUE) {
    do {
      DeleteFile(CString(_T("C:\\")) + fd.cFileName);
    } while(FindNextFile(find, &fd));
    FindClose(find);
  }
}

/*-----------------------------------------------------------------------------
  Locate the directories for a bunch of Windows caches
-----------------------------------------------------------------------------*/
void BrowserSettings::GetStandardDirectories() {
  TCHAR path[4096];
  windows_dir_ = _T("c:\\windows");
  if (GetWindowsDirectory(path, _countof(path)))
    windows_dir_ = path;
  if (SUCCEEDED(SHGetFolderPath(NULL, CSIDL_APPDATA | CSIDL_FLAG_CREATE,
                                NULL, SHGFP_TYPE_CURRENT, path)))
    app_data_dir_ = path;
  if (SUCCEEDED(SHGetFolderPath(NULL, CSIDL_LOCAL_APPDATA | CSIDL_FLAG_CREATE,
                                NULL, SHGFP_TYPE_CURRENT, path)))
    local_app_data_dir_ = path;
  if (SUCCEEDED(SHGetFolderPath(NULL, CSIDL_PROGRAM_FILES,
                                NULL, SHGFP_TYPE_CURRENT, path)))
    program_files_dir_ = path;
  if (SHGetSpecialFolderPath(NULL, path, CSIDL_PROFILE, FALSE))
    profile_dir_ = path;
  HKEY hKey;
  if (SUCCEEDED(RegOpenKeyEx(HKEY_CURRENT_USER,
      L"Software\\Microsoft\\Windows\\CurrentVersion"
      L"\\Explorer\\User Shell Folders", 0, KEY_READ, &hKey))) {
    DWORD len = _countof(path);
    if (SUCCEEDED(RegQueryValueEx(hKey, _T("Cookies"), 0, 0, 
                                  (LPBYTE)path, &len)))
      cookies_dir_ = path;
    len = _countof(path);
    if (SUCCEEDED(RegQueryValueEx(hKey, _T("History"), 0, 0, 
                                  (LPBYTE)path, &len)))
      history_dir_ = path;
    len = _countof(path);
    if (SUCCEEDED(RegQueryValueEx(hKey, _T("Cache"), 0, 0,
                                  (LPBYTE)path, &len)))
      temp_files_dir_ = path;
    temp_dir_ = local_app_data_dir_ + L"\\Temp";
    flash_dir_ = app_data_dir_ + L"\\Macromedia\\Flash Player\\#SharedObjects";
    recovery_dir_ = local_app_data_dir_ + 
        L"\\Microsoft\\Internet Explorer\\Recovery\\Active";
    silverlight_dir_ = local_app_data_dir_ + L"\\Microsoft\\Silverlight";

    RegCloseKey(hKey);
  }
  if (SUCCEEDED(RegOpenKeyEx(HKEY_CURRENT_USER,
      L"Software\\Microsoft\\Windows\\CurrentVersion\\Internet Settings"
      L"\\5.0\\Cache\\Extensible Cache\\DOMStore", 0, KEY_READ, &hKey))) {
    DWORD len = _countof(path);
    if (SUCCEEDED(RegQueryValueEx(hKey, L"CachePath", 0, 0,
                                  (LPBYTE)path, &len)))
      dom_storage_dir_ = path;
    RegCloseKey(hKey);
  }
  webcache_dir_ = local_app_data_dir_ + L"\\Microsoft\\Windows\\WebCache";

  cookies_dir_.Replace(_T("%USERPROFILE%"), profile_dir_);
  history_dir_.Replace(_T("%USERPROFILE%"), profile_dir_);
  temp_files_dir_.Replace(_T("%USERPROFILE%"), profile_dir_);
  dom_storage_dir_.Replace(_T("%USERPROFILE%"), profile_dir_);
}

/*-----------------------------------------------------------------------------
  Clear out the WinInet caches (have to do this before launching the browser)
-----------------------------------------------------------------------------*/
void BrowserSettings::ClearWinInetCache() {
  HANDLE hEntry;
  DWORD len, entry_size = 0;
  GROUPID id;
  INTERNET_CACHE_ENTRY_INFO * info = NULL;
  HANDLE hGroup = FindFirstUrlCacheGroup(0, CACHEGROUP_SEARCH_ALL, 0,
                                         0, &id, 0);
  if (hGroup) {
    do {
      len = entry_size;
      hEntry = FindFirstUrlCacheEntryEx(NULL, 0, 0xFFFFFFFF, id, info, &len,
                                        NULL, NULL, NULL);
      if (!hEntry && GetLastError() == ERROR_INSUFFICIENT_BUFFER && len) {
        entry_size = len;
        info = (INTERNET_CACHE_ENTRY_INFO *)realloc(info, len);
        if (info) {
          hEntry = FindFirstUrlCacheEntryEx(NULL, 0, 0xFFFFFFFF, id, info,
                                            &len, NULL, NULL, NULL);
        }
      }
      if (hEntry && info) {
        bool ok = true;
        do {
          DeleteUrlCacheEntry(info->lpszSourceUrlName);
          len = entry_size;
          if (!FindNextUrlCacheEntryEx(hEntry, info, &len, NULL, NULL, NULL)) {
            if (GetLastError() == ERROR_INSUFFICIENT_BUFFER && len) {
              entry_size = len;
              info = (INTERNET_CACHE_ENTRY_INFO *)realloc(info, len);
              if (info) {
                if (!FindNextUrlCacheEntryEx(hEntry, info, &len,
                                             NULL, NULL, NULL)) {
                  ok = false;
                }
              }
            } else {
              ok = false;
            }
          }
        } while (ok);
      }
      if (hEntry) {
        FindCloseUrlCache(hEntry);
      }
      DeleteUrlCacheGroup(id, CACHEGROUP_FLAG_FLUSHURL_ONDELETE, 0);
    } while(FindNextUrlCacheGroup(hGroup, &id,0));
    FindCloseUrlCache(hGroup);
  }

  len = entry_size;
  hEntry = FindFirstUrlCacheEntryEx(NULL, 0, 0xFFFFFFFF, 0, info, &len,
                                    NULL, NULL, NULL);
  if (!hEntry && GetLastError() == ERROR_INSUFFICIENT_BUFFER && len) {
    entry_size = len;
    info = (INTERNET_CACHE_ENTRY_INFO *)realloc(info, len);
    if (info) {
      hEntry = FindFirstUrlCacheEntryEx(NULL, 0, 0xFFFFFFFF, 0, info, &len,
                                        NULL, NULL, NULL);
    }
  }
  if (hEntry && info) {
    bool ok = true;
    do {
      DeleteUrlCacheEntry(info->lpszSourceUrlName);
      len = entry_size;
      if (!FindNextUrlCacheEntryEx(hEntry, info, &len, NULL, NULL, NULL)) {
        if (GetLastError() == ERROR_INSUFFICIENT_BUFFER && len) {
          entry_size = len;
          info = (INTERNET_CACHE_ENTRY_INFO *)realloc(info, len);
          if (info) {
            if (!FindNextUrlCacheEntryEx(hEntry, info, &len,
                                         NULL, NULL, NULL)) {
              ok = false;
            }
          }
        } else {
          ok = false;
        }
      }
    } while (ok);
  }
  if (hEntry) {
    FindCloseUrlCache(hEntry);
  }

  len = entry_size;
  hEntry = FindFirstUrlCacheEntry(NULL, info, &len);
  if (!hEntry && GetLastError() == ERROR_INSUFFICIENT_BUFFER && len) {
    entry_size = len;
    info = (INTERNET_CACHE_ENTRY_INFO *)realloc(info, len);
    if (info) {
      hEntry = FindFirstUrlCacheEntry(NULL, info, &len);
    }
  }
  if (hEntry && info) {
    bool ok = true;
    do {
      DeleteUrlCacheEntry(info->lpszSourceUrlName);
      len = entry_size;
      if (!FindNextUrlCacheEntry(hEntry, info, &len)) {
        if (GetLastError() == ERROR_INSUFFICIENT_BUFFER && len) {
          entry_size = len;
          info = (INTERNET_CACHE_ENTRY_INFO *)realloc(info, len);
          if (info) {
            if (!FindNextUrlCacheEntry(hEntry, info, &len)) {
              ok = false;
            }
          }
        } else {
          ok = false;
        }
      }
    } while (ok);
  }
  if (hEntry) {
    FindCloseUrlCache(hEntry);
  }
  if (info)
    free(info);

  // This magic value is the combination of the following bitflags:
  // #define CLEAR_HISTORY         0x0001 // Clears history
  // #define CLEAR_COOKIES         0x0002 // Clears cookies
  // #define CLEAR_CACHE           0x0004 // Clears Temporary Internet Files folder
  // #define CLEAR_CACHE_ALL       0x0008 // Clears offline favorites and download history
  // #define CLEAR_FORM_DATA       0x0010 // Clears saved form data for form auto-fill-in
  // #define CLEAR_PASSWORDS       0x0020 // Clears passwords saved for websites
  // #define CLEAR_PHISHING_FILTER 0x0040 // Clears phishing filter data
  // #define CLEAR_RECOVERY_DATA   0x0080 // Clears webpage recovery data
  // #define CLEAR_PRIVACY_ADVISOR 0x0800 // Clears tracking data
  // #define CLEAR_SHOW_NO_GUI     0x0100 // Do not show a GUI when running the cache clearing
  //
  // Bitflags available but not used in this magic value are as follows:
  // #define CLEAR_USE_NO_THREAD      0x0200 // Do not use multithreading for deletion
  // #define CLEAR_PRIVATE_CACHE      0x0400 // Valid only when browser is in private browsing mode
  // #define CLEAR_DELETE_ALL         0x1000 // Deletes data stored by add-ons
  // #define CLEAR_PRESERVE_FAVORITES 0x2000 // Preserves cached data for "favorite" websites

  // Use the command-line version of cache clearing in case WinInet didn't work
  LaunchProcess(_T("RunDll32.exe InetCpl.cpl,ClearMyTracksByProcess 6655"));
}

/*-----------------------------------------------------------------------------
  Delete the connection tracking history in IE 10
-----------------------------------------------------------------------------*/
void BrowserSettings::ClearWebCache() {
  CAtlList<DWORD> processes;
  POSITION pos;

  // Kill all running instances of dllhost.exe and taskhostex.exe.
  // It's ugly but it's the only way to nuke the connection cache files
  // right now
  FindProcessIds(L"dllhost.exe", processes);
  FindProcessIds(L"taskhostex.exe", processes);

  if (!processes.IsEmpty()) {
    pos = processes.GetHeadPosition();
    while (pos) {
      DWORD pid = processes.GetNext(pos);
      TerminateProcessById(pid);
    }
  }

  DeleteDirectory(webcache_dir_, false);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
static bool Unzip(CString file, CStringA dir) {
  bool ret = false;

  dir = dir.Trim("\\") + "\\";
  unzFile zip_file_handle = unzOpen(CT2A(file));
  if (zip_file_handle) {
    ret = true;
    if (unzGoToFirstFile(zip_file_handle) == UNZ_OK) {
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
