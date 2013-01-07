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
#include "ncodehook/NCodeHookInstantiation.h"

class TestState;

/******************************************************************************
*******************************************************************************
**																			                                     **
**								          Function Prototypes		                           **
**																			                                     **
*******************************************************************************
******************************************************************************/

typedef BOOL(__stdcall * LPENDPAINT)(HWND hWnd, CONST PAINTSTRUCT *lpPaint);
typedef int (__stdcall * LPRELEASEDC)(HWND hWnd, HDC hDC);
typedef BOOL(__stdcall * LPSETWINDOWTEXTA)(HWND hWnd, LPCSTR text);
typedef BOOL(__stdcall * LPSETWINDOWTEXTW)(HWND hWnd, LPCWSTR text);

/******************************************************************************
*******************************************************************************
**                                                                           **
**								            CGDIHook Class                                 **
**																			                                     **
*******************************************************************************
******************************************************************************/
class CGDIHook {
public:
  CGDIHook(TestState& test_state);
  ~CGDIHook(void);
  void Init();
  
  BOOL	EndPaint(HWND hWnd, CONST PAINTSTRUCT *lpPaint);
  int   ReleaseDC(HWND hWnd, HDC hDC);
  BOOL  SetWindowTextA(HWND hWnd, LPCSTR text);
  BOOL  SetWindowTextW(HWND hWnd, LPCWSTR text);

private:
  NCodeHookIA32	hook;
  TestState&  _test_state;
  CRITICAL_SECTION	cs;
  CAtlMap<HWND, bool>	_document_windows;

  LPENDPAINT		    _EndPaint;
  LPRELEASEDC       _ReleaseDC;
  LPSETWINDOWTEXTA  _SetWindowTextA;
  LPSETWINDOWTEXTW  _SetWindowTextW;
};
