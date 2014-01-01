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

#pragma once

/*typedef struct {
  char* pattern;
  TCHAR* name;
} CDN_PROVIDER;*/
typedef struct {
  CStringA pattern;
  CStringA name;
} CDN_PROVIDER;

typedef struct {
  CStringA response_field;
  CStringA pattern;
  CStringA name;
} CDN_PROVIDER_HEADER;

CDN_PROVIDER cdnList[] = {
  {".akamai.net", "Akamai"},
  {".akamaiedge.net", "Akamai"},
  {".llnwd.net", "Limelight"},
  {"edgecastcdn.net", "Edgecast"},
  {".systemcdn.net", "Edgecast"},
  {".transactcdn.net", "Edgecast"},
  {".v1cdn.net", "Edgecast"},
  {".v2cdn.net", "Edgecast"},
  {".v3cdn.net", "Edgecast"},
  {".v4cdn.net", "Edgecast"},
  {".v5cdn.net", "Edgecast"},
  {"hwcdn.net", "Highwinds"},
  {".simplecdn.net", "Simple CDN"},
  {".instacontent.net", "Mirror Image"},
  {".footprint.net", "Level 3"},
  {".ay1.b.yahoo.com", "Yahoo"},
  {".yimg.", "Yahoo"},
  {".yahooapis.com", "Yahoo"},
  {".google.", "Google"},
  {"googlesyndication.", "Google"},
  {"youtube.", "Google"},
  {".googleusercontent.com", "Google"},
  {"googlehosted.com", "Google"},
  {".insnw.net", "Instart Logic"},
  {".inscname.net", "Instart Logic"},
  {".internapcdn.net", "Internap"},
  {".cloudfront.net", "Amazon CloudFront"},
  {".netdna-cdn.com", "NetDNA"},
  {".netdna-ssl.com", "NetDNA"},
  {".netdna.com", "NetDNA"},
  {".cotcdn.net", "Cotendo CDN"},
  {".cachefly.net", "Cachefly"},
  {"bo.lt", "BO.LT"},
  {".cloudflare.com", "Cloudflare"},
  {".afxcdn.net", "afxcdn.net"},
  {".lxdns.com", "ChinaNetCenter"},
  {".att-dsa.net", "AT&T"},
  {".vo.msecnd.net", "Windows Azure"},
  {".voxcdn.net", "VoxCDN"},
  {".bluehatnetwork.com", "Blue Hat Network"},
  {".swiftcdn1.com", "SwiftCDN"},
  {".cdngc.net", "CDNetworks"},
  {".gccdn.net", "CDNetworks"},
  {".panthercdn.com", "CDNetworks"},
  {".fastly.net", "Fastly"},
  {".nocookie.net", "Fastly"},
  {".gslb.taobao.com", "Taobao"},
  {".gslb.tbcache.com", "Alimama"},
  {".mirror-image.net", "Mirror Image"},
  {".yottaa.net", "Yottaa"},
  {".cubecdn.net", "cubeCDN"},
  {".r.cdn77.net", "CDN77"},
  {".incapdns.net", "Incapsula"},
  {".bitgravity.com", "BitGravity"},
  {".r.worldcdn.net", "OnApp"},
  {".r.worldssl.net", "OnApp"},
  {"tbcdn.cn", "Taobao"},
  {".taobaocdn.com", "Taobao"},
  {".ngenix.net", "NGENIX"},
  {".pagerain.net", "PageRain"},
  {".ccgslb.com", "ChinaCache"},
  {"cdn.sfr.net", "SFR"},
  {".azioncdn.net", "Azion"},
  {".azioncdn.com", "Azion"},
  {".azion.net", "Azion"},
  {".cdncloud.net.au", "MediaCloud"},
  {".rncdn1.com", "Reflected Networks"},
  {".cdnsun.net", "CDNsun"},
  {".mncdn.com", "Medianova"},
  {".mncdn.net", "Medianova"},
  {".mncdn.org", "Medianova"},
  {"cdn.jsdelivr.net", "jsDelivr"},
  {"END_MARKER", "END_MARKER"}
};

CDN_PROVIDER_HEADER cdnHeaderList[] = {
  {"server", "cloudflare", "Cloudflare"},
  {"server", "ECS", "Edgecast"},
  {"server", "ECAcc", "Edgecast"},
  {"server", "ECD", "Edgecast"},
  {"server", "NetDNA", "NetDNA"},
  {"X-CDN-Geo", "", "OVH CDN"},
  {"X-Px", "", "CDNetworks"},
  {"X-Instart-Request-ID", "instart", "Instart Logic"},
  {"Via", "CloudFront", "Amazon CloudFront"},
  {"X-Edge-IP", "", "CDN"},
  {"X-Edge-Location", "", "CDN"}
};
