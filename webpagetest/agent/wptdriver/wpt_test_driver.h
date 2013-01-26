#pragma once
#include "wpt_test.h"

class WptTestDriver :
  public WptTest
{
public:
  WptTestDriver(DWORD default_timeout);
  virtual ~WptTestDriver(void);
  virtual bool  Load(CString& test);
  bool  Start();
  bool  SetFileBase();
};

