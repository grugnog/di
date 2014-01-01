#include "StdAfx.h"
#include "request.h"
#include "test_state.h"
#include "track_sockets.h"
#include "hook_schannel.h"

static SchannelHook* g_hook = NULL;

// Stub Functions

SECURITY_STATUS SEC_ENTRY InitializeSecurityContextW_Hook(
    PCredHandle phCredential,
    PCtxtHandle phContext, SEC_WCHAR * pszTargetName, 
    unsigned long fContextReq, unsigned long Reserved1,
    unsigned long TargetDataRep, PSecBufferDesc pInput,
    unsigned long Reserved2, PCtxtHandle phNewContext,
    PSecBufferDesc pOutput, unsigned long * pfContextAttr,
    PTimeStamp ptsExpiry) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (g_hook)
    ret = g_hook->InitializeSecurityContextW(phCredential, phContext,
            pszTargetName, fContextReq, Reserved1, TargetDataRep, pInput,
            Reserved2, phNewContext, pOutput, pfContextAttr, ptsExpiry);
  return ret;
}

SECURITY_STATUS SEC_ENTRY InitializeSecurityContextA_Hook(
    PCredHandle phCredential,
    PCtxtHandle phContext, SEC_CHAR * pszTargetName, 
    unsigned long fContextReq, unsigned long Reserved1,
    unsigned long TargetDataRep, PSecBufferDesc pInput,
    unsigned long Reserved2, PCtxtHandle phNewContext,
    PSecBufferDesc pOutput, unsigned long * pfContextAttr,
    PTimeStamp ptsExpiry) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (g_hook)
    ret = g_hook->InitializeSecurityContextA(phCredential, phContext,
            pszTargetName, fContextReq, Reserved1, TargetDataRep, pInput,
            Reserved2, phNewContext, pOutput, pfContextAttr, ptsExpiry);
  return ret;
}

SECURITY_STATUS SEC_ENTRY DeleteSecurityContext_Hook(PCtxtHandle phContext) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (g_hook) {
    ret = g_hook->DeleteSecurityContext(phContext);
  }
  return ret;
}

SECURITY_STATUS SEC_ENTRY EncryptMessage_Hook(PCtxtHandle phContext, 
    unsigned long fQOP, PSecBufferDesc pMessage, unsigned long MessageSeqNo) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (g_hook)
    ret = g_hook->EncryptMessage(phContext, fQOP, pMessage, MessageSeqNo);
  return ret;
}

SECURITY_STATUS SEC_ENTRY DecryptMessage_Hook(PCtxtHandle phContext, 
    PSecBufferDesc pMessage, unsigned long MessageSeqNo,
    unsigned long * pfQOP) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (g_hook)
    ret = g_hook->DecryptMessage(phContext, pMessage, MessageSeqNo, pfQOP);
  return ret;
}

BOOL __stdcall CertVerifyCertificateChainPolicy_Hook(
    LPCSTR pszPolicyOID, PCCERT_CHAIN_CONTEXT pChainContext,
    PCERT_CHAIN_POLICY_PARA pPolicyPara,
    PCERT_CHAIN_POLICY_STATUS pPolicyStatus) {
  BOOL ret = FALSE;
  if (g_hook)
    ret = g_hook->CertVerifyCertificateChainPolicy(pszPolicyOID, pChainContext,
                                                   pPolicyPara, pPolicyStatus);
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SchannelHook::SchannelHook(TrackSockets& sockets, TestState& test_state):
  _hook(NULL)
  ,_sockets(sockets)
  ,_test_state(test_state)
  ,InitializeSecurityContextW_(NULL)
  ,InitializeSecurityContextA_(NULL)
  ,DecryptMessage_(NULL)
  ,EncryptMessage_(NULL)
  ,CertVerifyCertificateChainPolicy_(NULL) {
}


/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SchannelHook::~SchannelHook(void){
  if (g_hook == this)
    g_hook = NULL;
  delete _hook;  // remove all the hooks
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
void SchannelHook::Init() {
  if (_hook || g_hook) {
    return;
  }
  _hook = new NCodeHookIA32();
  g_hook = this;
  WptTrace(loglevel::kProcess, _T("[wpthook] SchannelHook::Init()\n"));
  InitializeSecurityContextW_ = _hook->createHookByName(
      "secur32.dll", "InitializeSecurityContextW", 
      InitializeSecurityContextW_Hook);
  InitializeSecurityContextA_ = _hook->createHookByName(
      "secur32.dll", "InitializeSecurityContextA", 
      InitializeSecurityContextA_Hook);
  DeleteSecurityContext_ = _hook->createHookByName(
      "secur32.dll", "DeleteSecurityContext", 
      DeleteSecurityContext_Hook);
  DecryptMessage_ = _hook->createHookByName(
      "secur32.dll", "DecryptMessage", DecryptMessage_Hook);
  EncryptMessage_ = _hook->createHookByName(
      "secur32.dll", "EncryptMessage", EncryptMessage_Hook);
  CertVerifyCertificateChainPolicy_ = _hook->createHookByName(
      "crypt32.dll", "CertVerifyCertificateChainPolicy",
      CertVerifyCertificateChainPolicy_Hook);
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SECURITY_STATUS SchannelHook::InitializeSecurityContextW(
    PCredHandle phCredential,
    PCtxtHandle phContext, SEC_WCHAR * pszTargetName, 
    unsigned long fContextReq, unsigned long Reserved1,
    unsigned long TargetDataRep, PSecBufferDesc pInput,
    unsigned long Reserved2, PCtxtHandle phNewContext,
    PSecBufferDesc pOutput, unsigned long * pfContextAttr,
    PTimeStamp ptsExpiry) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (InitializeSecurityContextW_) {
    ret = InitializeSecurityContextW_(phCredential, phContext,
            pszTargetName, fContextReq, Reserved1, TargetDataRep, pInput,
            Reserved2, phNewContext, pOutput, pfContextAttr, ptsExpiry);
    if (!phContext && phNewContext) {
      _sockets.SetSslFd((PRFileDesc *)phNewContext);
    }
  }
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SECURITY_STATUS SchannelHook::InitializeSecurityContextA(
    PCredHandle phCredential,
    PCtxtHandle phContext, SEC_CHAR * pszTargetName, 
    unsigned long fContextReq, unsigned long Reserved1,
    unsigned long TargetDataRep, PSecBufferDesc pInput,
    unsigned long Reserved2, PCtxtHandle phNewContext,
    PSecBufferDesc pOutput, unsigned long * pfContextAttr,
    PTimeStamp ptsExpiry) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (InitializeSecurityContextA_) {
    ret = InitializeSecurityContextA_(phCredential, phContext,
            pszTargetName, fContextReq, Reserved1, TargetDataRep, pInput,
            Reserved2, phNewContext, pOutput, pfContextAttr, ptsExpiry);
    if (!phContext && phNewContext) {
      _sockets.SetSslFd((PRFileDesc *)phNewContext);
    }
  }
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SECURITY_STATUS SchannelHook::DeleteSecurityContext(PCtxtHandle phContext) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (phContext) {
    _sockets.ClearSslFd((PRFileDesc *)phContext);
  }
  if (DeleteSecurityContext_)
    ret = DeleteSecurityContext_(phContext);
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SECURITY_STATUS SchannelHook::EncryptMessage(PCtxtHandle phContext, 
    unsigned long fQOP, PSecBufferDesc pMessage, unsigned long MessageSeqNo) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (EncryptMessage_) {
    SOCKET s = INVALID_SOCKET;
    _sockets.SslSocketLookup((PRFileDesc *)phContext, s);
    if (pMessage && !_test_state._exit) {
      for (ULONG i = 0; i < pMessage->cBuffers; i++) {
        unsigned long len = pMessage->pBuffers[i].cbBuffer;
        if (pMessage->pBuffers[i].pvBuffer && len &&
            pMessage->pBuffers[i].BufferType == SECBUFFER_DATA) {
          DataChunk chunk((LPCSTR)pMessage->pBuffers[i].pvBuffer, len);
          // TODO, allow for actual modification of the buffer
          //if (_sockets.ModifyDataOut(s, chunk, true)) {
          //}
          _sockets.DataOut(s, chunk, true);
        }
      }
    }
    ret = EncryptMessage_(phContext, fQOP, pMessage, MessageSeqNo);
  }
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
SECURITY_STATUS SchannelHook::DecryptMessage(PCtxtHandle phContext, 
    PSecBufferDesc pMessage,unsigned long MessageSeqNo,unsigned long * pfQOP) {
  SECURITY_STATUS ret = SEC_E_INTERNAL_ERROR;
  if (DecryptMessage_) {
    SOCKET s = INVALID_SOCKET;
    ret = DecryptMessage_(phContext, pMessage, MessageSeqNo, pfQOP);
    if (ret == SEC_E_OK && pMessage && !_test_state._exit) {
      if (_sockets.SslSocketLookup((PRFileDesc *)phContext, s) && 
            s != INVALID_SOCKET) {
        for (ULONG i = 0; i < pMessage->cBuffers; i++) {
          unsigned long len = pMessage->pBuffers[i].cbBuffer;
          if (pMessage->pBuffers[i].pvBuffer && len &&
              pMessage->pBuffers[i].BufferType == SECBUFFER_DATA) {
            _sockets.DataIn(s, 
                      DataChunk((LPCSTR)pMessage->pBuffers[i].pvBuffer, len), 
                      true);
          }
        }
      }
    }
  }
  return ret;
}

/*-----------------------------------------------------------------------------
-----------------------------------------------------------------------------*/
BOOL SchannelHook::CertVerifyCertificateChainPolicy(
  LPCSTR pszPolicyOID, PCCERT_CHAIN_CONTEXT pChainContext,
  PCERT_CHAIN_POLICY_PARA pPolicyPara,
  PCERT_CHAIN_POLICY_STATUS pPolicyStatus) {
  BOOL ret = TRUE;
  if (pPolicyStatus)
    pPolicyStatus->dwError = 0;
  return ret;
}
