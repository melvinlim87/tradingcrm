//+------------------------------------------------------------------+
//|                                              TradingCRM_EA.mq5   |
//|       Combined: Risk Monitor v2 + Chart Exporter                 |
//|                                                                    |
//|   ONE EA, ONE URL, ONE TOKEN.                                     |
//|   Attach to any chart. The EA:                                    |
//|     1. POSTs account / positions / orders / history every 10s    |
//|     2. Polls for chart-export requests every 10s; for each one,  |
//|        opens H4 / D1 / W1 charts with MA + MACD + RSI overlays,  |
//|        screenshots them, and uploads the PNGs.                   |
//|     3. (Optional) GETs server signals every 600s and executes    |
//|        them. If the signals endpoint isn't implemented in the    |
//|        backend, the EA simply gets nothing back.                 |
//|                                                                    |
//|   MT5 → Tools → Options → Expert Advisors → Allow WebRequest     |
//|         add: https://quant.lazetrader.com                         |
//+------------------------------------------------------------------+
#property copyright "TradingCRM"
#property version   "3.71"
#property strict

#define EA_VERSION "3.71"

#include <Trade\Trade.mqh>
#include <ExecutionMonitor\Dashboard.mqh>

//=== UNIFIED BACKEND ============================================================
input group           "=== BACKEND ==="
input string  InpBackendBase  = "https://quant.lazetrader.com/api/ea"; // Base URL, no trailing /
input string  InpEaToken      = "CHANGE_ME_TO_64_CHAR_RANDOM_STRING";    // Matches .env EA_PUSH_TOKEN

//=== TIMERS =====================================================================
input group           "=== TIMERS ==="
input int     InpSignalInterval     = 600;   // GET /signals every N sec (0 = disable)
input int     InpRiskPushInterval   = 10;    // POST /push every N sec
input int     InpChartPollInterval  = 10;    // GET /chart-requests/pending every N sec
input int     InpNewsRefreshSec     = 60;    // On-chart News panel refresh (0 = disable)
input int     InpNewsPushInterval   = 900;   // POST MT5 calendar → backend every N sec (0 = disable)
input int     InpNewsPushWindowH    = 168;   // How far ahead to look (168h = 7d)
input int     InpNewsPushBackH      = 168;   // How far back to look (168h = 7d)
input int     InpBrokerNewsInterval = 300;   // Scan MQL5/Files/news/*.htm → POST every N sec (0=off)
input string  InpBrokerNewsFolder   = "news";// Subfolder under MQL5/Files where broker drops .htm

//=== NEWS PANEL (on-chart) ======================================================
// Occupies the empty 6th cell of CDashboard (row 2, column 3).
// Visual style is locked to Dashboard.mqh — same bg/border/header colors,
// same fonts (Segoe UI Semibold header + Consolas body), same 18 px row pitch.
// Defaults assume DashboardX=20, DashboardY=30 (X = 20+400, Y = 30+228).
input group           "=== NEWS PANEL ==="
input bool    InpShowNewsPanel     = true;
input int     InpNewsPanelX        = 420;       // = DashboardX + DASH_COL_W*2
input int     InpNewsPanelY        = 258;       // = DashboardY + 228 (row 2 top)
input int     InpNewsPanelW        = 200;       // = DASH_COL_W
input int     InpNewsPanelH        = 232;       // = DASH_HEIGHT - 228
input int     InpNewsMaxLines      = 9;         // Matches LP/Exec column row count
input bool    InpIncludeMt5Calendar = true;     // Pull MT5 native calendar too

//=== SIGNAL TRADING (from v2) ===================================================
input group           "=== SIGNAL TRADING ==="
input int     InpMagicNumber  = 112;
input double  InpLots         = 0.1;

//=== RISK MONITOR ===============================================================
input group           "=== RISK MONITOR ==="
input int     InpRiskHistoryLimit  = 500;     // History deals per push
input double  AccountWarningPercent = 7.0;
input double  AccountDangerPercent  = 15.0;
input double  PairWarningPercent    = 5.0;
input double  PairDangerPercent     = 7.0;
input int     LayerWarningCount     = 5;
input int     LayerDangerCount      = 7;

//=== CHART EXPORTER =============================================================
input group           "=== CHART EXPORTER ==="
input int     InpChartWidth        = 1920;
input int     InpChartHeight       = 1080;
input int     InpBarsToShow        = 200;
input int     InpRenderDelayMs     = 1500;
input int     InpMAPeriod          = 50;
input ENUM_MA_METHOD InpMAMethod   = MODE_SMA;
input int     InpMACDFast          = 12;
input int     InpMACDSlow          = 26;
input int     InpMACDSignal        = 9;
input int     InpRSIPeriod         = 14;

//=== GENERAL ====================================================================
input group           "=== GENERAL ==="
input string  InpMonitoredSymbols  = "";     // Symbols (empty = Market Watch)
input bool    InpShowDashboard     = true;
input int     InpDashboardX        = 20;
input int     InpDashboardY        = 30;

input group           "=== SLIPPAGE ==="
input double  InpSlippageAlertPoints = 5.0;

input group           "=== SPREAD ==="
input double  InpSpreadMultiplier  = 3.0;
input int     InpSpreadSampleSec   = 5;

input group           "=== RISK MANAGEMENT ==="
input double  InpMaxMarginUtil    = 80.0;
input double  InpMaxDailyDrawdown = 5.0;
input double  InpMaxTotalExposure = 10.0;
input int     InpMaxPositions     = 10;

input group           "=== LATENCY ==="
input int     InpLatencyAlertMs   = 500;

input group           "=== LOGGING ==="
input bool    InpEnableCSVLog     = true;
input int     InpRiskLogInterval  = 30;
input int     InpLPReportInterval = 3600;

input group           "=== ALERTS ==="
input bool    InpPushNotifications = false;
input bool    InpEmailAlerts       = false;
input bool    InpSoundAlerts       = true;
input int     InpAlertCooldown     = 60;

//=== Module Instances ===========================================================
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
double            m_max_drawdown = 0.0;

string processed_signals[100];
int    processed_count = 0;

//=== Chart Exporter Constants ===================================================
ENUM_TIMEFRAMES TIMEFRAMES[]     = {PERIOD_H4, PERIOD_D1, PERIOD_W1};
string          TIMEFRAME_NAMES[] = {"H4", "D1", "W1"};

//=== News panel state ===========================================================
struct NewsItem
{
   long     timestamp;   // unix seconds, for sorting
   string   when;        // "MM-DD HH:MM" Asia/Singapore
   string   currency;
   string   impact;      // HIGH | MEDIUM | LOW | HOLIDAY
   string   title;
   string   source;      // "backend" | "mt5"
};
NewsItem g_news_items[];
datetime g_last_news_refresh = 0;
const string NEWS_OBJ_PREFIX  = "tcrm_news_";

//+------------------------------------------------------------------+
//| Helper: build full endpoint URL                                   |
//+------------------------------------------------------------------+
string EndpointUrl(string suffix)
{
   string base = InpBackendBase;
   while(StringLen(base) > 0 && StringGetCharacter(base, StringLen(base)-1) == '/')
      base = StringSubstr(base, 0, StringLen(base)-1);
   if(StringLen(suffix) > 0 && StringGetCharacter(suffix, 0) != '/')
      suffix = "/" + suffix;
   return base + suffix;
}

//+------------------------------------------------------------------+
//| OnInit                                                            |
//+------------------------------------------------------------------+
int OnInit()
{
   Print("==================================================");
   PrintFormat("  TradingCRM EA v%s (combined)", EA_VERSION);
   Print("  Build flags: ResolveBrokerSymbol=ON  NewsPanel=ON");
   Print("==================================================");
   PrintFormat("  Backend: %s", InpBackendBase);
   PrintFormat("  Timers — Signal:%ds  RiskPush:%ds  ChartPoll:%ds  NewsPanel:%ds  CalPush:%ds  BrokerNews:%ds",
               InpSignalInterval, InpRiskPushInterval, InpChartPollInterval,
               InpNewsRefreshSec, InpNewsPushInterval, InpBrokerNewsInterval);
   PrintFormat("  Broker news folder: MQL5/Files/%s/*.htm", InpBrokerNewsFolder);

   if(!g_logger.Init(InpEnableCSVLog, "TradingCRM"))
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

   if(InpShowNewsPanel) NewsPanelCreate();

   EventSetTimer(1);

   Print("[TradingCRM] Init complete");
   g_alert.FireAlert(ALERT_INFO, ALERT_CAT_GENERAL, "", "TradingCRM EA started");

   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   g_lp.GenerateReport();
   g_dashboard.Destroy();
   g_spread.Deinit();
   g_logger.Deinit();
   NewsPanelDestroy();
   Print("[TradingCRM] Shutdown. Reason: ", reason);
}

void OnTick()
{
   g_spread.OnTickUpdate();
   g_risk.Update();
   g_lp.CheckReportTimer();
}

//+------------------------------------------------------------------+
//| OnTimer — drives all 3 backend interactions                       |
//+------------------------------------------------------------------+
void OnTimer()
{
   static datetime last_signal_check = 0;
   static datetime last_risk_push   = 0;
   static datetime last_chart_poll  = 0;
   datetime current_time = TimeCurrent();

   if(InpSignalInterval > 0 && current_time - last_signal_check >= InpSignalInterval)
   {
      FetchServerSignals();
      last_signal_check = current_time;
   }

   if(InpRiskPushInterval > 0 && current_time - last_risk_push >= InpRiskPushInterval)
   {
      ExportRiskData();
      last_risk_push = current_time;
   }

   if(InpChartPollInterval > 0 && current_time - last_chart_poll >= InpChartPollInterval)
   {
      PollChartRequests();
      PollNewsRequests();
      last_chart_poll = current_time;
   }

   if(InpShowNewsPanel && InpNewsRefreshSec > 0 &&
      current_time - g_last_news_refresh >= InpNewsRefreshSec)
   {
      NewsPanelRefresh();
      g_last_news_refresh = current_time;
   }

   static datetime last_news_push = 0;
   if(InpNewsPushInterval > 0 &&
      current_time - last_news_push >= InpNewsPushInterval)
   {
      PushMt5CalendarNewsPeriodic();
      last_news_push = current_time;
   }

   static datetime last_broker_news_scan = 0;
   if(InpBrokerNewsInterval > 0 &&
      current_time - last_broker_news_scan >= InpBrokerNewsInterval)
   {
      ScanBrokerNewsFolder();
      last_broker_news_scan = current_time;
   }

   if((current_time - g_last_dashboard_update) >= g_dashboard_update_interval)
   {
      g_last_dashboard_update = current_time;
      g_dashboard.Update();
   }

   g_risk.Update();
   g_lp.CheckReportTimer();
}

// =========================================================================
// MODULE 1: SIGNAL FETCHING & EXECUTION
// =========================================================================

void FetchServerSignals()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char post[], result[];
   string res_headers, headers = "Authorization: Bearer " + InpEaToken + "\r\n";
   string url = EndpointUrl("/signals");

   if(WebRequest("GET", url, headers, 5000, post, result, res_headers) == -1) return;

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(response == "" || StringFind(response, "\"success\":true") == -1) return;

   string objects[];
   int obj_count = StringSplit(response, '{', objects);

   for(int i = 1; i < obj_count; i++)
   {
      string obj = objects[i];
      string sym = ExtractJSONString(obj, "symbol");
      string act = ExtractJSONString(obj, "action");
      string ep  = ExtractJSONString(obj, "entry_price");
      string sl_str = ExtractJSONString(obj, "stop_loss");
      string tp_str = ExtractJSONString(obj, "take_profit");
      string id  = ExtractJSONString(obj, "id");

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

   request.symbol    = sym;
   request.volume    = InpLots;
   request.magic     = InpMagicNumber;
   request.deviation = 10;
   request.comment   = commentId;

   if(apiSL > 0) request.sl = NormalizeDouble(apiSL, digits);
   if(apiTP > 0) request.tp = NormalizeDouble(apiTP, digits);

   if(apiEntryPrice <= 0.0)
   {
      request.action = TRADE_ACTION_DEAL;
      request.type   = (type == "BUY") ? ORDER_TYPE_BUY : ORDER_TYPE_SELL;
      request.price  = (type == "BUY") ? ask : bid;
   }
   else
   {
      request.action = TRADE_ACTION_PENDING;
      request.price  = NormalizeDouble(apiEntryPrice, digits);
      if(type == "BUY")
         request.type = (apiEntryPrice < ask) ? ORDER_TYPE_BUY_LIMIT : ORDER_TYPE_BUY_STOP;
      else
         request.type = (apiEntryPrice > bid) ? ORDER_TYPE_SELL_LIMIT : ORDER_TYPE_SELL_STOP;
   }

   OrderSend(request, result);
}

// =========================================================================
// MODULE 2: RISK MONITORING & DATA EXPORT (POST /api/ea/push)
// =========================================================================

void ExportRiskData()
{
   long   accountNum  = AccountInfoInteger(ACCOUNT_LOGIN);
   string accountName = AccountInfoString(ACCOUNT_NAME);
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

   string symbols[100];
   long   magics[100]     = {0};
   double pnlArray[100]   = {0};
   int    buyLayers[100]  = {0};
   int    sellLayers[100] = {0};
   int    pairCount = 0;

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

      int index = -1;
      for(int j = 0; j < pairCount; j++)
         if(symbols[j] == sym && magics[j] == magic) { index = j; break; }
      if(index == -1 && pairCount < 100)
      {
         index = pairCount;
         symbols[pairCount] = sym;
         magics[pairCount]  = magic;
         pairCount++;
      }
      if(index != -1)
      {
         pnlArray[index] += (profit + swap);
         if(ptype == POSITION_TYPE_BUY) buyLayers[index]++; else sellLayers[index]++;
      }
   }

   string pendJson = "";
   bool firstPend = true;
   for(int i = 0; i < OrdersTotal(); i++)
   {
      ulong oTicket = OrderGetTicket(i);
      if(oTicket == 0 || !OrderSelect(oTicket)) continue;

      string pSym = OrderGetString(ORDER_SYMBOL);
      ENUM_ORDER_TYPE otype = (ENUM_ORDER_TYPE)OrderGetInteger(ORDER_TYPE);
      double pVolume = OrderGetDouble(ORDER_VOLUME_CURRENT);
      double pPrice  = OrderGetDouble(ORDER_PRICE_OPEN);
      double pSl     = OrderGetDouble(ORDER_SL);
      double pTp     = OrderGetDouble(ORDER_TP);
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

   string histJson = "";
   bool firstHist = true;
   if(HistorySelect(0, TimeCurrent()))
   {
      int totalDeals = HistoryDealsTotal();
      int hCount = 0;
      for(int i = totalDeals - 1; i >= 0 && hCount < InpRiskHistoryLimit; i--)
      {
         ulong t = HistoryDealGetTicket(i);
         if(t == 0) continue;
         if(HistoryDealGetInteger(t, DEAL_ENTRY) != DEAL_ENTRY_OUT) continue;

         string hSym = HistoryDealGetString(t, DEAL_SYMBOL);
         long   hMagic = HistoryDealGetInteger(t, DEAL_MAGIC);
         ulong  posId = HistoryDealGetInteger(t, DEAL_POSITION_ID);
         ENUM_DEAL_TYPE outType = (ENUM_DEAL_TYPE)HistoryDealGetInteger(t, DEAL_TYPE);
         double volume     = HistoryDealGetDouble(t, DEAL_VOLUME);
         double closePrice = HistoryDealGetDouble(t, DEAL_PRICE);
         double hSl        = HistoryDealGetDouble(t, DEAL_SL);
         double hTp        = HistoryDealGetDouble(t, DEAL_TP);
         double hProfit    = HistoryDealGetDouble(t, DEAL_PROFIT);
         double hSwap      = HistoryDealGetDouble(t, DEAL_SWAP);
         double hComm      = HistoryDealGetDouble(t, DEAL_COMMISSION);
         datetime closedAt = (datetime)HistoryDealGetInteger(t, DEAL_TIME);
         int hDigits = (int)SymbolInfoInteger(hSym, SYMBOL_DIGITS);

         double   openPrice = 0.0;
         datetime openedAt  = 0;
         for(int k = 0; k < totalDeals; k++)
         {
            ulong tk = HistoryDealGetTicket(k);
            if(tk == 0) continue;
            if((ulong)HistoryDealGetInteger(tk, DEAL_POSITION_ID) != posId) continue;
            if(HistoryDealGetInteger(tk, DEAL_ENTRY) != DEAL_ENTRY_IN) continue;
            openPrice = HistoryDealGetDouble(tk, DEAL_PRICE);
            openedAt  = (datetime)HistoryDealGetInteger(tk, DEAL_TIME);
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
         firstHist = false; hCount++;
      }
   }

   string json = "{";
   json += "\"account\":{";
   json += "\"number\":" + (string)accountNum + ",";
   json += "\"name\":\""   + accountName + "\",";
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

void SendRiskDataPost(string jsonPayload)
{
   char postData[], result[]; string resultHeaders;
   string headers = "Content-Type: application/json\r\nAuthorization: Bearer " + InpEaToken + "\r\n";
   string url = EndpointUrl("/push");
   StringToCharArray(jsonPayload, postData, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(postData, ArraySize(postData) - 1);
   int code = WebRequest("POST", url, headers, 5000, postData, result, resultHeaders);
   if(code != 200 && code != -1)
      PrintFormat("[TradingCRM] Push HTTP %d", code);
}

// =========================================================================
// MODULE 3: CHART REQUEST POLLING + SCREENSHOT UPLOAD
// =========================================================================

void PollChartRequests()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char   post[];
   char   result[];
   string resHeaders;
   string headers = "Authorization: Bearer " + InpEaToken + "\r\n" +
                    "Accept: application/json\r\n";
   string url = EndpointUrl("/chart-requests/pending");

   int code = WebRequest("GET", url, headers, 10000, post, result, resHeaders);
   if(code != 200)
   {
      if(code != -1) PrintFormat("[ChartExporter] Poll HTTP %d", code);
      return;
   }

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(StringLen(response) < 5) return;

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
      int id     = (int)StringToInteger(ExtractJSONString(obj, "id"));
      string sym = ExtractJSONString(obj, "symbol");
      if(id <= 0 || StringLen(sym) < 3) continue;

      PrintFormat("[ChartExporter] Request #%d → %s", id, sym);
      ProcessChartRequest(id, sym);
   }
}

void ProcessChartRequest(int requestId, string symbol)
{
   string resolved = ResolveBrokerSymbol(symbol);
   if(resolved == "")
   {
      PrintFormat("[ChartExporter] No broker symbol matches '%s' (tried bare + common suffixes)", symbol);
      ReportChartRequestError(requestId, "symbol_not_available");
      return;
   }
   if(resolved != symbol)
      PrintFormat("[ChartExporter] Resolved '%s' → '%s'", symbol, resolved);

   for(int i = 0; i < ArraySize(TIMEFRAMES); i++)
   {
      ENUM_TIMEFRAMES tf = TIMEFRAMES[i];
      string tfName = TIMEFRAME_NAMES[i];

      long chartId = ChartOpen(resolved, tf);
      if(chartId == 0)
      {
         PrintFormat("[ChartExporter] ChartOpen failed: %s %s", resolved, tfName);
         continue;
      }

      AttachIndicators(chartId, resolved, tf);
      ChartSetInteger(chartId, CHART_SHOW_GRID,        true);
      ChartSetInteger(chartId, CHART_MODE,             CHART_CANDLES);
      ChartSetInteger(chartId, CHART_SHIFT,            false);
      ChartSetInteger(chartId, CHART_AUTOSCROLL,       true);
      ChartSetInteger(chartId, CHART_VISIBLE_BARS,     InpBarsToShow);
      ChartSetInteger(chartId, CHART_SHOW_OHLC,        true);
      ChartSetInteger(chartId, CHART_SHOW_BID_LINE,    true);
      ChartSetInteger(chartId, CHART_SHOW_ASK_LINE,    true);
      ChartSetInteger(chartId, CHART_SHOW_LAST_LINE,   true);
      ChartSetInteger(chartId, CHART_SHOW_PRICE_SCALE, true);
      ChartSetInteger(chartId, CHART_SHOW_DATE_SCALE,  true);
      ChartRedraw(chartId);
      Sleep(InpRenderDelayMs);

      string safeName = SanitizeSymbol(resolved);
      string filename = StringFormat("ai_%s_%s_%d.png",
                                     safeName, tfName, (int)TimeCurrent());
      bool ok = ChartScreenShot(chartId, filename,
                                InpChartWidth, InpChartHeight, ALIGN_RIGHT);
      if(!ok)
      {
         PrintFormat("[ChartExporter] Screenshot failed: %s %s", resolved, tfName);
         ChartClose(chartId);
         continue;
      }

      Sleep(500);
      double bid = SymbolInfoDouble(resolved, SYMBOL_BID);
      double ask = SymbolInfoDouble(resolved, SYMBOL_ASK);
      int digits = (int)SymbolInfoInteger(resolved, SYMBOL_DIGITS);
      UploadChartScreenshot(requestId, symbol, tfName, filename, bid, ask, digits);
      ChartClose(chartId);
   }
}

string ResolveBrokerSymbol(string clean)
{
   string suffixes[] = {"", ".m", "m", ".raw", ".std", ".pro", ".ecn",
                        ".sml", ".micro", "#", "-LIVE", ".cash", ".c",
                        ".x", "_i", ".i", ".live", ".a", ".b", "-cd"};

   for(int i = 0; i < ArraySize(suffixes); i++)
   {
      string candidate = clean + suffixes[i];
      if(SymbolSelect(candidate, true))
      {
         PrintFormat("[ChartExporter] Symbol resolver: matched suffix '%s' → %s",
                     suffixes[i], candidate);
         return candidate;
      }
   }

   int mwTotal = SymbolsTotal(true);
   for(int i = 0; i < mwTotal; i++)
   {
      string name = SymbolName(i, true);
      if(StringFind(name, clean) >= 0 && SymbolSelect(name, true))
      {
         PrintFormat("[ChartExporter] Symbol resolver: Market Watch match → %s", name);
         return name;
      }
   }

   int allTotal = SymbolsTotal(false);
   for(int i = 0; i < allTotal; i++)
   {
      string name = SymbolName(i, false);
      if(StringFind(name, clean) >= 0 && SymbolSelect(name, true))
      {
         PrintFormat("[ChartExporter] Symbol resolver: broker match → %s (added to Market Watch)", name);
         return name;
      }
   }

   PrintFormat("[ChartExporter] Symbol resolver: NOTHING matched '%s'. MW had %d symbols, broker had %d.",
               clean, mwTotal, allTotal);
   return "";
}

string SanitizeSymbol(string s)
{
   string out = "";
   for(int i = 0; i < StringLen(s); i++)
   {
      ushort c = StringGetCharacter(s, i);
      if((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9'))
         out += ShortToString(c);
   }
   return out;
}

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

bool UploadChartScreenshot(int requestId, string symbol, string tfName, string filename,
                           double bid = 0.0, double ask = 0.0, int digits = 5)
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
   if(bid > 0) headers += "X-Bid: " + DoubleToString(bid, digits) + "\r\n";
   if(ask > 0) headers += "X-Ask: " + DoubleToString(ask, digits) + "\r\n";
   headers += "X-Digits: " + IntegerToString(digits) + "\r\n";

   char   result[];
   string resHeaders;
   string url = EndpointUrl("/chart-exports");

   int code = WebRequest("POST", url, headers, 120000, fileData, result, resHeaders);
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

void ReportChartRequestError(int requestId, string reason)
{
   string headers = "Content-Type: application/json\r\n" +
                    "Authorization: Bearer " + InpEaToken + "\r\n";
   string payload = StringFormat("{\"reason\":\"%s\"}", reason);
   char   data[]; char result[]; string resHeaders;
   StringToCharArray(payload, data, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(data, ArraySize(data) - 1);
   string url = EndpointUrl("/chart-requests/" + IntegerToString(requestId) + "/fail");
   WebRequest("POST", url, headers, 5000, data, result, resHeaders);
}

// =========================================================================
// HELPERS
// =========================================================================

string FormatIso8601(datetime t)
{
   if(t <= 0) return "";
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
      if(end == -1) end = StringLen(json);
      string val = StringSubstr(json, start, end - start);
      StringReplace(val, "\"", ""); StringReplace(val, " ", "");
      return val;
   }
   start += StringLen(s);
   return StringSubstr(json, start, StringFind(json, "\"", start) - start);
}

bool IsSignalProcessed(string id)
{
   for(int i = 0; i < 100; i++) if(processed_signals[i] == id) return true;
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
   long   times[100];
   double lots[100], prices[100];
   int collected = 0;
   for(int i = 0; i < (int)PositionsTotal(); i++)
   {
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0 || !PositionSelectByTicket(ticket)) continue;
      if(PositionGetString(POSITION_SYMBOL) != sym ||
         PositionGetInteger(POSITION_MAGIC) != magic ||
         PositionGetInteger(POSITION_TYPE) != targetType) continue;
      if(collected >= 100) break;
      times[collected]  = (long)PositionGetInteger(POSITION_TIME);
      lots[collected]   = PositionGetDouble(POSITION_VOLUME);
      prices[collected] = PositionGetDouble(POSITION_PRICE_OPEN);
      collected++;
   }
   if(collected > 0 && balance > 0.0)
      outLotPer1000 = DoubleToString(lots[0] / (balance / 1000.0), 3);
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

// =========================================================================
// EVENT HANDLERS
// =========================================================================

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
      PrintFormat("[TradingCRM] Dashboard %s",
         g_dashboard.IsVisible() ? "shown" : "hidden");
   }
   if(id == CHARTEVENT_KEYDOWN && lparam == 'N')
   {
      NewsPanelRefresh();
   }
}

// =========================================================================
// MODULE 4: NEWS PANEL (on-chart card) — styled to match Dashboard.mqh
// =========================================================================

// Style constants — mirrored from Dashboard.mqh so the news cell visually
// docks into the rest of the dashboard. If you change DASH_* there, change
// them here too.
#define NEWS_BG_COLOR      C'18,18,28'
#define NEWS_BORDER_COLOR  C'55,65,95'
#define NEWS_HEADER_COLOR  C'200,220,255'
#define NEWS_TEXT_COLOR    C'160,170,190'
#define NEWS_VALUE_COLOR   C'220,230,245'
#define NEWS_GOOD_COLOR    C'80,220,120'
#define NEWS_WARN_COLOR    C'255,200,60'
#define NEWS_BAD_COLOR     C'255,80,80'
#define NEWS_HINT_COLOR    C'120,140,170'
#define NEWS_ROW_H         18
#define NEWS_FONT          "Consolas"
#define NEWS_HEADER_FONT   "Segoe UI Semibold"

void CreateNewsLabel(string suffix, int x, int y, string text,
                     color clr, int fontSize, string font)
{
   string name = NEWS_OBJ_PREFIX + suffix;
   ObjectCreate(0, name, OBJ_LABEL, 0, 0, 0);
   ObjectSetInteger(0, name, OBJPROP_XDISTANCE, x);
   ObjectSetInteger(0, name, OBJPROP_YDISTANCE, y);
   ObjectSetString(0, name, OBJPROP_TEXT, text);
   ObjectSetString(0, name, OBJPROP_FONT, font);
   ObjectSetInteger(0, name, OBJPROP_FONTSIZE, fontSize);
   ObjectSetInteger(0, name, OBJPROP_COLOR, clr);
   ObjectSetInteger(0, name, OBJPROP_CORNER, CORNER_LEFT_UPPER);
   ObjectSetInteger(0, name, OBJPROP_BACK, false);
   ObjectSetInteger(0, name, OBJPROP_SELECTABLE, false);
   ObjectSetInteger(0, name, OBJPROP_HIDDEN, true);
}

void NewsPanelCreate()
{
   // Background — same fill/border as Dashboard's bgTop/bgBot
   string bg = NEWS_OBJ_PREFIX + "bg";
   ObjectCreate(0, bg, OBJ_RECTANGLE_LABEL, 0, 0, 0);
   ObjectSetInteger(0, bg, OBJPROP_XDISTANCE, InpNewsPanelX);
   ObjectSetInteger(0, bg, OBJPROP_YDISTANCE, InpNewsPanelY);
   ObjectSetInteger(0, bg, OBJPROP_XSIZE, InpNewsPanelW);
   ObjectSetInteger(0, bg, OBJPROP_YSIZE, InpNewsPanelH);
   ObjectSetInteger(0, bg, OBJPROP_BGCOLOR, NEWS_BG_COLOR);
   ObjectSetInteger(0, bg, OBJPROP_BORDER_TYPE, BORDER_FLAT);
   ObjectSetInteger(0, bg, OBJPROP_BORDER_COLOR, NEWS_BORDER_COLOR);
   ObjectSetInteger(0, bg, OBJPROP_WIDTH, 2);
   ObjectSetInteger(0, bg, OBJPROP_CORNER, CORNER_LEFT_UPPER);
   ObjectSetInteger(0, bg, OBJPROP_BACK, false);
   ObjectSetInteger(0, bg, OBJPROP_SELECTABLE, false);
   ObjectSetInteger(0, bg, OBJPROP_HIDDEN, true);

   // Section header — same offset (4 px) + size (10) + font as Dashboard headers
   CreateNewsLabel("title", InpNewsPanelX + 10, InpNewsPanelY + 4,
                   "ECONOMIC CALENDAR", NEWS_HEADER_COLOR, 10, NEWS_HEADER_FONT);

   // Status / hint line — sits one row below header
   CreateNewsLabel("hint", InpNewsPanelX + 10, InpNewsPanelY + 22,
                   "Loading...", NEWS_HINT_COLOR, 8, NEWS_FONT);

   // News rows — same 18 px pitch and font size 8 as the RECENT ALERTS column
   int contentY = InpNewsPanelY + 44;
   for(int i = 0; i < InpNewsMaxLines; i++)
   {
      CreateNewsLabel("line_" + IntegerToString(i),
                      InpNewsPanelX + 10,
                      contentY + i * NEWS_ROW_H,
                      " ", NEWS_TEXT_COLOR, 8, NEWS_FONT);
   }

   ChartRedraw(0);
}

void NewsPanelDestroy()
{
   ObjectsDeleteAll(0, NEWS_OBJ_PREFIX);
}

void NewsPanelRefresh()
{
   ArrayResize(g_news_items, 0);

   FetchBackendNews();
   if(InpIncludeMt5Calendar) FetchMt5CalendarNews();

   SortNewsByTime();
   RenderNewsPanel();
}

void FetchBackendNews()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char post[]; char result[]; string resHeaders;
   string headers = "Authorization: Bearer " + InpEaToken + "\r\n" +
                    "Accept: application/json\r\n";
   string url = EndpointUrl("/news/latest?limit=15");

   int code = WebRequest("GET", url, headers, 10000, post, result, resHeaders);
   if(code != 200) return;

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(StringLen(response) < 10) return;

   int dataStart = StringFind(response, "\"data\"");
   if(dataStart < 0) return;
   int arrStart = StringFind(response, "[", dataStart);
   int arrEnd   = StringFind(response, "]", arrStart);
   if(arrStart < 0 || arrEnd < 0) return;

   string arr = StringSubstr(response, arrStart + 1, arrEnd - arrStart - 1);
   string items[];
   int n = StringSplit(arr, '{', items);

   for(int i = 1; i < n; i++)
   {
      string obj = items[i];
      string when     = ExtractJSONString(obj, "event_at");
      string currency = ExtractJSONString(obj, "currency");
      string impact   = ExtractJSONString(obj, "impact");
      string title    = ExtractJSONString(obj, "title");
      if(StringLen(title) == 0) continue;

      NewsItem item;
      item.when      = when;
      item.currency  = currency;
      item.impact    = impact;
      item.title     = title;
      item.source    = "backend";
      item.timestamp = ParseDisplayTime(when);
      AppendNewsItem(item);
   }
}

void FetchMt5CalendarNews()
{
   datetime from = TimeCurrent() - 12 * 3600;
   datetime to   = TimeCurrent() + 72 * 3600;

   MqlCalendarValue values[];
   if(CalendarValueHistory(values, from, to) <= 0) return;

   int total = ArraySize(values);
   for(int i = 0; i < total && i < 50; i++)
   {
      MqlCalendarEvent event;
      if(!CalendarEventById(values[i].event_id, event)) continue;

      MqlCalendarCountry country;
      if(!CalendarCountryById(event.country_id, country)) continue;

      NewsItem item;
      item.timestamp = (long)values[i].time;
      item.when      = TimeToString(values[i].time, TIME_DATE | TIME_MINUTES);
      if(StringLen(item.when) > 11)
      {
         string mmdd = StringSubstr(item.when, 5, 2) + "-" + StringSubstr(item.when, 8, 2);
         string hhmm = StringSubstr(item.when, 11, 5);
         item.when = mmdd + " " + hhmm;
      }
      item.currency = country.currency;
      item.impact   = Mt5ImportanceToImpact(event.importance);
      item.title    = event.name;
      item.source   = "mt5";
      AppendNewsItem(item);
   }
}

string Mt5ImportanceToImpact(int importance)
{
   switch(importance)
   {
      case CALENDAR_IMPORTANCE_HIGH:     return "HIGH";
      case CALENDAR_IMPORTANCE_MODERATE: return "MEDIUM";
      case CALENDAR_IMPORTANCE_LOW:      return "LOW";
      default:                           return "LOW";
   }
}

string Mt5UnitName(int unit)
{
   switch(unit)
   {
      case CALENDAR_UNIT_PERCENT:      return "%";
      case CALENDAR_UNIT_CURRENCY:     return "currency";
      case CALENDAR_UNIT_HOUR:         return "hours";
      case CALENDAR_UNIT_JOB:          return "jobs";
      case CALENDAR_UNIT_RIG:          return "rigs";
      case CALENDAR_UNIT_USD:          return "USD";
      case CALENDAR_UNIT_PEOPLE:       return "people";
      case CALENDAR_UNIT_MORTGAGE:     return "mortgages";
      case CALENDAR_UNIT_VOTE:         return "votes";
      case CALENDAR_UNIT_BARREL:       return "barrels";
      case CALENDAR_UNIT_CUBICFEET:    return "cu.ft";
      case CALENDAR_UNIT_POSITION:     return "positions";
      case CALENDAR_UNIT_BUILDING:     return "buildings";
      case CALENDAR_UNIT_NONE:
      default:                         return "";
   }
}

string Mt5SectorName(int sector)
{
   switch(sector)
   {
      case CALENDAR_SECTOR_MARKET:           return "Market";
      case CALENDAR_SECTOR_GDP:              return "GDP";
      case CALENDAR_SECTOR_JOBS:             return "Jobs";
      case CALENDAR_SECTOR_PRICES:           return "Prices";
      case CALENDAR_SECTOR_MONEY:            return "Money";
      case CALENDAR_SECTOR_TRADE:            return "Trade";
      case CALENDAR_SECTOR_GOVERNMENT:       return "Government";
      case CALENDAR_SECTOR_BUSINESS:         return "Business";
      case CALENDAR_SECTOR_CONSUMER:         return "Consumer";
      case CALENDAR_SECTOR_HOUSING:          return "Housing";
      case CALENDAR_SECTOR_TAXES:            return "Taxes";
      case CALENDAR_SECTOR_HOLIDAYS:         return "Holidays";
      case CALENDAR_SECTOR_NONE:
      default:                               return "";
   }
}

string Mt5FrequencyName(int frequency)
{
   switch(frequency)
   {
      case CALENDAR_FREQUENCY_WEEK:      return "Weekly";
      case CALENDAR_FREQUENCY_MONTH:     return "Monthly";
      case CALENDAR_FREQUENCY_QUARTER:   return "Quarterly";
      case CALENDAR_FREQUENCY_YEAR:      return "Yearly";
      case CALENDAR_FREQUENCY_DAY:       return "Daily";
      case CALENDAR_FREQUENCY_NONE:
      default:                           return "";
   }
}

string Mt5EventTypeName(int type)
{
   switch(type)
   {
      case CALENDAR_TYPE_EVENT:      return "event";
      case CALENDAR_TYPE_INDICATOR:  return "indicator";
      case CALENDAR_TYPE_HOLIDAY:    return "holiday";
      default:                       return "";
   }
}

bool CalendarHasValue(long v)
{
   if(v == LONG_MIN || v == LONG_MAX) return false;
   double scaled = (double)v / 1000000.0;
   if(scaled < -1e9 || scaled > 1e9) return false;
   return true;
}

// Returns # of events pushed (>0 on success). On failure returns 0 AND sets
// `outReason` to a machine-readable code that's surfaced in the UI.
int PushMt5CalendarNews(int &outImported, int &outUpdated, string &outReason)
{
   outImported = 0;
   outUpdated  = 0;
   outReason   = "";

   if(!TerminalInfoInteger(TERMINAL_CONNECTED))
   {
      outReason = "mt5_disconnected";
      return 0;
   }

   datetime from = TimeCurrent() - (datetime)(InpNewsPushBackH * 3600);
   datetime to   = TimeCurrent() + (datetime)(InpNewsPushWindowH * 3600);

   MqlCalendarValue values[];
   ResetLastError();
   int n = CalendarValueHistory(values, from, to);
   if(n <= 0)
   {
      int err = GetLastError();
      PrintFormat("[NewsPush] CalendarValueHistory returned %d (err %d) — window %s..%s",
                  n, err, TimeToString(from), TimeToString(to));
      outReason = StringFormat("mt5_calendar_empty (err=%d, window=%dh back / %dh ahead)",
                               err, InpNewsPushBackH, InpNewsPushWindowH);
      return 0;
   }

   string json = "{\"events\":[";
   bool first = true;
   int pushed = 0;
   int max = MathMin(n, 500);
   int skippedNoEvent = 0, skippedNoCountry = 0, skippedBadCurrency = 0;

   for(int i = 0; i < max; i++)
   {
      MqlCalendarEvent event;
      if(!CalendarEventById(values[i].event_id, event)) { skippedNoEvent++; continue; }

      MqlCalendarCountry country;
      if(!CalendarCountryById(event.country_id, country)) { skippedNoCountry++; continue; }

      string currency = country.currency;
      if(StringLen(currency) < 3) { skippedBadCurrency++; continue; }

      string eventAt = FormatIso8601(values[i].time);

      string forecast = CalendarHasValue(values[i].forecast_value)
                        ? DoubleToString((double)values[i].forecast_value / 1000000.0, 4) : "";
      string previous = CalendarHasValue(values[i].prev_value)
                        ? DoubleToString((double)values[i].prev_value     / 1000000.0, 4) : "";
      string actual   = CalendarHasValue(values[i].actual_value)
                        ? DoubleToString((double)values[i].actual_value   / 1000000.0, 4) : "";

      if(!first) json += ",";
      json += "{";
      json += "\"event_id\":"  + IntegerToString((int)event.id) + ",";
      json += "\"title\":\""    + JsonEscape(event.name)     + "\",";
      json += "\"currency\":\"" + currency                   + "\",";
      json += "\"impact\":\""   + Mt5ImportanceToImpact(event.importance) + "\",";
      json += "\"forecast\":\"" + forecast                   + "\",";
      json += "\"previous\":\"" + previous                   + "\",";
      json += "\"actual\":\""   + actual                     + "\",";
      json += "\"event_at\":\"" + eventAt                    + "Z\",";
      json += "\"source_url\":\"" + JsonEscape(event.source_url)        + "\",";
      json += "\"unit\":\""       + Mt5UnitName(event.unit)             + "\",";
      json += "\"sector\":\""     + Mt5SectorName(event.sector)         + "\",";
      json += "\"frequency\":\""  + Mt5FrequencyName(event.frequency)   + "\",";
      json += "\"event_type\":\"" + Mt5EventTypeName(event.type)        + "\"";
      json += "}";
      first = false;
      pushed++;
   }
   json += "]}";

   if(pushed == 0)
   {
      PrintFormat("[NewsPush] No usable events in %d raw values (skipped: no_event=%d, no_country=%d, bad_currency=%d)",
                  n, skippedNoEvent, skippedNoCountry, skippedBadCurrency);
      outReason = StringFormat("mt5_calendar_all_filtered (raw=%d, no_event=%d, no_country=%d, bad_currency=%d)",
                               n, skippedNoEvent, skippedNoCountry, skippedBadCurrency);
      return 0;
   }

   char postData[]; char result[]; string resHeaders;
   string headers = "Content-Type: application/json\r\n" +
                    "Authorization: Bearer " + InpEaToken + "\r\n";
   string url = EndpointUrl("/news");

   StringToCharArray(json, postData, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(postData, ArraySize(postData) - 1);

   int code = WebRequest("POST", url, headers, 30000, postData, result, resHeaders);
   if(code >= 200 && code < 300)
   {
      string body = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
      PrintFormat("[NewsPush] Pushed %d MT5 events → HTTP %d  %s",
                  pushed, code, StringSubstr(body, 0, 120));
      outImported = (int)StringToInteger(ExtractJSONString(body, "imported"));
      outUpdated  = (int)StringToInteger(ExtractJSONString(body, "updated"));
      return pushed;
   }

   string body = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   PrintFormat("[NewsPush] HTTP %d for %s (pushed %d events) body=%s",
               code, url, pushed, StringSubstr(body, 0, 200));
   outReason = StringFormat("backend_http_%d (url=%s)", code, url);
   return 0;
}

void PushMt5CalendarNewsPeriodic()
{
   int imp = 0, upd = 0;
   string reason = "";
   PushMt5CalendarNews(imp, upd, reason);
}

void PollNewsRequests()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;

   char   post[];
   char   result[];
   string resHeaders;
   string headers = "Authorization: Bearer " + InpEaToken + "\r\n" +
                    "Accept: application/json\r\n";
   string url = EndpointUrl("/news-requests/pending");

   int code = WebRequest("GET", url, headers, 10000, post, result, resHeaders);
   if(code != 200) return;

   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   int dataStart = StringFind(response, "\"data\"");
   if(dataStart < 0) return;
   int arrStart = StringFind(response, "[", dataStart);
   int arrEnd   = StringFind(response, "]", arrStart);
   if(arrStart < 0 || arrEnd < 0) return;

   string arr = StringSubstr(response, arrStart + 1, arrEnd - arrStart - 1);
   if(StringLen(arr) < 5) return;

   string items[];
   int n = StringSplit(arr, '{', items);

   for(int i = 1; i < n; i++)
   {
      string obj = items[i];
      int reqId = (int)StringToInteger(ExtractJSONString(obj, "id"));
      if(reqId <= 0) continue;

      PrintFormat("[NewsReq] Request #%d → fetching MT5 calendar...", reqId);

      int imported = 0, updated = 0;
      string reason = "";
      int pushed = PushMt5CalendarNews(imported, updated, reason);

      if(pushed > 0)
      {
         CompleteNewsRequest(reqId, imported, updated);
         PrintFormat("[NewsReq] #%d done: pushed=%d imported=%d updated=%d",
                     reqId, pushed, imported, updated);
      }
      else
      {
         if(StringLen(reason) == 0) reason = "unknown";
         FailNewsRequest(reqId, reason);
         PrintFormat("[NewsReq] #%d failed: %s", reqId, reason);
      }
   }
}

void CompleteNewsRequest(int reqId, int imported, int updated)
{
   string headers = "Content-Type: application/json\r\n" +
                    "Authorization: Bearer " + InpEaToken + "\r\n";
   string payload = StringFormat("{\"imported\":%d,\"updated\":%d}", imported, updated);
   char data[]; char result[]; string resHeaders;
   StringToCharArray(payload, data, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(data, ArraySize(data) - 1);
   string url = EndpointUrl("/news-requests/" + IntegerToString(reqId) + "/complete");
   WebRequest("POST", url, headers, 5000, data, result, resHeaders);
}

void FailNewsRequest(int reqId, string reason)
{
   string headers = "Content-Type: application/json\r\n" +
                    "Authorization: Bearer " + InpEaToken + "\r\n";
   string payload = StringFormat("{\"reason\":\"%s\"}", reason);
   char data[]; char result[]; string resHeaders;
   StringToCharArray(payload, data, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(data, ArraySize(data) - 1);
   string url = EndpointUrl("/news-requests/" + IntegerToString(reqId) + "/fail");
   WebRequest("POST", url, headers, 5000, data, result, resHeaders);
}

string JsonEscape(string s)
{
   StringReplace(s, "\\", "\\\\");
   StringReplace(s, "\"", "\\\"");
   StringReplace(s, "\n", " ");
   StringReplace(s, "\r", " ");
   StringReplace(s, "\t", " ");
   return s;
}

string JsonEscapeKeepNewlines(string s)
{
   StringReplace(s, "\\", "\\\\");
   StringReplace(s, "\"", "\\\"");
   StringReplace(s, "\r\n", "\\n");
   StringReplace(s, "\n", "\\n");
   StringReplace(s, "\r", "\\n");
   StringReplace(s, "\t", "\\t");
   return s;
}

string  g_uploaded_news[];
bool    g_uploaded_loaded = false;

void LoadUploadedNewsList()
{
   if(g_uploaded_loaded) return;
   ArrayResize(g_uploaded_news, 0);
   int h = FileOpen("uploaded_news.txt", FILE_READ | FILE_TXT | FILE_ANSI);
   if(h != INVALID_HANDLE)
   {
      while(!FileIsEnding(h))
      {
         string line = FileReadString(h);
         StringTrimLeft(line); StringTrimRight(line);
         if(StringLen(line) > 0)
         {
            int n = ArraySize(g_uploaded_news);
            ArrayResize(g_uploaded_news, n + 1);
            g_uploaded_news[n] = line;
         }
      }
      FileClose(h);
   }
   g_uploaded_loaded = true;
}

void RememberUploadedNews(string filename)
{
   int n = ArraySize(g_uploaded_news);
   ArrayResize(g_uploaded_news, n + 1);
   g_uploaded_news[n] = filename;
   int h = FileOpen("uploaded_news.txt", FILE_WRITE | FILE_READ | FILE_TXT | FILE_ANSI);
   if(h != INVALID_HANDLE)
   {
      FileSeek(h, 0, SEEK_END);
      FileWrite(h, filename);
      FileClose(h);
   }
}

bool WasUploadedNews(string filename)
{
   for(int i = 0; i < ArraySize(g_uploaded_news); i++)
      if(g_uploaded_news[i] == filename) return true;
   return false;
}

void ScanBrokerNewsFolder()
{
   if(!TerminalInfoInteger(TERMINAL_CONNECTED)) return;
   LoadUploadedNewsList();

   string pattern = InpBrokerNewsFolder + "\\*.htm";
   string filename = "";
   long handle = FileFindFirst(pattern, filename);
   if(handle == INVALID_HANDLE) return;

   int pushed = 0;
   do
   {
      if(StringFind(filename, ".htm") < 0) continue;

      string fullPath = InpBrokerNewsFolder + "\\" + filename;
      if(WasUploadedNews(fullPath)) continue;

      string html = ReadFileFully(fullPath);
      if(StringLen(html) < 50) continue;

      string subject  = ExtractHtmlTitle(html);
      string category = "MT5 Broker News";
      string extId    = filename;

      if(PushBrokerNewsItem(extId, subject, category, html))
      {
         RememberUploadedNews(fullPath);
         pushed++;
      }
   } while(FileFindNext(handle, filename));
   FileFindClose(handle);

   if(pushed > 0)
      PrintFormat("[BrokerNews] Pushed %d new HTML article(s) from MQL5/Files/%s/",
                  pushed, InpBrokerNewsFolder);
}

string ReadFileFully(string path)
{
   int h = FileOpen(path, FILE_READ | FILE_TXT | FILE_UNICODE);
   if(h != INVALID_HANDLE)
   {
      string body = "";
      while(!FileIsEnding(h))
      {
         body += FileReadString(h);
         if(!FileIsEnding(h)) body += "\n";
      }
      FileClose(h);
      if(StringLen(body) >= 20) return body;
   }

   h = FileOpen(path, FILE_READ | FILE_TXT | FILE_ANSI);
   if(h != INVALID_HANDLE)
   {
      string body = "";
      while(!FileIsEnding(h))
      {
         body += FileReadString(h);
         if(!FileIsEnding(h)) body += "\n";
      }
      FileClose(h);
      return body;
   }

   return "";
}

string ExtractHtmlTitle(string html)
{
   int s = StringFind(html, "<title>");
   if(s < 0) s = StringFind(html, "<TITLE>");
   if(s < 0) return "Untitled MT5 News";
   s += 7;
   int e = StringFind(html, "</title>", s);
   if(e < 0) e = StringFind(html, "</TITLE>", s);
   if(e < 0) return "Untitled MT5 News";
   string t = StringSubstr(html, s, e - s);
   StringTrimLeft(t); StringTrimRight(t);
   return t;
}

bool PushBrokerNewsItem(string extId, string subject, string category, string html)
{
   string nowIso = FormatIso8601(TimeCurrent());

   string json = "{\"items\":[{";
   json += "\"external_id\":\"" + JsonEscape(extId)         + "\",";
   json += "\"subject\":\""     + JsonEscape(subject)       + "\",";
   json += "\"category\":\""    + JsonEscape(category)      + "\",";
   json += "\"event_at\":\""    + nowIso                    + "Z\",";
   json += "\"body_html\":\""   + JsonEscapeKeepNewlines(html) + "\"";
   json += "}]}";

   char postData[]; char result[]; string resHeaders;
   string headers = "Content-Type: application/json\r\n" +
                    "Authorization: Bearer " + InpEaToken + "\r\n";
   string url = EndpointUrl("/news/broker");

   StringToCharArray(json, postData, 0, WHOLE_ARRAY, CP_UTF8);
   ArrayResize(postData, ArraySize(postData) - 1);

   int code = WebRequest("POST", url, headers, 30000, postData, result, resHeaders);
   if(code >= 200 && code < 300)
   {
      PrintFormat("[BrokerNews] OK '%s' (%d bytes)", subject, StringLen(html));
      return true;
   }
   PrintFormat("[BrokerNews] FAIL HTTP %d for '%s'", code, subject);
   return false;
}

long ParseDisplayTime(string mmddHHMM)
{
   if(StringLen(mmddHHMM) < 11) return (long)TimeCurrent();
   MqlDateTime now;
   TimeToStruct(TimeCurrent(), now);
   MqlDateTime t = now;
   t.mon = (int)StringToInteger(StringSubstr(mmddHHMM, 0, 2));
   t.day = (int)StringToInteger(StringSubstr(mmddHHMM, 3, 2));
   t.hour = (int)StringToInteger(StringSubstr(mmddHHMM, 6, 2));
   t.min = (int)StringToInteger(StringSubstr(mmddHHMM, 9, 2));
   t.sec = 0;
   return (long)StructToTime(t);
}

void AppendNewsItem(NewsItem &item)
{
   int n = ArraySize(g_news_items);
   for(int i = 0; i < n; i++)
   {
      if(g_news_items[i].title == item.title && g_news_items[i].when == item.when)
         return;
   }
   ArrayResize(g_news_items, n + 1);
   g_news_items[n] = item;
}

void SortNewsByTime()
{
   int n = ArraySize(g_news_items);
   for(int i = 1; i < n; i++)
   {
      NewsItem cur = g_news_items[i];
      int j = i - 1;
      while(j >= 0 && g_news_items[j].timestamp > cur.timestamp)
      {
         g_news_items[j + 1] = g_news_items[j];
         j--;
      }
      g_news_items[j + 1] = cur;
   }
}

void RenderNewsPanel()
{
   int total = ArraySize(g_news_items);

   // Pick the slice of items closest to "now". Anchor on the first item
   // within the last 6 hours; if everything is in the past, show the tail.
   long nowTs = (long)TimeCurrent();
   int  startIdx = 0;
   bool anchored = false;
   for(int i = 0; i < total; i++)
   {
      if(g_news_items[i].timestamp >= nowTs - 6 * 3600)
      {
         startIdx = i;
         anchored = true;
         break;
      }
   }
   if(!anchored && total > InpNewsMaxLines)
      startIdx = total - InpNewsMaxLines;

   ObjectSetString(0, NEWS_OBJ_PREFIX + "hint", OBJPROP_TEXT,
                   StringFormat("%d events  updated %s",
                                total,
                                TimeToString(TimeCurrent(), TIME_MINUTES)));

   for(int i = 0; i < InpNewsMaxLines; i++)
   {
      string name = NEWS_OBJ_PREFIX + "line_" + IntegerToString(i);
      int idx = startIdx + i;
      if(idx >= total)
      {
         ObjectSetString(0, name, OBJPROP_TEXT, " ");
         ObjectSetInteger(0, name, OBJPROP_COLOR, NEWS_TEXT_COLOR);
         continue;
      }
      NewsItem n = g_news_items[idx];

      // Same prefix vocabulary the RECENT ALERTS column uses (!! / !  / i  ),
      // mapped onto news impact instead of alert severity.
      string prefix;
      color  col;
      if(n.impact == "HIGH")        { prefix = "!! "; col = NEWS_BAD_COLOR;   }
      else if(n.impact == "MEDIUM") { prefix = "!  "; col = NEWS_WARN_COLOR;  }
      else                          { prefix = "i  "; col = NEWS_TEXT_COLOR;  }

      // Row format: "!! 05-26 14:30 USD CPI m/m"  → fits ~32 chars in a 200 px column.
      int titleMax = 14;
      string shortTitle = (StringLen(n.title) > titleMax)
                          ? StringSubstr(n.title, 0, titleMax - 1) + ".."
                          : n.title;

      string text = StringFormat("%s%s %s %s",
                                 prefix,
                                 n.when,
                                 (n.currency == "" ? "---" : n.currency),
                                 shortTitle);

      ObjectSetString(0, name, OBJPROP_TEXT, text);
      ObjectSetInteger(0, name, OBJPROP_COLOR, col);
   }

   ChartRedraw(0);
}

//+------------------------------------------------------------------+