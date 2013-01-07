#include "StdAfx.h"
#include "wpt_test_hook.h"
#include "shared_mem.h"


/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
WptTestHook::WptTestHook(DWORD timeout) {
  _measurement_timeout = timeout;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
WptTestHook::~WptTestHook(void) {
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void WptTestHook::LoadFromFile() {
  WptTrace(loglevel::kFunction, _T("[wpthook] - WptTestHook::LoadFromFile\n"));

  HANDLE file = CreateFile(_test_file, GENERIC_READ,0,0, OPEN_EXISTING, 0, 0);
  if (file != INVALID_HANDLE_VALUE) {
    DWORD len = GetFileSize(file, NULL);
    if (len) {
      wchar_t * buff = (wchar_t *) malloc(len + sizeof(wchar_t));
      if (buff) {
        memset(buff, 0, len + sizeof(wchar_t));
        DWORD bytes_read = 0;
        if (ReadFile(file, buff, len, &bytes_read, 0)) {
          CString test_data(buff);
          if (Load(test_data)) {
            _clear_cache = shared_cleared_cache;
            _run = shared_current_run;
            BuildScript();
          }
        }
        free(buff);
      }
    }
    CloseHandle(file);
  }
}

