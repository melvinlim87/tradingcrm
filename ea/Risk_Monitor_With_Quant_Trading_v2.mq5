//+------------------------------------------------------------------+
//|                  Risk_Monitor_With_Quant_Trading_v2.mq5          |
//+------------------------------------------------------------------+
//| v2 changes vs v1:                                                |
//|  - account block: + currency, leverage, margin, free_margin,     |
//|    margin_level                                                  |
//|  - positions[]:   + type (buy/sell), volume, current_price, swap,|
//|    commission, opened_at (ISO 8601)                              |
//|  - pending_orders[]: + type (buy_limit/sell_limit/buy_stop/...), |
//|    volume, expiration (nullable)                                 |
//|  - history[]:     + type, volume, open_price, opened_at,         |
//|    closed_at, commission. Window extended from 20 → 500          |
//|    (backend dedupes by ticket so volume of repeat data is fine)  |
//+------------------------------------------------------------------+
#property copyright "Copyright 2026, AISITA AI"
#property version   "2.00"
#property strict

#include <Trade\Trade.mqh>
#include <ExecutionMonitor\Dashboard.mqh>

// General ---
input string    InpUrl           = "backendurl";
input string    InpBearerToken   = "authtoken";
input int       InpInterval      = 600;
input int       InpMagicNumber   = 112;
input double    InpLots          = 0.1;
input group           "=== GENERAL ==="
input string   InpMonitoredSymbols     = "";           // Symbols (empty=Market Watch)
input bool     InpShowDashboard        = true;         // Show on-chart dashboard
input int      InpDashboardX           = 20;           // Dashboard X position
input int      InpDashboardY           = 30;           // Dashboard Y position

// --- SECTION 2: RISK MONITOR INPUTS ---
input string    RiskBackendURL   = "backendurl";
input string    RiskAuthToken    = "authtoken";
input int       RiskPushInterval = 10;
input int       RiskHistoryLimit = 500;                // History deals per push
input double    AccountWarningPercent = 7.0;
input double    AccountDangerPercent = 15.0;
input double    PairWarningPercent = 5.0;
input double    PairDangerPercent = 7.0;
input int       LayerWarningCount = 5;
input int       LayerDangerCount = 7;
double m_max_drawdown = 0.0;

//=== SLIPPAGE ===
input group           "=== SLIPPAGE ==="
input double   InpSlippageAlertPoints  = 5.0;

//=== SPREAD ===
input group           "=== SPREAD ==="
input double   InpSpreadMultiplier     = 3.0;
input int      InpSpreadSampleSec      = 5;

//=== RISK MANAGEMENT ===
input group           "=== RISK MANAGEMENT ==="
input double   InpMaxMarginUtil        = 80.0;
input double   InpMaxDailyDrawdown     = 5.0;
input double   InpMaxTotalExposure     = 10.0;
input int      InpMaxPositions         = 10;

//=== LATENCY ===
input group           "=== LATENCY ==="
input int      InpLatencyAlertMs       = 500;

//=== LOGGING ===
input group           "=== LOGGING ==="
input bool     InpEnableCSVLog         = true;
input int      InpRiskLogInterval      = 30;
input int      InpLPReportInterval     = 3600;

//=== ALERTS ===
input group           "=== ALERTS ==="
input bool     InpPushNotifications    = false;
input bool     InpEmailAlerts          = false;
input bool     InpSoundAlerts          = true;
input int      InpAlertCooldown        = 60;

//=== Account Information ===
input group           "=== Account Information ==="
string GetAccountNumber() { return IntegerToString(AccountInfoInteger(ACCOUNT_LOGIN)); }
string GetBrokerName()    { return AccountInfoString(ACCOUNT_COMPANY); }
string GetLeverage()      { return "1:" + IntegerToString(AccountInfoInteger(ACCOUNT_LEVERAGE)); }

string GetAccountType() {
    long mode = AccountInfoInteger(ACCOUNT_TRADE_MODE);
    return (mode == ACCOUNT_TRADE_MODE_REAL) ? "Real" :
           (mode == ACCOUNT_TRADE_MODE_DEMO) ? "Demo" : "Contest";
}

//+------------------------------------------------------------------+
//| Module Instances                                                  |
//+------------------------------------------------------------------+
CCSVLogger        g_logger;
CAlertManager     g_alert;
CSlippageTracker  g_slippage;
CLatencyMonitor   g_latency;
CSpreadAnalyzer   g_spread;
CRiskManager      g_risk;
CLPProfiler       g_lp;
CDashboard        g_dashboard;

datetime          g_last_dashboard_update = 0;
int               g_dashboard_update_interval = 2;

string processed_signals[100];
int    processed_count = 0;

//+------------------------------------------------------------------+
int OnInit()
{
   EventSetTimer(1);

   Print("==================================================");
   Print("  EXECUTION QUALITY & RISK MONITOR v2.0");
   Print("  Non-trading diagnostic EA");
   Print("==================================================");

   if(!g_logger.Init(InpEnableCSVLog, "ExecutionMonitor"))
   {
      Print("[ERROR] CSV Logger initialization failed");
      return INIT_FAILED;
   }

   g_alert.Init(InpPushNotifications, InpEmailAlerts, InpSoundAlerts,
                false, InpAlertCooldown, 50);

   g_slippage.Init(&g_logger, &g_alert, InpSlippageAlertPoints,
                   InpMagicNumber, 100);

   g_latency.Init(&g_alert, InpLatencyAlertMs, InpMagicNumber, 100);

   g_spread.Init(&g_logger, &g_alert, InpSpreadMultiplier, InpSpreadSampleSec);

   if(InpMonitoredSymbols == "")
   {
      g_spread.SubscribeAllMarketWatch();
   }
   else
   {
      string symbols[];
      int count = StringSplit(InpMonitoredSymbols, ',', symbols);
      for(int i = 0; i < count; i++)
      {
         string sym = symbols[i];
         StringTrimLeft(sym);
         StringTrimRight(sym);
         if(sym != "")
            g_spread.AddMonitoredSymbol(sym);
      }
   }

   g_risk.Init(&g_logger, &g_alert, InpMaxMarginUtil, InpMaxDailyDrawdown,
               InpMaxTotalExposure, InpMaxPositions, InpMagicNumber,
               InpRiskLogInterval);

   g_lp.Init(&g_logger, &g_alert, InpLPReportInterval);

   g_dashboard.Init(ChartID(), InpDashboardX, InpDashboardY, InpShowDashboard,
                    &g_slippage, &g_latency, &g_spread, &g_risk, &g_lp, &g_alert);

   datetime today = StringToTime(TimeToString(TimeCurrent(), TIME_DATE));
   g_slippage.ScanHistoryDeals(today, TimeCurrent());
   g_latency.EstimateFromHistory(today, TimeCurrent());

   EventSetTimer(1);

   Print("[ExecutionMonitor] Initialization complete");
   g_alert.FireAlert(ALERT_INFO, ALERT_CAT_GENERAL, "", "Execution Monitor started");

   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   g_lp.GenerateReport();
   g_dashboard.Destroy();
   g_spread.Deinit();
   g_logger.Deinit();
   Print("[ExecutionMonitor] Shutdown complete. Reason: ", reason);
}

void OnTick()
{
   g_spread.OnTickUpdate();
   g_risk.Update();
   g_lp.CheckReportTimer();
}

void OnTimer()
{
   static datetime last_server_check = 0;
   static datetime last_risk_push = 0;
   datetime current_time = TimeCurrent();

   if(current_time - last_server_check >= InpInterval)
   {
      FetchServerSignals();
      last_server_check = current_time;
   }

   if(current_time - last_risk_push >= RiskPushInterval)
   {
      ExportRiskData();
      last_risk_push = current_time;
   }

   datetime now = TimeCurrent();
   if((now - g_last_dashboard_update) >= g_dashboard_update_interval)
   {
      g_last_dashboard_update = now;
      g_dashboard.Update();
   }

   g_risk.Update();
   g_lp.CheckReportTimer();
}

// =========================================================================
// MODULE 1: SIGNAL FETCHING & EXECUTION (unchanged from v1)
// =========================================================================

void FetchServerSignals()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char post[], result[];
   string res_headers, headers = "Authorization: Bearer " + InpBearerToken + "\r\n";

   if(WebRequest("GET", InpUrl, headers, 5000, post, result, res_headers) == -1) return;

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(response == "" || StringFind(response, "\"success\":true") == -1) return;

   string objects[];
   int obj_count = StringSplit(response, "{", objects);

   for(int i = 1; i < obj_count; i++)
   {
      string obj = objects[i];
      string sym = ExtractJSONString(obj, "symbol");
      string act = ExtractJSONString(obj, "action");
      string ep  = ExtractJSONString(obj, "entry_price");
      string sl_str = ExtractJSONString(obj, "stop_loss");
      string tp_str = ExtractJSONString(obj, "take_profit");
      string id = ExtractJSONString(obj, "id");

      string sigId = sym + "_" + act + "_" + ep;

      if(sym != "" && !IsSignalProcessed(sigId))
      {
         double d_ep = StringToDouble(ep);
         double d_sl = StringToDouble(sl_str);
         double d_tp = StringToDouble(tp_str);

         ExecuteOrderMT5(act, d_ep, d_sl, d_tp, sigId, sym, id);
         MarkSignalProcessed(sigId);
      }
   }
}

void ExecuteOrderMT5(string type, double apiEntryPrice, double apiSL, double apiTP, string sigId, string sym, string commentId)
{
   if(sym == "XAUUSD") sym = "GOLD";
   double ask = SymbolInfoDouble(sym, SYMBOL_ASK);
   double bid = SymbolInfoDouble(sym, SYMBOL_BID);
   int digits = (int)SymbolInfoInteger(sym, SYMBOL_DIGITS);

   if(ask <= 0 || bid <= 0) return;

   MqlTradeRequest request = {};
   MqlTradeResult result = {};

   request.symbol = sym;
   request.volume = InpLots;
   request.magic = InpMagicNumber;
   request.deviation = 10;
   request.comment = commentId;

   if(apiSL > 0) request.sl = NormalizeDouble(apiSL, digits);
   if(apiTP > 0) request.tp = NormalizeDouble(apiTP, digits);

   if(apiEntryPrice <= 0.0)
   {
      request.action = TRADE_ACTION_DEAL;
      request.type = (type == "BUY") ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
      request.price = (type == "BUY") ? ask : bid;
   }
   else
   {
      request.action = TRADE_ACTION_PENDING;
      request.price = NormalizeDouble(apiEntryPrice, digits);
      if(type == "BUY") request.type = (apiEntryPrice < ask) ? ORDER_TYPE_BUY_LIMIT : ORDER_TYPE_BUY_STOP;
      else request.type = (apiEntryPrice > bid) ? ORDER_TYPE_SELL_LIMIT : ORDER_TYPE_SELL_STOP;
   }

   OrderSend(request, result);
}

// =========================================================================
// MODULE 2: RISK MONITORING & DATA EXPORT (v2 — extended fields)
// =========================================================================

void ExportRiskData()
{
   // 1. Account Identity & Margin
   long accountNum    = AccountInfoInteger(ACCOUNT_LOGIN);
   string brokerName  = AccountInfoString(ACCOUNT_COMPANY);
   string serverName  = AccountInfoString(ACCOUNT_SERVER);
   string currency    = AccountInfoString(ACCOUNT_CURRENCY);
   long   leverage    = AccountInfoInteger(ACCOUNT_LEVERAGE);
   double balance     = AccountInfoDouble(ACCOUNT_BALANCE);
   double equity      = AccountInfoDouble(ACCOUNT_EQUITY);
   double margin      = AccountInfoDouble(ACCOUNT_MARGIN);
   double freeMargin  = AccountInfoDouble(ACCOUNT_MARGIN_FREE);
   double marginLevel = AccountInfoDouble(ACCOUNT_MARGIN_LEVEL);
   double drawdown    = (balance > 0) ? (balance - equity) / balance * 100.0 : 0.0;

   double pf = TesterStatistics(STAT_PROFIT_FACTOR);
   double sr = TesterStatistics(STAT_SHARPE_RATIO);
   double rf = TesterStatistics(STAT_RECOVERY_FACTOR);

   // 2. Active Positions
   string symbols[100];
   long magics[100] = {0};
   double pnlArray[100] = {0};
   int buyLayers[100] = {0}, sellLayers[100] = {0}, pairCount = 0;

   string posJson = "";
   bool firstPos = true;

   for(int i = 0; i < PositionsTotal(); i++)
   {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0 || !PositionSelectByTicket(ticket)) continue;

      string sym = PositionGetString(POSITION_SYMBOL);
      long magic = PositionGetInteger(POSITION_MAGIC);
      ENUM_POSITION_TYPE ptype = (ENUM_POSITION_TYPE)PositionGetInteger(POSITION_TYPE);
      double volume       = PositionGetDouble(POSITION_VOLUME);
      double profit       = PositionGetDouble(POSITION_PROFIT);
      double swap         = PositionGetDouble(POSITION_SWAP);
      double openPrice    = PositionGetDouble(POSITION_PRICE_OPEN);
      double currentPrice = PositionGetDouble(POSITION_PRICE_CURRENT);
      double sl           = PositionGetDouble(POSITION_SL);
      double tp           = PositionGetDouble(POSITION_TP);
      datetime openedAt   = (datetime)PositionGetInteger(POSITION_TIME);
      int digits = (int)SymbolInfoInteger(sym, SYMBOL_DIGITS);

      if(!firstPos) posJson += ",";
      posJson += "{";
      posJson += "\"ticket\":" + (string)ticket + ",";
      posJson += "\"symbol\":\"" + sym + "\",";
      posJson += "\"type\":\"" + ((ptype == POSITION_TYPE_BUY) ? "buy" : "sell") + "\",";
      posJson += "\"volume\":" + DoubleToString(volume, 2) + ",";
      posJson += "\"open_price\":" + DoubleToString(openPrice, digits) + ",";
      posJson += "\"current_price\":" + DoubleToString(currentPrice, digits) + ",";
      posJson += "\"sl\":" + DoubleToString(sl, digits) + ",";
      posJson += "\"tp\":" + DoubleToString(tp, digits) + ",";
      posJson += "\"profit\":" + DoubleToString(profit, 2) + ",";
      posJson += "\"swap\":" + DoubleToString(swap, 2) + ",";
      posJson += "\"pnl\":" + DoubleToString(profit + swap, 2) + ",";
      posJson += "\"magic\":" + (string)magic + ",";
      posJson += "\"opened_at\":\"" + FormatIso8601(openedAt) + "\"";
      posJson += "}";
      firstPos = false;

      // Aggregate per symbol+magic
      int index = -1;
      for(int j = 0; j < pairCount; j++)
      {
         if(symbols[j] == sym && magics[j] == magic) { index = j; break; }
      }
      if(index == -1 && pairCount < 100)
      {
         index = pairCount;
         symbols[pairCount] = sym;
         magics[pairCount] = magic;
         pairCount++;
      }
      if(index != -1)
      {
         pnlArray[index] += (profit + swap);
         if(ptype == POSITION_TYPE_BUY) buyLayers[index]++;
         else sellLayers[index]++;
      }
   }

   // 3. Pending Orders
   string pendJson = "";
   bool firstPend = true;
   for(int i = 0; i < OrdersTotal(); i++)
   {
      ulong oTicket = OrderGetTicket(i);
      if(oTicket == 0 || !OrderSelect(oTicket)) continue;

      string pSym = OrderGetString(ORDER_SYMBOL);
      ENUM_ORDER_TYPE otype = (ENUM_ORDER_TYPE)OrderGetInteger(ORDER_TYPE);
      double pVolume = OrderGetDouble(ORDER_VOLUME_CURRENT);
      double pPrice = OrderGetDouble(ORDER_PRICE_OPEN);
      double pSl = OrderGetDouble(ORDER_SL);
      double pTp = OrderGetDouble(ORDER_TP);
      datetime pCreated = (datetime)OrderGetInteger(ORDER_TIME_SETUP);
      datetime pExpires = (datetime)OrderGetInteger(ORDER_TIME_EXPIRATION);
      long pMagic = OrderGetInteger(ORDER_MAGIC);
      int pDigits = (int)SymbolInfoInteger(pSym, SYMBOL_DIGITS);

      if(!firstPend) pendJson += ",";
      pendJson += "{";
      pendJson += "\"ticket\":" + (string)oTicket + ",";
      pendJson += "\"symbol\":\"" + pSym + "\",";
      pendJson += "\"type\":\"" + PendingTypeName(otype) + "\",";
      pendJson += "\"volume\":" + DoubleToString(pVolume, 2) + ",";
      pendJson += "\"entry\":" + DoubleToString(pPrice, pDigits) + ",";
      pendJson += "\"sl\":" + DoubleToString(pSl, pDigits) + ",";
      pendJson += "\"tp\":" + DoubleToString(pTp, pDigits) + ",";
      pendJson += "\"magic\":" + (string)pMagic + ",";
      pendJson += "\"created_at\":\"" + FormatIso8601(pCreated) + "\",";
      pendJson += "\"expires_at\":" + (pExpires > 0 ? "\"" + FormatIso8601(pExpires) + "\"" : "null");
      pendJson += "}";
      firstPend = false;
   }

   // 4. History — closed positions only (DEAL_ENTRY_OUT, paired with IN deal)
   string histJson = "";
   bool firstHist = true;
   if(HistorySelect(0, TimeCurrent()))
   {
      int totalDeals = HistoryDealsTotal();
      int hCount = 0;
      for(int i = totalDeals - 1; i >= 0 && hCount < RiskHistoryLimit; i--)
      {
         ulong t = HistoryDealGetTicket(i);
         if(t == 0) continue;
         if(HistoryDealGetInteger(t, DEAL_ENTRY) != DEAL_ENTRY_OUT) continue;

         string hSym = HistoryDealGetString(t, DEAL_SYMBOL);
         long hMagic = HistoryDealGetInteger(t, DEAL_MAGIC);
         ulong posId = HistoryDealGetInteger(t, DEAL_POSITION_ID);
         ENUM_DEAL_TYPE outType = (ENUM_DEAL_TYPE)HistoryDealGetInteger(t, DEAL_TYPE);
         double volume = HistoryDealGetDouble(t, DEAL_VOLUME);
         double closePrice = HistoryDealGetDouble(t, DEAL_PRICE);
         double hSl = HistoryDealGetDouble(t, DEAL_SL);
         double hTp = HistoryDealGetDouble(t, DEAL_TP);
         double hProfit = HistoryDealGetDouble(t, DEAL_PROFIT);
         double hSwap = HistoryDealGetDouble(t, DEAL_SWAP);
         double hComm = HistoryDealGetDouble(t, DEAL_COMMISSION);
         datetime closedAt = (datetime)HistoryDealGetInteger(t, DEAL_TIME);
         int hDigits = (int)SymbolInfoInteger(hSym, SYMBOL_DIGITS);

         // Lookup entry-IN deal of same position_id to get open price + opened_at
         double openPrice = 0.0;
         datetime openedAt = 0;
         for(int k = 0; k < totalDeals; k++)
         {
            ulong tk = HistoryDealGetTicket(k);
            if(tk == 0) continue;
            if((ulong)HistoryDealGetInteger(tk, DEAL_POSITION_ID) != posId) continue;
            if(HistoryDealGetInteger(tk, DEAL_ENTRY) != DEAL_ENTRY_IN) continue;
            openPrice = HistoryDealGetDouble(tk, DEAL_PRICE);
            openedAt = (datetime)HistoryDealGetInteger(tk, DEAL_TIME);
            break;
         }

         string origType = (outType == DEAL_TYPE_BUY) ? "sell" : "buy";

         if(!firstHist) histJson += ",";
         histJson += "{";
         histJson += "\"ticket\":" + (string)t + ",";
         histJson += "\"position_id\":" + (string)posId + ",";
         histJson += "\"symbol\":\"" + hSym + "\",";
         histJson += "\"type\":\"" + origType + "\",";
         histJson += "\"volume\":" + DoubleToString(volume, 2) + ",";
         histJson += "\"open_price\":" + DoubleToString(openPrice, hDigits) + ",";
         histJson += "\"close_price\":" + DoubleToString(closePrice, hDigits) + ",";
         histJson += "\"sl\":" + DoubleToString(hSl, hDigits) + ",";
         histJson += "\"tp\":" + DoubleToString(hTp, hDigits) + ",";
         histJson += "\"profit\":" + DoubleToString(hProfit, 2) + ",";
         histJson += "\"swap\":" + DoubleToString(hSwap, 2) + ",";
         histJson += "\"commission\":" + DoubleToString(hComm, 2) + ",";
         histJson += "\"pnl\":" + DoubleToString(hProfit + hSwap + hComm, 2) + ",";
         histJson += "\"magic\":" + (string)hMagic + ",";
         histJson += "\"opened_at\":" + (openedAt > 0 ? "\"" + FormatIso8601(openedAt) + "\"" : "null") + ",";
         histJson += "\"closed_at\":\"" + FormatIso8601(closedAt) + "\"";
         histJson += "}";
         firstHist = false;
         hCount++;
      }
   }

   // 5. JSON Assembly
   string json = "{";

   json += "\"account\":{";
   json += "\"number\":" + (string)accountNum + ",";
   json += "\"broker\":\"" + brokerName + "\",";
   json += "\"server\":\"" + serverName + "\",";
   json += "\"currency\":\"" + currency + "\",";
   json += "\"leverage\":" + (string)leverage + ",";
   json += "\"balance\":" + DoubleToString(balance, 2) + ",";
   json += "\"equity\":" + DoubleToString(equity, 2) + ",";
   json += "\"margin\":" + DoubleToString(margin, 2) + ",";
   json += "\"free_margin\":" + DoubleToString(freeMargin, 2) + ",";
   json += "\"margin_level\":" + DoubleToString(marginLevel, 2) + ",";
   json += "\"drawdown\":" + DoubleToString(drawdown, 2);
   json += "},";

   json += "\"performance\":{";
   json += "\"profitFactor\":" + DoubleToString(pf, 2) + ",";
   json += "\"sharpeRatio\":" + DoubleToString(sr, 2) + ",";
   json += "\"recoveryFactor\":" + DoubleToString(rf, 2);
   json += "},";

   json += "\"overall\":{";
   for(int k = 0; k < pairCount; k++)
   {
      string lp, em, es;
      AnalyzeObservedSetup(symbols[k], magics[k], buyLayers[k], sellLayers[k], balance, lp, em, es);
      json += "\"" + symbols[k] + "\":{\"pnl\":" + DoubleToString(pnlArray[k], 2)
            + ",\"buy_layers\":" + (string)buyLayers[k]
            + ",\"sell_layers\":" + (string)sellLayers[k]
            + ",\"lot_per_1000\":\"" + lp + "\""
            + ",\"multiplier\":\"" + em + "\""
            + ",\"step_pips\":\"" + es + "\"}";
      if(k < pairCount - 1) json += ",";
   }
   json += "},";

   json += "\"pending_orders\":[" + pendJson + "],";
   json += "\"positions\":[" + posJson + "],";
   json += "\"history\":[" + histJson + "],";
   json += "\"reported_at\":\"" + FormatIso8601(TimeCurrent()) + "\"";
   json += "}";

   SendRiskDataPost(json);
}

// =========================================================================
// HELPERS (v2)
// =========================================================================

string FormatIso8601(datetime t)
{
   if(t <= 0) return "";
   // MT5 server time is broker time. We emit it as broker-local ISO 8601 without
   // TZ offset; backend parses and converts. (If you want strict UTC, change to
   // TimeGMT().)
   MqlDateTime d;
   TimeToStruct(t, d);
   return StringFormat("%04d-%02d-%02dT%02d:%02d:%02d",
                       d.year, d.mon, d.day, d.hour, d.min, d.sec);
}

string PendingTypeName(ENUM_ORDER_TYPE t)
{
   switch(t)
   {
      case ORDER_TYPE_BUY_LIMIT:       return "buy_limit";
      case ORDER_TYPE_SELL_LIMIT:      return "sell_limit";
      case ORDER_TYPE_BUY_STOP:        return "buy_stop";
      case ORDER_TYPE_SELL_STOP:       return "sell_stop";
      case ORDER_TYPE_BUY_STOP_LIMIT:  return "buy_stop_limit";
      case ORDER_TYPE_SELL_STOP_LIMIT: return "sell_stop_limit";
      default:                         return "unknown";
   }
}

void SendRiskDataPost(string jsonPayload)
{
   char postData[], result[]; string resultHeaders;
   string headers = "Content-Type: application/json\r\nAuthorization: Bearer " + RiskAuthToken + "\r\n";
   StringToCharArray(jsonPayload, postData, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(postData, ArraySize(postData) - 1);
   WebRequest("POST", RiskBackendURL, headers, 5000, postData, result, resultHeaders);
}

string ExtractJSONString(string json, string key)
{
   string s = "\"" + key + "\":\"";
   int start = StringFind(json, s);
   if(start == -1)
   {
      s = "\"" + key + "\":";
      start = StringFind(json, s);
      if(start == -1) return "";
      start += StringLen(s);
      int end = StringFind(json, ",", start);
      int endBrace = StringFind(json, "}", start);
      if(end == -1 || (endBrace != -1 && endBrace < end)) end = endBrace;
      string val = StringSubstr(json, start, end - start);
      StringReplace(val, "\"", ""); StringReplace(val, " ", "");
      return val;
   }
   start += StringLen(s);
   return StringSubstr(json, start, StringFind(json, "\"", start) - start);
}

bool IsSignalProcessed(string id)
{
   for(int i=0; i<100; i++) if(processed_signals[i] == id) return true;
   return false;
}

void MarkSignalProcessed(string id)
{
   if(processed_count >= 100) processed_count = 0;
   processed_signals[processed_count++] = id;
}

void AnalyzeObservedSetup(string sym, long magic, int buyCount, int sellCount, double balance, string &outLotPer1000, string &outEstMult, string &outEstStep)
{
   outLotPer1000 = "-"; outEstMult = "-"; outEstStep = "-";
   int targetType = (sellCount > buyCount) ? POSITION_TYPE_SELL : POSITION_TYPE_BUY;
   long times[100]; double lots[100], prices[100]; int collected = 0;
   for(int i = 0; i < (int)PositionsTotal(); i++)
   {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0 || !PositionSelectByTicket(ticket)) continue;
      if(PositionGetString(POSITION_SYMBOL) != sym || PositionGetInteger(POSITION_MAGIC) != magic || PositionGetInteger(POSITION_TYPE) != targetType) continue;
      if(collected >= 100) break;
      times[collected] = (long)PositionGetInteger(POSITION_TIME);
      lots[collected] = PositionGetDouble(POSITION_VOLUME);
      prices[collected] = PositionGetDouble(POSITION_PRICE_OPEN);
      collected++;
   }
   if(collected > 0 && balance > 0.0) outLotPer1000 = DoubleToString(lots[0] / (balance / 1000.0), 3);
}

//+------------------------------------------------------------------+
//| Trade transaction handler (unchanged from v1)                     |
//+------------------------------------------------------------------+
void OnTradeTransaction(const MqlTradeTransaction &trans,
                        const MqlTradeRequest &request,
                        const MqlTradeResult &result)
{
   g_slippage.ProcessDeal(trans, request, result);

   if(trans.type == TRADE_TRANSACTION_DEAL_ADD && trans.deal > 0)
   {
      ulong dealTicket = trans.deal;
      if(HistoryDealSelect(dealTicket) ||
         (HistorySelect(TimeCurrent()-300, TimeCurrent()) && HistoryDealSelect(dealTicket)))
      {
         ENUM_DEAL_TYPE dt = (ENUM_DEAL_TYPE)HistoryDealGetInteger(dealTicket, DEAL_TYPE);
         if(dt == DEAL_TYPE_BUY || dt == DEAL_TYPE_SELL)
         {
            long magic = HistoryDealGetInteger(dealTicket, DEAL_MAGIC);
            if(InpMagicNumber == 0 || magic == InpMagicNumber)
            {
               string sym = HistoryDealGetString(dealTicket, DEAL_SYMBOL);
               datetime dealTime = (datetime)HistoryDealGetInteger(dealTicket, DEAL_TIME);

               ulong orderTicket = (ulong)HistoryDealGetInteger(dealTicket, DEAL_ORDER);
               if(orderTicket > 0 && HistoryOrderSelect(orderTicket))
               {
                  datetime orderTime = (datetime)HistoryOrderGetInteger(orderTicket, ORDER_TIME_SETUP);
                  g_latency.RecordLatency(dealTicket, sym, orderTime, dealTime);

                  double lastSlip = g_slippage.GetLastSlippage();
                  double spread = (double)SymbolInfoInteger(sym, SYMBOL_SPREAD);
                  double lat = g_latency.GetLastLatency();
                  g_lp.RecordExecution(lastSlip, spread, lat, true, false, false);
               }
            }
         }
      }
   }

   if(trans.type == TRADE_TRANSACTION_REQUEST)
   {
      if(result.retcode == TRADE_RETCODE_REJECT ||
         result.retcode == TRADE_RETCODE_ERROR ||
         result.retcode == TRADE_RETCODE_TIMEOUT)
      {
         g_lp.RecordExecution(0, 0, 0, false, false, false);
         string msg = StringFormat("Order rejected/timeout. Code: %d", result.retcode);
         g_alert.FireAlert(ALERT_WARNING, ALERT_CAT_FILLRATE, "", msg);
      }
      else if(result.retcode == TRADE_RETCODE_REQUOTE)
      {
         g_lp.RecordExecution(0, 0, 0, false, false, true);
         g_alert.FireAlert(ALERT_WARNING, ALERT_CAT_FILLRATE, "", "Requote received");
      }
   }
}

void OnBookEvent(const string &symbol)
{
   g_spread.OnBookUpdate(symbol);
}

void OnChartEvent(const int id, const long &lparam, const double &dparam, const string &sparam)
{
   if(id == CHARTEVENT_KEYDOWN && lparam == 'D')
   {
      g_dashboard.SetVisible(!g_dashboard.IsVisible());
      PrintFormat("[ExecutionMonitor] Dashboard %s",
         g_dashboard.IsVisible() ? "shown" : "hidden");
   }
}

double GetCurrentDrawdown()
{
   double balance = AccountInfoDouble(ACCOUNT_BALANCE);
   double equity  = AccountInfoDouble(ACCOUNT_EQUITY);
   return (balance > 0 && balance > equity) ? ((balance - equity) / balance) * 100.0 : 0.0;
}

double GetWinRate()
{
   if(!HistorySelect(0, TimeCurrent())) return 0.0;

   int totalClosed = 0, wins = 0;
   int deals = HistoryDealsTotal();

   for(int i = deals - 1; i >= 0; i--)
   {
      ulong ticket = HistoryDealGetTicket(i);
      if(ticket == 0 || HistoryDealGetInteger(ticket, DEAL_ENTRY) != DEAL_ENTRY_OUT) continue;

      totalClosed++;
      double pnl = HistoryDealGetDouble(ticket, DEAL_PROFIT) + HistoryDealGetDouble(ticket, DEAL_SWAP);
      if(pnl > 0) wins++;
   }
   return (totalClosed > 0) ? ((double)wins / totalClosed) * 100.0 : 0.0;
}

void UpdateMaxDrawdown()
{
   double current_dd = GetCurrentDrawdown();
   if(current_dd > m_max_drawdown) m_max_drawdown = current_dd;
}
//+------------------------------------------------------------------+
