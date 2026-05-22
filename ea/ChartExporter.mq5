//+------------------------------------------------------------------+
//|                                              ChartExporter.mq5    |
//|                            TradingCRM — AI Analysis chart exporter |
//+------------------------------------------------------------------+
//| Polls backend for pending chart requests, captures H4 / D1 / W1   |
//| screenshots with MACD + RSI + Moving Average overlays applied,    |
//| and POSTs each image (binary PNG) to the Laravel backend.         |
//|                                                                    |
//| Attach this EA to any chart in MT5. It runs purely as a poller —  |
//| no trading. Allow the backend URL under                            |
//|   Tools → Options → Expert Advisors → Allow WebRequest for URL.   |
//+------------------------------------------------------------------+
#property copyright "TradingCRM"
#property version   "1.00"
#property strict

//=== INPUTS ===================================================
input string  InpBackendUrl    = "https://yourdomain.com/api/ea"; // Laravel base URL (no trailing slash)
input string  InpEaToken       = "REPLACE_WITH_EA_PUSH_TOKEN";    // Same token as Risk Monitor EA
input int     InpPollInterval  = 10;     // Poll interval (sec)
input int     InpChartWidth    = 1920;   // Screenshot width (px)
input int     InpChartHeight   = 1080;   // Screenshot height (px)
input int     InpBarsToShow    = 200;    // Bars on chart before screenshot
input int     InpRenderDelayMs = 1500;   // Sleep before screenshot (ms)

input group "=== INDICATORS ==="
input int     InpMAPeriod      = 50;     // Moving Average period
input ENUM_MA_METHOD InpMAMethod  = MODE_SMA;
input int     InpMACDFast      = 12;
input int     InpMACDSlow      = 26;
input int     InpMACDSignal    = 9;
input int     InpRSIPeriod     = 14;

//=== CONSTANTS ================================================
ENUM_TIMEFRAMES TIMEFRAMES[] = {PERIOD_H4, PERIOD_D1, PERIOD_W1};
string          TIMEFRAME_NAMES[] = {"H4", "D1", "W1"};

//+------------------------------------------------------------------+
int OnInit()
{
   EventSetTimer(InpPollInterval);
   PrintFormat("[ChartExporter] Started. Polling %s every %d sec",
               InpBackendUrl, InpPollInterval);
   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   Print("[ChartExporter] Stopped. Reason: ", reason);
}

void OnTimer()
{
   PollPendingRequests();
}

//+------------------------------------------------------------------+
//| Poll backend for pending chart requests                           |
//+------------------------------------------------------------------+
void PollPendingRequests()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char   post[];
   char   result[];
   string resHeaders;
   string headers = "Authorization: Bearer " + InpEaToken + "\r\n" +
                    "Accept: application/json\r\n";
   string url = InpBackendUrl + "/chart-requests/pending";

   int code = WebRequest("GET", url, headers, 10000, post, result, resHeaders);
   if(code != 200)
   {
      if(code != -1) PrintFormat("[ChartExporter] Poll HTTP %d", code);
      return;
   }

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(StringLen(response) < 5) return;

   // Expected: { "data": [ {"id":1,"symbol":"AUDUSD"}, ... ] }
   ProcessRequestArray(response);
}

void ProcessRequestArray(string json)
{
   int dataStart = StringFind(json, "\"data\"");
   if(dataStart < 0) return;
   int arrStart = StringFind(json, "[", dataStart);
   int arrEnd   = StringFind(json, "]", arrStart);
   if(arrStart < 0 || arrEnd < 0) return;

   string arr = StringSubstr(json, arrStart + 1, arrEnd - arrStart - 1);
   if(StringLen(arr) < 5) return;

   string items[];
   int n = StringSplit(arr, '{', items);
   for(int i = 1; i < n; i++)
   {
      string obj = items[i];
      int id     = (int)StringToInteger(ExtractValue(obj, "id"));
      string sym = ExtractValue(obj, "symbol");
      if(id <= 0 || StringLen(sym) < 3) continue;

      PrintFormat("[ChartExporter] Request #%d → %s", id, sym);
      ProcessRequest(id, sym);
   }
}

//+------------------------------------------------------------------+
//| Export H4 / D1 / W1 charts for a request                          |
//+------------------------------------------------------------------+
void ProcessRequest(int requestId, string symbol)
{
   bool symbolOk = SymbolSelect(symbol, true);
   if(!symbolOk)
   {
      PrintFormat("[ChartExporter] SymbolSelect failed: %s", symbol);
      ReportRequestError(requestId, "symbol_not_available");
      return;
   }

   for(int i = 0; i < ArraySize(TIMEFRAMES); i++)
   {
      ENUM_TIMEFRAMES tf = TIMEFRAMES[i];
      string tfName = TIMEFRAME_NAMES[i];

      long chartId = ChartOpen(symbol, tf);
      if(chartId == 0)
      {
         PrintFormat("[ChartExporter] ChartOpen failed: %s %s", symbol, tfName);
         continue;
      }

      AttachIndicators(chartId, symbol, tf);
      ChartSetInteger(chartId, CHART_SHOW_GRID, true);
      ChartSetInteger(chartId, CHART_MODE, CHART_CANDLES);
      ChartSetInteger(chartId, CHART_SHIFT, false);
      ChartSetInteger(chartId, CHART_AUTOSCROLL, true);
      ChartSetInteger(chartId, CHART_VISIBLE_BARS, InpBarsToShow);
      ChartRedraw(chartId);
      Sleep(InpRenderDelayMs);

      string filename = StringFormat("ai_%s_%s_%d.png",
                                     symbol, tfName, (int)TimeCurrent());
      bool ok = ChartScreenShot(chartId, filename,
                                InpChartWidth, InpChartHeight, ALIGN_RIGHT);
      if(!ok)
      {
         PrintFormat("[ChartExporter] Screenshot failed: %s %s", symbol, tfName);
         ChartClose(chartId);
         continue;
      }

      Sleep(500);
      UploadChart(requestId, symbol, tfName, filename);
      ChartClose(chartId);
   }
}

//+------------------------------------------------------------------+
//| Attach MA (main), MACD (sub1), RSI (sub2) to chart                |
//+------------------------------------------------------------------+
void AttachIndicators(long chartId, string symbol, ENUM_TIMEFRAMES tf)
{
   int maHandle = iMA(symbol, tf, InpMAPeriod, 0, InpMAMethod, PRICE_CLOSE);
   if(maHandle != INVALID_HANDLE)
      ChartIndicatorAdd(chartId, 0, maHandle);

   int macdHandle = iMACD(symbol, tf, InpMACDFast, InpMACDSlow,
                          InpMACDSignal, PRICE_CLOSE);
   if(macdHandle != INVALID_HANDLE)
      ChartIndicatorAdd(chartId, 1, macdHandle);

   int rsiHandle = iRSI(symbol, tf, InpRSIPeriod, PRICE_CLOSE);
   if(rsiHandle != INVALID_HANDLE)
      ChartIndicatorAdd(chartId, 2, rsiHandle);
}

//+------------------------------------------------------------------+
//| POST binary PNG to backend                                        |
//+------------------------------------------------------------------+
bool UploadChart(int requestId, string symbol, string tfName, string filename)
{
   int handle = FileOpen(filename, FILE_READ | FILE_BIN);
   if(handle == INVALID_HANDLE)
   {
      PrintFormat("[ChartExporter] FileOpen failed: %s (err %d)",
                  filename, GetLastError());
      return false;
   }

   ulong size = FileSize(handle);
   uchar fileData[];
   ArrayResize(fileData, (int)size);
   FileReadArray(handle, fileData, 0, (int)size);
   FileClose(handle);

   string headers = "Content-Type: image/png\r\n";
   headers += "Authorization: Bearer " + InpEaToken + "\r\n";
   headers += "X-Symbol: " + symbol + "\r\n";
   headers += "X-Timeframe: " + tfName + "\r\n";
   headers += "X-Request-Id: " + IntegerToString(requestId) + "\r\n";

   char   result[];
   string resHeaders;
   string url = InpBackendUrl + "/chart-exports";

   int code = WebRequest("POST", url, headers, 30000, fileData, result, resHeaders);
   if(code < 200 || code >= 300)
   {
      PrintFormat("[ChartExporter] Upload HTTP %d for %s %s",
                  code, symbol, tfName);
      return false;
   }

   PrintFormat("[ChartExporter] Uploaded %s %s (%.1f KB)",
               symbol, tfName, size / 1024.0);
   return true;
}

//+------------------------------------------------------------------+
//| Notify backend a request failed (best-effort)                     |
//+------------------------------------------------------------------+
void ReportRequestError(int requestId, string reason)
{
   string headers = "Content-Type: application/json\r\n" +
                    "Authorization: Bearer " + InpEaToken + "\r\n";
   string payload = StringFormat("{\"reason\":\"%s\"}", reason);
   char   data[]; char result[]; string resHeaders;
   StringToCharArray(payload, data, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(data, ArraySize(data) - 1);
   string url = InpBackendUrl + "/chart-requests/" +
                IntegerToString(requestId) + "/fail";
   WebRequest("POST", url, headers, 5000, data, result, resHeaders);
}

//+------------------------------------------------------------------+
//| JSON value extraction helper (string/number, ASCII-safe)          |
//+------------------------------------------------------------------+
string ExtractValue(string obj, string key)
{
   string strPat = "\"" + key + "\":\"";
   int pos = StringFind(obj, strPat);
   if(pos >= 0)
   {
      pos += StringLen(strPat);
      int end = StringFind(obj, "\"", pos);
      if(end > pos) return StringSubstr(obj, pos, end - pos);
      return "";
   }

   string numPat = "\"" + key + "\":";
   pos = StringFind(obj, numPat);
   if(pos < 0) return "";
   pos += StringLen(numPat);
   int e1 = StringFind(obj, ",", pos);
   int e2 = StringFind(obj, "}", pos);
   int end = (e1 >= 0 && (e2 < 0 || e1 < e2)) ? e1 : e2;
   if(end < 0) end = StringLen(obj);
   string val = StringSubstr(obj, pos, end - pos);
   StringTrimLeft(val); StringTrimRight(val);
   return val;
}
//+------------------------------------------------------------------+
